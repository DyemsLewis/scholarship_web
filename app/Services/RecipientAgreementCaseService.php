<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\PortalNotification;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\RecipientAgreementCaseResponse;
use App\Models\RecipientAgreementEvidenceExclusion;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class RecipientAgreementCaseService
{
    public function __construct(private readonly RecipientAgreementCaseAccess $access) {}

    public function createDraft(User $actor, ScholarshipApplication $application, string $type, array $input): RecipientAgreementCase
    {
        $application = ScholarshipApplication::query()->findOrFail($application->id);
        $this->access->authorizeCreate($actor, $application, $type);
        $data = $this->validateDraft($type, $input);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $application, $type, $data, $hash): RecipientAgreementCase {
            // Match release/withdrawal locking order, so a draft never observes a half-written award.
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->access->authorizeCreate($actor, $application, $type);
            $existing = $application->recipientAgreementCases()
                ->where('opened_by', $actor->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->case_type === $type && hash_equals($existing->request_hash, $hash), 409,
                    'This request key was already used for a different draft.');

                return $existing;
            }

            $this->assertAcceptedAgreement($application);
            $source = $type === Catalog::AGREEMENT_CONCERN
                ? $this->obligationSource($application, $data['term_key'])
                : $this->benefitSource($application, $data);

            $case = new RecipientAgreementCase;
            $case->forceFill([
                'scholarship_application_id' => $application->id,
                'opened_by' => $actor->id,
                'case_type' => $type,
                'status' => 'draft',
                'revision' => 1,
                'request_key' => $data['request_key'],
                'request_hash' => $hash,
                'subject' => $data['subject'],
                'details' => $data['details'],
                'agreement_snapshot' => $application->provider_contract_terms_snapshot,
                'agreement_version' => $application->student_response_terms_version ?: $application->provider_contract_terms_version,
                'agreement_accepted_at' => $application->provider_contract_terms_accepted_at,
                'finding' => 'not_assessed',
                'disclosure_status' => 'not_shared',
                ...$source,
            ])->save();
            $this->recordEvent($case, $actor, 'draft_created', null, 'A private draft was created.', [
                'subject' => $case->subject, 'details' => $case->details,
            ]);

            return $case;
        });
    }

    public function reviseDraft(User $actor, RecipientAgreementCase $case, int $revision, array $input): RecipientAgreementCase
    {
        $data = Validator::make($this->trimText($input), [
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'details' => ['required', 'string', 'min:10', 'max:4000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $case, $revision, $data): RecipientAgreementCase {
            $case = $this->lockedDraft($actor, $case, $revision);
            if ($case->subject === $data['subject'] && $case->details === $data['details']) {
                return $case;
            }

            $case->forceFill([...$data, 'revision' => $case->revision + 1])->save();
            $this->recordEvent($case, $actor, 'draft_revised', 'draft', 'The draft explanation was revised.', $data);

            return $case;
        });
    }

    public function cancelDraft(User $actor, RecipientAgreementCase $case, int $revision): RecipientAgreementCase
    {
        return DB::transaction(function () use ($actor, $case, $revision): RecipientAgreementCase {
            $case = $this->lockedDraft($actor, $case, $revision);
            $case->forceFill(['status' => 'cancelled', 'revision' => $case->revision + 1, 'closed_at' => now()])->save();
            $this->recordEvent($case, $actor, 'draft_cancelled', 'draft', 'The author cancelled this unissued draft.');

            return $case;
        });
    }

    public function attachDraftEvidence(User $actor, RecipientAgreementCase $case, int $revision, UploadedFile $file): RecipientAgreementCaseEvidence
    {
        Validator::make(['evidence' => $file], [
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ])->validate();
        $paths = [];

        try {
            return DB::transaction(function () use ($actor, $case, $revision, $file, &$paths): RecipientAgreementCaseEvidence {
                $case = $this->lockedDraft($actor, $case, $revision);
                if ($case->evidence()->whereDoesntHave('exclusion')->count() >= 5) {
                    throw ValidationException::withMessages(['evidence' => 'A draft can contain at most five supporting files.']);
                }

                $evidence = $this->storeEvidence($actor, $case, $file, $paths);
                $case->forceFill(['revision' => $case->revision + 1])->save();
                $this->recordEvent($case, $actor, 'evidence_added', 'draft', 'A private supporting file was added.', [
                    'evidence_id' => $evidence->id, 'original_name' => $evidence->original_name,
                ]);

                return $evidence;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);

            throw $error;
        }
    }

    public function evidenceResponse(User $actor, RecipientAgreementCaseEvidence $evidence): StreamedResponse
    {
        $evidence = RecipientAgreementCaseEvidence::query()->findOrFail($evidence->id);
        $this->access->authorizeView($actor, $evidence->agreementCase);
        abort_if($evidence->exclusion()->exists(), 403);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        return Storage::disk('local')->response($evidence->path, $evidence->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function excludeDraftEvidence(User $actor, RecipientAgreementCase $case, int $revision, RecipientAgreementCaseEvidence $evidence): RecipientAgreementCase
    {
        return DB::transaction(function () use ($actor, $case, $revision, $evidence): RecipientAgreementCase {
            $case = $this->lockedDraft($actor, $case, $revision);
            $evidence = $case->evidence()->whereNull('response_id')->findOrFail($evidence->id);
            abort_if($evidence->exclusion()->exists(), 409, 'This attachment was already removed. Reload the draft.');
            $exclusion = new RecipientAgreementEvidenceExclusion;
            $exclusion->forceFill(['evidence_id' => $evidence->id, 'excluded_by' => $actor->id, 'created_at' => now()])->save();
            $case->forceFill(['revision' => $case->revision + 1])->save();
            $this->recordEvent($case, $actor, 'evidence_excluded', 'draft', 'An attachment was removed from the unissued draft.', ['evidence_id' => $evidence->id]);

            return $case;
        });
    }

    public function concernOptions(ScholarshipApplication $application): array
    {
        try {
            $this->assertAcceptedAgreement($application);
            $options = [];
            foreach (Catalog::OBLIGATION_LABELS as $key => $label) {
                $text = data_get($application->provider_contract_terms_snapshot, $key);
                if (is_string($text) && trim($text) !== '') {
                    $this->obligationSource($application, $key);
                    $options[] = ['key' => $key, 'label' => $label, 'text' => $text];
                }
            }

            return $options;
        } catch (ValidationException) {
            return [];
        }
    }

    public function issueConcern(User $actor, RecipientAgreementCase $case, int $revision, array $input): RecipientAgreementCase
    {
        $data = Validator::make($input, [
            'response_days' => ['required', 'integer', 'between:3,30'], 'confirmed' => ['accepted'],
        ])->validate();

        return DB::transaction(function () use ($actor, $case, $revision, $data): RecipientAgreementCase {
            $case = $this->lockedDraft($actor, $case, $revision);
            abort_unless($case->case_type === Catalog::AGREEMENT_CONCERN, 403);
            $application = $case->application;
            $this->assertAcceptedAgreement($application);
            $source = $this->obligationSource($application, $case->term_key);
            abort_unless($case->agreement_snapshot === $application->provider_contract_terms_snapshot
                && $source['term_text'] === $case->term_text
                && $case->agreement_version === ($application->student_response_terms_version ?: $application->provider_contract_terms_version),
                409, 'The accepted agreement record changed. Cancel this draft and request a record review.');
            $key = hash('sha256', $application->id.':'.$case->term_key);
            abort_if(RecipientAgreementCase::query()->where('open_obligation_key', $key)->exists(), 409,
                'An issued concern for this obligation already needs review. Open the existing concern instead.');
            $evidence = $case->evidence()->whereNull('response_id')->whereDoesntHave('exclusion')->get();
            if ($evidence->isEmpty()) {
                throw ValidationException::withMessages(['evidence' => 'Add at least one supporting PDF or image before issuing the notice.']);
            }
            foreach ($evidence as $file) {
                if (! Storage::disk('local')->exists($file->path)
                    || ! hash_equals($file->sha256, hash('sha256', Storage::disk('local')->get($file->path)))) {
                    throw ValidationException::withMessages(['evidence' => 'A supporting file is missing or changed. Remove it and upload the correct file.']);
                }
            }
            $now = CarbonImmutable::now();
            $case->forceFill([
                'status' => 'awaiting_response', 'revision' => $case->revision + 1,
                'open_obligation_key' => $key, 'notice_sent_at' => $now,
                'response_due_at' => $now->addDays((int) $data['response_days']),
            ])->save();
            $this->recordEvent($case, $actor, 'notice_issued', 'draft', 'A concern notice was issued. No finding or support change was made.');
            PortalNotification::create([
                'user_id' => $application->applicant_id, 'type' => 'recipient_agreement_concern',
                'title' => 'A scholarship agreement concern needs your response',
                'message' => $application->scholarship->title.': review the accepted term, supporting files, and response deadline.',
                'action_url' => route('dashboard.applications.show.section', ['application' => $application, 'section' => 'outcome'], false).'#agreement-concerns',
                'deduplication_key' => "agreement-concern:{$case->id}:{$case->revision}:applicant",
            ]);

            return $case;
        });
    }

    public function respondToConcern(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeConcernResponse($actor, $case);
        $data = Validator::make($this->trimText($input), [
            'request_key' => ['required', 'uuid'], 'response_kind' => ['required', Rule::in(array_keys(Catalog::RESPONSE_KINDS))],
            'details' => ['required', 'string', 'min:10', 'max:4000'], 'confirmed' => ['accepted'],
        ])->validate();
        Validator::make(['evidence' => $files], [
            'evidence' => ['array', 'max:3'], 'evidence.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ])->validate();
        $hash = hash('sha256', json_encode([
            $data['response_kind'], $data['details'],
            array_map(fn (UploadedFile $file): array => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files),
        ], JSON_THROW_ON_ERROR));
        $paths = [];

        try {
            return DB::transaction(function () use ($actor, $case, $revision, $data, $hash, $files, &$paths): RecipientAgreementCase {
                $applicationId = RecipientAgreementCase::query()->whereKey($case->id)->value('scholarship_application_id');
                ScholarshipApplication::query()->lockForUpdate()->findOrFail($applicationId);
                $case = RecipientAgreementCase::query()->lockForUpdate()->findOrFail($case->id);
                $this->authorizeConcernResponse($actor, $case);
                $existing = $case->responses()->where('request_key', strtolower($data['request_key']))->first();
                if ($existing) {
                    abort_unless($existing->submitted_by === $actor->id && hash_equals($existing->request_hash, $hash), 409,
                        'This response key was already used for different content.');

                    return $case;
                }
                abort_unless($case->revision === $revision && $case->status === 'awaiting_response', 409,
                    'This concern changed or already has a response. Reload it before continuing.');
                $noticeRevision = $case->events()->where('action', 'notice_issued')->value('revision');
                abort_unless($noticeRevision, 409, 'The issued notice history is unavailable. Request a record review.');
                $actor = User::query()->findOrFail($actor->id);
                $response = new RecipientAgreementCaseResponse;
                $response->forceFill([
                    'recipient_agreement_case_id' => $case->id, 'case_revision' => $case->revision + 1,
                    'notice_revision' => $noticeRevision, 'submitted_by' => $actor->id,
                    'author_name' => $actor->name, 'author_role' => $actor->role, 'body' => $data['details'],
                    'response_kind' => $data['response_kind'], 'request_key' => strtolower($data['request_key']), 'request_hash' => $hash,
                    'created_at' => now(),
                ])->save();
                foreach ($files as $file) {
                    $this->storeEvidence($actor, $case, $file, $paths, $response->id);
                }
                $late = $case->responseDeadlinePassed();
                $case->forceFill(['status' => 'under_review', 'revision' => $case->revision + 1, 'response_received_at' => now()])->save();
                $this->recordEvent($case, $actor, 'response_submitted', 'awaiting_response', 'The applicant submitted a response for review.', [
                    'response_id' => $response->id, 'late' => $late,
                ]);
                $this->notifyRecipientOfficers($case);

                return $case;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
    }

    private function authorizeConcernResponse(User $actor, RecipientAgreementCase $case): void
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isActive() && $actor->isApplicant()
            && $case->case_type === Catalog::AGREEMENT_CONCERN
            && $case->application->applicant_id === $actor->id && $case->notice_sent_at !== null, 403);
    }

    private function storeEvidence(User $actor, RecipientAgreementCase $case, UploadedFile $file, array &$paths, ?int $responseId = null): RecipientAgreementCaseEvidence
    {
        return app(RecipientCaseEvidenceStore::class)->store($actor, $case, $file, $paths, $responseId);
    }

    private function notifyRecipientOfficers(RecipientAgreementCase $case): void
    {
        $application = $case->application;
        $officers = User::query()->where('role', 'provider')->where(fn ($q) => $q
            ->whereKey($application->scholarship->provider_id)->orWhere('parent_account_id', $application->scholarship->provider_id))->get();
        foreach ($officers as $officer) {
            if (! app(RecipientAgreementCaseAccess::class)->visibleTo($officer)->whereKey($case->id)->exists()) {
                continue;
            }
            PortalNotification::create([
                'user_id' => $officer->id, 'type' => 'recipient_agreement_concern_response',
                'title' => 'An applicant responded to an agreement concern',
                'message' => $application->applicant->name.' submitted a response for '.$application->scholarship->title.'.',
                'action_url' => route('provider.workspaces.recipients.concerns', [], false),
                'deduplication_key' => "agreement-concern:{$case->id}:{$case->revision}:officer:{$officer->id}",
            ]);
        }
    }

    private function validateDraft(string $type, array $input): array
    {
        $rules = [
            'request_key' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'details' => ['required', 'string', 'min:10', 'max:4000'],
        ];
        $rules += $type === Catalog::AGREEMENT_CONCERN ? [
            'term_key' => ['required', Rule::in(Catalog::OBLIGATION_KEYS)],
        ] : [
            'benefit_entitlement_id' => ['required_without:benefit_release_record_id', 'nullable', 'integer', 'min:1'],
            'benefit_release_record_id' => ['required_without:benefit_entitlement_id', 'nullable', 'integer', 'min:1'],
        ];

        $data = Validator::make($this->trimText($input), $rules)->validate();
        // A fixed order and ID types make safe retries independent of JSON key order.
        $normalized = [
            'request_key' => strtolower($data['request_key']), 'subject' => $data['subject'], 'details' => $data['details'],
        ];

        return $type === Catalog::AGREEMENT_CONCERN
            ? [...$normalized, 'term_key' => $data['term_key']]
            : [...$normalized,
                'benefit_entitlement_id' => filled($data['benefit_entitlement_id'] ?? null) ? (int) $data['benefit_entitlement_id'] : null,
                'benefit_release_record_id' => filled($data['benefit_release_record_id'] ?? null) ? (int) $data['benefit_release_record_id'] : null,
            ];
    }

    private function trimText(array $input): array
    {
        foreach (['subject', 'details'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        return $input;
    }

    private function assertAcceptedAgreement(ScholarshipApplication $application): void
    {
        if ($application->final_outcome !== 'selected' || $application->student_response_status !== 'accepted'
            || ! $application->provider_contract_terms_accepted_at
            || ! filled($application->student_response_terms_version ?: $application->provider_contract_terms_version)
            || ! is_array($application->provider_contract_terms_snapshot) || $application->provider_contract_terms_snapshot === []) {
            throw ValidationException::withMessages(['agreement' => 'A recorded, accepted scholarship agreement is required.']);
        }
        if (filled($application->student_response_terms_version) && filled($application->provider_contract_terms_version)
            && $application->student_response_terms_version !== $application->provider_contract_terms_version) {
            throw ValidationException::withMessages(['agreement' => 'The saved terms do not match the accepted agreement version. Request a record review.']);
        }
    }

    private function obligationSource(ScholarshipApplication $application, string $key): array
    {
        if (! in_array($application->status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true)
            || in_array($application->supportDecisions()->first()?->decision, ['completed', 'terminated'], true)) {
            throw ValidationException::withMessages(['agreement' => 'An agreement concern requires active scholarship support.']);
        }

        $text = data_get($application->provider_contract_terms_snapshot, $key);
        if (! is_string($text) || trim($text) === '') {
            throw ValidationException::withMessages(['term_key' => 'Choose an obligation recorded in the accepted agreement.']);
        }

        return ['term_key' => $key, 'term_text' => $text];
    }

    private function benefitSource(ScholarshipApplication $application, array $data): array
    {
        $entitlement = isset($data['benefit_entitlement_id'])
            ? RecipientBenefitEntitlement::query()->where('scholarship_application_id', $application->id)
                ->where('applicant_id', $application->applicant_id)->where('scholarship_id', $application->scholarship_id)
                ->find($data['benefit_entitlement_id']) : null;
        $record = isset($data['benefit_release_record_id'])
            ? RecipientBenefitReleaseRecord::query()->with('release')->where('scholarship_application_id', $application->id)
                ->where('applicant_id', $application->applicant_id)
                ->whereHas('release', fn ($q) => $q->where('scholarship_id', $application->scholarship_id)->whereNotNull('published_at'))
                ->find($data['benefit_release_record_id']) : null;

        if ((isset($data['benefit_entitlement_id']) && ! $entitlement)
            || (isset($data['benefit_release_record_id']) && ! $record)) {
            throw ValidationException::withMessages(['benefit' => 'Choose a benefit belonging to this scholarship award.']);
        }
        if ($entitlement && $record && ! $record->allocations()->where('recipient_benefit_entitlement_id', $entitlement->id)->exists()) {
            throw ValidationException::withMessages(['benefit' => 'This release does not include the selected benefit.']);
        }

        return [
            'benefit_entitlement_id' => $entitlement?->id,
            'benefit_release_record_id' => $record?->id,
            'benefit_snapshot' => [
                'entitlement' => $entitlement?->only(['title', 'benefit_type', 'amount', 'coverage', 'frequency', 'duration', 'description']),
                'release' => $record ? [
                    'title' => $record->release->title, 'benefit_description' => $record->release->benefit_description,
                    'amount' => $record->release->amount, 'scheduled_at' => $record->release->release_at?->toIso8601String(),
                ] : null,
            ],
        ];
    }

    private function lockedDraft(User $actor, RecipientAgreementCase $case, int $revision): RecipientAgreementCase
    {
        $applicationId = RecipientAgreementCase::query()->whereKey($case->id)->value('scholarship_application_id');
        ScholarshipApplication::query()->lockForUpdate()->findOrFail($applicationId);
        $case = RecipientAgreementCase::query()->lockForUpdate()->findOrFail($case->id);
        $this->access->authorizeDraft($actor, $case);
        abort_unless($revision === $case->revision, 409, 'This draft has changed. Reload it before continuing.');

        return $case;
    }

    private function recordEvent(RecipientAgreementCase $case, User $actor, string $action, ?string $from, string $note, ?array $payload = null): void
    {
        app(RecipientCaseHistory::class)->record($case, $actor, $action, $from, $note, $payload);
    }
}
