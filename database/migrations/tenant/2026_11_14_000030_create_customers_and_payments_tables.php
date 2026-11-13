<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('type', 20)->default('particulier');        // bar|restaurant|revendeur|particulier
            $t->unsignedInteger('credit_limit')->default(0);       // FCFA ; 0 = pas de crédit
            $t->integer('balance')->default(0);                    // dette actuelle (cache, modifié uniquement par CreditService)
            $t->boolean('active')->default(true);
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index('name');
        });

        // Journal des dettes : source de vérité. amount > 0 = la dette augmente, < 0 = elle diminue.
        Schema::create('customer_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->string('type', 20);                                // sale_credit|payment|adjustment
            $t->integer('amount');
            $t->integer('balance_after');
            $t->nullableMorphs('reference');
            $t->foreignId('user_id')->constrained();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'id']);
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                            // idempotence (hors ligne / double clic)
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('cash_session_id')->nullable()->constrained();
            $t->foreignId('sale_id')->nullable()->constrained();   // paiement immédiat d'une vente à crédit
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('amount');
            $t->string('method', 10);                              // cash|momo
            $t->string('note')->nullable();
            $t->timestamp('paid_at');
            $t->timestamps();
            $t->index(['cash_session_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('customer_ledger');
        Schema::dropIfExists('customers');
    }
};
