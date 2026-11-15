<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Nếu đặt HS_API_TOKEN thì API yêu cầu header "Authorization: Bearer <token>". Bỏ trống = không kiểm tra. */
class EnsureHsApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('hs.api_token');
        if ($token && ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Sai hoặc thiếu token'], 401);
        }

        return $next($request);
    }
}
