<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderIsApproved
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isProvider(), 403);

        $owner = $user->providerOrganizationOwner();

        abort_unless(
            $owner->isActive()
                && $owner->hasVerifiedEmail()
                && $owner->providerProfile?->isVerified(),
            403,
            'Provider approval is required to access applicant and program review records.',
        );

        return $next($request);
    }
}
