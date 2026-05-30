<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings: late fee config + invoice due day
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('late_fee_amount', 10, 2)->default(0)->after('water_rate');
            $table->enum('late_fee_type', ['fixed', 'percentage'])->default('fixed')->after('late_fee_amount');
            $table->integer('grace_period_days')->default(5)->after('late_fee_type');
            $table->integer('invoice_due_day')->default(1)->after('grace_period_days');
        });

        // Expenses: link to maintenance request
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('maintenance_request_id')->nullable()->after('date');
            $table->uuid('room_id')->nullable()->after('maintenance_request_id');
        });

        // Payments: utility charges + receipt number
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('utility_amount', 10, 2)->default(0)->after('late_fee');
            $table->string('receipt_number')->nullable()->after('notes');
            $table->boolean('auto_generated')->default(false)->after('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['late_fee_amount', 'late_fee_type', 'grace_period_days', 'invoice_due_day']);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['maintenance_request_id', 'room_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['utility_amount', 'receipt_number', 'auto_generated']);
        });
    }
};
