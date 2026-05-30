<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Room;
use App\Models\Payment;
use App\Models\MaintenanceRequest;
use App\Models\Expense;
use App\Models\Contract;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/overview
     */
    public function overview(Request $request): JsonResponse
    {
        $ownerId = $this->getOwnerId($request);
        $scopeUser = fn($q) => $ownerId ? $q->where('user_id', $ownerId) : $q;

        $totalTenants = $scopeUser(Tenant::query())->where('status', 'active')->count();
        $occupiedRooms = $scopeUser(Room::query())->where('status', 'occupied')->count();
        $vacantRooms = $scopeUser(Room::query())->where('status', 'vacant')->count();

        $currentMonth = now()->format('F Y');
        $totalRevenue = $scopeUser(Payment::query())->where('status', 'paid')
            ->whereMonth('paid_date', now()->month)
            ->whereYear('paid_date', now()->year)
            ->sum('amount');

        // Dynamic overdue: pending + past due date
        $pendingPayments = $scopeUser(Payment::query())->where('status', 'pending')->count();
        $overduePayments = $scopeUser(Payment::query())->where(function ($q) {
            $q->where('status', 'overdue')
              ->orWhere(function ($q2) {
                  $q2->where('status', 'pending')
                     ->whereDate('due_date', '<', now()->startOfDay());
              });
        })->count();

        $maintenanceRequests = $scopeUser(MaintenanceRequest::query())->whereIn('status', ['pending', 'in-progress'])->count();

        $totalExpenses = $scopeUser(Expense::query())->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('amount');

        $netProfit = $totalRevenue - $totalExpenses;

        // Trend: compare with previous month
        $prevRevenue = $scopeUser(Payment::query())->where('status', 'paid')
            ->whereMonth('paid_date', now()->subMonth()->month)
            ->whereYear('paid_date', now()->subMonth()->year)
            ->sum('amount');

        $prevExpenses = $scopeUser(Expense::query())->whereMonth('date', now()->subMonth()->month)
            ->whereYear('date', now()->subMonth()->year)
            ->sum('amount');

        $revenueTrend = $prevRevenue > 0
            ? round((($totalRevenue - $prevRevenue) / $prevRevenue) * 100, 1)
            : 0;

        $expenseTrend = $prevExpenses > 0
            ? round((($totalExpenses - $prevExpenses) / $prevExpenses) * 100, 1)
            : 0;

        // Occupancy rate
        $totalRooms = $scopeUser(Room::query())->count();
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

        // Collection rate this month
        $thisMonthTotal = $scopeUser(Payment::query())->whereMonth('due_date', now()->month)
            ->whereYear('due_date', now()->year)->sum('amount');
        $thisMonthPaid = $scopeUser(Payment::query())->where('status', 'paid')
            ->whereMonth('due_date', now()->month)
            ->whereYear('due_date', now()->year)->sum('amount');
        $collectionRate = $thisMonthTotal > 0 ? round(($thisMonthPaid / $thisMonthTotal) * 100) : 0;

        return $this->success([
            'totalTenants' => $totalTenants,
            'occupiedRooms' => $occupiedRooms,
            'vacantRooms' => $vacantRooms,
            'totalRevenue' => round($totalRevenue, 2),
            'pendingPayments' => $pendingPayments,
            'overduePayments' => $overduePayments,
            'maintenanceRequests' => $maintenanceRequests,
            'totalExpenses' => round($totalExpenses, 2),
            'netProfit' => round($netProfit, 2),
            'occupancyRate' => $occupancyRate,
            'collectionRate' => $collectionRate,
            'trends' => [
                'revenue' => $revenueTrend,
                'expenses' => $expenseTrend,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/alerts
     * Consolidated alerts: overdue payments, expiring contracts, urgent maintenance
     */
    public function alerts(Request $request): JsonResponse
    {
        $ownerId = $this->getOwnerId($request);
        $scopeUser = fn($q) => $ownerId ? $q->where('user_id', $ownerId) : $q;
        $alerts = [];

        // Overdue payments
        $overduePayments = $scopeUser(Payment::with(['tenant', 'room']))
            ->where(function ($q) {
                $q->where('status', 'overdue')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'pending')
                         ->whereDate('due_date', '<', now()->startOfDay());
                  });
            })
            ->orderBy('due_date', 'asc')
            ->limit(5)
            ->get();

        foreach ($overduePayments as $p) {
            $tenantName = $p->tenant->name ?? 'N/A';
            $roomNumber = $p->room->room_number ?? 'N/A';
            $total = $p->total;
            $dueFormatted = $p->due_date->format('M d, Y');
            $alerts[] = [
                'type' => 'payment-overdue',
                'severity' => 'danger',
                'title' => "Overdue: {$tenantName} - Room {$roomNumber}",
                'message' => "\${$total} due on {$dueFormatted} ({$p->month})",
                'daysOverdue' => (int) $p->due_date->diffInDays(now()),
                'entityId' => $p->id,
            ];
        }

        // Expiring contracts (next 30 days)
        $expiringContracts = $scopeUser(Contract::with(['tenant', 'room']))
            ->where('status', 'active')
            ->whereBetween('end_date', [now(), now()->addDays(30)])
            ->orderBy('end_date', 'asc')
            ->limit(5)
            ->get();

        foreach ($expiringContracts as $c) {
            $daysLeft = (int) now()->diffInDays($c->end_date, false);
            $severity = $daysLeft <= 7 ? 'danger' : ($daysLeft <= 14 ? 'warning' : 'info');
            $tenantName = $c->tenant->name ?? 'N/A';
            $roomNumber = $c->room->room_number ?? 'N/A';
            $endFormatted = $c->end_date->format('M d, Y');
            $alerts[] = [
                'type' => 'contract-expiring',
                'severity' => $severity,
                'title' => "Contract Expiring: {$tenantName}",
                'message' => "Room {$roomNumber} — {$daysLeft} days remaining (expires {$endFormatted})",
                'daysRemaining' => $daysLeft,
                'entityId' => $c->id,
            ];
        }

        // Urgent maintenance
        $urgentMaintenance = $scopeUser(MaintenanceRequest::with('room'))
            ->where('priority', 'urgent')
            ->whereIn('status', ['pending', 'in-progress'])
            ->limit(5)
            ->get();

        foreach ($urgentMaintenance as $m) {
            $roomNumber = $m->room->room_number ?? 'N/A';
            $reportedFormatted = $m->reported_date->format('M d, Y');
            $alerts[] = [
                'type' => 'maintenance-urgent',
                'severity' => 'warning',
                'title' => "Urgent: {$m->title}",
                'message' => "Room {$roomNumber} — reported {$reportedFormatted}",
                'entityId' => $m->id,
            ];
        }

        // Sort by severity: danger first, then warning, then info
        $severityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3));

        return $this->success($alerts);
    }

    /**
     * GET /api/dashboard/recent-activity
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $ownerId = $this->getOwnerId($request);
        $scopeUser = fn($q) => $ownerId ? $q->where('user_id', $ownerId) : $q;
        $activities = collect();

        // Recent payments
        $payments = $scopeUser(Payment::with(['tenant', 'room']))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'type' => 'payment',
                    'title' => 'Payment received from Room ' . ($payment->room->room_number ?? 'N/A'),
                    'amount' => $payment->amount,
                    'date' => $payment->paid_date ?? $payment->created_at->format('Y-m-d'),
                    'status' => $payment->status,
                ];
            });

        // Recent maintenance requests
        $maintenance = $scopeUser(MaintenanceRequest::with('room'))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'type' => 'maintenance',
                    'title' => $request->title . ' - Room ' . ($request->room->room_number ?? 'N/A'),
                    'amount' => null,
                    'date' => $request->reported_date,
                    'status' => $request->status,
                ];
            });

        // Recent tenants
        $tenants = $scopeUser(Tenant::with('room'))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'type' => 'tenant',
                    'title' => 'New tenant: ' . $tenant->name,
                    'amount' => null,
                    'date' => $tenant->move_in_date ?? $tenant->created_at->format('Y-m-d'),
                    'status' => $tenant->status,
                ];
            });

        $activities = $payments->concat($maintenance)->concat($tenants)
            ->sortByDesc('date')
            ->take(10)
            ->values();

        return $this->success([
            'activities' => $activities,
        ]);
    }
}
