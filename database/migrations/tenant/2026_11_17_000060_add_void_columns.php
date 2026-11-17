<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'purchases'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users');
                $t->string('void_reason')->nullable();
            });
        }

        // Un règlement annulé n'est jamais supprimé : il est marqué, et exclu de la caisse.
        foreach (['payments', 'supplier_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('voided_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'supplier_payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('voided_at'));
        }
        foreach (['sales', 'purchases'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('voided_by');
                $t->dropColumn(['voided_at', 'void_reason']);
            });
        }
    }
};
