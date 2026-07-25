<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return response()->json([
                'message' => 'Unauthenticated. Please login first.',
                'status' => 401,
            ], 401);
        }

        return $next($request);
    }
}
