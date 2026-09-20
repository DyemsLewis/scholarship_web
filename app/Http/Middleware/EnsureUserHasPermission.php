<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(
            collect($permissions)->contains(
                fn (string $permission): bool => $request->user()?->hasPortalPermission($permission) ?? false
            ),
            403,
        );

        return $next($request);
    }
}
