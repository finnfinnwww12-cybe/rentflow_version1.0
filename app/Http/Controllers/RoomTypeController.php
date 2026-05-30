<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Http\Requests\UpdateRoomTypeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    /**
     * Get all room types with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(RoomType::query(), $request);

        // Filter by status
        if ($request->has('status')) {
            $status = filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN);
            $query->where('status', $status);
        }

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', '%' . $search . '%')
                  ->orWhere('description', 'like', '%' . $search . '%');
        }

        // Sort
        $sort = $request->input('sort', 'name');
        $order = $request->input('order', 'asc');
        $query->orderBy($sort, $order);

        // Pagination
        $limit = $request->input('limit', 10);
        $roomTypes = $query->paginate($limit);

        return response()->json([
            'success' => true,
            'message' => 'Room types retrieved successfully',
            'data' => $roomTypes->items(),
            'pagination' => [
                'total' => $roomTypes->total(),
                'per_page' => $roomTypes->perPage(),
                'current_page' => $roomTypes->currentPage(),
                'last_page' => $roomTypes->lastPage(),
                'from' => $roomTypes->firstItem(),
                'to' => $roomTypes->lastItem(),
            ]
        ]);
    }

    /**
     * Get all active room types (without pagination)
     */
    public function active(Request $request): JsonResponse
    {
        $roomTypes = $this->scopeByOwner(RoomType::active(), $request)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Active room types retrieved successfully',
            'data' => $roomTypes
        ]);
    }

    /**
     * Store a newly created room type
     */
    public function store(StoreRoomTypeRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($request->user() && $request->user()->isOwner()) {
            $data['user_id'] = $request->user()->id;
        }
        $roomType = RoomType::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Room type created successfully',
            'data' => $roomType
        ], 201);
    }

    /**
     * Get specific room type details
     */
    public function show(Request $request, RoomType $roomType): JsonResponse
    {
        if ($request->user() && $request->user()->isOwner() && $roomType->user_id !== $request->user()->id) {
            return $this->error('Unauthorized. Insufficient permissions.', 'unauthorized_room_type', null, 403);
        }

        $roomType->load(['rooms']);
        $roomType->room_count = $roomType->rooms()->count();

        return response()->json([
            'success' => true,
            'message' => 'Room type retrieved successfully',
            'data' => $roomType
        ]);
    }

    /**
     * Update a room type
     */
    public function update(UpdateRoomTypeRequest $request, RoomType $roomType): JsonResponse
    {
        if ($request->user() && $request->user()->isOwner() && $roomType->user_id !== $request->user()->id) {
            return $this->error('Unauthorized. Insufficient permissions.', 'unauthorized_room_type', null, 403);
        }

        $roomType->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Room type updated successfully',
            'data' => $roomType
        ]);
    }

    /**
     * Delete a room type
     */
    public function destroy(Request $request, RoomType $roomType): JsonResponse
    {
        if ($request->user() && $request->user()->isOwner() && $roomType->user_id !== $request->user()->id) {
            return $this->error('Unauthorized. Insufficient permissions.', 'unauthorized_room_type', null, 403);
        }

        // Check if room type has associated rooms
        if ($roomType->rooms()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete room type with existing rooms',
                'error' => 'room_type_has_rooms'
            ], 409);
        }

        $roomType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Room type deleted successfully'
        ]);
    }

    /**
     * Get room type statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $totalRoomTypes = $this->scopeByOwner(RoomType::query(), $request)->count();
        $activeRoomTypes = $this->scopeByOwner(RoomType::active(), $request)->count();
        $inactiveRoomTypes = $this->scopeByOwner(RoomType::inactive(), $request)->count();
        
        $roomTypeStats = $this->scopeByOwner(RoomType::withCount('rooms'), $request)
            ->orderBy('name')
            ->get()
            ->map(function ($roomType) {
                return [
                    'id' => $roomType->id,
                    'name' => $roomType->name,
                    'billing_cycle' => $roomType->billing_cycle,
                    'base_price' => $roomType->base_price,
                    'base_daily_price' => $roomType->base_daily_price,
                    'capacity' => $roomType->capacity,
                    'room_count' => $roomType->rooms_count,
                    'total_potential_revenue' => $roomType->base_price * $roomType->rooms_count,
                    'status' => $roomType->status,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Room type statistics retrieved successfully',
            'data' => [
                'total_room_types' => $totalRoomTypes,
                'active_room_types' => $activeRoomTypes,
                'inactive_room_types' => $inactiveRoomTypes,
                'room_type_details' => $roomTypeStats
            ]
        ]);
    }
}
