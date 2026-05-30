<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->integer('risk_score')->default(100)->after('status'); // 0=high risk, 100=low risk
            $table->string('risk_level')->default('low')->after('risk_score'); // low, medium, high
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['risk_score', 'risk_level']);
        });
    }
};
