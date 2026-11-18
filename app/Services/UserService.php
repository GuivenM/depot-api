<?php

namespace App\Services;

use App\Exceptions\DepotException;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seul point d'entrée pour créer et modifier les comptes du dépôt.
 * Un compte n'est jamais supprimé (il est référencé par les ventes, mouvements, caisses...) : on le désactive.
 */
class UserService
{
    /** @return array{0: User, 1: ?string} le compte et le mot de passe provisoire (null si le gérant en a fourni un) */
    public function create(array $data): array
    {
        $generated = empty($data['password']) ? $this->randomPassword() : null;

        $user = new User;
        $user->forceFill([
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'password'             => $generated ?? $data['password'],
            'role'                 => $data['role'],
            'active'               => true,
            'must_change_password' => true,
        ])->save();

        return [$user->fresh(), $generated];
    }

    public function update(User $actor, int $id, array $data): User
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $user = User::lockForUpdate()->findOrFail($id);

            if (isset($data['role']) && $data['role'] !== $user->role) {
                if ($user->id === $actor->id) {
                    throw new DepotException('Vous ne pouvez pas modifier votre propre rôle.');
                }
                $this->assertNoOpenCash($user, 'changer son rôle');
                if ($user->role === 'gerant') {
                    $this->assertAnotherActiveManager($user);
                }
            }

            $user->forceFill(array_intersect_key($data, array_flip(['name', 'email', 'role'])))->save();

            return $user->fresh();
        });
    }

    public function setActive(User $actor, int $id, bool $active): User
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $user = User::lockForUpdate()->findOrFail($id);

            if ((bool) $user->active === $active) {
                return $user;
            }

            if (! $active) {
                if ($user->id === $actor->id) {
                    throw new DepotException('Vous ne pouvez pas désactiver votre propre compte.');
                }
                $this->assertNoOpenCash($user, 'le désactiver');
                if ($user->role === 'gerant') {
                    $this->assertAnotherActiveManager($user);
                }
            }

            $user->forceFill(['active' => $active])->save();

            if (! $active) {
                $user->tokens()->delete();   // coupe les sessions ouvertes sur tous les appareils
            }

            return $user->fresh();
        });
    }

    /** @return array{0: User, 1: string} le compte et le nouveau mot de passe provisoire (à communiquer une seule fois) */
    public function resetPassword(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $user = User::lockForUpdate()->findOrFail($id);

            if ($user->id === $actor->id) {
                throw new DepotException('Pour votre propre compte, utilisez le changement de mot de passe.');
            }

            $plain = $this->randomPassword();
            $user->forceFill(['password' => $plain, 'must_change_password' => true])->save();
            $user->tokens()->delete();

            return [$user->fresh(), $plain];
        });
    }

    /** Changement par l'utilisateur lui-même : ses autres appareils sont déconnectés, celui-ci reste connecté. */
    public function changeOwnPassword(User $user, string $current, string $new, ?int $keepTokenId): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new DepotException('Le mot de passe actuel est incorrect.');
        }

        if (Hash::check($new, $user->password)) {
            throw new DepotException('Le nouveau mot de passe doit être différent de l\'actuel.');
        }

        DB::transaction(function () use ($user, $new, $keepTokenId) {
            $user->forceFill(['password' => $new, 'must_change_password' => false])->save();

            $tokens = $user->tokens();
            if ($keepTokenId !== null) {
                $tokens = $tokens->where('id', '!=', $keepTokenId);
            }
            $tokens->delete();
        });
    }

    private function randomPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /** Une caisse ouverte appartient à son titulaire : on la clôture d'abord. */
    private function assertNoOpenCash(User $user, string $action): void
    {
        if (CashSession::open()->where('user_id', $user->id)->exists()) {
            throw new DepotException("Ce compte a une caisse ouverte : clôturez-la avant de {$action}.", 409);
        }
    }

    /** Garde-fou : il doit toujours rester un gérant actif (les gérants concernés sont verrouillés). */
    private function assertAnotherActiveManager(User $target): void
    {
        $others = User::where('role', 'gerant')->where('active', true)
            ->where('id', '!=', $target->id)->lockForUpdate()->count();

        if ($others === 0) {
            throw new DepotException('Il doit rester au moins un gérant actif.', 409);
        }
    }
}
