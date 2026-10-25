<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->disabled_at) {
            auth()->guard('web')->logout();
            abort(403, 'This account has been disabled.');
        }

        return $next($request);
    }
}
