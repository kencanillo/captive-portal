<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $startTime;

        Log::info('Request performance', [
            'method' => $request->method(),
            'uri' => $request->fullUrl(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round($duration * 1000),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
