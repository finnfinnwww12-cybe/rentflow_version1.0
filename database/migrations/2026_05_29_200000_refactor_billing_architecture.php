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
        if (!Schema::hasColumn('contracts', 'billing_cycle')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->enum('billing_cycle', ['daily', 'monthly'])->default('monthly')->after('rent_amount');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'contract_id')) {
                $table->foreignUuid('contract_id')->nullable()->constrained('contracts')->nullOnDelete()->after('room_id');
            }
            if (!Schema::hasColumn('payments', 'invoice_type')) {
                $table->enum('invoice_type', ['daily_rental', 'monthly_rent', 'late_fee'])->default('monthly_rent')->after('status');
            }
            if (!Schema::hasColumn('payments', 'billing_period_start')) {
                $table->date('billing_period_start')->nullable()->after('invoice_type');
            }
            if (!Schema::hasColumn('payments', 'billing_period_end')) {
                $table->date('billing_period_end')->nullable()->after('billing_period_start');
            }
            if (!Schema::hasColumn('payments', 'invoice_number')) {
                $table->string('invoice_number')->unique()->nullable()->after('receipt_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'contract_id')) {
                $table->dropForeign(['contract_id']);
            }
            $table->dropColumn(array_filter([
                Schema::hasColumn('payments', 'contract_id') ? 'contract_id' : null,
                Schema::hasColumn('payments', 'invoice_type') ? 'invoice_type' : null,
                Schema::hasColumn('payments', 'billing_period_start') ? 'billing_period_start' : null,
                Schema::hasColumn('payments', 'billing_period_end') ? 'billing_period_end' : null,
                Schema::hasColumn('payments', 'invoice_number') ? 'invoice_number' : null,
            ]));
        });

        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'billing_cycle')) {
                $table->dropColumn('billing_cycle');
            }
        });
    }
};
