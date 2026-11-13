<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained();
            $t->string('type');                  // entree|sortie|casse|ajustement|inventaire
            $t->integer('quantity');             // en BOUTEILLES, signée
            $t->unsignedInteger('unit_cost')->nullable();
            $t->nullableMorphs('reference');     // vente, réception...
            $t->foreignId('user_id')->constrained();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
