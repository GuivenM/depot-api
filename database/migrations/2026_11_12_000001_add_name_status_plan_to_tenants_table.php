<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('name')->nullable()->after('id');
            $t->string('status')->default('pending_db')->after('name'); // pending_db|provisioning|active|failed|suspended
            $t->string('plan')->default('starter')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['name', 'status', 'plan']));
    }
};
