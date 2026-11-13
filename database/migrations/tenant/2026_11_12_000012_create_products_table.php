<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');                                   // "Béninoise 65cl"
            $t->string('brand')->nullable();
            $t->unsignedSmallInteger('format_cl')->nullable();
            $t->unsignedSmallInteger('bottles_per_crate')->default(12);
            $t->unsignedInteger('price_crate');                   // FCFA, entiers
            $t->unsignedInteger('price_bottle')->nullable();
            $t->unsignedInteger('cost_crate')->nullable();
            $t->integer('stock_bottles')->default(0);             // cache, modifié uniquement par StockService
            $t->unsignedInteger('alert_threshold')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
