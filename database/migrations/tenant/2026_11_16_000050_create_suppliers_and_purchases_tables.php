<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('note')->nullable();
            $t->integer('balance')->default(0);                    // ce que le dépôt doit au fournisseur (cache, via SupplierService)
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('name');
        });

        // Journal des dettes fournisseurs : amount > 0 = on doit plus, < 0 = on a payé / corrigé.
        Schema::create('supplier_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained();
            $t->string('type', 20);                                // purchase|payment|adjustment
            $t->integer('amount');
            $t->integer('balance_after');
            $t->nullableMorphs('reference');
            $t->foreignId('user_id')->constrained();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'id']);
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                            // idempotence
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('invoice_ref', 60)->nullable();
            $t->unsignedInteger('total');                          // FCFA
            $t->string('status')->default('received');
            $t->timestamp('purchased_at');
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index('supplier_id');
        });

        Schema::create('purchase_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->string('unit', 10);                                // crate|bottle
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('bottles');                        // équivalent en bouteilles (stock)
            $t->unsignedInteger('unit_cost');
            $t->unsignedInteger('line_total');
            $t->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('supplier_id')->constrained();
            $t->foreignId('cash_session_id')->nullable()->constrained();   // renseigné si payé en cash (sort du tiroir)
            $t->foreignId('purchase_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('amount');
            $t->string('method', 10);                              // cash|momo|bank
            $t->string('note')->nullable();
            $t->timestamp('paid_at');
            $t->timestamps();
            $t->index(['cash_session_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('supplier_ledger');
        Schema::dropIfExists('suppliers');
    }
};
