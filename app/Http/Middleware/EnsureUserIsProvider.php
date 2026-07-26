<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsProvider
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isProvider(), 403);

        if ($user->isManagedAccount()) {
            $owner = $user->providerOrganizationOwner();
            abort_unless($owner->isProvider() && $owner->isActive(), 403, 'This provider organization is not active.');
        }

        return $next($request);
    }
}
