<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(MaintenanceRequest::with('room'), $request);

        if ($status = $request->query('status')) $query->where('status', $status);
        if ($priority = $request->query('priority')) $query->where('priority', $priority);
        if ($search = $request->query('search')) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('reported_by', 'like', "%{$search}%");
            });
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        $items = $query->paginate($request->query('limit', 10));
        $items->getCollection()->transform(fn($m) => [
            'id' => $m->id, 'room' => $m->room->room_number ?? 'N/A',
            'title' => $m->title, 'description' => $m->description,
            'priority' => $m->priority, 'status' => $m->status,
            'reportedBy' => $m->reported_by, 'reportedDate' => $m->reported_date,
            'completedDate' => $m->completed_date, 'cost' => $m->cost,
            'hasExpense' => Expense::where('maintenance_request_id', $m->id)->exists(),
        ]);

        return $this->paginated($items);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $m = $this->scopeByOwner(MaintenanceRequest::with('room'), $request)->find($id);
        if (!$m) return $this->error('Request not found', 'not_found', null, 404);

        $expense = Expense::where('maintenance_request_id', $m->id)->first();

        return $this->success([
            'id' => $m->id, 'room' => $m->room->room_number ?? 'N/A',
            'room_id' => $m->room_id, 'title' => $m->title,
            'description' => $m->description, 'priority' => $m->priority,
            'status' => $m->status, 'reportedBy' => $m->reported_by,
            'reportedDate' => $m->reported_date,
            'completedDate' => $m->completed_date, 'notes' => $m->notes,
            'cost' => $m->cost, 'created_at' => $m->created_at,
            'linkedExpense' => $expense ? [
                'id' => $expense->id,
                'amount' => $expense->amount,
                'date' => $expense->date,
            ] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'room' => 'required|string',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'reportedBy' => 'required|string|max:255',
        ]);

        $room = \App\Models\Room::where('room_number', $v['room'])->first();

        $m = MaintenanceRequest::create([
            'room_id' => $room?->id,
            'title' => $v['title'], 'description' => $v['description'],
            'priority' => $v['priority'] ?? 'low',
            'status' => 'pending',
            'reported_by' => $v['reportedBy'],
            'reported_date' => now()->format('Y-m-d'),
            'user_id' => $request->user()->id,
        ]);

        return $this->success($m->load('room'), 'Maintenance request created', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $m = $this->scopeByOwner(MaintenanceRequest::query(), $request)->find($id);
        if (!$m) return $this->error('Request not found', 'not_found', null, 404);

        $v = $request->validate([
            'status' => 'sometimes|in:pending,in-progress,completed',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'notes' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $data = [];
        if (isset($v['status'])) {
            $data['status'] = $v['status'];
            if ($v['status'] === 'completed') {
                $data['completed_date'] = now()->format('Y-m-d');
            }
        }
        if (isset($v['priority'])) $data['priority'] = $v['priority'];
        if (array_key_exists('notes', $v)) $data['notes'] = $v['notes'];
        if (isset($v['cost'])) $data['cost'] = $v['cost'];

        $m->update($data);

        // AUTO-LINK: When maintenance is completed with a cost, create an expense
        if (isset($v['status']) && $v['status'] === 'completed' && (float)$m->cost > 0) {
            $alreadyLinked = Expense::where('maintenance_request_id', $m->id)->exists();

            if (!$alreadyLinked) {
                $roomNum = $m->room->room_number ?? 'N/A';
                Expense::create([
                    'category'               => 'repairs',
                    'description'            => "Maintenance: {$m->title} (Room {$roomNum})",
                    'amount'                 => $m->cost,
                    'date'                   => now()->format('Y-m-d'),
                    'maintenance_request_id' => $m->id,
                    'room_id'                => $m->room_id,
                    'user_id'                => $m->user_id,
                ]);
            }
        }

        return $this->success($m->fresh()->load('room'), 'Request updated successfully');
    }

    public function stats(Request $request): JsonResponse
    {
        $scopeUser = fn($q) => ($oid = $this->getOwnerId($request)) ? $q->where('user_id', $oid) : $q;
        return $this->success([
            'total' => $scopeUser(MaintenanceRequest::query())->count(),
            'pending' => $scopeUser(MaintenanceRequest::query())->where('status', 'pending')->count(),
            'inProgress' => $scopeUser(MaintenanceRequest::query())->where('status', 'in-progress')->count(),
            'completed' => $scopeUser(MaintenanceRequest::query())->where('status', 'completed')->count(),
            'urgent' => $scopeUser(MaintenanceRequest::query())->where('priority', 'urgent')->whereIn('status', ['pending', 'in-progress'])->count(),
            'totalCost' => round($scopeUser(MaintenanceRequest::query())->where('status', 'completed')->sum('cost'), 2),
        ]);
    }
}
