<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('room_types')) {
            Schema::table('room_types', function (Blueprint $table) {
                if (!Schema::hasColumn('room_types', 'billing_cycle')) {
                    $table->enum('billing_cycle', ['daily', 'monthly', 'both'])->default('monthly')->after('name');
                }
                if (!Schema::hasColumn('room_types', 'base_daily_price')) {
                    $table->decimal('base_daily_price', 10, 2)->default(0.00)->after('base_price');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('room_types')) {
            Schema::table('room_types', function (Blueprint $table) {
                if (Schema::hasColumn('room_types', 'billing_cycle')) {
                    $table->dropColumn('billing_cycle');
                }
                if (Schema::hasColumn('room_types', 'base_daily_price')) {
                    $table->dropColumn('base_daily_price');
                }
            });
        }
    }
};
