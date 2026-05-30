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
        // SQLite doesn't support ALTER COLUMN for enums, so we use a workaround
        // For MySQL, you would use: ALTER TABLE contracts MODIFY COLUMN status ENUM(...)
        // For SQLite, enum is just stored as text, so no migration needed for the column itself.
        // The validation is handled at the application level.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
