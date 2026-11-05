<?php

namespace App\Http\Middleware;

use App\Services\RecipientCaseAccessAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class AuditRecipientCaseAccess
{
    public function __construct(private readonly RecipientCaseAccessAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->audit->supports($request)) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            // Never turn a denied read into a success when the audit store is unavailable.
            try {
                $this->audit->record($request, method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500);
            } catch (Throwable $auditError) {
                report($auditError);
            }
            throw $error;
        }
        try {
            $this->audit->record($request, $response->getStatusCode());
        } catch (Throwable $error) {
            report($error);
            if ($response->isSuccessful()) {
                throw new HttpException(503, 'Private case access is temporarily unavailable. Please try again.');
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
