<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seul point d'entrée pour écrire dans le journal d'audit.
 * À appeler DANS la transaction de l'action auditée : si l'action échoue, aucune trace ne subsiste ;
 * si elle réussit, la trace existe forcément.
 * Ne jamais y mettre de mot de passe ni de secret.
 */
class AuditService
{
    public function record(User $by, string $action, ?Model $subject = null, array $details = [], ?string $note = null): AuditLog
    {
        return AuditLog::create([
            'user_id'      => $by->id,
            'action'       => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id'   => $subject?->getKey(),
            'details'      => $details ?: null,
            'note'         => $note !== null ? mb_substr($note, 0, 255) : null,
            'ip'           => request()?->ip(),
        ]);
    }

    /** Crée un modèle et journalise sa création (valeurs initiales des champs surveillés), dans une transaction. */
    public function createAudited(User $by, string $action, string $modelClass, array $data, array $watch): Model
    {
        return DB::transaction(function () use ($by, $action, $modelClass, $data, $watch) {
            $model = $modelClass::create($data)->fresh();
            $this->record($by, $action, $model, ['values' => $this->snapshot($model, $watch)]);

            return $model;
        });
    }

    /** Modifie un modèle et journalise les seuls champs surveillés réellement changés (rien si aucun), dans une transaction. */
    public function updateAudited(User $by, string $action, Model $model, array $data, array $watch): Model
    {
        return DB::transaction(function () use ($by, $action, $model, $data, $watch) {
            $before = $this->snapshot($model, $watch);
            $model->update($data);
            $fresh = $model->fresh();

            $changes = $this->diff($before, $this->snapshot($fresh, $watch), $watch);
            if ($changes) {
                $this->record($by, $action, $fresh, $changes);
            }

            return $fresh;
        });
    }

    /**
     * Compare deux états sur une liste de champs surveillés.
     *
     * @return array<string, array{from: mixed, to: mixed}> uniquement les champs modifiés
     */
    public function diff(array $before, array $after, array $watch): array
    {
        $changes = [];

        foreach ($watch as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            if ($this->normalize($from) !== $this->normalize($to)) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** Valeurs d'un modèle sur les champs surveillés. */
    public function snapshot(Model $model, array $watch): array
    {
        return array_intersect_key($model->getAttributes(), array_flip($watch));
    }

    private function normalize(mixed $value): string
    {
        return match (true) {
            $value === null  => '~null',
            is_bool($value)  => $value ? '1' : '0',
            default          => (string) $value,
        };
    }
}
