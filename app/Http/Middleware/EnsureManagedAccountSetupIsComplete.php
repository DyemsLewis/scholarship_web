<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagedAccountSetupIsComplete
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isManagedAccount() || ($user->hasVerifiedEmail() && ! $user->must_reset_password)) {
            return $next($request);
        }

        if ($request->routeIs([
            'account.setup',
            'account.setup.data',
            'account.setup.password',
            'verification.verify',
            'verification.send',
            'logout',
        ])) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $user->hasVerifiedEmail()
                    ? 'Change your temporary password before continuing.'
                    : 'Verify your email address before continuing.',
                'redirect' => route('account.setup', absolute: false),
            ], 409);
        }

        return redirect()->route('account.setup');
    }
}
