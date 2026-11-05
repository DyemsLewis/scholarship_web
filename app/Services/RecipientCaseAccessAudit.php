<?php

namespace App\Services;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\RecipientCaseAccessLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

final class RecipientCaseAccessAudit
{
    public const ROUTE_SUFFIXES = ['agreement-concerns.show', 'agreement-concerns.evidence.show', 'delivery-reports.show', 'delivery-reports.evidence', 'recipient-cases.show', 'recipient-cases.evidence', 'recipient-case-privacy.show'];

    public function supports(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }
        foreach (['dashboard', 'provider', 'admin'] as $prefix) {
            foreach (self::ROUTE_SUFFIXES as $suffix) {
                if ($request->routeIs($prefix.'.'.$suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function record(Request $request, int $status): void
    {
        if (! $request->user() || $status < 200 || ($status >= 300 && $status < 400)) {
            return;
        }
        $evidence = $request->route('evidence');
        $case = null;
        if ($evidence instanceof RecipientAgreementCaseEvidence) {
            $case = $evidence->agreementCase;
        } elseif ($id = filter_var($evidence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $evidence = RecipientAgreementCaseEvidence::query()->find($id);
            $case = $evidence?->agreementCase;
        }
        if (! $case) {
            foreach (['case', 'concern', 'report'] as $key) {
                $parameter = $request->route($key);
                if ($parameter instanceof RecipientAgreementCase) {
                    $case = $parameter;
                    break;
                }
                if ($id = filter_var($parameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                    $case = RecipientAgreementCase::query()->find($id);
                    break;
                }
            }
        }
        $outcome = $status < 300 ? 'allowed' : (in_array($status, [401, 403], true) ? 'denied' : 'unavailable');
        $actor = User::query()->find($request->user()->id);
        $key = hash('sha256', json_encode([$case?->id, $request->user()->id, $request->route()?->getName(), $evidence instanceof RecipientAgreementCaseEvidence ? $evidence->id : null, $outcome, intdiv(now()->getTimestamp(), 300)], JSON_THROW_ON_ERROR));
        if (RecipientCaseAccessLog::where('dedupe_key', $key)->exists()) {
            return;
        }
        $row = new RecipientCaseAccessLog;
        $row->forceFill(['recipient_agreement_case_id' => $case?->id,
            'evidence_id' => $evidence instanceof RecipientAgreementCaseEvidence ? $evidence->id : null,
            'actor_id' => $actor?->id, 'actor_role' => $actor?->role ?? 'former_account',
            'resource' => $request->route('evidence') !== null ? 'evidence' : 'case',
            'route_name' => $request->route()->getName(), 'outcome' => $outcome, 'dedupe_key' => $key, 'created_at' => now()]);
        try {
            $row->save();
        } catch (QueryException $error) {
            if (! RecipientCaseAccessLog::where('dedupe_key', $key)->exists()) {
                throw $error;
            }
        }
    }

    public function history(User $actor, RecipientAgreementCase $case, int $page = 1): array
    {
        app(RecipientCasePrivacyService::class)->authorize($actor, $case);
        $admin = $actor->isAdmin();
        $query = RecipientCaseAccessLog::query()->where('recipient_agreement_case_id', $case->id);
        if (! $admin) {
            $query->where('outcome', 'allowed');
        }
        $rows = $query->orderByDesc('id')->paginate(8, ['*'], 'access_page', $page);
        $names = $admin ? User::query()->whereIn('id', $rows->getCollection()->pluck('actor_id')->filter())->get()->keyBy('id') : collect();
        $rows->through(fn ($row) => [
            'id' => $row->id, 'at' => $row->created_at?->toIso8601String(),
            'actor_label' => $row->actor_id === $actor->id ? 'You' : match ($row->actor_role) {
                'admin' => 'Platform review team', 'provider' => $row->outcome === 'allowed' ? 'Originating provider team' : 'Provider account', 'applicant' => 'Applicant', default => 'Former account'
            },
            ...($admin ? ['actor_name' => $names->get($row->actor_id)?->name ?? 'Former account'] : []),
            'resource_label' => $row->resource === 'evidence' ? 'Supporting file' : 'Case record',
            'outcome_label' => match ($row->outcome) {
                'allowed' => 'Authorized access', 'denied' => 'Blocked access', default => 'Unavailable record'
            },
        ]);

        return $rows->toArray();
    }
}
