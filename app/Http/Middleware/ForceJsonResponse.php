<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ForceJsonResponse
 *
 * Forces the Accept header to application/json for every API request so
 * validation errors and exceptions are always rendered as JSON, never as
 * an HTML error page, which would break the React frontend.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
