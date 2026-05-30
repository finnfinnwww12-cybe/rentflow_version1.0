<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * GET /api/tenants
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(Tenant::with('room'), $request);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Sort
        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        $query->orderBy($field, $direction);

        $limit = $request->query('limit', 10);
        $tenants = $query->paginate($limit);

        // Transform data to match API spec
        $tenants->getCollection()->transform(function ($tenant) {
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'room' => $tenant->room->room_number ?? null,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'moveInDate' => $tenant->move_in_date,
                'moveOutDate' => $tenant->move_out_date,
                'status' => $tenant->status,
            ];
        });

        return $this->paginated($tenants);
    }

    /**
     * GET /api/tenants/:id
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $this->scopeByOwner(Tenant::with(['room', 'payments', 'contracts']), $request)->find($id);

        if (!$tenant) {
            return $this->error('Tenant not found', 'not_found', null, 404);
        }

        return $this->success([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'room' => $tenant->room->room_number ?? null,
            'room_id' => $tenant->room_id,
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'moveInDate' => $tenant->move_in_date,
            'moveOutDate' => $tenant->move_out_date,
            'idNumber' => $tenant->id_number,
            'emergencyContact' => $tenant->emergency_contact,
            'status' => $tenant->status,
            'payments' => $tenant->payments->map(fn($p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'status' => $p->status,
                'month' => $p->month,
                'paidDate' => $p->paid_date,
            ]),
            'contracts' => $tenant->contracts->map(fn($c) => [
                'id' => $c->id,
                'startDate' => $c->start_date,
                'endDate' => $c->end_date,
                'rentAmount' => $c->rent_amount,
                'status' => $c->status,
            ]),
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ]);
    }

    /**
     * POST /api/tenants
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Resolve room_id from room number
        $roomId = null;
        $room = null;
        if (!empty($validated['room'])) {
            $room = $this->scopeByOwner(\App\Models\Room::query(), $request)->where('room_number', $validated['room'])->first();
            if (!$room) {
                return $this->error('Room not found', 'not_found', null, 404);
            }
            if ($room->status === 'occupied' || !empty($room->tenant_id)) {
                return $this->error("Room {$room->room_number} is already occupied by another active tenant.", 'room_occupied', null, 422);
            }
            $roomId = $room->id;
            $room->update(['status' => 'occupied', 'tenant_id' => null]); // Will update tenant_id after creation
        }

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'room_id' => $roomId,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'move_in_date' => $validated['moveInDate'] ?? null,
            'move_out_date' => $validated['moveOutDate'] ?? null,
            'id_number' => $validated['idNumber'] ?? null,
            'emergency_contact' => $validated['emergencyContact'] ?? null,
            'status' => 'active',
            'user_id' => $request->user()->id,
        ]);

        // Update room's tenant_id
        if ($roomId) {
            \App\Models\Room::where('id', $roomId)->update(['tenant_id' => $tenant->id, 'status' => 'occupied']);
        }

        // Auto-create draft contract when tenant is assigned to a room
        if ($room && $roomId) {
            $billingCycle = $room->roomType->billing_cycle ?? 'monthly';
            $startDate = $validated['moveInDate'] ?? now()->format('Y-m-d');
            
            if (!empty($validated['moveOutDate'])) {
                $endDate = $validated['moveOutDate'];
            } else {
                if ($billingCycle === 'daily') {
                    $endDate = \Carbon\Carbon::parse($startDate)->addDay()->format('Y-m-d');
                } else {
                    $endDate = \Carbon\Carbon::parse($startDate)->addMonth()->format('Y-m-d');
                }
            }

            \App\Models\Contract::create([
                'tenant_id' => $tenant->id,
                'room_id' => $roomId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'rent_amount' => $room->rent ?? 0,
                'billing_cycle' => $billingCycle,
                'status' => 'draft',
                'terms' => 'Auto-generated draft contract. Please review and activate.',
                'user_id' => $request->user()->id,
            ]);
        }

        return $this->success($tenant->load('room'), 'Tenant created successfully', 201);
    }

    /**
     * PUT /api/tenants/:id
     */
    public function update(UpdateTenantRequest $request, string $id): JsonResponse
    {
        $tenant = $this->scopeByOwner(Tenant::query(), $request)->find($id);

        if (!$tenant) {
            return $this->error('Tenant not found', 'not_found', null, 404);
        }

        $validated = $request->validated();

        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = $validated['name'];
        if (isset($validated['phone'])) $updateData['phone'] = $validated['phone'];
        if (isset($validated['email'])) $updateData['email'] = $validated['email'];
        if (isset($validated['moveInDate'])) $updateData['move_in_date'] = $validated['moveInDate'];
        if (array_key_exists('moveOutDate', $validated)) $updateData['move_out_date'] = $validated['moveOutDate'];
        if (isset($validated['idNumber'])) $updateData['id_number'] = $validated['idNumber'];
        if (isset($validated['emergencyContact'])) $updateData['emergency_contact'] = $validated['emergencyContact'];
        if (isset($validated['status'])) {
            $updateData['status'] = $validated['status'];
            if ($validated['status'] === 'inactive' && $tenant->room_id) {
                \App\Models\Room::where('id', $tenant->room_id)->update(['status' => 'vacant', 'tenant_id' => null]);
                $updateData['room_id'] = null;
                $tenant->room_id = null; // Update the model instance to reflect this immediately
            }
        }

        // Handle room change
        if (array_key_exists('room', $validated) && $tenant->status === 'active') {
            if (!empty($validated['room'])) {
                $room = $this->scopeByOwner(\App\Models\Room::query(), $request)->where('room_number', $validated['room'])->first();
                if (!$room) {
                    return $this->error('Room not found', 'not_found', null, 404);
                }

                // Block if assigning to a different occupied room
                if ($room->id !== $tenant->room_id && ($room->status === 'occupied' || !empty($room->tenant_id))) {
                    return $this->error("Room {$room->room_number} is already occupied by another active tenant.", 'room_occupied', null, 422);
                }

                // Free old room
                if ($tenant->room_id && $tenant->room_id !== $room->id) {
                    \App\Models\Room::where('id', $tenant->room_id)->update(['status' => 'vacant', 'tenant_id' => null]);
                }

                $updateData['room_id'] = $room->id;
                $room->update(['status' => 'occupied', 'tenant_id' => $tenant->id]);
            } else {
                // Free old room if unassigning
                if ($tenant->room_id) {
                    \App\Models\Room::where('id', $tenant->room_id)->update(['status' => 'vacant', 'tenant_id' => null]);
                }
                $updateData['room_id'] = null;
            }
        }

        $tenant->update($updateData);

        return $this->success($tenant->fresh()->load('room'), 'Tenant updated successfully');
    }

    /**
     * DELETE /api/tenants/:id
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $this->scopeByOwner(Tenant::query(), $request)->find($id);

        if (!$tenant) {
            return $this->error('Tenant not found', 'not_found', null, 404);
        }

        // Free the room
        if ($tenant->room_id) {
            \App\Models\Room::where('id', $tenant->room_id)->update(['status' => 'vacant', 'tenant_id' => null]);
        }

        // Delete related data
        \App\Models\Payment::where('tenant_id', $tenant->id)->delete();
        \App\Models\Contract::where('tenant_id', $tenant->id)->delete();

        $tenant->delete();

        return $this->success(null, 'Tenant and related data deleted successfully');
    }
}
