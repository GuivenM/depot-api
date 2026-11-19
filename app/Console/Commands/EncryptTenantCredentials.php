<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptTenantCredentials extends Command
{
    protected $signature = 'depot:encrypt-credentials';
    protected $description = 'Chiffre les mots de passe de base des dépôts encore stockés en clair (idempotent, à lancer une fois après la mise à jour)';

    public function handle(): int
    {
        $done = 0;
        $already = 0;

        foreach (DB::table('tenants')->select('id', 'data')->get() as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $password = $data['tenancy_db_password'] ?? null;

            if ($password === null || $password === '') {
                continue;
            }

            if ($this->isEncrypted((string) $password)) {
                $already++;
                continue;
            }

            $data['tenancy_db_password'] = Crypt::encryptString((string) $password);
            DB::table('tenants')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            $done++;
        }

        $this->info("{$done} dépôt(s) chiffré(s), {$already} déjà chiffré(s).");
        $this->warn('Sauvegardez APP_KEY hors du serveur : sans elle, ces mots de passe sont irrécupérables.');

        return self::SUCCESS;
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
