<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Room;
use App\Models\Payment;
use App\Models\Contract;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPortalController extends Controller
{
    /**
     * POST /api/tenant-portal/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|string',
            'phone' => 'nullable|string',
        ]);

        $query = Tenant::with(['room']);

        if ($request->email) {
            $query->where('email', $request->email);
        } elseif ($request->phone) {
            // strip potential non-digits or formatting for phone matching
            $phone = preg_replace('/\D/', '', $request->phone);
            $query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ["%{$phone}"]);
        } else {
            return $this->error('Email or phone number is required.', 'invalid_input', null, 422);
        }

        $tenant = $query->first();

        if (!$tenant) {
            return $this->error('Tenant not found. Please contact your property manager.', 'tenant_not_found', null, 404);
        }

        return $this->success([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'room_id' => $tenant->room_id,
                'room_number' => $tenant->room->room_number ?? 'N/A',
            ]
        ], 'Login successful');
    }

    /**
     * GET /api/tenant-portal/dashboard/{tenantId}
     */
    public function dashboard(string $tenantId): JsonResponse
    {
        $tenant = Tenant::with('room')->find($tenantId);
        if (!$tenant) {
            return $this->error('Tenant not found', 'not_found', null, 404);
        }

        $room = $tenant->room;

        // Fetch payments
        $payments = Payment::where('tenant_id', $tenant->id)
            ->orderBy('due_date', 'desc')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'invoiceNumber' => $p->invoice_number ?? ('INV-' . strtoupper(substr($p->id, 0, 8))),
                'amount' => (float)$p->amount,
                'utilityAmount' => (float)$p->utility_amount,
                'lateFee' => (float)$p->late_fee,
                'total' => (float)$p->total,
                'dueDate' => $p->due_date,
                'paidDate' => $p->paid_date,
                'month' => $p->month,
                'status' => $p->status,
                'paidAt' => $p->paid_date, // fallback for legacy frontend usage
                'type' => $p->type ?? 'rent',
            ]);

        // Fetch active contracts
        $contracts = Contract::where('tenant_id', $tenant->id)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'startDate' => $c->start_date,
                'endDate' => $c->end_date,
                'monthlyRent' => $c->rent_amount,
                'status' => $c->status,
            ]);

        // Fetch maintenance requests for the room
        $maintenanceRequests = MaintenanceRequest::where('room_id', $tenant->room_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'description' => $m->description,
                'priority' => $m->priority,
                'status' => $m->status,
                'reportedDate' => $m->reported_date,
                'completedDate' => $m->completed_date,
            ]);

        // Fetch notifications for the tenant (represented by general announcements & direct landlord-tenant messages)
        $admin = \App\Models\User::first();
        $notifications = Notification::where('user_id', $admin?->id)
            ->where(function($query) use ($tenantId) {
                $query->whereIn('type', ['general', 'broadcast'])
                      ->orWhere(function($q) use ($tenantId) {
                          $q->whereIn('type', ['sms', 'telegram_sent', 'telegram'])
                            ->where(function($sub) use ($tenantId) {
                                $sub->where('metadata', 'like', '%"tenant_id":"' . $tenantId . '"%')
                                    ->orWhere('metadata', 'like', '%"tenant_id": "' . $tenantId . '"%');
                            });
                      });
            })
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(fn($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'type' => $n->type,
                'createdAt' => $n->created_at,
            ]);

        return $this->success([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'room_id' => $tenant->room_id,
                'room_number' => $room->room_number ?? 'N/A',
                'room_type' => $room->type ?? 'N/A',
                'rent' => $room->price ?? 0,
            ],
            'payments' => $payments,
            'contracts' => $contracts,
            'maintenance' => $maintenanceRequests,
            'notifications' => $notifications,
        ]);
    }

    /**
     * POST /api/tenant-portal/maintenance
     */
    public function createMaintenance(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high,urgent',
        ]);

        $tenant = Tenant::find($v['tenant_id']);
        if (!$tenant) {
            return $this->error('Tenant not found', 'not_found', null, 404);
        }

        $m = MaintenanceRequest::create([
            'room_id' => $tenant->room_id,
            'title' => $v['title'],
            'description' => $v['description'],
            'priority' => $v['priority'] ?? 'low',
            'status' => 'pending',
            'reported_by' => $tenant->name,
            'reported_date' => now()->format('Y-m-d'),
        ]);

        // Trigger system notification so the Landlord sees it instantly
        $admin = \App\Models\User::first();
        if ($admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => "New Repair Request: Room " . ($tenant->room->room_number ?? 'N/A'),
                'message' => "Tenant {$tenant->name} reported: {$v['title']}\nPriority: " . ($v['priority'] ?? 'low'),
                'type' => 'maintenance',
                'read' => false,
            ]);
        }

        return $this->success($m, 'Maintenance request submitted successfully!', 201);
    }

    /**
     * POST /api/tenant-portal/payments/{paymentId}/pay
     */
    public function payInvoice(string $paymentId, Request $request): JsonResponse
    {
        $p = Payment::find($paymentId);
        if (!$p) {
            return $this->error('Invoice not found', 'not_found', null, 404);
        }

        $method = $request->input('paymentMethod', 'qr_code');
        $p->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_date' => now()->toDateString(),
            'payment_method' => $method,
        ]);

        // Trigger system notification so the Landlord sees the payment instantly
        $admin = \App\Models\User::first();
        if ($admin) {
            $tenant = Tenant::find($p->tenant_id);
            $roomNum = $tenant->room->room_number ?? 'N/A';
            Notification::create([
                'user_id' => $admin->id,
                'title' => "Payment Received: Room {$roomNum}",
                'message' => "Tenant " . ($tenant->name ?? 'Guest') . " paid $" . number_format($p->amount, 2) . " for rent.",
                'type' => 'payment_received',
                'read' => false,
            ]);
        }

        return $this->success($p, 'Payment processed successfully!');
    }

    /**
     * POST /api/tenant-portal/messages
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'message' => 'required|string',
        ]);

        $tenant = Tenant::find($v['tenant_id']);
        
        // Trigger a notification that will show in the Landlord's Communications dashboard
        $admin = \App\Models\User::first();
        if ($admin) {
            Notification::create([
                'user_id' => $admin->id,
                'title' => "Message from " . $tenant->name . " (Room " . ($tenant->room->room_number ?? 'N/A') . ")",
                'message' => $v['message'],
                'type' => 'telegram', // reuse telegram style in Admin UI so it supports replies!
                'metadata' => json_encode(['tenant_id' => $tenant->id]),
                'read' => false,
            ]);
        }

        return $this->success(null, 'Message sent to landlord successfully!');
    }

    /**
     * POST /api/tenant-portal/contracts/{contractId}/sign
     */
    public function signContract(string $contractId, Request $request): JsonResponse
    {
        $c = Contract::with(['tenant', 'room'])->find($contractId);
        if (!$c) {
            return $this->error('Contract not found', 'not_found', null, 404);
        }

        // Get signature data from request
        $signature = $request->input('signature');
        $typedName = $request->input('signedByName');

        $updatedTerms = $c->terms;
        if ($signature) {
            $updatedTerms = $c->terms . "\n\n[SIGNATURE]:" . $signature;
        } elseif ($typedName) {
            $updatedTerms = $c->terms . "\n\n[SIGNATURE]:typed:" . $typedName;
        }

        // Update status to active
        $c->update([
            'status' => 'active',
            'terms' => $updatedTerms,
        ]);

        // Trigger system notification so the Landlord sees the signature instantly
        $admin = \App\Models\User::first();
        if ($admin) {
            $tenant = Tenant::find($c->tenant_id);
            $roomNum = $c->room->room_number ?? 'N/A';
            Notification::create([
                'user_id' => $admin->id,
                'title' => "Contract Signed: Room {$roomNum}",
                'message' => "Tenant " . ($tenant->name ?? 'Guest') . " digitally signed the contract.",
                'type' => 'general',
                'read' => false,
            ]);
        }

        return $this->success([
            'id' => $c->id,
            'tenantId' => $c->tenant_id,
            'tenantName' => $c->tenant->name ?? 'Guest',
            'roomNumber' => $c->room->room_number ?? 'N/A',
            'roomType' => $c->room->type ?? 'N/A',
            'rentAmount' => (float)$c->rent_amount,
            'billingCycle' => $c->billing_cycle ?? 'monthly',
            'startDate' => $c->start_date ? $c->start_date->toDateString() : null,
            'endDate' => $c->end_date ? $c->end_date->toDateString() : null,
            'status' => $c->status,
            'terms' => $c->terms,
        ], 'Contract signed successfully!');
    }

    /**
     * GET /api/tenant-portal/payments/{paymentId}
     */
    public function getInvoice(string $paymentId): JsonResponse
    {
        $p = Payment::with(['tenant.room', 'room.roomType', 'contract'])->find($paymentId);
        if (!$p) {
            return $this->error('Invoice not found', 'not_found', null, 404);
        }

        $paymentOptions = \App\Models\PaymentOption::where('user_id', $p->user_id)
            ->where('is_active', true)
            ->get()
            ->map(fn($o) => [
                'id' => $o->id,
                'paymentType' => $o->payment_type,
                'paymentMethodName' => $o->payment_method_name,
                'bankName' => $o->bank_name,
                'accountName' => $o->account_name,
                'accountNumber' => $o->account_number,
                'currency' => $o->currency,
                'qrCode' => $o->qr_code,
                'remark' => $o->remark,
            ]);

        return $this->success([
            'id' => $p->id,
            'invoiceNumber' => $p->invoice_number ?? ('INV-' . strtoupper(substr($p->id, 0, 8))),
            'amount' => (float)$p->amount,
            'utilityAmount' => (float)$p->utility_amount,
            'lateFee' => (float)$p->late_fee,
            'total' => (float)$p->total,
            'dueDate' => $p->due_date ? $p->due_date->toDateString() : null,
            'paidDate' => $p->paid_date ? $p->paid_date->toDateString() : null,
            'month' => $p->month,
            'status' => $p->status,
            'paymentMethod' => $p->payment_method ?? 'qr_code',
            'notes' => $p->notes,
            'receiptNumber' => $p->receipt_number,
            'tenantName' => $p->tenant->name ?? 'Guest',
            'roomNumber' => $p->room->room_number ?? ($p->tenant->room->room_number ?? 'N/A'),
            'roomType' => $p->room->type ?? ($p->tenant->room->type ?? 'N/A'),
            'billingCycle' => $p->room->roomType->billing_cycle ?? $p->contract->billing_cycle ?? 'monthly',
            'billingPeriodStart' => $p->billing_period_start ? $p->billing_period_start->toDateString() : null,
            'billingPeriodEnd' => $p->billing_period_end ? $p->billing_period_end->toDateString() : null,
            'paymentOptions' => $paymentOptions,
        ], 'Invoice details retrieved successfully');
    }

    /**
     * GET /api/tenant-portal/contracts/{contractId}
     */
    public function getContract(string $contractId): JsonResponse
    {
        $c = Contract::with(['tenant.room', 'room'])->find($contractId);
        if (!$c) {
            return $this->error('Contract not found', 'not_found', null, 404);
        }

        return $this->success([
            'id' => $c->id,
            'tenantId' => $c->tenant_id,
            'tenantName' => $c->tenant->name ?? 'Guest',
            'roomNumber' => $c->room->room_number ?? 'N/A',
            'roomType' => $c->room->type ?? 'N/A',
            'rentAmount' => (float)$c->rent_amount,
            'billingCycle' => $c->billing_cycle ?? 'monthly',
            'startDate' => $c->start_date ? $c->start_date->toDateString() : null,
            'endDate' => $c->end_date ? $c->end_date->toDateString() : null,
            'status' => $c->status,
            'terms' => $c->terms,
        ], 'Contract details retrieved successfully');
    }
}
