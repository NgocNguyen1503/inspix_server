<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OptionalSanctumMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // This middleware is no longer used - Bearer token auth is now handled directly in controllers
        return $next($request);
    }
}
