<?php

namespace App\Http\Controllers;

use App\Models\Utility;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UtilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(Utility::with('room'), $request);

        if ($month = $request->query('month')) $query->where('month', $month);
        if ($search = $request->query('search')) {
            $query->whereHas('room', fn($q) => $q->where('room_number', 'like', "%{$search}%"));
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        $utilities = $query->paginate($request->query('limit', 10));
        $utilities->getCollection()->transform(fn($u) => [
            'id' => $u->id, 'room' => $u->room->room_number ?? 'N/A',
            'electricity' => $u->electricity, 'water' => $u->water,
            'month' => $u->month,
            'electricityCost' => $u->electricity_cost,
            'waterCost' => $u->water_cost,
            'total' => round($u->electricity_cost + $u->water_cost, 2),
            'addedToInvoice' => $this->isAddedToInvoice($request, $u),
        ]);

        return $this->paginated($utilities);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'room' => 'required|string',
            'electricity' => 'required|numeric|min:0',
            'water' => 'required|numeric|min:0',
            'month' => 'required|string',
        ]);

        $room = $this->scopeByOwner(\App\Models\Room::query(), $request)->where('room_number', $v['room'])->first();
        if (!$room) return $this->error('Room not found', 'not_found', null, 404);

        // Get previous reading
        $prev = $this->scopeByOwner(Utility::query(), $request)->where('room_id', $room->id)
            ->where('month', '<', $v['month'])
            ->orderBy('month', 'desc')
            ->first();

        // Calculate usage
        $eUsage = $prev ? ($v['electricity'] - $prev->electricity) : $v['electricity'];
        $wUsage = $prev ? ($v['water'] - $prev->water) : $v['water'];

        // Get rates from settings
        $settings = $this->scopeByOwner(Setting::query(), $request)->first();
        $eRate = $settings->electricity_rate ?? 0.20;
        $wRate = $settings->water_rate ?? 0.50;

        $eCost = round($eUsage * $eRate, 2);
        $wCost = round($wUsage * $wRate, 2);

        $utility = Utility::create([
            'user_id' => $request->user()->id,
            'room_id' => $room->id,
            'electricity' => $v['electricity'],
            'water' => $v['water'],
            'month' => $v['month'],
            'electricity_cost' => $eCost,
            'water_cost' => $wCost,
        ]);

        // AUTO-LINK: Update existing payment for this room+month with utility charges
        $tenant = $room->tenant;
        if ($tenant) {
            $payment = $this->scopeByOwner(Payment::query(), $request)->where('tenant_id', $tenant->id)
                ->where('month', $v['month'])
                ->first();

            if ($payment) {
                $ePrev = $prev ? $prev->electricity : 0;
                $wPrev = $prev ? $prev->water : 0;
                $noteText = "Utility Details: E_usage={$eUsage}kWh, E_cost=\${$eCost}, E_prev={$ePrev}, E_curr={$v['electricity']} | W_usage={$wUsage}m³, W_cost=\${$wCost}, W_prev={$wPrev}, W_curr={$v['water']}";

                $payment->update([
                    'utility_amount' => round($eCost + $wCost, 2),
                    'notes' => $noteText,
                ]);
            }
        }

        return $this->success($utility->load('room'), 'Utility reading recorded and cost calculated', 201);
    }

    public function rates(Request $request): JsonResponse
    {
        $settings = $this->scopeByOwner(Setting::query(), $request)->first();
        return $this->success([
            'electricityRate' => $settings->electricity_rate ?? 0.20,
            'waterRate' => $settings->water_rate ?? 0.50,
        ]);
    }

    public function updateRates(Request $request): JsonResponse
    {
        $v = $request->validate([
            'electricityRate' => 'required|numeric|min:0',
            'waterRate' => 'required|numeric|min:0',
        ]);

        $settings = $this->scopeByOwner(Setting::query(), $request)->first();

        if (!$settings) {
            $settings = Setting::create([
                'user_id' => $request->user()->id,
                'property_name' => $request->user()->name . "'s Property",
                'currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
            ]);
        }

        $settings->update([
            'electricity_rate' => $v['electricityRate'],
            'water_rate' => $v['waterRate'],
        ]);

        return $this->success([
            'electricityRate' => $settings->electricity_rate,
            'waterRate' => $settings->water_rate,
        ], 'Rates updated successfully');
    }

    public function monthly(Request $request, string $month): JsonResponse
    {
        $utilities = $this->scopeByOwner(Utility::with('room'), $request)->where('month', $month)->get();

        $totalElectricity = $utilities->sum('electricity');
        $totalWater = $utilities->sum('water');
        $totalElectricityCost = round($utilities->sum('electricity_cost'), 2);
        $totalWaterCost = round($utilities->sum('water_cost'), 2);

        return $this->success([
            'month' => $month,
            'totalElectricity' => $totalElectricity,
            'totalWater' => $totalWater,
            'totalElectricityCost' => $totalElectricityCost,
            'totalWaterCost' => $totalWaterCost,
            'totalCost' => round($totalElectricityCost + $totalWaterCost, 2),
            'roomCount' => $utilities->count(),
            'readings' => $utilities->map(fn($u) => [
                'room' => $u->room->room_number ?? 'N/A',
                'electricity' => $u->electricity, 'water' => $u->water,
                'electricityCost' => $u->electricity_cost,
                'waterCost' => $u->water_cost,
            ]),
        ]);
    }

    /**
     * Check if utility charges were already added to a payment
     */
    private function isAddedToInvoice(Request $request, Utility $utility): bool
    {
        $room = $utility->room;
        if (!$room || !$room->tenant) return false;

        return $this->scopeByOwner(Payment::query(), $request)->where('tenant_id', $room->tenant->id)
            ->where('month', $utility->month)
            ->where('utility_amount', '>', 0)
            ->exists();
    }

    /**
     * POST /api/utilities/{id}/link
     */
    public function linkToInvoice(Request $request, string $id): JsonResponse
    {
        $utility = $this->scopeByOwner(Utility::with('room.tenant'), $request)->find($id);
        if (!$utility) {
            return $this->error('Utility record not found', 'not_found', null, 404);
        }

        $room = $utility->room;
        if (!$room) {
            return $this->error('Room not found', 'not_found', null, 404);
        }

        $tenant = $room->tenant;
        if (!$tenant) {
            return $this->error("Cannot link: Room {$room->room_number} has no active tenant assigned.", 'no_tenant', null, 422);
        }

        // Find or create an invoice for this tenant for the utility's month
        $payment = $this->scopeByOwner(Payment::query(), $request)->where('tenant_id', $tenant->id)
            ->where('month', $utility->month)
            ->first();

        $eCost = (float)$utility->electricity_cost;
        $wCost = (float)$utility->water_cost;
        $utilityTotal = round($eCost + $wCost, 2);

        // Fetch previous utility reading to calculate correct readings and usage
        $prev = \App\Models\Utility::where('room_id', $room->id)
            ->where('month', '<', $utility->month)
            ->orderBy('month', 'desc')
            ->first();

        $ePrev = $prev ? $prev->electricity : 0;
        $eCurr = $utility->electricity;
        $eUsage = $prev ? ($eCurr - $ePrev) : $eCurr;

        $wPrev = $prev ? $prev->water : 0;
        $wCurr = $utility->water;
        $wUsage = $prev ? ($wCurr - $wPrev) : $wCurr;

        $noteText = "Utility Details: E_usage={$eUsage}kWh, E_cost=\${$eCost}, E_prev={$ePrev}, E_curr={$eCurr} | W_usage={$wUsage}m³, W_cost=\${$wCost}, W_prev={$wPrev}, W_curr={$wCurr}";

        if ($payment) {
            $payment->update([
                'utility_amount' => $utilityTotal,
                'notes' => $noteText,
            ]);
        } else {
            $contract = \App\Models\Contract::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->first();
            if (!$contract) {
                $contract = \App\Models\Contract::where('tenant_id', $tenant->id)
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            $billingCycle = $room->roomType->billing_cycle ?? $contract?->billing_cycle ?? 'monthly';
            $invoiceType = $billingCycle === 'daily' ? 'daily_rental' : 'monthly_rent';
            $amount = $room->rent;

            if ($billingCycle === 'daily') {
                $billingPeriodStart = $contract?->start_date ? $contract->start_date->format('Y-m-d') : ($tenant->move_in_date ?? now()->format('Y-m-d'));
                $billingPeriodEnd = $contract?->end_date ? $contract->end_date->format('Y-m-d') : ($tenant->move_out_date ?? now()->addDay()->format('Y-m-d'));
                
                $start = \Carbon\Carbon::parse($billingPeriodStart);
                $end = \Carbon\Carbon::parse($billingPeriodEnd);
                $days = max(1, $start->diffInDays($end));
                $amount = $days * $room->rent;
            } else {
                $checkInDate = $contract?->start_date 
                    ? \Carbon\Carbon::parse($contract->start_date) 
                    : ($tenant->move_in_date 
                        ? \Carbon\Carbon::parse($tenant->move_in_date) 
                        : now()->startOfMonth());
                
                try {
                    $targetDate = \Carbon\Carbon::parse($utility->month);
                } catch (\Exception $e) {
                    $targetDate = now();
                }

                $checkInDay = $checkInDate->day;
                $daysInMonth = $targetDate->daysInMonth;
                $billingDay = min($checkInDay, $daysInMonth);
                
                $start = $targetDate->copy()->day($billingDay);
                $billingPeriodStart = $start->format('Y-m-d');
                $billingPeriodEnd = $start->copy()->addMonth()->format('Y-m-d');
            }

            $settings = $this->scopeByOwner(Setting::query(), $request)->first() ?? Setting::first();
            $dueDay = $settings->invoice_due_day ?? 1;

            if ($billingCycle === 'daily') {
                $defaultDate = now()->startOfMonth()->day($dueDay);
                if ($defaultDate->lt(now()->startOfDay())) {
                    $dueDate = now()->addDays($settings->grace_period_days ?? 5)->format('Y-m-d');
                } else {
                    $dueDate = $defaultDate->format('Y-m-d');
                }
            } else {
                $dueDate = $billingPeriodEnd;
            }

            $payment = Payment::create([
                'user_id' => $request->user()->id,
                'tenant_id' => $tenant->id,
                'room_id' => $room->id,
                'amount' => $amount,
                'utility_amount' => $utilityTotal,
                'late_fee' => 0,
                'due_date' => $dueDate,
                'status' => 'pending',
                'month' => $utility->month,
                'invoice_type' => $invoiceType,
                'billing_period_start' => $billingPeriodStart,
                'billing_period_end' => $billingPeriodEnd,
                'notes' => $noteText,
            ]);
        }

        return $this->success([
            'utility' => [
                'id' => $utility->id,
                'addedToInvoice' => true,
            ],
            'payment' => $payment
        ], 'Utility reading successfully linked to monthly invoice!');
    }
}
