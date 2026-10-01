<?php

namespace App\Http\Middleware;

use App\Support\AdminWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalManagerWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->isAdmin()
                && AdminWorkspace::usesPortalManagerWorkspace($request->user()),
            403,
        );

        return $next($request);
    }
}
