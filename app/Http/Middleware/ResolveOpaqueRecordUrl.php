<?php

namespace App\Http\Middleware;

use App\Support\OpaqueRecordUrl;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Symfony\Component\HttpFoundation\Response;

final class ResolveOpaqueRecordUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $codec = app(OpaqueRecordUrl::class);
        if (! $codec->isOpaque($request->getPathInfo())) return $next($request);
        try {
            $context = $codec->decode($request->getPathInfo());
        } catch (DecryptException) {
            abort(404);
        }
        abort_unless($context, 404);

        // Resolve before route matching so every existing binding, policy, and throttle still runs.
        $server = $request->server->all();
        $server['REQUEST_URI'] = $request->getBaseUrl().$context['path'].($request->getQueryString() ? '?'.$request->getQueryString() : '');
        unset($server['PATH_INFO'], $server['ORIG_PATH_INFO']);
        $resolved = $request->duplicate(server: $server);
        $resolved->attributes->set('opaque_record_url', $context);
        app()->instance('request', $resolved);
        RequestFacade::clearResolvedInstance('request');

        return $next($resolved);
    }
}
