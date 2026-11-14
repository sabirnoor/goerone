<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;

/**
 * Keeps draft / pending / rejected stores off the store-panel routes.
 *
 * Admin / staff tokens (User model) pass straight through, so the same routes keep working
 * for admin. Only a *Store* token that is not approved is blocked.
 *
 * Usage (no alias needed):
 *   Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureStoreApproved::class])->group(...);
 */
class EnsureStoreApproved
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user instanceof Store && !$user->isApproved()) {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 403,
                ],
                'registration_status' => $user->registration_status,
                'message' => 'Your store registration is not approved yet.',
            ], 403);
        }

        return $next($request);
    }
}