<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Utility;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'rent:generate';
    protected $description = 'Auto-generate invoices for active contracts based on billing cycles';

    public function handle(): int
    {
        $settings = Setting::first();
        $dueDay = $settings->invoice_due_day ?? 1;
        $month = now()->format('F Y');

        // Only process active contracts
        $contracts = Contract::with(['tenant', 'room'])
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($contracts as $contract) {
            if (!$contract->tenant || !$contract->room) {
                $skipped++;
                continue;
            }

            // 1. Daily Stay Billing Rules
            if ($contract->billing_cycle === 'daily') {
                $exists = Payment::where('contract_id', $contract->id)
                    ->where('invoice_type', 'daily_rental')
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Calculate the entire consolidated checkout cost
                $totalRent = $contract->calculateTotalRent();
                $totalDays = $contract->getDurationInDays();

                // Create the one-off check-in stay invoice
                Payment::create([
                    'tenant_id'            => $contract->tenant_id,
                    'room_id'              => $contract->room_id,
                    'contract_id'          => $contract->id,
                    'amount'               => $totalRent,
                    'utility_amount'       => 0, // Utilities calculated separately
                    'late_fee'             => 0,
                    'due_date'             => $contract->start_date->format('Y-m-d'), // Due on check-in
                    'status'               => 'pending',
                    'month'                => $contract->start_date->format('F Y'),
                    'invoice_type'         => 'daily_rental',
                    'billing_period_start' => $contract->start_date->format('Y-m-d'),
                    'billing_period_end'   => $contract->end_date->format('Y-m-d'),
                    'receipt_number'       => null,
                    'invoice_number'       => Payment::generateInvoiceNumber(),
                    'auto_generated'       => true,
                    'notes'                => "Consolidated daily short-stay invoice ({$totalDays} days)",
                    'user_id'              => $contract->user_id,
                ]);

                $created++;
            } 
            // 2. Monthly Recurring Billing Rules
            else {
                // Check if invoice already exists for this contract and current month billing period
                $exists = Payment::where('contract_id', $contract->id)
                    ->where('invoice_type', 'monthly_rent')
                    ->where('month', $month)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Check for utility charges this month (if applicable)
                $utility = Utility::where('room_id', $contract->room_id)
                    ->where('month', $month)
                    ->first();

                $utilityAmount = $utility
                    ? round((float)$utility->electricity_cost + (float)$utility->water_cost, 2)
                    : 0;

                // Calculate custom check-in-based lease period & due date
                $checkInDate = $contract->start_date 
                    ? \Carbon\Carbon::parse($contract->start_date) 
                    : ($contract->tenant->move_in_date 
                        ? \Carbon\Carbon::parse($contract->tenant->move_in_date) 
                        : now()->startOfMonth());

                try {
                    $targetDate = \Carbon\Carbon::parse($month);
                } catch (\Exception $e) {
                    $targetDate = now();
                }

                $checkInDay = $checkInDate->day;
                $daysInMonth = $targetDate->daysInMonth;
                $billingDay = min($checkInDay, $daysInMonth);
                
                $start = $targetDate->copy()->day($billingDay);
                $billingPeriodStart = $start->format('Y-m-d');
                $billingPeriodEnd = $start->copy()->addMonth()->format('Y-m-d');

                $dueDate = $billingPeriodEnd;

                Payment::create([
                    'tenant_id'            => $contract->tenant_id,
                    'room_id'              => $contract->room_id,
                    'contract_id'          => $contract->id,
                    'amount'               => $contract->rent_amount, // 1 month rate only
                    'utility_amount'       => $utilityAmount,
                    'late_fee'             => 0,
                    'due_date'             => $dueDate,
                    'status'               => 'pending',
                    'month'                => $month,
                    'invoice_type'         => 'monthly_rent',
                    'billing_period_start' => $billingPeriodStart,
                    'billing_period_end'   => $billingPeriodEnd,
                    'receipt_number'       => null,
                    'invoice_number'       => Payment::generateInvoiceNumber(),
                    'auto_generated'       => true,
                    'notes'                => 'Auto-generated recurring monthly rent invoice',
                    'user_id'              => $contract->user_id,
                ]);

                $created++;
            }
        }

        $this->info("Generated invoices: {$created} created, {$skipped} skipped.");
        return self::SUCCESS;
    }
}
