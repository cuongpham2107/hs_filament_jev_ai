<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Xác thực API ngoài bằng header "x-api-key" khớp với APP_KEY (config('app.key')). */
class EnsureAppKeyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('app.key');
        $provided = (string) $request->header('x-api-key', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Sai hoặc thiếu x-api-key.'], 401);
        }

        return $next($request);
    }
}
