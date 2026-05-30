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
        // 1. Drop check constraint on SQLite / PostgreSQL if any, and make status a plain string column
        if (config('database.default') === 'sqlite') {
            // SQLite allows altering columns to string. Let's do it via Laravel Schema.
            Schema::table('payments', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
                $table->softDeletes();
            });
        } else {
            // PostgreSQL/MySQL
            Schema::table('payments', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
