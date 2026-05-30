<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Models\Contract;

class CleanOrphanedData extends Command
{
    protected $signature = 'data:clean-orphans';
    protected $description = 'Delete payments, maintenance, tenants and contracts with no associated room';

    public function handle(): void
    {
        // Payments with no tenant
        $payments = Payment::whereDoesntHave('tenant')->count();
        Payment::whereDoesntHave('tenant')->delete();
        $this->info("Deleted $payments orphaned payment(s)");

        // Maintenance with no room
        $maintenance = MaintenanceRequest::whereDoesntHave('room')->count();
        MaintenanceRequest::whereDoesntHave('room')->delete();
        $this->info("Deleted $maintenance orphaned maintenance request(s)");

        // Contracts with no tenant
        $contracts = Contract::whereDoesntHave('tenant')->count();
        Contract::whereDoesntHave('tenant')->delete();
        $this->info("Deleted $contracts orphaned contract(s)");

        // Tenants with no room (null room_id)
        $tenants = Tenant::whereNull('room_id')->count();
        Tenant::whereNull('room_id')->delete();
        $this->info("Deleted $tenants orphaned tenant(s)");

        $this->info('Cleanup complete!');
    }
}
