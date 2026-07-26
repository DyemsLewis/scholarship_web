<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403);

        if ($user->isManagedAccount()) {
            $owner = $user->parentAccount()->first();
            abort_unless($owner?->isAdmin() && $owner->isActive(), 403, 'This admin group is not active.');
        }

        return $next($request);
    }
}
