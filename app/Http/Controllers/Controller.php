<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Return a success JSON response.
     */
    protected function success($data = null, string $message = 'Operation successful', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Return an error JSON response.
     */
    protected function error(string $message = 'Error occurred', string $errorCode = 'error', $details = null, int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => $errorCode,
            'details' => $details,
        ], $code);
    }

    /**
     * Return a paginated JSON response.
     */
    protected function paginated($paginator, string $message = 'Data retrieved successfully'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Get the owner user_id for scoping queries.
     * Returns the authenticated user's id for owners, null for super admins.
     */
    protected function getOwnerId($request): ?int
    {
        $user = $request->user();
        return $user && $user->isOwner() ? $user->id : null;
    }

    /**
     * Apply owner scope to an Eloquent query builder.
     * For owners: scopes to their user_id. For super admins: no scope (sees all).
     */
    protected function scopeByOwner($query, $request)
    {
        $ownerId = $this->getOwnerId($request);
        if ($ownerId !== null) {
            $query->where('user_id', $ownerId);
        }
        return $query;
    }

    /**
     * Log system activity.
     */
    protected function logActivity($request, string $action, string $description, ?array $details = null): void
    {
        \App\Models\ActivityLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'description' => $description,
            'details' => $details,
            'ip_address' => $request->ip(),
        ]);
    }
}
