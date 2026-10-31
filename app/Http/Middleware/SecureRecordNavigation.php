<?php

namespace App\Http\Middleware;

use App\Support\OpaqueRecordUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecureRecordNavigation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) return $next($request);
        $existing = $request->attributes->get('opaque_record_url');
        $context = $existing ?? app(OpaqueRecordUrl::class)->encode($request->getPathInfo());
        if ($context) $request->attributes->set('opaque_record_url', $context);
        $response = $next($request);

        // Only canonicalize an authorized rendered page, never an API, signed email, or payment callback.
        if (! $existing && $context && $request->user() && $response->getStatusCode() === 200
            && ! str_contains((string) $response->headers->get('Content-Type'), 'application/json')
            && $request->header('Sec-Fetch-Dest') === 'document') {
            return redirect($request->getBaseUrl().$context['path'].($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }

        return $response;
    }
}
