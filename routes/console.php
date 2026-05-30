<?php

use Illuminate\Support\Facades\Schedule;

// ============================================================
// AUTOMATED SCHEDULED TASKS
// Run: php artisan schedule:run (or schedule:work for testing)
// ============================================================

// Auto-generate rent invoices on 1st of every month at 8:00 AM
Schedule::command('rent:generate')->monthlyOn(1, '08:00');

// Detect overdue payments + apply late fees daily at 9:00 AM
Schedule::command('payments:process-late-fees')->dailyAt('09:00');

// Auto-expire contracts + send 30/14/7 day warnings daily at 7:00 AM
Schedule::command('leases:check-expiry')->dailyAt('07:00');

// Clean orphaned data weekly on Sunday at 2:00 AM
Schedule::command('data:clean-orphans')->weeklyOn(0, '02:00');
