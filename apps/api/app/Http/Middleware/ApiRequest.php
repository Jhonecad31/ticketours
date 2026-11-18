<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }

        $id = (string) Str::uuid();
        $request->attributes->set('correlation_id', $id);
        $request->headers->set('Accept', 'application/json');
        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $id);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
