<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\UtilityController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\PaymentOptionController;

/*
|--------------------------------------------------------------------------
| Authentication (public)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:api')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

// Public Booking Demo requests (landing page integration)
Route::post('/bookings/demo', [NotificationController::class, 'storeDemoBooking']);

// Temporary database inspection route
Route::get('/db-inspect', function () {
    return response()->json([
        'tenants' => \App\Models\Tenant::all()->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'created_at' => $t->created_at ? $t->created_at->toDateTimeString() : null]),
        'rooms' => \App\Models\Room::all()->map(fn($r) => ['id' => $r->id, 'room_number' => $r->room_number, 'status' => $r->status, 'tenant_id' => $r->tenant_id]),
        'payments' => \App\Models\Payment::all()->map(fn($p) => ['id' => $p->id, 'tenant' => $p->tenant->name ?? null, 'amount' => $p->amount, 'month' => $p->month, 'status' => $p->status]),
        'expenses' => \App\Models\Expense::all()->map(fn($e) => ['id' => $e->id, 'description' => $e->description, 'amount' => $e->amount, 'date' => $e->date]),
    ]);
});

// Tenant Portal Integration (Public endpoints for rentflow-app)
Route::prefix('tenant-portal')->group(function () {
    Route::post('/login', [\App\Http\Controllers\TenantPortalController::class, 'login']);
    Route::get('/dashboard/{tenantId}', [\App\Http\Controllers\TenantPortalController::class, 'dashboard']);
    Route::post('/maintenance', [\App\Http\Controllers\TenantPortalController::class, 'createMaintenance']);
    Route::get('/payments/{paymentId}', [\App\Http\Controllers\TenantPortalController::class, 'getInvoice']);
    Route::post('/payments/{paymentId}/pay', [\App\Http\Controllers\TenantPortalController::class, 'payInvoice']);
    Route::post('/messages', [\App\Http\Controllers\TenantPortalController::class, 'sendMessage']);
    Route::get('/contracts/{contractId}', [\App\Http\Controllers\TenantPortalController::class, 'getContract']);
    Route::post('/contracts/{contractId}/sign', [\App\Http\Controllers\TenantPortalController::class, 'signContract']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (require Bearer token)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:authenticated', 'ensure-active'])->group(function () {

    // Super Admin Routes
    Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'dashboard']);
        Route::get('/owners', [SuperAdminController::class, 'getOwners']);
        Route::post('/owners', [SuperAdminController::class, 'createOwner']);
        Route::put('/owners/{id}', [SuperAdminController::class, 'updateOwner']);
        Route::delete('/owners/{id}', [SuperAdminController::class, 'deleteOwner']);
        Route::put('/owners/{id}/toggle-status', [SuperAdminController::class, 'toggleOwnerStatus']);
        Route::get('/properties', [SuperAdminController::class, 'getProperties']);
        Route::delete('/properties/{id}', [SuperAdminController::class, 'deleteProperty']);
        Route::get('/statistics', [SuperAdminController::class, 'getStatistics']);
        Route::get('/invoices', [SuperAdminController::class, 'getInvoices']);
        Route::get('/activity-logs', [SuperAdminController::class, 'getActivityLogs']);
        Route::get('/settings', [SuperAdminController::class, 'getSettings']);
        Route::put('/settings', [SuperAdminController::class, 'updateSettings']);
    });

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/overview', [DashboardController::class, 'overview']);
        Route::get('/alerts', [DashboardController::class, 'alerts']);
        Route::get('/recent-activity', [DashboardController::class, 'recentActivity']);
    });

    // Tenants
    Route::apiResource('tenants', TenantController::class);

    // Rooms
    Route::apiResource('rooms', RoomController::class);

    // Room Types
    Route::get('/room-types/active', [RoomTypeController::class, 'active']);
    Route::get('/room-types/statistics', [RoomTypeController::class, 'statistics']);
    Route::apiResource('room-types', RoomTypeController::class);

    // Payments
    Route::get('/payments/late', [PaymentController::class, 'late']);
    Route::get('/payments/schedule/{month}', [PaymentController::class, 'schedule']);
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'receipt']);
    Route::post('/payments/generate-invoices', [PaymentController::class, 'generateInvoices']);
    Route::apiResource('payments', PaymentController::class)->except(['show']);

    // Maintenance
    Route::get('/maintenance/stats', [MaintenanceController::class, 'stats']);
    Route::apiResource('maintenance', MaintenanceController::class)->except(['destroy']);

    // Expenses
    Route::get('/expenses/category/{category}', [ExpenseController::class, 'byCategory']);
    Route::get('/expenses/monthly/{month}', [ExpenseController::class, 'monthly']);
    Route::apiResource('expenses', ExpenseController::class)->except(['show', 'update']);

    // Utilities
    Route::get('/utilities/rates', [UtilityController::class, 'rates']);
    Route::put('/utilities/rates', [UtilityController::class, 'updateRates']);
    Route::get('/utilities/monthly/{month}', [UtilityController::class, 'monthly']);
    Route::apiResource('utilities', UtilityController::class)->only(['index', 'store']);
    Route::post('/utilities/{id}/link', [UtilityController::class, 'linkToInvoice']);

    // Contracts
    Route::get('/contracts/expiring-soon', [ContractController::class, 'expiringSoon']);
    Route::post('/contracts/{id}/renew', [ContractController::class, 'renew']);
    Route::apiResource('contracts', ContractController::class);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::get('/settings/profile', [SettingController::class, 'profile']);
    Route::put('/settings/profile', [SettingController::class, 'updateProfile']);

    // Payment Options
    Route::apiResource('payment-options', PaymentOptionController::class);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/income', [ReportController::class, 'income']);
        Route::get('/expenses', [ReportController::class, 'expenses']);
        Route::get('/occupancy', [ReportController::class, 'occupancy']);
        Route::get('/profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('/tenant-summary', [ReportController::class, 'tenantSummary']);
        Route::get('/financial-summary', [ReportController::class, 'financialSummary']);
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::post('/notifications/mark-read/{id}', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::post('/notifications/send-sms', [NotificationController::class, 'sendSms']);

    // File Upload (stricter rate limit)
    Route::middleware('throttle:uploads')->post('/files/upload', [FileController::class, 'upload']);


});
