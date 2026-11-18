<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();                 // qui l'a ouvert
            $t->string('status', 12)->default('open');               // open|validated|cancelled
            $t->string('note')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('validated_at')->nullable();
            $t->foreignId('validated_by')->nullable()->constrained('users');
            $t->unsignedInteger('lines_with_gap')->default(0);       // renseignés à la validation
            $t->unsignedInteger('shortage_value')->default(0);       // manquants valorisés au dernier coût, FCFA
            $t->unsignedInteger('surplus_value')->default(0);        // excédents valorisés au dernier coût, FCFA
            $t->timestamps();
            $t->index('status');
        });

        Schema::create('inventory_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                                  // product|container
            $t->foreignId('product_id')->nullable()->constrained();
            $t->foreignId('container_type_id')->nullable()->constrained();
            $t->integer('expected')->nullable();                     // stock du système AU MOMENT du comptage (bouteilles ou casiers)
            $t->integer('counted')->nullable();                      // stock physique compté (même unité)
            $t->integer('difference')->nullable();                   // counted - expected
            $t->foreignId('counted_by')->nullable()->constrained('users');
            $t->timestamp('counted_at')->nullable();
            $t->timestamps();
            $t->index('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lines');
        Schema::dropIfExists('inventories');
    }
};
