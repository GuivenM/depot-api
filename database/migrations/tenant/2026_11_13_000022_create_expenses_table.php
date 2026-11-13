<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cash_session_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('category', 60);                              // transport, électricité...
            $t->unsignedInteger('amount');
            $t->string('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
