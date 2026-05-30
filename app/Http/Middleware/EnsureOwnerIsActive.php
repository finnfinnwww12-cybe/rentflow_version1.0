<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerIsActive
{
    /**
     * Handle an incoming request.
     * Blocks deactivated owner accounts from accessing the API.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isOwner() && !$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact the administrator.',
                'error_code' => 'account_deactivated',
            ], 403);
        }

        return $next($request);
    }
}
