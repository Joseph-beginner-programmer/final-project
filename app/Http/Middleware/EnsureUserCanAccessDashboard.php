<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessDashboard
{
    public function handle(Request $request, Closure $next, string $dashboardRole): Response
    {
        $role = UserRole::from($dashboardRole);

        abort_unless($request->user()->can('view-dashboard', $role), 403);

        return $next($request);
    }
}
