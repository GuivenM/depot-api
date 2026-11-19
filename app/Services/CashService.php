<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashService
{
    public function current(User $user): ?CashSession
    {
        return CashSession::open()->where('user_id', $user->id)->first();
    }

    public function open(User $by, int $openingAmount): CashSession
    {
        return DB::transaction(function () use ($by, $openingAmount) {
            if (CashSession::open()->where('user_id', $by->id)->lockForUpdate()->exists()) {
                throw DepotException::cashSessionAlreadyOpen();
            }

            return CashSession::create([
                'user_id'        => $by->id,
                'opening_amount' => $openingAmount,
                'opened_at'      => now(),
            ]);
        });
    }

    public function close(CashSession $session, User $by, int $countedCash, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($session, $by, $countedCash, $note) {
            $s = CashSession::lockForUpdate()->findOrFail($session->id);

            if ($s->closed_at) {
                throw DepotException::cashSessionClosed();
            }

            $expected = $s->summary()['expected_cash'];

            $s->update([
                'closed_at'     => now(),
                'closed_by'     => $by->id,
                'expected_cash' => $expected,
                'counted_cash'  => $countedCash,
                'difference'    => $countedCash - $expected,
                'note'          => $note,
            ]);

            $fresh = $s->fresh();

            // Tracé seulement si un écart existe ou si la caisse est clôturée par quelqu'un d'autre que son titulaire.
            if ((int) $by->id !== (int) $s->user_id || (int) $fresh->difference !== 0) {
                app(AuditService::class)->record($by, 'cash.closed', $fresh, [
                    'owner_id' => $s->user_id, 'expected_cash' => (int) $fresh->expected_cash,
                    'counted_cash' => (int) $fresh->counted_cash, 'difference' => (int) $fresh->difference,
                ], $note);
            }

            return $fresh;
        });
    }

    public function addExpense(User $by, string $category, int $amount, ?string $note = null): Expense
    {
        return DB::transaction(function () use ($by, $category, $amount, $note) {
            $session = CashSession::open()->where('user_id', $by->id)->lockForUpdate()->first();

            if (! $session) {
                throw DepotException::noOpenCashSession();
            }

            return Expense::create([
                'cash_session_id' => $session->id,
                'user_id'         => $by->id,
                'category'        => $category,
                'amount'          => $amount,
                'note'            => $note,
            ]);
        });
    }
}
