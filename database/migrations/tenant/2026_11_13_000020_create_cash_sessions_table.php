<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();                 // propriétaire de la caisse
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->unsignedInteger('opening_amount')->default(0);       // fond de caisse, FCFA
            $t->integer('expected_cash')->nullable();                // caisse théorique à la clôture
            $t->integer('counted_cash')->nullable();                 // caisse physique comptée
            $t->integer('difference')->nullable();                   // counted - expected
            $t->string('note')->nullable();
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
