<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeByOwner(Expense::query(), $request);

        if ($category = $request->query('category')) $query->where('category', strtolower($category));
        if ($from = $request->query('from')) $query->whereDate('date', '>=', $from);
        if ($to = $request->query('to')) $query->whereDate('date', '<=', $to);
        if ($search = $request->query('search')) {
            $query->where('description', 'like', "%{$search}%");
        }

        $sort = $request->query('sort', '-date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction);

        $expenses = $query->paginate($request->query('limit', 10));
        $expenses->getCollection()->transform(fn($e) => [
            'id' => $e->id, 'category' => ucfirst($e->category),
            'description' => $e->description, 'amount' => $e->amount,
            'date' => $e->date,
            'maintenanceRequestId' => $e->maintenance_request_id,
            'roomId' => $e->room_id,
            'maintenanceTitle' => $e->maintenanceRequest?->title,
        ]);

        return $this->paginated($expenses);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'category' => 'required|in:repairs,cleaning,utilities,taxes,insurance,Repairs,Cleaning,Utilities,Taxes,Insurance',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0',
            'date' => 'required|date',
        ]);

        $expense = Expense::create([
            'category' => strtolower($v['category']),
            'description' => $v['description'],
            'amount' => $v['amount'],
            'date' => $v['date'],
            'user_id' => $request->user()->id,
        ]);

        return $this->success($expense, 'Expense created successfully', 201);
    }

    public function byCategory(Request $request, string $category): JsonResponse
    {
        $expenses = $this->scopeByOwner(Expense::query(), $request)->where('category', strtolower($category))
            ->orderBy('date', 'desc')->get()
            ->map(fn($e) => [
                'id' => $e->id, 'category' => ucfirst($e->category),
                'description' => $e->description,
                'amount' => $e->amount, 'date' => $e->date,
            ]);

        return $this->success($expenses);
    }

    public function monthly(Request $request, string $month): JsonResponse
    {
        $expenses = $this->scopeByOwner(Expense::query(), $request)->where('date', 'like', $this->monthToDatePrefix($month) . '%')
            ->orderBy('date', 'desc')->get();

        $byCategory = $expenses->groupBy('category')->map(fn($items, $cat) => [
            'category' => ucfirst($cat),
            'total' => round($items->sum('amount'), 2),
            'count' => $items->count(),
        ])->values();

        return $this->success([
            'month' => $month,
            'totalExpenses' => round($expenses->sum('amount'), 2),
            'count' => $expenses->count(),
            'byCategory' => $byCategory,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $expense = $this->scopeByOwner(Expense::query(), $request)->find($id);
        if (!$expense) return $this->error('Expense not found', 'not_found', null, 404);

        $expense->delete();
        return $this->success(null, 'Expense deleted successfully');
    }

    private function monthToDatePrefix(string $month): string
    {
        // Convert "April 2026" to "2026-04"
        try {
            return \Carbon\Carbon::parse("1 {$month}")->format('Y-m');
        } catch (\Exception $e) {
            return $month;
        }
    }
}
