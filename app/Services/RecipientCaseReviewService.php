<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\PortalNotification;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseResponse;
use App\Models\RecipientCaseReview;
use App\Models\RecipientSupportDecision;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RecipientCaseReviewService
{
    public const RECOMMENDATIONS = [
        'no_support_change' => 'No support change recommended', 'correction_requested' => 'Correct the records',
        'support_plan' => 'Agree a support plan', 'provider_decision_review' => 'Provider may consider a support decision',
        'delivery_follow_up' => 'Follow up the reported delivery',
    ];

    public const FINDINGS = ['not_established' => 'Concern not established', 'excused' => 'Circumstances accepted / excused', 'confirmed' => 'Concern supported by evidence'];

    public const ADVERSE_REASONS = ['requirement_not_met', 'document_noncompliance', 'misrepresentation'];

    public const WITHDRAWAL_REQUEST_REQUIRED = 'Approve a withdrawal request from the recipient in Scholarship withdrawal instead.';

    public function __construct(private readonly RecipientAgreementCaseAccess $access, private readonly RecipientCaseEvidenceStore $files, private readonly RecipientCaseHistory $history) {}

    public function authorizeAdmin(User $actor): void
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isActive() && $actor->isAdmin() && $actor->hasPortalPermission('manage_reviews'), 403);
        if ($actor->isManagedAccount()) {
            $owner = $actor->parentAccount()->first();
            abort_unless($owner?->isAdmin() && $owner->isActive(), 403);
        }
    }

    public function authorize(User $actor, RecipientAgreementCase $case): void
    {
        $this->access->authorizeView($actor, $case);
        abort_unless($case->notice_sent_at && ! in_array($case->status, ['draft', 'cancelled'], true), 403);
    }

    public function request(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        return $this->command($actor, $case, $revision, 'review_requested', $input, $files, function ($case, $actor): void {
            abort_if($actor->isAdmin(), 403);
            abort_if($case->review_state !== null || $case->status === 'closed', 409, 'A review already exists. Open the current review or appeal its outcome.');
            if ($case->case_type === Catalog::AGREEMENT_CONCERN && ! $case->response_received_at && ! $case->responseDeadlinePassed()) {
                throw ValidationException::withMessages(['details' => 'Allow the applicant to respond or wait until the recorded response deadline has passed. Silence is not a finding.']);
            }
            $case->forceFill(['review_state' => 'requested', 'escalated_at' => now()]);
        });
    }

    public function statement(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        return $this->command($actor, $case, $revision, 'review_statement', $input, $files, function ($case, $actor): void {
            abort_if($actor->isAdmin(), 403);
            abort_unless(in_array($case->review_state, ['requested', 'awaiting_information', 'appeal_pending'], true), 409, 'There is no open review. Appeal the recorded outcome to submit new information.');
            $case->forceFill(['review_state' => $case->review_state === 'appeal_pending' ? 'appeal_pending' : 'requested']);
        });
    }

    public function information(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeAdmin($actor);

        return $this->command($actor, $case, $revision, 'review_information_requested', $input, $files, function ($case, $actor): void {
            $this->assertReviewer($actor, $case);
            abort_unless(in_array($case->review_state, ['requested', 'awaiting_information', 'appeal_pending'], true), 409);
            // Preserve appeal state so a request for information cannot bypass independence.
            if ($case->review_state !== 'appeal_pending') {
                $case->forceFill(['review_state' => 'awaiting_information']);
            }
        });
    }

    public function outcome(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeAdmin($actor);
        $data = Validator::make($input, [
            'finding' => ['required', Rule::in(array_keys(self::FINDINGS))],
            'recommendation' => ['required', Rule::in(array_keys(self::RECOMMENDATIONS))],
            'evidence_ids' => ['sometimes', 'array', 'max:20'], 'evidence_ids.*' => ['integer', 'distinct', 'min:1'],
            'terms_reviewed' => ['accepted'], 'responses_reviewed' => ['accepted'],
        ])->validate();

        return $this->command($actor, $case, $revision, 'review_outcome', [...$input, ...$data], $files, function ($case, $actor, $response, $sourceRevision) use ($data): void {
            $this->assertReviewer($actor, $case);
            abort_unless(in_array($case->review_state, ['requested', 'awaiting_information', 'appeal_pending'], true), 409, 'This review is not awaiting an outcome.');
            if (mb_strlen($response->body) < 30) {
                throw ValidationException::withMessages(['details' => 'Explain the evidence, response, and reasoning in at least 30 characters.']);
            }
            $ids = collect($data['evidence_ids'] ?? [])->map(fn ($id) => (int) $id)->merge($response->evidence()->pluck('id'))->unique()->values();
            $proof = $case->evidence()->whereIn('id', $ids)->whereDoesntHave('exclusion')->get();
            if ($proof->isEmpty() || $proof->count() !== $ids->count()) {
                throw ValidationException::withMessages(['evidence_ids' => 'Select or attach relevant proof from this case. Excluded or foreign files cannot be cited.']);
            }
            foreach ($proof as $file) {
                if (! Storage::disk('local')->exists($file->path) || ! hash_equals($file->sha256, hash('sha256', Storage::disk('local')->get($file->path)))) {
                    throw ValidationException::withMessages(['evidence_ids' => 'Cited proof is missing or changed. Request a new copy before recording a finding.']);
                }
            }
            $delivery = $case->case_type === Catalog::BENEFIT_DELIVERY;
            if ($delivery && ! in_array($data['recommendation'], ['no_support_change', 'delivery_follow_up'], true)) {
                throw ValidationException::withMessages(['recommendation' => 'Delivery reviews may recommend follow-up, not an applicant support penalty or receipt confirmation.']);
            }
            if (! $delivery && ! $case->response_received_at && $case->response_due_at?->isFuture()) {
                throw ValidationException::withMessages(['details' => 'The response opportunity is still open.']);
            }
            if (! $delivery && $data['recommendation'] === 'provider_decision_review') {
                if ($data['finding'] !== 'confirmed' || ! $this->acceptedConsequences($case)) {
                    throw ValidationException::withMessages(['recommendation' => 'A supported concern and a recorded consequence in the accepted agreement are required.']);
                }
            }
            if (in_array($data['finding'], ['not_established', 'excused'], true) && $data['recommendation'] === 'provider_decision_review') {
                throw ValidationException::withMessages(['recommendation' => 'An unestablished or excused concern cannot authorize an adverse support decision.']);
            }
            $review = new RecipientCaseReview;
            $review->forceFill([
                'recipient_agreement_case_id' => $case->id, 'response_id' => $response->id, 'reviewed_by' => $actor->id, 'reviewer_name' => $actor->name,
                'source_revision' => $sourceRevision, 'finding' => $data['finding'], 'recommendation' => $data['recommendation'], 'rationale' => $response->body,
                'evidence_ids' => $ids->all(), 'statement_ids' => $case->responses()->where('id', '!=', $response->id)->pluck('id')->all(),
                'is_appeal' => $case->review_state === 'appeal_pending', 'appeal_due_at' => now()->addDays(14), 'created_at' => now(),
            ])->save();
            $case->forceFill(['review_state' => 'reviewed', 'latest_review_id' => $review->id, 'finding' => $data['finding'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            if (! $delivery) {
                $case->forceFill(['status' => 'outcome_recorded', 'outcome' => $data['recommendation'], 'open_obligation_key' => null]);
                if ($review->is_appeal && $case->support_decision_id && $case->application->status === 'benefits_terminated') {
                    RecipientSupportDecision::whereKey($case->support_decision_id)->update(['response_status' => in_array($data['finding'], ['not_established', 'excused'], true) ? 'open' : 'resolved']);
                }
            }
        });
    }

    public function appeal(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        return $this->command($actor, $case, $revision, 'review_appeal', $input, $files, function ($case, $actor, $response): void {
            abort_if($actor->isAdmin(), 403);
            abort_unless($case->review_state === 'reviewed' && $case->latestReview, 409, 'Only a recorded review outcome can be appealed.');
            if ($case->latestReview->appeal_due_at->isPast() && mb_strlen($response->body) < 30) {
                throw ValidationException::withMessages(['details' => 'For a late appeal, explain the issue and the reason for the delay in at least 30 characters.']);
            }
            $case->forceFill(['review_state' => 'appeal_pending']);
            if ($case->case_type === Catalog::AGREEMENT_CONCERN) {
                $case->forceFill(['status' => 'appeal_pending']);
                if ($case->support_decision_id && $case->application->status === 'benefits_terminated') {
                    RecipientSupportDecision::whereKey($case->support_decision_id)->update(['response_status' => 'open']);
                }
            }
        });
    }

    public function acceptedConsequences(RecipientAgreementCase $case): ?string
    {
        $value = data_get($case->agreement_snapshot, 'recipient_expectation.noncompliance_consequence');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function authorizeSupportDecision(ScholarshipApplication $application, array $input): ?RecipientCaseReview
    {
        $category = $input['reason_category'] ?? '';
        if ($category === 'recipient_withdrew') {
            throw ValidationException::withMessages(['reason_category' => self::WITHDRAWAL_REQUEST_REQUIRED]);
        }
        if (($input['decision'] ?? '') !== 'terminated') {
            return null;
        }
        // Funding is not misconduct. "Other" must not bypass review.
        if ($category === 'provider_funding_ended') {
            return null;
        }
        $application = ScholarshipApplication::query()->findOrFail($application->id);
        $review = RecipientCaseReview::query()->find($input['agreement_case_review_id'] ?? null);
        $case = $review?->agreementCase;
        if ($application->student_response_status !== 'accepted' || ! $case || $case->scholarship_application_id !== $application->id || $case->case_type !== Catalog::AGREEMENT_CONCERN
            || $case->latest_review_id !== $review->id || $case->review_state !== 'reviewed' || $review->finding !== 'confirmed'
            || $review->recommendation !== 'provider_decision_review' || $review->appeal_due_at->isFuture() || ! $this->acceptedConsequences($case)
            || $case->support_decision_id) {
            throw ValidationException::withMessages(['agreement_case_review_id' => 'A current, evidence-backed admin review of this accepted agreement is required. Finish the response/appeal opportunity first; a pending appeal cannot authorize termination.']);
        }

        if ($case->agreement_snapshot !== $application->provider_contract_terms_snapshot
            || $case->agreement_version !== ($application->student_response_terms_version ?: $application->provider_contract_terms_version)) {
            throw ValidationException::withMessages(['agreement_case_review_id' => 'The accepted agreement record changed. Request a fresh review before a support decision.']);
        }
        $proofs = $case->evidence()->whereIn('id', $review->evidence_ids)->whereDoesntHave('exclusion')->get();
        if ($proofs->isEmpty() || $proofs->count() !== count($review->evidence_ids)
            || $proofs->contains(fn ($file) => ! Storage::disk('local')->exists($file->path) || ! hash_equals($file->sha256, hash('sha256', Storage::disk('local')->get($file->path))))) {
            throw ValidationException::withMessages(['agreement_case_review_id' => 'The review proof is missing or changed. Resolve the evidence issue before recording a support decision.']);
        }

        return $review;
    }

    public function eligibleReviews(ScholarshipApplication $application): array
    {
        return $application->recipientAgreementCases()->where('case_type', Catalog::AGREEMENT_CONCERN)->where('review_state', 'reviewed')
            ->whereNull('support_decision_id')->with('latestReview')->get()->filter(fn ($case) => $case->latestReview?->finding === 'confirmed'
                && $case->latestReview?->recommendation === 'provider_decision_review' && $case->latestReview?->appeal_due_at->isPast()
                && $this->acceptedConsequences($case))->map(fn ($case) => ['id' => $case->latest_review_id, 'label' => $case->subject])->values()->all();
    }

    public function canReinstate(RecipientSupportDecision $decision): bool
    {
        $case = $decision->agreementReview?->agreementCase;

        return $decision->decision === 'terminated' && $decision->resolution_outcome !== 'support_reinstated'
            && $case?->review_state === 'reviewed' && in_array($case->latestReview?->finding, ['not_established', 'excused'], true);
    }

    private function assertReviewer(User $actor, RecipientAgreementCase $case): void
    {
        $this->authorizeAdmin($actor);
        if ($case->opened_by === $actor->id || $case->application->applicant_id === $actor->id) {
            throw ValidationException::withMessages(['details' => 'An involved party cannot review its own case, even after an account role change.']);
        }
        if ($case->review_state === 'appeal_pending' && $case->latestReview?->reviewed_by === $actor->id) {
            throw ValidationException::withMessages(['details' => 'A different review officer must decide this appeal. Request another authorized reviewer.']);
        }
    }

    private function command(User $actor, RecipientAgreementCase $case, int $revision, string $kind, array $input, array $files, callable $apply): RecipientAgreementCase
    {
        $details = $input['details'] ?? null;
        if (is_string($details)) {
            $details = trim($details);
        }
        $data = Validator::make([...$input, 'details' => $details], ['request_key' => ['required', 'uuid'], 'details' => ['required', 'string', 'min:10', 'max:4000'], 'confirmed' => ['accepted']])->validate();
        $this->files->validate($files);
        $reviewData = $kind === 'review_outcome' ? [$input['finding'], $input['recommendation'], array_map('intval', $input['evidence_ids'] ?? [])] : [];
        $hash = hash('sha256', json_encode([$kind, $data['details'], $reviewData,
            array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files)], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $case, $revision, $kind, $data, $files, $hash, $apply, &$paths): RecipientAgreementCase {
                ScholarshipApplication::query()->lockForUpdate()->findOrFail($case->scholarship_application_id);
                $case = RecipientAgreementCase::query()->lockForUpdate()->findOrFail($case->id);
                $actor = User::query()->findOrFail($actor->id);
                $this->authorize($actor, $case);
                if ($previous = $case->responses()->where('request_key', strtolower($data['request_key']))->first()) {
                    abort_unless($previous->submitted_by === $actor->id && hash_equals($previous->request_hash, $hash), 409, 'This request key was used for different content.');

                    return $case;
                }
                abort_unless($case->revision === $revision, 409, 'This case changed. Reload before continuing.');
                $from = $case->status;
                $sourceRevision = $case->revision;
                $response = new RecipientAgreementCaseResponse;
                $response->forceFill(['recipient_agreement_case_id' => $case->id, 'case_revision' => $case->revision + 1, 'notice_revision' => 1,
                    'submitted_by' => $actor->id, 'author_name' => $actor->name, 'author_role' => $actor->role,
                    'response_kind' => $kind, 'body' => $data['details'], 'request_key' => strtolower($data['request_key']), 'request_hash' => $hash, 'created_at' => now()])->save();
                foreach ($files as $file) {
                    $this->files->store($actor, $case, $file, $paths, $response->id);
                }
                $apply($case, $actor, $response, $sourceRevision);
                $case->forceFill(['revision' => $sourceRevision + 1])->save();
                $this->history->record($case, $actor, $kind, $from, match ($kind) {
                    'review_requested' => 'An independent case review was requested. Support is unchanged.',
                    'review_outcome' => 'An administrator recorded a reasoned review outcome. No support or receipt change was applied.',
                    'review_appeal' => 'An appeal was submitted. The finding is under review; existing support is not automatically changed.',
                    'review_information_requested' => 'The reviewer requested more information without making a finding.',
                    default => 'Additional information was submitted for case review.',
                });
                $this->notify($case, $actor, $kind);

                return $case;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
    }

    private function notify(RecipientAgreementCase $case, User $actor, string $kind): void
    {
        $application = $case->application;
        $users = User::query()->whereIn('role', ['admin', 'provider'])->get()->filter(fn ($user) => $this->access->visibleTo($user)->whereKey($case->id)->exists())
            ->push($application->applicant)->unique('id')->filter(fn ($user) => $user->id !== $actor->id && $user->isActive());
        foreach ($users as $user) {
            PortalNotification::create([
                'user_id' => $user->id, 'type' => 'recipient_case_review', 'title' => $kind === 'review_appeal' ? 'Case review appealed' : 'Recipient case review updated',
                'message' => $application->scholarship->title.': '.$case->subject,
                'action_url' => $user->isAdmin() ? route('admin.recipient-cases.page', [], false)
                    : ($user->isProvider() ? ($user->hasPortalPermission('manage_recipients') ? route('provider.applications.show', $application, false).'#case-reviews'
                        : route('provider.workspaces.releases.delivery-reports', [], false).'?status=all')
                        : ($case->case_type === Catalog::AGREEMENT_CONCERN ? route('dashboard.applications.show.section', ['application' => $application, 'section' => 'outcome'], false).'#case-reviews'
                            : route('dashboard.monitoring.show.section', ['application' => $application, 'section' => 'benefits'], false).'#case-reviews')),
                'deduplication_key' => "case-review:{$case->id}:{$case->revision}:{$user->id}",
            ]);
        }
    }
}
