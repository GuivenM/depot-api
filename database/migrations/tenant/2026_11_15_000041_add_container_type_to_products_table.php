<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            // null = produit non consigné (canette, bouteille perdue...)
            $t->foreignId('container_type_id')->nullable()->after('active')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('container_type_id');
        });
    }
};
