<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EligibilityReviewRequest;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\EligibilityRulePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EligibilityReviewService
{
    public function raw(Scholarship $program, User $applicant): array
    {
        return app(ScholarshipEligibilityService::class)->evaluate($program, $applicant, false, false);
    }

    public function contextHash(Scholarship $program, array $raw): string
    {
        $criteria = collect($raw['criteria'] ?? [])->filter(fn ($row) => ($row['category'] ?? '') === 'eligibility')->sortBy('key')->values()->all();
        $enabled = $program->eligibility_review_rules ?? [];
        sort($enabled);
        $intake = [$program->program_cycle, $program->application_opens_at?->toDateString(), $program->deadline?->toDateString()];
        return hash('sha256', json_encode([$program->id, $intake, $enabled, $criteria], JSON_THROW_ON_ERROR));
    }

    public function applicantGate(Scholarship $program, User $applicant): ?string
    {
        $readiness = $applicant->applicantProfileReadiness();
        return match (true) {
            ! $applicant->isApplicant() || ! $applicant->isActive() => 'An active applicant account is required.',
            ! $applicant->hasVerifiedEmail() => 'Verify your email before requesting eligibility review.',
            ! ($readiness['complete'] ?? false) || ! ($readiness['verified'] ?? false) => 'Complete and verify your profile before requesting eligibility review.',
            ! $program->isAcceptingApplications() => 'Requests are available only while this program is accepting applications and has intake capacity.',
            ! $program->provider?->isActive() || ! $program->provider?->providerProfile?->isVerified() => 'This provider is not available for eligibility review.',
            ScholarshipApplication::where('scholarship_id', $program->id)->where('applicant_id', $applicant->id)->exists() => 'You already have an application for this program.',
            default => null,
        };
    }

    public function context(Scholarship $program, User $applicant): array
    {
        $raw = $this->raw($program, $applicant);
        $blockers = app(ScholarshipEligibilityService::class)->blockers($raw);
        $hash = $this->contextHash($program, $raw);
        $request = EligibilityReviewRequest::where('scholarship_id', $program->id)->where('applicant_id', $applicant->id)->latest('id')->first();
        $matches = $request && hash_equals($request->context_hash, $hash);
        $fixed = collect($blockers)->reject(fn ($row) => in_array($row['key'], $program->eligibility_review_rules ?? [], true))->values()->all();
        $reason = $this->applicantGate($program, $applicant);
        if (! $reason && $blockers === []) $reason = 'You have no confirmed required eligibility mismatch to reconsider.';
        if (! $reason && $fixed !== []) $reason = 'The provider does not allow exceptions for: '.collect($fixed)->pluck('label')->implode(', ').'.';
        if (! $reason && $matches) $reason = match ($request->status) {
            'approved' => 'Your exception was approved. You may apply while the intake is open and other requirements are met.',
            'declined' => 'The provider declined this request. Read the decision note or contact the provider for clarification.',
            default => 'Your request is waiting for provider review. You do not need to send it again.',
        };
        return ['can_request' => $reason === null, 'reason' => $reason, 'context_hash' => $hash, 'criteria' => $blockers,
            'request' => $request ? $this->payload($request, $applicant, $matches) : null,
            'endpoint' => "/dashboard/scholarships/{$program->id}/eligibility-review"];
    }

    public function apply(Scholarship $program, User $applicant, array $match): array
    {
        if (! $applicant->isApplicant() || empty($program->eligibility_review_rules)) return $match;
        $request = EligibilityReviewRequest::where('scholarship_id', $program->id)->where('applicant_id', $applicant->id)
            ->where('context_hash', $this->contextHash($program, $match))->where('status', 'approved')->latest('id')->first();
        if (! $request) return $match;
        $keys = array_column($request->criteria_snapshot, 'key');
        foreach ($match['criteria'] as &$row) {
            if (EligibilityRulePolicy::isBlocking($row) && in_array($row['key'], $keys, true)) {
                $row['exception_approved'] = true;
                $row['exception_request_id'] = $request->id;
                $row['exception_note'] = $request->decision_note;
                $row['is_blocking'] = false;
                $row['comparison'] .= ' The provider approved an exception to allow this application; this is not a matched rule or a selection decision.';
            }
        }
        unset($row);
        $match['blocking_criteria'] = app(ScholarshipEligibilityService::class)->blockers($match);
        $match['is_eligible'] = $match['blocking_criteria'] === [];
        if ($match['is_eligible']) {
            $match['difference_summary'] = 'The provider approved your eligibility exception. Your profile is unchanged; required documents, verification, deadlines and intake limits still apply. Selection is not guaranteed.';
            $match['guidance'] = ['state' => 'attention', 'label' => 'Exception approved', 'title' => 'You may apply with a provider-approved exception', 'description' => $match['difference_summary']];
        }
        return $match;
    }

    public function submit(Scholarship $program, User $applicant, array $input, ?UploadedFile $file): EligibilityReviewRequest
    {
        $path = null;
        try {
            return DB::transaction(function () use ($program, $applicant, $input, $file, &$path) {
                $program = Scholarship::whereKey($program->id)->lockForUpdate()->firstOrFail();
                $applicant = User::whereKey($applicant->id)->lockForUpdate()->firstOrFail();
                $context = $this->context($program, $applicant);
                if (! hash_equals($context['context_hash'], $input['context_hash'])) throw ValidationException::withMessages(['context_hash' => 'Your profile or the program rules changed. Reload the comparison before sending a request.']);
                if (! $context['can_request']) throw ValidationException::withMessages(['request' => $context['reason']]);
                if (EligibilityReviewRequest::where('scholarship_id', $program->id)->where('applicant_id', $applicant->id)->count() >= 10) throw ValidationException::withMessages(['request' => 'Contact the provider before sending another review request.']);
                if ($file) $path = $file->store("eligibility-reviews/{$applicant->id}", 'local');
                $record = EligibilityReviewRequest::create([
                    'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'context_hash' => $context['context_hash'],
                    'criteria_snapshot' => $context['criteria'], 'explanation' => trim($input['explanation']),
                    'evidence_path' => $path, 'evidence_name' => $file ? Str::limit(basename($file->getClientOriginalName()), 240, '') : null,
                    'evidence_mime' => $file?->getMimeType(), 'evidence_size' => $file?->getSize(), 'status' => 'pending',
                ]);
                User::where('role', 'provider')->where('account_status', 'active')
                    ->where(fn ($query) => $query->where('id', $program->provider_id)->orWhere('parent_account_id', $program->provider_id))
                    ->get()->filter(fn (User $actor) => $actor->hasPortalPermission('verify_applications') && $actor->canAccessProviderProgram($program))->each(function (User $actor) use ($program): void {
                    PortalNotification::create(['user_id' => $actor->id, 'type' => 'eligibility_review_requested', 'title' => 'Eligibility review requested', 'message' => "An applicant requested reconsideration of {$program->title} eligibility.", 'action_url' => '/provider/workspaces/reviews/eligibility-requests']);
                });
                ActivityLog::record($applicant, 'eligibility_review_requested', 'Applicant requested a program eligibility exception.', metadata: ['eligibility_review_id' => $record->id, 'scholarship_id' => $program->id]);
                return $record;
            });
        } catch (Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function decide(EligibilityReviewRequest $record, User $actor, array $input): EligibilityReviewRequest
    {
        return DB::transaction(function () use ($record, $actor, $input) {
            $program = Scholarship::whereKey($record->scholarship_id)->lockForUpdate()->firstOrFail();
            $applicant = User::whereKey($record->applicant_id)->lockForUpdate()->firstOrFail();
            $record = EligibilityReviewRequest::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProvider($actor, $program);
            abort_unless($record->status === 'pending', 409, 'This request has already been decided. Reload the queue.');
            if ($input['decision'] === 'approved') {
                $context = $this->context($program, $applicant);
                abort_unless(hash_equals($record->context_hash, $context['context_hash']) && hash_equals($input['context_hash'], $record->context_hash), 409, 'The eligibility information changed. The applicant must submit a new request.');
                if ($reason = $this->applicantGate($program, $applicant)) throw ValidationException::withMessages(['decision' => $reason]);
            }
            $record->update(['status' => $input['decision'], 'decision_note' => trim($input['decision_note']), 'decided_by' => $actor->id, 'decided_at' => now()]);
            PortalNotification::create(['user_id' => $applicant->id, 'type' => 'eligibility_review_decided', 'title' => $input['decision'] === 'approved' ? 'Eligibility exception approved' : 'Eligibility review decision', 'message' => "{$program->title}: open the eligibility section to read the provider decision. Approval does not guarantee selection.", 'action_url' => "/dashboard/scholarships/{$program->id}/eligibility"]);
            ActivityLog::record($actor, 'eligibility_review_decided', 'Provider recorded an eligibility exception decision.', metadata: ['eligibility_review_id' => $record->id, 'outcome' => $input['decision']]);
            return $record->fresh();
        });
    }

    public function authorizeProvider(User $actor, Scholarship $program): void
    {
        abort_unless($actor->isProvider() && $actor->isActive() && $actor->hasPortalPermission('verify_applications') && $actor->canAccessProviderProgram($program)
            && $actor->providerOrganizationOwner()->isActive() && $actor->providerOrganizationOwner()->hasVerifiedEmail() && $actor->providerOrganizationOwner()->providerProfile?->isVerified(), 403);
    }

    public function payload(EligibilityReviewRequest $record, User $viewer, ?bool $matches = null): array
    {
        $matches ??= hash_equals($record->context_hash, $this->contextHash($record->scholarship, $this->raw($record->scholarship, $record->applicant)));
        $prefix = $viewer->isApplicant() ? 'dashboard' : 'provider';
        return ['id' => $record->id, 'context_hash' => $record->context_hash, 'context_matches' => $matches,
            'status' => $record->status, 'status_label' => ! $matches && ! $record->consumed_at ? 'Information changed' : ['pending' => 'Awaiting provider review', 'approved' => 'Exception approved', 'declined' => 'Request declined'][$record->status],
            'criteria' => $record->criteria_snapshot, 'explanation' => $record->explanation, 'decision_note' => $record->decision_note,
            'submitted_at' => $record->created_at->format('M d, Y h:i A'), 'decided_at' => $record->decided_at?->format('M d, Y h:i A'),
            'applied' => $record->consumed_at !== null, 'reviewer' => $record->reviewer?->name,
            'evidence' => $record->evidence_path ? ['name' => $record->evidence_name, 'mime' => $record->evidence_mime, 'size' => $record->evidence_size, 'url' => "/{$prefix}/eligibility-reviews/{$record->id}/evidence"] : null];
    }

    public function attachToApplication(ScholarshipApplication $application, array $match): void
    {
        $ids = collect($match['criteria'])->pluck('exception_request_id')->filter()->unique();
        if ($ids->isEmpty()) return;
        EligibilityReviewRequest::whereIn('id', $ids)->where('scholarship_id', $application->scholarship_id)->where('applicant_id', $application->applicant_id)
            ->where('status', 'approved')->whereNull('consumed_at')->update(['scholarship_application_id' => $application->id, 'consumed_at' => now()]);
    }

    public function revalidateSubmission(Scholarship $program, User $applicant, array $match): array
    {
        if (! collect($match['criteria'] ?? [])->contains(fn ($row) => ! empty($row['exception_approved']))) return $match;
        $applicant = User::whereKey($applicant->id)->lockForUpdate()->firstOrFail();
        if ($reason = $this->applicantGate($program, $applicant)) throw ValidationException::withMessages(['eligibility_review' => $reason]);
        $current = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        if (! $current['is_eligible']) throw ValidationException::withMessages(['eligibility_review' => 'The reviewed information changed. Review your current eligibility before applying.']);
        return $current;
    }
}
