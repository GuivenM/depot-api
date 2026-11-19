<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('action', 60);                      // ex. product.updated, sale.voided, user.deactivated
            $t->string('subject_type', 40)->nullable();    // ex. product, customer, sale
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('details')->nullable();               // avant/après ou contexte de l'action (jamais de mot de passe)
            $t->string('note', 255)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();     // pas de updated_at : un journal ne se modifie pas

            $t->index('action');
            $t->index(['subject_type', 'subject_id']);
            $t->index('user_id');
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
