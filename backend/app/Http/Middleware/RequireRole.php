<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $role = $request->user()?->role?->value ?? $request->user()?->role;
        abort_unless(in_array($role, $roles, true), 403);
        return $next($request);
    }
}
