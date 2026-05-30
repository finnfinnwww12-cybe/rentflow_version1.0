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
        // 1. Add role and is_active to users
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('owner')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Get the first user ID to assign existing records
        $firstUserId = DB::table('users')->orderBy('id')->value('id');

        // 2. Add user_id to all resource tables
        $tables = [
            'rooms', 'tenants', 'payments', 'contracts',
            'expenses', 'utilities', 'maintenance_requests',
            'room_types', 'settings', 'notifications',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'user_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('id');
                });

                // Assign existing records to the first user
                if ($firstUserId) {
                    DB::table($tableName)->whereNull('user_id')->update(['user_id' => $firstUserId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'rooms', 'tenants', 'payments', 'contracts',
            'expenses', 'utilities', 'maintenance_requests',
            'room_types', 'settings', 'notifications',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'user_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('user_id');
                });
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
