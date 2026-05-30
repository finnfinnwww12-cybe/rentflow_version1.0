<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // PostgreSQL often retains the check constraint from the original enum creation
            // We explicitly drop the check constraint to allow custom payment methods
            DB::statement("ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_method_check");
            
            // Also ensure the column is set to VARCHAR
            DB::statement("ALTER TABLE payments ALTER COLUMN payment_method TYPE VARCHAR(255)");

            // Proactively drop constraint on rooms type enum to allow custom room types on Render
            DB::statement("ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_type_check");
            DB::statement("ALTER TABLE rooms ALTER COLUMN type TYPE VARCHAR(255)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for safety in production rollback
    }
};
