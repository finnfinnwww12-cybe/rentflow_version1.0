<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('active', 'expired', 'terminated', 'draft') DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL requires ALTER COLUMN TYPE and does not support MODIFY
            DB::statement("ALTER TABLE contracts ALTER COLUMN status TYPE VARCHAR(50)");
            DB::statement("ALTER TABLE contracts ALTER COLUMN status SET DEFAULT 'active'");
        }
        // SQLite stores enums as text natively, so no changes needed
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM('active', 'expired', 'terminated') DEFAULT 'active'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE contracts ALTER COLUMN status TYPE VARCHAR(50)");
            DB::statement("ALTER TABLE contracts ALTER COLUMN status SET DEFAULT 'active'");
        }
    }
};
