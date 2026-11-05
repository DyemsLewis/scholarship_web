<?php

namespace App\Services;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientCasePrivacyReview;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecipientCasePrivacyService
{
    public const ASSESSMENTS = ['blocked' => 'Do not disclose', 'policy_review_needed' => 'Further privacy review needed', 'not_necessary' => 'Disclosure is not necessary'];

    public const AUDIENCES = ['originating_award' => 'Originating award only', 'proposed_other_provider' => 'Proposed other-provider disclosure (not enabled)'];

    public const AUDIT_RETENTION_DAYS = 90;

    public function __construct(private readonly RecipientAgreementCaseAccess $access, private readonly RecipientCaseHistory $history) {}

    public function authorize(User $actor, RecipientAgreementCase $case): void
    {
        $this->access->authorizeView($actor, $case);
        abort_unless($case->notice_sent_at && ! in_array($case->status, ['draft', 'cancelled'], true), 403);
    }

    public function blockers(RecipientAgreementCase $case): array
    {
        $case->loadMissing('latestReview');
        $result = ['External sharing has not been activated after an independent privacy/policy review.'];
        if ($case->case_type !== 'agreement_concern') {
            $result[] = 'A delivery report must not become a warning against an applicant.';
        }
        if ($case->review_state !== 'reviewed' || ! $case->latestReview) {
            $result[] = 'There is no current completed independent case review.';
        }
        if ($case->finding !== 'confirmed' || $case->latestReview?->finding !== 'confirmed') {
            $result[] = 'An unestablished or excused concern is not an adverse disclosure candidate.';
        }
        if ($case->review_state === 'appeal_pending') {
            $result[] = 'The outcome is under appeal; any earlier assessment is superseded.';
        }
        if ($case->latestReview?->appeal_due_at->isFuture()) {
            $result[] = 'The recorded appeal opportunity remains open.';
        }

        return $result;
    }

    public function record(User $actor, RecipientAgreementCase $case, int $revision, array $input): RecipientCasePrivacyReview
    {
        app(RecipientCaseReviewService::class)->authorizeAdmin($actor);
        foreach (['purpose', 'basis_reference', 'rationale'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'], 'assessment' => ['required', Rule::in(array_keys(self::ASSESSMENTS))],
            'audience' => ['required', Rule::in(array_keys(self::AUDIENCES))], 'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'basis_reference' => ['nullable', 'string', 'max:500'], 'rationale' => ['required', 'string', 'min:30', 'max:2000'],
            'audit_retention_hold' => ['required', 'boolean'], 'recheck_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'confirmed' => ['accepted'],
        ])->validate();
        $data['request_key'] = strtolower($data['request_key']);
        $data['audit_retention_hold'] = (bool) $data['audit_retention_hold'];
        $hash = hash('sha256', json_encode(array_diff_key($data, ['confirmed' => true]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $case, $revision, $data, $hash): RecipientCasePrivacyReview {
            ScholarshipApplication::query()->lockForUpdate()->findOrFail($case->scholarship_application_id);
            $case = RecipientAgreementCase::query()->lockForUpdate()->findOrFail($case->id);
            $actor = User::query()->findOrFail($actor->id);
            app(RecipientCaseReviewService::class)->authorizeAdmin($actor);
            $this->authorize($actor, $case);
            if ($case->opened_by === $actor->id || $case->application->applicant_id === $actor->id) {
                throw ValidationException::withMessages(['rationale' => 'An involved party cannot record its own privacy assessment.']);
            }
            if ($existing = $case->privacyReviews()->where('request_key', $data['request_key'])->first()) {
                abort_unless($existing->recorded_by === $actor->id && hash_equals($existing->request_hash, $hash), 409, 'This request key was used for a different privacy assessment.');

                return $existing;
            }
            abort_unless($case->revision === $revision, 409, 'The case changed. Reload and review the current record.');
            $assessment = new RecipientCasePrivacyReview;
            $assessment->forceFill([
                'recipient_agreement_case_id' => $case->id, 'recorded_by' => $actor->id, 'recorder_name' => $actor->name,
                'request_key' => $data['request_key'], 'request_hash' => $hash,
                'source_revision' => $case->revision, 'case_revision' => $case->revision + 1,
                'assessment' => $data['assessment'], 'audience' => $data['audience'], 'purpose' => $data['purpose'],
                'basis_reference' => $data['basis_reference'] ?? null, 'rationale' => $data['rationale'],
                'blockers' => $this->blockers($case), 'audit_retention_hold' => $data['audit_retention_hold'],
                'recheck_on' => $data['recheck_on'], 'created_at' => now(),
            ])->save();
            $from = $case->status;
            $case->forceFill(['revision' => $case->revision + 1])->save();
            $this->history->record($case, $actor, 'privacy_assessment_recorded', $from, 'An internal privacy assessment was recorded. External sharing remains disabled; support and findings are unchanged.');

            return $assessment;
        });
    }

    public function payload(User $actor, RecipientAgreementCase $case, int $page = 1): array
    {
        $this->authorize($actor, $case);
        $case = $case->fresh();
        $assessment = $case->privacyReviews()->first();
        $state = ! $assessment ? 'not_recorded' : ($assessment->case_revision !== $case->revision ? 'superseded' : ($assessment->recheck_on->endOfDay()->isPast() ? 'recheck_due' : 'recorded'));
        $rows = $case->privacyReviews()->paginate(5, ['*'], 'page', $page);
        $rows->through(fn ($item) => [
            'id' => $item->id, 'label' => self::ASSESSMENTS[$item->assessment], 'audience' => self::AUDIENCES[$item->audience],
            ...($actor->isAdmin() ? ['purpose' => $item->purpose, 'basis_reference' => $item->basis_reference, 'rationale' => $item->rationale,
                'blockers' => $item->blockers, 'recorder_name' => $item->recorder_name] : []),
            'created_at' => $item->created_at?->toIso8601String(),
            'source_revision' => $item->source_revision, 'recheck_on' => $item->recheck_on->toDateString(), 'audit_retention_hold' => $item->audit_retention_hold,
        ]);

        return [
            'sharing_enabled' => false, 'sharing_label' => 'Not shared with other providers', 'shared_fields' => [],
            'revision' => $case->revision, 'assessment_state' => $state,
            'assessment_state_label' => match ($state) {
                'not_recorded' => 'No internal assessment', 'superseded' => 'Case changed; assessment superseded', 'recheck_due' => 'Assessment needs rechecking', default => 'Internal assessment recorded'
            },
            'blockers' => $this->blockers($case), 'audit_retention_days' => self::AUDIT_RETENTION_DAYS,
            'audit_retention_hold' => (bool) $assessment?->audit_retention_hold,
            'can_record' => $actor->isAdmin() && $case->opened_by !== $actor->id && $case->application->applicant_id !== $actor->id,
            'record_url' => $actor->isAdmin() ? route('admin.recipient-case-privacy.record', $case, false) : null,
            'assessments' => $rows,
            'notice' => 'An internal finding, privacy note, or consent checkbox does not grant external access. No blacklist, automatic rejection, public flag, or case-document export is created.',
        ];
    }
}
