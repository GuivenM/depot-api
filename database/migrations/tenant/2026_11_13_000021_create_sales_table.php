<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                              // généré côté client : idempotence hors ligne
            $t->foreignId('cash_session_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('payment_method');                            // cash|momo
            $t->unsignedInteger('total');                            // FCFA
            $t->string('status')->default('completed');
            $t->timestamp('sold_at');
            $t->timestamps();
            $t->index(['cash_session_id', 'status']);
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->string('unit');                                      // crate|bottle
            $t->unsignedInteger('quantity');                         // dans l'unité vendue
            $t->unsignedInteger('bottles');                          // équivalent en bouteilles (stock)
            $t->unsignedInteger('unit_price');
            $t->unsignedInteger('line_total');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
