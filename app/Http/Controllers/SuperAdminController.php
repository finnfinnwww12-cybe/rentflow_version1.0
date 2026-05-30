<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\Payment;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SuperAdminController extends Controller
{
    /**
     * GET /api/super-admin/dashboard
     * Super Admin dashboard overview
     */
    public function dashboard(): JsonResponse
    {
        $totalOwners = User::owners()->count();
        $activeOwners = User::owners()->active()->count();
        $inactiveOwners = $totalOwners - $activeOwners;
        $totalProperties = Room::count();
        $availableRooms = Room::where('status', 'vacant')->count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $totalTenants = Tenant::where('status', 'active')->count();

        // Invoices and Payments Overview
        $totalInvoices = Payment::count();
        $paidInvoices = Payment::where('status', 'paid')->count();
        $pendingInvoices = Payment::where('status', 'pending')->count();
        $overdueInvoices = Payment::where('status', 'overdue')
            ->orWhere(function ($q) {
                $q->where('status', 'pending')
                  ->whereDate('due_date', '<', now()->startOfDay());
            })->count();

        $totalRevenue = Payment::where('status', 'paid')->sum('amount');

        // Recent properties (rooms created recently)
        $recentProperties = Room::with('owner')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($room) => [
                'id' => $room->id,
                'roomNumber' => $room->room_number,
                'type' => $room->type,
                'status' => $room->status,
                'rent' => $room->rent,
                'owner' => $room->owner->name ?? 'N/A',
                'createdAt' => $room->created_at?->toDateTimeString(),
            ]);

        // Recent owners
        $recentOwners = User::owners()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isActive' => $user->is_active,
                'propertiesCount' => Room::where('user_id', $user->id)->count(),
                'createdAt' => $user->created_at?->toDateTimeString(),
            ]);

        // Recent Payments / Invoices
        $recentPayments = Payment::with(['owner', 'tenant'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'invoiceId' => $p->invoice_number ?? ('INV-' . strtoupper(substr($p->id, 0, 8))),
                'owner' => $p->owner->name ?? 'N/A',
                'tenant' => $p->tenant->name ?? 'N/A',
                'amount' => $p->amount,
                'status' => $p->status,
                'date' => $p->created_at?->toDateString(),
            ]);

        // Recent Activity Logs
        $recentLogs = \App\Models\ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user->name ?? 'System',
                'createdAt' => $log->created_at?->toDateTimeString(),
            ]);

        // System Summary Box
        $totalRecords = User::count() + Room::count() + Tenant::count() + Payment::count() + Contract::count() + MaintenanceRequest::count() + Expense::count() + \App\Models\Utility::count();

        return $this->success([
            'totalOwners' => $totalOwners,
            'activeOwners' => $activeOwners,
            'inactiveOwners' => $inactiveOwners,
            'totalProperties' => $totalProperties,
            'availableRooms' => $availableRooms,
            'occupiedRooms' => $occupiedRooms,
            'maintenanceRooms' => $maintenanceRooms,
            'totalTenants' => $totalTenants,
            'totalRevenue' => round($totalRevenue, 2),
            'totalInvoices' => $totalInvoices,
            'paidInvoices' => $paidInvoices,
            'pendingInvoices' => $pendingInvoices,
            'overdueInvoices' => $overdueInvoices,
            'recentProperties' => $recentProperties,
            'recentOwners' => $recentOwners,
            'recentPayments' => $recentPayments,
            'recentLogs' => $recentLogs,
            'systemSummary' => [
                'status' => 'Active',
                'database' => 'Operational',
                'totalRecords' => $totalRecords,
            ]
        ]);
    }

    /**
     * GET /api/super-admin/owners
     * List all owners with search/filter/pagination
     */
    public function getOwners(Request $request): JsonResponse
    {
        $query = User::owners()->withCount([
            'rooms',
            'tenants' => fn($q) => $q->where('status', 'active'),
        ]);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->input('per_page', 15), 50);
        $owners = $query->paginate($perPage);

        $data = $owners->getCollection()->map(fn($owner) => [
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'isActive' => $owner->is_active,
            'roomsCount' => $owner->rooms_count,
            'tenantsCount' => $owner->tenants_count,
            'createdAt' => $owner->created_at?->toDateTimeString(),
        ]);

        return $this->success([
            'data' => $data,
            'meta' => [
                'currentPage' => $owners->currentPage(),
                'lastPage' => $owners->lastPage(),
                'perPage' => $owners->perPage(),
                'total' => $owners->total(),
            ],
        ]);
    }

    /**
     * POST /api/super-admin/owners
     * Create a new owner account
     */
    public function createOwner(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::min(8)],
        ]);

        $owner = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Create default settings for this owner
        Setting::create([
            'user_id' => $owner->id,
            'property_name' => $request->name . "'s Property",
            'address' => '',
            'phone' => '',
            'email' => $request->email,
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

        return $this->success([
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'isActive' => $owner->is_active,
        ], 'Owner created successfully', 201);
    }

    /**
     * PUT /api/super-admin/owners/{id}
     * Update owner details
     */
    public function updateOwner(Request $request, $id): JsonResponse
    {
        $owner = User::owners()->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => ['sometimes', Password::min(8)],
        ]);

        $data = $request->only(['name', 'email']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $owner->update($data);

        return $this->success([
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'isActive' => $owner->is_active,
        ], 'Owner updated successfully');
    }

    /**
     * DELETE /api/super-admin/owners/{id}
     * Delete owner and all their data
     */
    public function deleteOwner($id): JsonResponse
    {
        $owner = User::owners()->findOrFail($id);

        // Delete all owner's data (cascade)
        Payment::where('user_id', $id)->delete();
        Contract::where('user_id', $id)->delete();
        MaintenanceRequest::where('user_id', $id)->delete();
        Expense::where('user_id', $id)->delete();
        \App\Models\Utility::where('user_id', $id)->delete();
        Tenant::where('user_id', $id)->delete();
        Room::where('user_id', $id)->delete();
        \App\Models\RoomType::where('user_id', $id)->delete();
        \App\Models\Notification::where('user_id', $id)->delete();
        Setting::where('user_id', $id)->delete();

        // Revoke all tokens
        $owner->tokens()->delete();

        $owner->delete();

        return $this->success(null, 'Owner and all associated data deleted successfully');
    }

    /**
     * PUT /api/super-admin/owners/{id}/toggle-status
     * Activate/deactivate owner
     */
    public function toggleOwnerStatus($id): JsonResponse
    {
        $owner = User::owners()->findOrFail($id);
        $owner->update(['is_active' => !$owner->is_active]);

        // If deactivated, revoke all their tokens
        if (!$owner->is_active) {
            $owner->tokens()->delete();
        }

        $status = $owner->is_active ? 'activated' : 'deactivated';
        return $this->success([
            'id' => $owner->id,
            'isActive' => $owner->is_active,
        ], "Owner {$status} successfully");
    }

    /**
     * GET /api/super-admin/properties
     * View all properties across all owners
     */
    public function getProperties(Request $request): JsonResponse
    {
        $query = Room::with(['tenant', 'owner', 'roomType']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        // Filter by owner
        if ($ownerId = $request->input('owner_id')) {
            $query->where('user_id', $ownerId);
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $perPage = min($request->input('per_page', 15), 50);
        $rooms = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $rooms->getCollection()->map(fn($room) => [
            'id' => $room->id,
            'roomNumber' => $room->room_number,
            'type' => $room->type,
            'status' => $room->status,
            'rent' => $room->rent,
            'capacity' => $room->capacity,
            'tenant' => $room->tenant?->name,
            'owner' => $room->owner?->name ?? 'N/A',
            'ownerId' => $room->user_id,
            'roomType' => $room->roomType?->name,
            'createdAt' => $room->created_at?->toDateTimeString(),
        ]);

        return $this->success([
            'data' => $data,
            'meta' => [
                'currentPage' => $rooms->currentPage(),
                'lastPage' => $rooms->lastPage(),
                'perPage' => $rooms->perPage(),
                'total' => $rooms->total(),
            ],
        ]);
    }

    /**
     * DELETE /api/super-admin/properties/{id}
     * Delete any property
     */
    public function deleteProperty($id): JsonResponse
    {
        $room = Room::findOrFail($id);

        if ($room->status === 'occupied') {
            return $this->error('Cannot delete an occupied room', 'room_occupied', null, 422);
        }

        // Clean up related records
        Payment::where('room_id', $id)->delete();
        Contract::where('room_id', $id)->delete();
        MaintenanceRequest::where('room_id', $id)->delete();
        \App\Models\Utility::where('room_id', $id)->delete();

        $room->delete();

        return $this->success(null, 'Property deleted successfully');
    }

    /**
     * GET /api/super-admin/statistics
     * System-wide statistics
     */
    public function getStatistics(): JsonResponse
    {
        $totalOwners = User::owners()->count();
        $activeOwners = User::owners()->active()->count();
        $inactiveOwners = $totalOwners - $activeOwners;
        $totalRooms = Room::count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $vacantRooms = Room::where('status', 'vacant')->count();
        $totalTenants = Tenant::where('status', 'active')->count();

        // Revenue this month
        $monthlyRevenue = Payment::where('status', 'paid')
            ->whereMonth('paid_date', now()->month)
            ->whereYear('paid_date', now()->year)
            ->sum('amount');

        // Revenue last month
        $lastMonthRevenue = Payment::where('status', 'paid')
            ->whereMonth('paid_date', now()->subMonth()->month)
            ->whereYear('paid_date', now()->subMonth()->year)
            ->sum('amount');

        $revenueTrend = $lastMonthRevenue > 0
            ? round((($monthlyRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : 0;

        // Owners by property count
        $ownerStats = User::owners()
            ->withCount('rooms')
            ->orderBy('rooms_count', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($owner) => [
                'name' => $owner->name,
                'properties' => $owner->rooms_count,
                'isActive' => $owner->is_active,
            ]);

        return $this->success([
            'totalOwners' => $totalOwners,
            'activeOwners' => $activeOwners,
            'inactiveOwners' => $inactiveOwners,
            'totalRooms' => $totalRooms,
            'occupiedRooms' => $occupiedRooms,
            'vacantRooms' => $vacantRooms,
            'totalTenants' => $totalTenants,
            'monthlyRevenue' => round($monthlyRevenue, 2),
            'revenueTrend' => $revenueTrend,
            'topOwners' => $ownerStats,
        ]);
    }

    /**
     * GET /api/super-admin/invoices
     * List all system invoices
     */
    public function getInvoices(Request $request): JsonResponse
    {
        $query = Payment::with(['tenant', 'room.roomType', 'owner']);

        if ($status = $request->query('status')) {
            if ($status === 'overdue') {
                $query->where(function ($q) {
                    $q->where('status', 'overdue')
                      ->orWhere(function ($q2) {
                          $q2->where('status', 'pending')
                             ->whereDate('due_date', '<', now()->startOfDay());
                      });
                });
            } else {
                $query->where('status', $status);
            }
        }
        
        if ($ownerId = $request->query('owner_id')) {
            $query->where('user_id', $ownerId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('tenant', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('room', fn($q2) => $q2->where('room_number', 'like', "%{$search}%"))
                  ->orWhere('id', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->query('per_page', 15), 50);
        $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $ownerIds = $invoices->pluck('user_id')->unique()->filter()->toArray();
        $settingsMap = Setting::whereIn('user_id', $ownerIds)->get()->keyBy('user_id');

        $data = $invoices->getCollection()->map(function($p) use ($settingsMap) {
            $settings = $settingsMap->get($p->user_id);
            return [
                'id' => $p->id,
                'invoiceId' => $p->invoice_number ?? ('INV-' . strtoupper(substr($p->id, 0, 8))),
                'tenant' => $p->tenant->name ?? 'N/A',
                'room' => $p->room->room_number ?? 'N/A',
                'roomType' => $p->room->roomType->name ?? $p->room->type ?? 'Standard Suite',
                'amount' => $p->amount,
                'utilityAmount' => $p->utility_amount,
                'lateFee' => $p->late_fee,
                'total' => $p->total,
                'dueDate' => $p->due_date?->toDateString(),
                'paidDate' => $p->paid_date?->toDateString(),
                'status' => $p->status,
                'owner' => $p->owner->name ?? 'N/A',
                'month' => $p->month,
                'property' => [
                    'name' => $settings->property_name ?? 'RentFlow Property Group Ltd.',
                    'address' => $settings->address ?? 'Suite 500, 100 Innovation Way, Tech District',
                    'phone' => $settings->phone ?? '+1 (555) 123-4567',
                    'email' => $settings->email ?? 'billing@rentflow-pms.com',
                ]
            ];
        });

        return $this->success([
            'data' => $data,
            'meta' => [
                'currentPage' => $invoices->currentPage(),
                'lastPage' => $invoices->lastPage(),
                'perPage' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * GET /api/super-admin/activity-logs
     * List all activity logs
     */
    public function getActivityLogs(Request $request): JsonResponse
    {
        $query = \App\Models\ActivityLog::with('user');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($search = $request->query('search')) {
            $query->where('description', 'like', "%{$search}%");
        }

        $perPage = min($request->query('per_page', 15), 50);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $logs->getCollection()->map(fn($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'user' => $log->user->name ?? 'System',
            'ipAddress' => $log->ip_address,
            'createdAt' => $log->created_at?->toDateTimeString(),
        ]);

        return $this->success([
            'data' => $data,
            'meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
                'perPage' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * GET /api/super-admin/settings
     * Fetch super admin platform settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        $settings = Setting::where('user_id', $request->user()->id)->first();
        if (!$settings) {
            $settings = Setting::create([
                'user_id' => $request->user()->id,
                'property_name' => 'RentFlow Platform',
                'address' => 'Global HQ',
                'phone' => '+1 (555) 000-0000',
                'email' => 'admin@rentflow-pms.com',
                'currency' => 'USD',
                'timezone' => 'UTC',
                'theme' => 'dark',
            ]);
        }

        return $this->success($settings);
    }

    /**
     * PUT /api/super-admin/settings
     * Update platform settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $settings = Setting::where('user_id', $request->user()->id)->first();
        if (!$settings) {
            $settings = new Setting(['user_id' => $request->user()->id]);
        }

        $v = $request->validate([
            'propertyName' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|max:255',


            'theme' => 'sometimes|string|max:50',
        ]);

        $data = [];
        if (isset($v['propertyName'])) $data['property_name'] = $v['propertyName'];
        if (isset($v['address'])) $data['address'] = $v['address'];
        if (isset($v['phone'])) $data['phone'] = $v['phone'];
        if (isset($v['email'])) $data['email'] = $v['email'];
        // Currency is hardcoded to USD — not changeable
        // Timezone is hardcoded to Asia/Phnom_Penh — not changeable
        if (isset($v['theme'])) $data['theme'] = $v['theme'];

        $settings->fill($data);
        $settings->save();

        $this->logActivity($request, 'settings_updated', 'Super Admin updated general platform settings');

        return $this->success($settings, 'Settings updated successfully');
    }
}
