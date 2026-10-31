<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=()');
        $response->headers->remove('X-Powered-By');
        if ($request->user() || $request->is('login', 'account/*', 'reset-password', 'forgot-password')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (app()->isProduction()) {
            if ($request->isSecure()) $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            $nonce = Vite::cspNonce();
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'", "base-uri 'self'", "object-src 'none'", "frame-ancestors 'self'", "form-action 'self'",
                "script-src 'self' 'nonce-{$nonce}' https://unpkg.com/leaflet@1.9.4/dist/leaflet.js",
                "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://unpkg.com https://fonts.googleapis.com",
                "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
                "img-src 'self' data: blob: https:", "connect-src 'self' https://psgc.cloud https://nominatim.openstreetmap.org",
                "frame-src 'self' blob:", "media-src 'self' blob:", "worker-src 'self' blob:", 'upgrade-insecure-requests',
            ]));
        }

        return $response;
    }
}
