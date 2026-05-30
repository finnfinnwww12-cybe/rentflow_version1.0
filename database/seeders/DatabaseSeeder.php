<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create Super Admin
        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@rentflow.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Create sample Owner
        $owner = User::create([
            'name' => 'Sample Owner',
            'email' => 'owner@rentflow.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Create default settings for Owner
        Setting::create([
            'user_id' => $owner->id,
            'property_name' => 'Sunrise Apartments',
            'address' => '123 Monivong Blvd, Phnom Penh',
            'phone' => '+855 23 456 789',
            'email' => 'info@sunrise-apartments.com',
            'currency' => 'USD',
            'timezone' => 'Asia/Phnom_Penh',
            'theme' => 'light',
            'electricity_rate' => 0.20,
            'water_rate' => 0.50,
            'late_fee_amount' => 10.00,
            'late_fee_type' => 'fixed',
            'grace_period_days' => 5,
            'invoice_due_day' => 1,
        ]);
    }
}
