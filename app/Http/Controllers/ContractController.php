<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(Contract::with(['tenant', 'room']), $request);

        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = $request->query('search')) {
            $query->whereHas('tenant', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        $contracts = $query->paginate($request->query('limit', 10));
        $contracts->getCollection()->transform(fn($c) => [
            'id' => $c->id, 'tenant' => $c->tenant->name ?? 'N/A',
            'tenant_id' => $c->tenant_id,
            'room' => $c->room->room_number ?? 'N/A',
            'startDate' => $c->start_date, 'endDate' => $c->end_date,
            'rentAmount' => $c->rent_amount, 
            'billingCycle' => $c->billing_cycle,
            'status' => $c->status,
        ]);

        return $this->paginated($contracts);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $c = $this->scopeByOwner(Contract::with(['tenant', 'room']), $request)->find($id);
        if (!$c) return $this->error('Contract not found', 'not_found', null, 404);

        return $this->success([
            'id' => $c->id,
            'tenant' => $c->tenant ? ['id' => $c->tenant->id, 'name' => $c->tenant->name] : null,
            'room' => $c->room ? ['id' => $c->room->id, 'roomNumber' => $c->room->room_number] : null,
            'startDate' => $c->start_date, 'endDate' => $c->end_date,
            'rentAmount' => $c->rent_amount, 
            'billingCycle' => $c->billing_cycle,
            'status' => $c->status,
            'terms' => $c->terms,
            'created_at' => $c->created_at,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tenantId' => 'required|exists:tenants,id',
            'roomId' => 'required|exists:rooms,id',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
            'rentAmount' => 'required|numeric|min:0',
            'billingCycle' => 'sometimes|in:daily,monthly',
            'terms' => 'nullable|string',
            'status' => 'sometimes|in:active,draft,expired,terminated',
        ]);

        $contract = Contract::create([
            'tenant_id' => $v['tenantId'], 'room_id' => $v['roomId'],
            'start_date' => $v['startDate'], 'end_date' => $v['endDate'],
            'rent_amount' => $v['rentAmount'], 
            'billing_cycle' => $v['billingCycle'] ?? 'monthly',
            'status' => $v['status'] ?? 'draft',
            'terms' => $v['terms'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        return $this->success($contract->load(['tenant', 'room']), 'Contract created', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $c = $this->scopeByOwner(Contract::query(), $request)->find($id);
        if (!$c) return $this->error('Contract not found', 'not_found', null, 404);

        $v = $request->validate([
            'startDate' => 'sometimes|date',
            'endDate' => 'sometimes|date',
            'rentAmount' => 'sometimes|numeric|min:0',
            'billingCycle' => 'sometimes|in:daily,monthly',
            'status' => 'sometimes|in:active,expired,terminated,draft',
            'terms' => 'nullable|string',
        ]);

        $data = [];
        if (isset($v['startDate'])) $data['start_date'] = $v['startDate'];
        if (isset($v['endDate'])) $data['end_date'] = $v['endDate'];
        if (isset($v['rentAmount'])) $data['rent_amount'] = $v['rentAmount'];
        if (isset($v['billingCycle'])) $data['billing_cycle'] = $v['billingCycle'];
        if (isset($v['status'])) $data['status'] = $v['status'];
        if (array_key_exists('terms', $v)) $data['terms'] = $v['terms'];

        $c->update($data);
        return $this->success($c->fresh()->load(['tenant', 'room']), 'Contract updated');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $c = $this->scopeByOwner(Contract::query(), $request)->find($id);
        if (!$c) return $this->error('Contract not found', 'not_found', null, 404);

        $c->delete();
        return $this->success(null, 'Contract deleted successfully');
    }

    /**
     * GET /api/contracts/expiring-soon
     * Returns contracts expiring within the next 30 days
     */
    public function expiringSoon(Request $request): JsonResponse
    {
        $contracts = $this->scopeByOwner(Contract::with(['tenant', 'room']), $request)
            ->where('status', 'active')
            ->whereBetween('end_date', [now(), now()->addDays(30)])
            ->orderBy('end_date', 'asc')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'tenant' => $c->tenant->name ?? 'N/A',
                'tenant_id' => $c->tenant_id,
                'room' => $c->room->room_number ?? 'N/A',
                'startDate' => $c->start_date->format('Y-m-d'),
                'endDate' => $c->end_date->format('Y-m-d'),
                'daysRemaining' => (int) now()->diffInDays($c->end_date, false),
                'rentAmount' => $c->rent_amount,
                'status' => $c->status,
            ]);

        return $this->success($contracts);
    }

    /**
     * POST /api/contracts/{id}/renew
     * Renew an existing contract:
     *  - Old contract → expired
     *  - New contract → active, with optional rent increase
     */
    public function renew(Request $request, string $id): JsonResponse
    {
        $oldContract = Contract::with(['tenant', 'room'])->find($id);
        if (!$oldContract) {
            return $this->error('Contract not found', 'not_found', null, 404);
        }

        if (!in_array($oldContract->status, ['active', 'draft'])) {
            return $this->error('Only active or draft contracts can be renewed', 'invalid_status', null, 422);
        }

        $v = $request->validate([
            'rentIncrease' => 'nullable|numeric|min:0|max:100', // percentage
            'durationMonths' => 'nullable|integer|min:1|max:60', // 1-60 months
            'terms' => 'nullable|string',
        ]);

        $rentIncrease = $v['rentIncrease'] ?? 0;
        $durationMonths = $v['durationMonths'] ?? 12;
        $newRent = round($oldContract->rent_amount * (1 + $rentIncrease / 100), 2);

        $newStartDate = Carbon::parse($oldContract->end_date)->addDay();
        $newEndDate = $newStartDate->copy()->addMonths($durationMonths);

        // Expire old contract
        $oldContract->update(['status' => 'expired']);

        // Create new contract
        $newContract = Contract::create([
            'tenant_id' => $oldContract->tenant_id,
            'room_id' => $oldContract->room_id,
            'start_date' => $newStartDate->format('Y-m-d'),
            'end_date' => $newEndDate->format('Y-m-d'),
            'rent_amount' => $newRent,
            'billing_cycle' => $oldContract->billing_cycle,
            'status' => 'active',
            'terms' => $v['terms'] ?? $oldContract->terms,
            'user_id' => $oldContract->user_id,
        ]);

        return $this->success([
            'oldContract' => [
                'id' => $oldContract->id,
                'status' => 'expired',
            ],
            'newContract' => [
                'id' => $newContract->id,
                'tenant' => $oldContract->tenant->name ?? 'N/A',
                'room' => $oldContract->room->room_number ?? 'N/A',
                'startDate' => $newContract->start_date,
                'endDate' => $newContract->end_date,
                'rentAmount' => $newContract->rent_amount,
                'status' => $newContract->status,
            ],
        ], 'Contract renewed successfully', 201);
    }
}
