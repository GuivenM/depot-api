<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un type = un casier avec ses bouteilles (l'unité qui circule et qu'on se rend).
        Schema::create('container_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);                                  // "Casier 65cl"
            $t->unsignedInteger('deposit_amount')->default(0);        // valeur de consigne d'un casier, FCFA
            $t->integer('empty_stock')->default(0);                   // casiers vides au dépôt (cache, via ContainerService)
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Casiers détenus par un tiers (aujourd'hui : client ; plus tard : fournisseur). Cache du journal.
        Schema::create('container_holdings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('container_type_id')->constrained();
            $t->string('party_type', 20);                             // customer|supplier
            $t->unsignedBigInteger('party_id');
            $t->integer('quantity')->default(0);                      // casiers que le tiers doit rendre
            $t->timestamps();
            $t->unique(['container_type_id', 'party_type', 'party_id']);
        });

        // Journal des casiers : source de vérité.
        Schema::create('container_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('container_type_id')->constrained();
            $t->string('party_type', 20)->nullable();
            $t->unsignedBigInteger('party_id')->nullable();
            $t->string('type', 20);                                   // sale_out|return_in|from_supplier|to_supplier|loss|adjustment
            $t->integer('party_delta')->default(0);                   // variation de ce que le tiers doit rendre
            $t->integer('depot_delta')->default(0);                   // variation des casiers vides au dépôt
            $t->integer('party_balance_after')->nullable();
            $t->nullableMorphs('reference');
            $t->foreignId('user_id')->constrained();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['container_type_id', 'created_at']);
            $t->index(['party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_movements');
        Schema::dropIfExists('container_holdings');
        Schema::dropIfExists('container_types');
    }
};
