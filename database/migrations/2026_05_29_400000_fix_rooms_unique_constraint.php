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
        try {
            Schema::table('rooms', function (Blueprint $table) {
                // Drop unique index on room_number
                $table->dropUnique(['room_number']);
            });
        } catch (\Exception $e) {
            // Index might not exist in SQLite as a named index, ignore
        }

        try {
            Schema::table('rooms', function (Blueprint $table) {
                // Add composite unique index on room_number and user_id
                $table->unique(['room_number', 'user_id']);
            });
        } catch (\Exception $e) {
            // Ignore if already exists
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropUnique(['room_number', 'user_id']);
            });
        } catch (\Exception $e) {
            // Ignore
        }

        try {
            Schema::table('rooms', function (Blueprint $table) {
                $table->unique(['room_number']);
            });
        } catch (\Exception $e) {
            // Ignore
        }
    }
};
