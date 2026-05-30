<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Command;

class CheckContractExpiry extends Command
{
    protected $signature = 'leases:check-expiry';
    protected $description = 'Auto-expire contracts and send expiry notifications (30/14/7 days)';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $admin = User::first();
        $expired = 0;
        $notified = 0;

        // Step 1: Auto-expire contracts past end_date
        $expiredContracts = Contract::where('status', 'active')
            ->whereDate('end_date', '<', $today)
            ->get();

        foreach ($expiredContracts as $contract) {
            $contract->update(['status' => 'expired']);
            $expired++;

            // Notify admin about expiration
            if ($admin) {
                $tenantName = $contract->tenant->name ?? 'Unknown';
                $roomNumber = $contract->room->room_number ?? 'N/A';

                Notification::create([
                    'user_id' => $admin->id,
                    'title'   => "Contract Expired: {$tenantName}",
                    'message' => "Contract for {$tenantName} (Room {$roomNumber}) has expired. Consider renewal or move-out.",
                    'type'    => 'contract-expired',
                    'read'    => false,
                ]);
            }
        }

        // Step 2: Send advance warning notifications (30, 14, 7 days)
        $alertDays = [30, 14, 7];

        foreach ($alertDays as $days) {
            $targetDate = $today->copy()->addDays($days);

            $contracts = Contract::with(['tenant', 'room'])
                ->where('status', 'active')
                ->whereDate('end_date', $targetDate->format('Y-m-d'))
                ->get();

            foreach ($contracts as $contract) {
                if (!$admin) continue;

                $tenantName = $contract->tenant->name ?? 'Unknown';
                $roomNumber = $contract->room->room_number ?? 'N/A';

                // Check if notification already sent for this contract + day combo
                $alreadySent = Notification::where('user_id', $admin->id)
                    ->where('type', 'contract-expiry-warning')
                    ->where('title', "like", "%{$tenantName}%{$days} days%")
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($alreadySent) continue;

                Notification::create([
                    'user_id' => $admin->id,
                    'title'   => "Contract Expiring: {$tenantName} — {$days} days left",
                    'message' => "Contract for {$tenantName} (Room {$roomNumber}) expires on {$contract->end_date->format('Y-m-d')}. Rent: \${$contract->rent_amount}/mo.",
                    'type'    => 'contract-expiry-warning',
                    'read'    => false,
                ]);
                $notified++;
            }
        }

        $this->info("Contracts: {$expired} expired. Warnings: {$notified} sent.");
        return self::SUCCESS;
    }
}
