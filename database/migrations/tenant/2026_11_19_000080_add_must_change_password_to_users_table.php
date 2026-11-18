<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // Vrai après une création ou une réinitialisation par le gérant (mot de passe provisoire).
            $t->boolean('must_change_password')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('must_change_password');
        });
    }
};
