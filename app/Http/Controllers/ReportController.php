<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Expense;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function income(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('F Y'));

        try {
            $date = \Carbon\Carbon::parse("1 {$month}");
            $month = $date->format('F Y');
        } catch (\Exception $e) {
            $date = now();
            $month = $date->format('F Y');
        }

        $payments = $this->scopeByOwner(Payment::query(), $request)
            ->where('month', $month)->get();

        $totalIncome = round($payments->sum(fn($p) => $p->total), 2);
        $paidAmount = round($payments->where('status', 'paid')->sum(fn($p) => $p->total), 2);
        $pendingAmount = round($payments->whereIn('status', ['pending', 'overdue'])->sum(fn($p) => $p->total), 2);

        $breakdown = $payments->groupBy(fn($p) => $p->room->room_number ?? 'Unknown')
            ->map(fn($items, $room) => [
                'room' => $room,
                'rent' => round($items->sum('amount'), 2),
                'utility' => round($items->sum('utility_amount'), 2),
                'lateFee' => round($items->sum('late_fee'), 2),
                'total' => round($items->sum(fn($p) => $p->total), 2),
                'status' => $items->first()->status,
            ])->values();

        return $this->success([
            'month' => $month, 'totalIncome' => $totalIncome,
            'paidAmount' => $paidAmount, 'pendingAmount' => $pendingAmount,
            'breakdown' => $breakdown,
        ]);
    }

    public function expenses(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('F Y'));

        try {
            $date = \Carbon\Carbon::parse("1 {$month}");
        } catch (\Exception $e) {
            $date = now();
        }

        $expenses = $this->scopeByOwner(Expense::query(), $request)
            ->whereMonth('date', $date->month)
            ->whereYear('date', $date->year)->get();

        $byCategory = $expenses->groupBy('category')->map(fn($items, $cat) => [
            'category' => ucfirst($cat),
            'total' => round($items->sum('amount'), 2),
            'count' => $items->count(),
        ])->values();

        return $this->success([
            'month' => $month,
            'totalExpenses' => round($expenses->sum('amount'), 2),
            'byCategory' => $byCategory,
        ]);
    }

    public function occupancy(Request $request): JsonResponse
    {
        $total = $this->scopeByOwner(Room::query(), $request)->count();
        $occupied = $this->scopeByOwner(Room::query(), $request)->where('status', 'occupied')->count();
        $vacant = $this->scopeByOwner(Room::query(), $request)->where('status', 'vacant')->count();
        $maintenance = $this->scopeByOwner(Room::query(), $request)->where('status', 'maintenance')->count();

        return $this->success([
            'totalRooms' => $total,
            'occupied' => $occupied, 'vacant' => $vacant,
            'maintenance' => $maintenance,
            'occupancyRate' => $total > 0 ? round(($occupied / $total) * 100, 1) : 0,
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('F Y'));

        try {
            $date = \Carbon\Carbon::parse("1 {$month}");
        } catch (\Exception $e) {
            $date = now();
        }

        $income = $this->scopeByOwner(Payment::query(), $request)->where('status', 'paid')
            ->whereMonth('paid_date', $date->month)
            ->whereYear('paid_date', $date->year)
            ->sum('amount');

        $expenses = $this->scopeByOwner(Expense::query(), $request)->whereMonth('date', $date->month)
            ->whereYear('date', $date->year)->sum('amount');

        return $this->success([
            'month' => $month,
            'totalIncome' => round($income, 2),
            'totalExpenses' => round($expenses, 2),
            'netProfit' => round($income - $expenses, 2),
            'profitMargin' => $income > 0 ? round((($income - $expenses) / $income) * 100, 1) : 0,
        ]);
    }

    public function tenantSummary(Request $request): JsonResponse
    {
        $active = $this->scopeByOwner(Tenant::query(), $request)->where('status', 'active')->count();
        $inactive = $this->scopeByOwner(Tenant::query(), $request)->where('status', 'inactive')->count();
        $total = $active + $inactive;

        $recentTenants = $this->scopeByOwner(Tenant::with('room'), $request)
            ->orderBy('created_at', 'desc')->limit(5)->get()
            ->map(fn($t) => [
                'id' => $t->id, 'name' => $t->name,
                'room' => $t->room->room_number ?? null,
                'moveInDate' => $t->move_in_date, 'status' => $t->status,
            ]);

        return $this->success([
            'total' => $total, 'active' => $active, 'inactive' => $inactive,
            'recentTenants' => $recentTenants,
        ]);
    }
    public function financialSummary(Request $request): JsonResponse
    {
        $now = now();

        // Year-to-date revenue (rent + utilities + late fees)
        $ytdPayments = $this->scopeByOwner(Payment::query(), $request)->where('month', 'like', "% {$now->year}")->get();
        $ytdRevenue = round($ytdPayments->where('status', 'paid')->sum(fn($p) => $p->total), 2);

        // This month income
        $thisMonthPayments = $this->scopeByOwner(Payment::query(), $request)->where('month', $now->format('F Y'))->get();
        $thisMonthIncome = round($thisMonthPayments->sum(fn($p) => $p->total), 2);
        $thisMonthPaid = round($thisMonthPayments->where('status', 'paid')->sum(fn($p) => $p->total), 2);

        // Collection rate
        $collectionRate = $thisMonthIncome > 0 ? round(($thisMonthPaid / $thisMonthIncome) * 100) : 0;

        // Occupancy rate
        $totalRooms = $this->scopeByOwner(Room::query(), $request)->count();
        $occupiedRooms = $this->scopeByOwner(Room::query(), $request)->where('status', 'occupied')->count();
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

        // Monthly income summary (last 6 months)
        $monthlySummary = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $now->copy()->startOfMonth()->subMonths($i);
            $monthPayments = $this->scopeByOwner(Payment::query(), $request)
                ->where('month', $date->format('F Y'))->get();
            $total = round($monthPayments->sum(fn($p) => $p->total), 2);
            $paid = round($monthPayments->where('status', 'paid')->sum(fn($p) => $p->total), 2);
            $unpaid = round($total - $paid, 2);
            $rate = $total > 0 ? round(($paid / $total) * 100) : 0;

            $monthlySummary[] = [
                'month' => $date->format('F Y'),
                'totalIncome' => $total,
                'paid' => $paid,
                'unpaid' => $unpaid,
                'collectionRate' => $rate,
            ];
        }

        // Payment methods distribution - grouping null, empty and 'cash' together
        $paidPayments = $this->scopeByOwner(Payment::query(), $request)->where('status', 'paid')->get();
        $totalPaidCount = $paidPayments->count();
        $methodDistribution = [];
        if ($totalPaidCount > 0) {
            $byMethod = $paidPayments->groupBy(fn($p) => strtolower($p->payment_method ?: 'cash'));
            foreach ($byMethod as $method => $items) {
                $methodDistribution[] = [
                    'method' => ucfirst(str_replace('_', ' ', $method)),
                    'count' => $items->count(),
                    'percentage' => round(($items->count() / $totalPaidCount) * 100),
                ];
            }
        }

        // Room type distribution
        $roomTypes = $this->scopeByOwner(Room::query(), $request)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')->get()
            ->map(fn($r) => [
                'type' => ucfirst($r->type),
                'count' => $r->count,
            ]);

        // YTD expenses
        $ytdExpenses = round($this->scopeByOwner(Expense::query(), $request)->whereYear('date', $now->year)->sum('amount'), 2);

        return $this->success([
            'ytdRevenue' => $ytdRevenue,
            'ytdExpenses' => $ytdExpenses,
            'netProfit' => round($ytdRevenue - $ytdExpenses, 2),
            'thisMonthIncome' => $thisMonthIncome,
            'collectionRate' => $collectionRate,
            'occupancyRate' => $occupancyRate,
            'monthlySummary' => $monthlySummary,
            'paymentMethods' => $methodDistribution,
            'roomTypes' => $roomTypes,
        ]);
    }
}
