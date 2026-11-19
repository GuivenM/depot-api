<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\ContainerHolding;
use App\Models\ContainerMovement;
use App\Models\ContainerType;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'entrée pour modifier les casiers (vides au dépôt et casiers dus par les tiers).
 * Ordre des verrous (à respecter partout) : caisse -> client -> produits -> types de casiers (triés) -> soldes.
 */
class ContainerService
{
    /**
     * Appelé par SaleService, dans la transaction de la vente.
     *
     * @param array<int,int> $sold     [container_type_id => casiers pleins vendus]
     * @param array<int,int> $returned [container_type_id => casiers vides rendus pendant la vente]
     */
    public function applySale(User $by, ?Customer $customer, Sale $sale, array $sold, array $returned): void
    {
        $typeIds = array_values(array_unique(array_merge(array_keys($sold), array_keys($returned))));
        sort($typeIds);

        foreach ($typeIds as $typeId) {
            $out = (int) ($sold[$typeId] ?? 0);
            $back = (int) ($returned[$typeId] ?? 0);
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);

            if ($customer) {
                $holding = $this->holding($type, 'customer', $customer->id);
                $before = (int) $holding->quantity;
                $after = $before + $out - $back;

                if ($after < 0) {
                    throw new DepotException(
                        "Le client ne peut rendre que ".($before + $out)." casier(s) « {$type->name} » (dus {$before} + achetés {$out})."
                    );
                }

                $holding->update(['quantity' => $after]);

                if ($out > 0) {
                    $this->log($by, $type, 'sale_out', 'customer', $customer->id, $out, 0, $before + $out, $sale);
                }
                if ($back > 0) {
                    $this->log($by, $type, 'return_in', 'customer', $customer->id, -$back, $back, $after, $sale);
                }
            } else {
                // Client anonyme : l'échange doit être équilibré, sinon on ne saurait pas qui doit les casiers.
                if ($out > $back) {
                    throw new DepotException(
                        "Casiers « {$type->name} » non rendus (".($out - $back)." de moins que vendus) : sélectionnez un client pour suivre ce qu'il doit."
                    );
                }
                if ($back > $out) {
                    throw new DepotException("Impossible de rendre plus de casiers « {$type->name} » que vendus sans client identifié.");
                }
                if ($back > 0) {
                    $this->log($by, $type, 'return_in', null, null, 0, $back, null, $sale);
                }
            }

            if ($back > 0) {
                $type->increment('empty_stock', $back);
            }
        }
    }

    /**
     * Appelé par PurchaseService, dans la transaction de l'achat.
     * Casiers pleins reçus = casiers dus au fournisseur ; vides rendus = on en doit moins (solde négatif autorisé :
     * le fournisseur nous en doit). Les vides rendus sortent du stock de vides du dépôt.
     *
     * @param array<int,int> $received [container_type_id => casiers pleins reçus]
     * @param array<int,int> $returned [container_type_id => casiers vides rendus au fournisseur]
     */
    public function applyPurchase(User $by, Supplier $supplier, Purchase $purchase, array $received, array $returned): void
    {
        $typeIds = array_values(array_unique(array_merge(array_keys($received), array_keys($returned))));
        sort($typeIds);

        foreach ($typeIds as $typeId) {
            $in = (int) ($received[$typeId] ?? 0);
            $out = (int) ($returned[$typeId] ?? 0);
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);

            if ($out > $type->empty_stock) {
                throw new DepotException("Stock de casiers vides insuffisant pour en rendre {$out} ({$type->empty_stock} « {$type->name} » au dépôt).");
            }

            $holding = $this->holding($type, 'supplier', $supplier->id);
            $before = (int) $holding->quantity;
            $after = $before + $in - $out;

            $holding->update(['quantity' => $after]);

            if ($in > 0) {
                $this->log($by, $type, 'purchase_in', 'supplier', $supplier->id, $in, 0, $before + $in, $purchase);
            }
            if ($out > 0) {
                $this->log($by, $type, 'to_supplier', 'supplier', $supplier->id, -$out, -$out, $after, $purchase);
                $type->decrement('empty_stock', $out);
            }
        }
    }

    /** Un client rapporte des casiers vides (hors vente). */
    public function customerReturn(User $by, Customer $customer, int $typeId, int $quantity, ?string $note = null): ContainerHolding
    {
        return DB::transaction(function () use ($by, $customer, $typeId, $quantity, $note) {
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);
            $holding = $this->holding($type, 'customer', $customer->id);

            if ($quantity > $holding->quantity) {
                throw new DepotException("Ce client ne doit que {$holding->quantity} casier(s) « {$type->name} ».");
            }

            $holding->update(['quantity' => $holding->quantity - $quantity]);
            $type->increment('empty_stock', $quantity);

            $this->log($by, $type, 'return_in', 'customer', $customer->id, -$quantity, $quantity, $holding->quantity, null, $note);

            return $holding->fresh();
        });
    }

    /** Correction ou abandon de casiers dus par un client (gérant). $delta signé. */
    public function adjustHolding(User $by, Customer $customer, int $typeId, int $delta, string $note): ContainerHolding
    {
        return DB::transaction(function () use ($by, $customer, $typeId, $delta, $note) {
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);
            $holding = $this->holding($type, 'customer', $customer->id);
            $before = (int) $holding->quantity;
            $after = $before + $delta;

            if ($after < 0) {
                throw new DepotException('Cet ajustement rendrait le nombre de casiers dus négatif.');
            }

            $holding->update(['quantity' => $after]);
            $this->log($by, $type, 'adjustment', 'customer', $customer->id, $delta, 0, $after, null, $note);
            app(AuditService::class)->record($by, 'container.holding_adjusted', $customer, [
                'container_type_id' => $type->id, 'container' => $type->name, 'delta' => $delta,
                'quantity_before' => $before, 'quantity_after' => $after,
            ], $note);

            return $holding->fresh();
        });
    }

    /** Mouvement des casiers vides du dépôt : retour/arrivée fournisseur, perte, correction. */
    public function depotMovement(User $by, int $typeId, string $kind, int $quantity, ?string $note = null): ContainerType
    {
        return DB::transaction(function () use ($by, $typeId, $kind, $quantity, $note) {
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);

            $delta = match ($kind) {
                'from_supplier'        => abs($quantity),
                'to_supplier', 'loss'  => -abs($quantity),
                'adjustment'           => $quantity,
            };

            if ($type->empty_stock + $delta < 0) {
                throw new DepotException("Stock de casiers vides insuffisant ({$type->empty_stock} « {$type->name} »).");
            }

            $before = (int) $type->empty_stock;
            $type->increment('empty_stock', $delta);
            $this->log($by, $type, $kind, null, null, 0, $delta, null, null, $note);

            if (in_array($kind, ['loss', 'adjustment'], true)) {   // pertes et corrections des vides du dépôt
                app(AuditService::class)->record($by, $kind === 'loss' ? 'container.depot_loss' : 'container.depot_adjusted', $type, [
                    'delta' => $delta, 'empty_stock_before' => $before, 'empty_stock_after' => $before + $delta,
                ], $note);
            }

            return $type->fresh();
        });
    }

    /**
     * Applique l'écart d'un inventaire physique sur les casiers vides du dépôt (appelé par InventoryService).
     * Refusé si les vides deviendraient négatifs.
     */
    public function applyInventory(User $by, int $typeId, int $delta, Model $inventory): void
    {
        $type = ContainerType::lockForUpdate()->findOrFail($typeId);

        if ($type->empty_stock + $delta < 0) {
            throw new DepotException(
                "Inventaire impossible à valider : le stock de casiers vides « {$type->name} » deviendrait négatif ({$type->empty_stock} au dépôt, écart {$delta})."
            );
        }

        $type->increment('empty_stock', $delta);
        $this->log($by, $type, 'inventory', null, null, 0, $delta, null, $inventory, 'Inventaire #'.$inventory->getKey());
    }

    /**
     * Annule les effets sur les casiers d'une opération (vente ou achat) en rejouant l'inverse de ses mouvements.
     * Refusé si un client aurait un solde négatif, ou si les vides du dépôt deviendraient négatifs
     * (casiers déjà rendus ou renvoyés depuis).
     */
    public function reverse(User $by, Model $ref, string $reason): void
    {
        $rows = ContainerMovement::where('reference_type', $ref->getMorphClass())
            ->where('reference_id', $ref->getKey())
            ->where('type', '!=', 'void')
            ->get();

        foreach ($rows->pluck('container_type_id')->unique()->sort() as $typeId) {
            $type = ContainerType::lockForUpdate()->findOrFail($typeId);

            $groups = $rows->where('container_type_id', $typeId)
                ->groupBy(fn ($r) => ($r->party_type ?? '-').':'.($r->party_id ?? 0));

            foreach ($groups as $group) {
                $first = $group->first();
                $partyDelta = -(int) $group->sum('party_delta');
                $depotDelta = -(int) $group->sum('depot_delta');
                $after = null;

                if ($first->party_type) {
                    $holding = $this->holding($type, $first->party_type, $first->party_id);
                    $after = (int) $holding->quantity + $partyDelta;

                    if ($first->party_type === 'customer' && $after < 0) {
                        throw new DepotException(
                            "Annulation impossible : le client a déjà rendu des casiers « {$type->name} » depuis cette opération. Corrigez ses casiers (ajustement) puis recommencez."
                        );
                    }

                    $holding->update(['quantity' => $after]);
                }

                if ($depotDelta !== 0) {
                    if ($type->empty_stock + $depotDelta < 0) {
                        throw new DepotException(
                            "Annulation impossible : stock de casiers vides « {$type->name} » insuffisant au dépôt ({$type->empty_stock})."
                        );
                    }

                    $type->increment('empty_stock', $depotDelta);
                }

                $this->log($by, $type, 'void', $first->party_type, $first->party_id, $partyDelta, $depotDelta, $after, $ref, $reason);
            }
        }
    }

    private function holding(ContainerType $type, string $partyType, int $partyId): ContainerHolding
    {
        $where = ['container_type_id' => $type->id, 'party_type' => $partyType, 'party_id' => $partyId];

        $holding = ContainerHolding::where($where)->lockForUpdate()->first();

        if (! $holding) {
            try {
                $holding = ContainerHolding::create($where + ['quantity' => 0]);
            } catch (UniqueConstraintViolationException) {
                $holding = ContainerHolding::where($where)->lockForUpdate()->firstOrFail();
            }
        }

        return $holding;
    }

    private function log(
        User $by,
        ContainerType $type,
        string $kind,
        ?string $partyType,
        ?int $partyId,
        int $partyDelta,
        int $depotDelta,
        ?int $balanceAfter,
        ?Model $ref = null,
        ?string $note = null,
    ): void {
        ContainerMovement::create([
            'container_type_id'   => $type->id,
            'party_type'          => $partyType,
            'party_id'            => $partyId,
            'type'                => $kind,
            'party_delta'         => $partyDelta,
            'depot_delta'         => $depotDelta,
            'party_balance_after' => $balanceAfter,
            'reference_type'      => $ref?->getMorphClass(),
            'reference_id'        => $ref?->getKey(),
            'user_id'             => $by->id,
            'note'                => $note,
        ]);
    }
}
