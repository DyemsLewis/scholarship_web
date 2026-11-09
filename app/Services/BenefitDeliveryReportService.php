<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\PortalNotification;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\RecipientAgreementCaseResponse;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BenefitDeliveryReportService
{
    public const OPEN = ['awaiting_provider', 'awaiting_recipient'];

    public function __construct(private readonly RecipientAgreementCaseAccess $access, private readonly RecipientCaseEvidenceStore $files, private readonly RecipientCaseHistory $history) {}

    public function authorizeApplication(User $actor, ScholarshipApplication $application): void
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isActive(), 403);
        if ($actor->isApplicant()) {
            abort_unless($application->applicant_id === $actor->id, 403);

            return;
        }
        $application->load('scholarship');
        abort_unless($actor->isProvider() && $actor->hasPortalPermission('manage_benefit_releases')
            && $actor->canAccessProviderProgram($application->scholarship)
            && $actor->providerOrganizationOwner()->isActive()
            && $actor->providerOrganizationOwner()->providerProfile?->verification_status === 'approved', 403);
    }

    public function records(ScholarshipApplication $application): Builder
    {
        return RecipientBenefitReleaseRecord::query()->where('scholarship_application_id', $application->id)->where('applicant_id', $application->applicant_id)
            ->whereHas('release', fn ($q) => $q->where('scholarship_id', $application->scholarship_id))
            ->where(fn ($q) => $q->where('status', 'released')->orWhereHas('release', fn ($r) => $r->whereNotNull('published_at')));
    }

    public function openForRecord(RecipientBenefitReleaseRecord $record, ?Collection $oneTimeEntitlementIds = null): Builder
    {
        $ids = $oneTimeEntitlementIds ?? $record->allocations()->whereHas('entitlement', fn ($e) => $e->where('frequency', 'one_time'))->pluck('recipient_benefit_entitlement_id');

        return RecipientAgreementCase::query()->where('case_type', Catalog::BENEFIT_DELIVERY)
            ->where('scholarship_application_id', $record->scholarship_application_id)->whereIn('status', self::OPEN)
            ->where(fn ($q) => $q->where('benefit_release_record_id', $record->id)->orWhere('delivery_record_id', $record->id)->orWhereIn('benefit_entitlement_id', $ids)
                ->orWhereHas('releaseRecord.allocations', fn ($a) => $a->whereIn('recipient_benefit_entitlement_id', $ids)));
    }

    public function openForEntitlement(RecipientBenefitEntitlement $entitlement): Builder
    {
        return RecipientAgreementCase::query()->where('case_type', Catalog::BENEFIT_DELIVERY)
            ->where('scholarship_application_id', $entitlement->scholarship_application_id)->whereIn('status', self::OPEN)
            ->where(fn ($q) => $q->where('benefit_entitlement_id', $entitlement->id)
                ->orWhereHas('releaseRecord.allocations', fn ($a) => $a->where('recipient_benefit_entitlement_id', $entitlement->id)));
    }

    /** @return Collection<int, bool> */
    public function openReportsByReleaseRecord(RecipientBenefitRelease $release): Collection
    {
        $release->loadMissing('records.allocations.entitlement');

        return $release->records->mapWithKeys(fn (RecipientBenefitReleaseRecord $record): array => [
            $record->id => $this->openForRecord(
                $record,
                $record->allocations->where('entitlement.frequency', 'one_time')->pluck('recipient_benefit_entitlement_id'),
            )->exists(),
        ]);
    }

    public function openIssueCount(RecipientBenefitRelease $release, Collection $deliveryReports): int
    {
        return $release->records->filter(fn (RecipientBenefitReleaseRecord $record): bool =>
            $record->receiptResponse?->status === 'open' || $deliveryReports->get($record->id, false),
        )->count();
    }

    public function options(ScholarshipApplication $application): array
    {
        $options = [];
        foreach ($this->records($application)->with(['release', 'receiptResponse'])->get() as $record) {
            if ($record->receiptResponse || $this->openForRecord($record)->exists()) {
                continue;
            }
            $options[] = ['type' => 'release', 'id' => $record->id, 'title' => $record->release->title,
                'description' => $record->release->benefit_description, 'scheduled_at' => $record->release->release_at?->toIso8601String(), 'period_required' => false];
        }
        if ($application->student_response_status === 'accepted') {
            foreach ($application->benefitEntitlements()->where('applicant_id', $application->applicant_id)
                ->where('scholarship_id', $application->scholarship_id)->where('status', '!=', 'fulfilled')->get() as $entitlement) {
                if ($entitlement->isOneTime() && $entitlement->allocations()->whereHas('releaseRecord.release', fn ($r) => $r->whereNotNull('published_at'))->exists()) {
                    continue;
                }
                if ($entitlement->isOneTime() && $this->openForEntitlement($entitlement)->exists()) {
                    continue;
                }
                $options[] = ['type' => 'entitlement', 'id' => $entitlement->id, 'title' => $entitlement->title,
                    'description' => $entitlement->description, 'scheduled_at' => null, 'period_required' => ! $entitlement->isOneTime()];
            }
        }

        return $options;
    }

    public function submit(User $actor, ScholarshipApplication $application, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeApplication($actor, $application);
        abort_unless($actor->isApplicant(), 403);
        $input = $this->trim($input);
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'], 'source_type' => ['required', Rule::in(['release', 'entitlement'])],
            'source_id' => ['required', 'integer', 'min:1'], 'issue_kind' => ['required', Rule::in(array_keys(Catalog::DELIVERY_ISSUES))],
            'details' => ['required', 'string', 'min:10', 'max:4000'], 'benefit_period' => ['nullable', 'string', 'max:120'], 'confirmed' => ['accepted'],
        ])->validate();
        $this->files->validate($files);
        $hash = $this->fingerprint([$data['source_type'], (int) $data['source_id'], $data['issue_kind'], $data['details'], $data['benefit_period'] ?? null], $files);
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $application, $data, $files, $hash, &$paths): RecipientAgreementCase {
                $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
                $this->authorizeApplication($actor, $application);
                $existing = $application->recipientAgreementCases()->where('opened_by', $actor->id)->where('request_key', strtolower($data['request_key']))->first();
                if ($existing) {
                    abort_unless($existing->case_type === Catalog::BENEFIT_DELIVERY && hash_equals($existing->request_hash, $hash), 409, 'This request key was used for different content.');

                    return $existing;
                }
                [$record, $entitlement] = $this->source($application, $data);
                if ($record && ($record->receiptResponse || $this->openForRecord($record)->exists())) {
                    throw ValidationException::withMessages(['source_id' => 'A receipt response or delivery report already exists. Open that report instead.']);
                }
                $period = $entitlement && ! $entitlement->isOneTime() ? Str::squish($data['benefit_period'] ?? '') : null;
                if ($entitlement && ! $entitlement->isOneTime() && ! $period) {
                    throw ValidationException::withMessages(['benefit_period' => 'Name the month, semester, or benefit period you are reporting.']);
                }
                if ($record?->release->release_at?->isFuture() && $data['issue_kind'] === 'not_received') {
                    throw ValidationException::withMessages(['issue_kind' => 'This release is not due yet. Choose Ask about delivery if you need clarification.']);
                }
                $key = hash('sha256', $application->id.':'.($record ? 'release:'.$record->id : 'entitlement:'.$entitlement->id.':'.Str::lower($period ?? 'one-time')));
                abort_if(RecipientAgreementCase::query()->where('open_delivery_key', $key)->exists(), 409, 'A report for this benefit and period is already open.');
                $case = $this->newCase($actor, $application, $record, $entitlement, $data['issue_kind'], $data['details'], strtolower($data['request_key']), $hash, $period);
                $case->forceFill(['open_delivery_key' => $key])->save();
                foreach ($files as $file) {
                    $this->files->store($actor, $case, $file, $paths);
                }
                if ($record && $record->status === 'released' && $data['issue_kind'] !== 'delivery_question') {
                    $receipt = $record->receiptResponse()->create([
                        'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
                        'response_type' => 'issue', 'issue_type' => $data['issue_kind'], 'issue_details' => $data['details'],
                        'status' => 'open', 'responded_at' => now(),
                    ]);
                    $case->forceFill(['legacy_receipt_response_id' => $receipt->id])->save();
                }
                $this->history->record($case, $actor, 'delivery_reported', null, 'A benefit delivery report was submitted. No finding against either party was made.');
                $this->markDisputed($case);
                $this->notify($case, false, 'A benefit delivery report needs a response');

                return $case;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
    }

    public function importReceipt(User $actor, RecipientBenefitReceiptResponse $receipt): RecipientAgreementCase
    {
        $this->authorizeApplication($actor, $receipt->application);
        abort_unless($receipt->response_type === 'issue' && ! $receipt->recipient_resolution_confirmed_at, 422, 'This receipt is not an unresolved issue.');

        return DB::transaction(function () use ($actor, $receipt): RecipientAgreementCase {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($receipt->scholarship_application_id);
            $this->authorizeApplication($actor, $application);
            $receipt = RecipientBenefitReceiptResponse::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($existing = $receipt->deliveryReport()->first()) {
                return $existing;
            }
            abort_unless($receipt->response_type === 'issue' && ! $receipt->recipient_resolution_confirmed_at, 422, 'This receipt is not an unresolved issue.');
            $record = $this->records($application)->with('release')->findOrFail($receipt->recipient_benefit_release_record_id);
            abort_unless($record->applicant_id === $receipt->applicant_id, 403);
            $existing = $this->openForRecord($record)->first();
            abort_if($existing, 409, 'A delivery report already covers this release. Open it instead.');
            $author = User::query()->findOrFail($receipt->applicant_id);
            $case = $this->newCase($author, $application, $record, null, $receipt->issue_type ?: 'other', $receipt->issue_details ?: 'Existing receipt issue.', (string) Str::uuid(), hash('sha256', 'legacy:'.$receipt->id));
            $case->forceFill(['legacy_receipt_response_id' => $receipt->id,
                'open_delivery_key' => hash('sha256', $application->id.':release:'.$record->id),
                'notice_sent_at' => $receipt->responded_at ?? $receipt->created_at,
            ])->save();
            if ($receipt->evidence_path) {
                $evidence = new RecipientAgreementCaseEvidence;
                $evidence->forceFill([
                    'recipient_agreement_case_id' => $case->id, 'uploaded_by' => $author->id,
                    'original_name' => $receipt->evidence_original_name ?: 'Original evidence', 'path' => $receipt->evidence_path,
                    'mime_type' => $receipt->evidence_mime_type ?: 'application/octet-stream', 'size' => $receipt->evidence_size,
                    'sha256' => Storage::disk('local')->exists($receipt->evidence_path) ? hash('sha256', Storage::disk('local')->get($receipt->evidence_path)) : str_repeat('0', 64),
                    'created_at' => $receipt->responded_at ?? $receipt->created_at,
                ])->save();
            }
            if ($receipt->resolved_at || $receipt->resolution_notes || $receipt->resolution_proof_path) {
                $resolver = User::query()->find($receipt->resolved_by);
                $reply = new RecipientAgreementCaseResponse;
                $reply->forceFill([
                    'recipient_agreement_case_id' => $case->id, 'case_revision' => 1, 'notice_revision' => 1,
                    'submitted_by' => $resolver?->id, 'author_name' => $resolver?->name ?? 'Earlier provider response',
                    'author_role' => 'provider', 'response_kind' => 'legacy_provider_response',
                    'body' => ($receipt->resolution_outcome ? Str::headline($receipt->resolution_outcome).': ' : '').($receipt->resolution_notes ?? ''),
                    'created_at' => $receipt->resolved_at ?? $receipt->updated_at,
                ])->save();
                if ($receipt->resolution_proof_path) {
                    $proof = new RecipientAgreementCaseEvidence;
                    $proof->forceFill([
                        'recipient_agreement_case_id' => $case->id, 'response_id' => $reply->id,
                        'uploaded_by' => $resolver?->id ?? $actor->id,
                        'original_name' => $receipt->resolution_proof_original_name ?: 'Earlier provider proof',
                        'path' => $receipt->resolution_proof_path, 'mime_type' => $receipt->resolution_proof_mime_type ?: 'application/octet-stream',
                        'size' => $receipt->resolution_proof_size ?? 0,
                        'sha256' => Storage::disk('local')->exists($receipt->resolution_proof_path) ? hash('sha256', Storage::disk('local')->get($receipt->resolution_proof_path)) : str_repeat('0', 64),
                        'created_at' => $receipt->resolved_at ?? $receipt->updated_at,
                    ])->save();
                }
            }
            $this->history->record($case, $actor, 'receipt_issue_linked', null, 'The existing receipt issue was linked without rewriting its original statement or provider record.');
            $this->markDisputed($case);

            return $case;
        });
    }

    public function propose(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeApplication($actor, $case->application);
        abort_unless($actor->isProvider(), 403);
        $data = Validator::make($this->trim($input), [
            'request_key' => ['required', 'uuid'], 'response_kind' => ['required', Rule::in(array_keys(Catalog::DELIVERY_REPLIES))],
            'details' => ['required', 'string', 'min:10', 'max:4000'], 'confirmed' => ['accepted'],
            'follow_up_on' => ['required_if:response_kind,correction_planned', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'delivery_record_id' => ['required_if:response_kind,delivery_proposed', 'nullable', 'integer', 'min:1'],
            'delivered_on' => ['required_if:response_kind,delivery_proposed', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])->validate();
        $this->files->validate($files);
        if ($data['response_kind'] === 'delivery_proposed' && ! $files) {
            throw ValidationException::withMessages(['evidence' => 'Attach delivery or correction proof before asking the applicant to confirm receipt.']);
        }

        return $this->command($actor, $case, $revision, $data, $files, function ($case, $response) use ($data): void {
            $old = $case->status;
            if ($data['response_kind'] === 'delivery_proposed') {
                $record = $this->matchingDeliveryRecord($case, (int) $data['delivery_record_id']);
                $case->forceFill(['status' => 'awaiting_recipient', 'delivery_record_id' => $record->id, 'follow_up_on' => null]);
            } elseif ($data['response_kind'] === 'correction_planned') {
                $case->forceFill(['status' => 'awaiting_provider', 'follow_up_on' => $data['follow_up_on']]);
            } else {
                $case->forceFill(['status' => $case->issue_kind === 'delivery_question' ? 'awaiting_recipient' : 'awaiting_provider', 'follow_up_on' => null]);
            }
            $case->forceFill(['proposal_response_id' => $response->id])->save();
            $this->markDisputed($case);
            $this->history->record($case, $response->author, 'delivery_response_proposed', $old, 'The provider responded. Receipt has not been confirmed.');
            $this->notify($case, true, 'The provider responded to your benefit delivery report');
        });
    }

    public function respond(User $actor, RecipientAgreementCase $case, int $revision, array $input, array $files = []): RecipientAgreementCase
    {
        $this->authorizeApplication($actor, $case->application);
        abort_unless($actor->isApplicant(), 403);
        $data = Validator::make($this->trim($input), [
            'request_key' => ['required', 'uuid'], 'response_kind' => ['required', Rule::in(['receipt_confirmed', 'clarification_accepted', 'still_unresolved', 'additional_information'])],
            'details' => ['nullable', 'string', 'max:4000', Rule::requiredIf(in_array($input['response_kind'] ?? '', ['still_unresolved', 'additional_information'], true))],
            'received_on' => ['required_if:response_kind,receipt_confirmed', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'confirmed' => ['accepted'],
        ])->validate();
        if (in_array($data['response_kind'], ['still_unresolved', 'additional_information'], true) && mb_strlen($data['details'] ?? '') < 10) {
            throw ValidationException::withMessages(['details' => 'Explain what is still missing or provide the requested information (at least 10 characters).']);
        }
        $this->files->validate($files);

        return $this->command($actor, $case, $revision, $data, $files, function ($case, $response) use ($data): void {
            $old = $case->status;
            $proposal = $case->proposal;
            if ($data['response_kind'] === 'receipt_confirmed') {
                abort_unless($case->status === 'awaiting_recipient' && $proposal?->response_kind === 'delivery_proposed', 409, 'A current, evidenced delivery proposal is required before confirming receipt.');
                $record = $this->matchingDeliveryRecord($case, $proposal->delivery_record_id);
                foreach ($proposal->evidence as $proof) {
                    if (! Storage::disk('local')->exists($proof->path) || ! hash_equals($proof->sha256, hash('sha256', Storage::disk('local')->get($proof->path)))) {
                        throw ValidationException::withMessages(['evidence' => 'The delivery proof is missing or changed. Ask the provider to supply it again.']);
                    }
                }
                abort_unless($proposal->evidence->isNotEmpty(), 409, 'Delivery proof is required.');
                $case->forceFill(['status' => 'closed', 'outcome' => 'receipt_confirmed', 'closed_at' => now(), 'open_delivery_key' => null, 'follow_up_on' => null])->save();
                $this->confirmDelivery($case, $record, $response, $proposal);
            } elseif ($data['response_kind'] === 'clarification_accepted') {
                abort_unless($case->status === 'awaiting_recipient' && $case->issue_kind === 'delivery_question' && $proposal?->response_kind === 'information_provided', 409, 'Only an information-only report can be closed as clarified.');
                $case->forceFill(['status' => 'closed', 'outcome' => 'clarified', 'closed_at' => now(), 'open_delivery_key' => null, 'follow_up_on' => null])->save();
            } else {
                $case->forceFill(['status' => 'awaiting_provider'])->save();
            }
            $this->history->record($case, $response->author, 'delivery_applicant_response', $old, $case->status === 'closed' ? 'The applicant confirmed the report resolution.' : 'The applicant replied; the delivery report remains open.');
            $this->notify($case, false, $case->status === 'closed' ? 'The applicant confirmed the benefit report resolution' : 'A benefit delivery report still needs follow-up');
        });
    }

    private function command(User $actor, RecipientAgreementCase $case, int $revision, array $data, array $files, callable $apply): RecipientAgreementCase
    {
        $hash = $this->fingerprint([$data['response_kind'], $data['details'] ?? '', isset($data['delivery_record_id']) ? (int) $data['delivery_record_id'] : null, $data['delivered_on'] ?? null, $data['received_on'] ?? null, $data['follow_up_on'] ?? null], $files);
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $case, $revision, $data, $hash, $files, $apply, &$paths): RecipientAgreementCase {
                $applicationId = RecipientAgreementCase::query()->whereKey($case->id)->value('scholarship_application_id');
                ScholarshipApplication::query()->lockForUpdate()->findOrFail($applicationId);
                $case = RecipientAgreementCase::query()->lockForUpdate()->findOrFail($case->id);
                $this->authorizeApplication($actor, $case->application);
                abort_unless($case->case_type === Catalog::BENEFIT_DELIVERY, 403);
                if ($existing = $case->responses()->where('request_key', strtolower($data['request_key']))->first()) {
                    abort_unless($existing->submitted_by === $actor->id && hash_equals($existing->request_hash, $hash), 409, 'This response key was used for different content.');

                    return $case;
                }
                abort_unless($case->revision === $revision && in_array($case->status, self::OPEN, true), 409, 'This report changed or was already resolved. Reload it before continuing.');
                $actor = User::query()->findOrFail($actor->id);
                $response = new RecipientAgreementCaseResponse;
                $response->forceFill([
                    'recipient_agreement_case_id' => $case->id, 'case_revision' => $case->revision + 1, 'notice_revision' => 1,
                    'submitted_by' => $actor->id, 'author_name' => $actor->name, 'author_role' => $actor->role,
                    'response_kind' => $data['response_kind'], 'body' => $data['details'] ?? '',
                    'request_key' => strtolower($data['request_key']), 'request_hash' => $hash, 'created_at' => now(),
                ]);
                // Set typed proposal fields before the append-only statement is first saved.
                if ($actor->isProvider()) {
                    if ($data['response_kind'] === 'delivery_proposed') {
                        $record = $this->matchingDeliveryRecord($case, (int) $data['delivery_record_id']);
                        $response->forceFill(['delivery_record_id' => $record->id, 'delivered_on' => $data['delivered_on']]);
                    } elseif ($data['response_kind'] === 'correction_planned') {
                        $response->forceFill(['follow_up_on' => $data['follow_up_on']]);
                    }
                } elseif ($data['response_kind'] === 'receipt_confirmed') {
                    $response->forceFill(['delivery_record_id' => $case->delivery_record_id, 'delivered_on' => $data['received_on']]);
                }
                $response->save();
                foreach ($files as $file) {
                    $this->files->store($actor, $case, $file, $paths, $response->id);
                }
                $case->forceFill(['revision' => $case->revision + 1]);
                $apply($case, $response);

                return $case;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }
    }

    private function source(ScholarshipApplication $application, array $data): array
    {
        if ($data['source_type'] === 'release') {
            return [$this->records($application)->with(['release', 'receiptResponse'])->findOrFail($data['source_id']), null];
        }
        abort_unless($application->student_response_status === 'accepted', 422, 'An accepted award is required to report an unscheduled benefit.');
        $entitlement = $application->benefitEntitlements()->where('applicant_id', $application->applicant_id)->where('scholarship_id', $application->scholarship_id)->findOrFail($data['source_id']);
        if ($entitlement->status === 'fulfilled' || ($entitlement->isOneTime() && $entitlement->allocations()->whereHas('releaseRecord.release', fn ($q) => $q->whereNotNull('published_at'))->exists())) {
            throw ValidationException::withMessages(['source_id' => 'Open the existing release or receipt report for this benefit.']);
        }

        return [null, $entitlement];
    }

    private function newCase(User $actor, ScholarshipApplication $application, ?RecipientBenefitReleaseRecord $record, ?RecipientBenefitEntitlement $entitlement, string $kind, string $details, string $requestKey, string $hash, ?string $period = null): RecipientAgreementCase
    {
        $case = new RecipientAgreementCase;
        $case->forceFill([
            'scholarship_application_id' => $application->id, 'opened_by' => $actor->id, 'case_type' => Catalog::BENEFIT_DELIVERY,
            'status' => 'awaiting_provider', 'revision' => 1, 'request_key' => $requestKey, 'request_hash' => $hash,
            'subject' => Str::limit((Catalog::DELIVERY_ISSUES[$kind] ?? 'Delivery report').': '.($record?->release->title ?? $entitlement?->title), 160, ''),
            'details' => $details, 'issue_kind' => $kind, 'benefit_period' => $period,
            'agreement_snapshot' => $application->provider_contract_terms_snapshot ?? [],
            'agreement_version' => $application->student_response_terms_version ?: $application->provider_contract_terms_version,
            'agreement_accepted_at' => $application->provider_contract_terms_accepted_at,
            'benefit_entitlement_id' => $entitlement?->id, 'benefit_release_record_id' => $record?->id,
            'benefit_snapshot' => ['title' => $record?->release->title ?? $entitlement?->title,
                'description' => $record?->release->benefit_description ?? $entitlement?->description,
                'amount' => $record?->release->amount ?? $entitlement?->amount,
                'scheduled_at' => $record?->release->release_at?->toIso8601String()],
            'notice_sent_at' => now(), 'finding' => 'not_assessed', 'disclosure_status' => 'not_shared',
        ])->save();

        return $case;
    }

    public function matchingDeliveryRecord(RecipientAgreementCase $case, int $id): RecipientBenefitReleaseRecord
    {
        $record = $this->records($case->application)->with(['release', 'allocations.entitlement', 'receiptResponse'])->findOrFail($id);
        abort_unless($record->status === 'released', 422, 'Record the actual release before submitting delivery proof.');
        abort_if($record->receiptResponse?->response_type === 'confirmed' || $record->receiptResponse?->recipient_resolution_confirmed_at, 422, 'This release already has confirmed receipt. Do not reuse it for a different delivery claim.');
        abort_unless($this->deliveryRecords($case)->whereKey($record->id)->exists(), 422, 'The release does not include all reported benefits, or is already assigned to another period report.');
        if ($case->benefit_entitlement_id && ! $case->entitlement?->isOneTime()) {
            abort_if(RecipientAgreementCase::query()->where('case_type', Catalog::BENEFIT_DELIVERY)
                ->where('benefit_entitlement_id', $case->benefit_entitlement_id)->where('delivery_record_id', $record->id)
                ->whereKeyNot($case->id)->exists(), 422, 'This release is already linked to another period report. Use the release for the affected period.');
        }

        return $record;
    }

    public function deliveryRecords(RecipientAgreementCase $case): Builder
    {
        $query = $this->records($case->application)->where('status', 'released')
            ->whereDoesntHave('receiptResponse', fn ($r) => $r->where('response_type', 'confirmed')->orWhereNotNull('recipient_resolution_confirmed_at'));
        if ($case->benefit_entitlement_id) {
            if (! $case->entitlement?->isOneTime()) {
                $query->whereNotIn('id', RecipientAgreementCase::query()->where('case_type', Catalog::BENEFIT_DELIVERY)
                    ->where('benefit_entitlement_id', $case->benefit_entitlement_id)->whereNotNull('delivery_record_id')
                    ->whereKeyNot($case->id)->select('delivery_record_id'));
            }

            return $query->whereHas('allocations', fn ($a) => $a->where('recipient_benefit_entitlement_id', $case->benefit_entitlement_id));
        }
        $ids = $case->releaseRecord?->allocations()->pluck('recipient_benefit_entitlement_id') ?? collect();

        // A closed missed appointment can be corrected by an actual replacement release.
        // A released one-time award is not reset or allocated a second time here.
        return $query->where(function ($q) use ($case, $ids): void {
            $q->whereKey($case->benefit_release_record_id);
            if ($ids->isNotEmpty() && $case->releaseRecord?->status !== 'released') {
                $q->orWhere(function ($candidate) use ($ids): void {
                    foreach ($ids as $id) {
                        $candidate->whereHas('allocations', fn ($a) => $a->where('recipient_benefit_entitlement_id', $id));
                    }
                });
            }
        });
    }

    public function markDisputed(RecipientAgreementCase $case): void
    {
        if ($case->issue_kind === 'delivery_question') {
            return;
        }
        $record = $case->delivery_record_id ? RecipientBenefitReleaseRecord::find($case->delivery_record_id) : $case->releaseRecord;
        if (! $record || $record->status !== 'released') {
            return;
        }
        foreach ($record->allocations()->with('entitlement')->get() as $allocation) {
            if ($case->benefit_entitlement_id && $allocation->recipient_benefit_entitlement_id !== $case->benefit_entitlement_id) {
                continue;
            }
            if ($case->benefit_release_record_id && $case->benefit_release_record_id !== $record->id
                && ! $case->releaseRecord->allocations()->where('recipient_benefit_entitlement_id', $allocation->recipient_benefit_entitlement_id)->exists()) {
                continue;
            }
            $allocation->update(['status' => 'issue_open']);
            if ($allocation->entitlement?->isOneTime()) {
                $allocation->entitlement->update(['status' => 'issue_open', 'fulfilled_at' => null]);
            }
        }
    }

    private function confirmDelivery(RecipientAgreementCase $case, RecipientBenefitReleaseRecord $record, RecipientAgreementCaseResponse $response, RecipientAgreementCaseResponse $proposal): void
    {
        $sourceIds = $case->releaseRecord?->allocations()->pluck('recipient_benefit_entitlement_id') ?? collect();
        foreach ($record->allocations as $allocation) {
            if ($case->benefit_entitlement_id && $allocation->recipient_benefit_entitlement_id !== $case->benefit_entitlement_id) {
                continue;
            }
            if (! $case->benefit_entitlement_id && $sourceIds->isNotEmpty() && ! $sourceIds->contains($allocation->recipient_benefit_entitlement_id)) {
                continue;
            }
            if ($this->openForRecord($record)->where('issue_kind', '!=', 'delivery_question')
                ->where(fn ($q) => $q->whereNull('benefit_entitlement_id')->orWhere('benefit_entitlement_id', $allocation->recipient_benefit_entitlement_id))->exists()) {
                continue;
            }
            $allocation->update(['status' => 'fulfilled']);
            if ($allocation->entitlement?->isOneTime()) {
                $allocation->entitlement->update(['status' => 'fulfilled', 'fulfilled_at' => now()]);
            }
        }
        if ($this->openForRecord($record)->where('issue_kind', '!=', 'delivery_question')->exists() || $record->allocations()->where('status', '!=', 'fulfilled')->exists()) {
            return;
        }
        $receipt = $record->receiptResponse;
        if (! $receipt) {
            $record->receiptResponse()->create([
                'scholarship_application_id' => $record->scholarship_application_id, 'applicant_id' => $record->applicant_id,
                'response_type' => 'confirmed', 'received_on' => $response->delivered_on, 'recipient_note' => $response->body,
                'status' => 'confirmed', 'responded_at' => now(), 'recipient_resolution_confirmed_at' => now(),
            ]);
        } elseif ($receipt->response_type === 'issue') {
            $proof = $proposal->evidence()->first();
            $receipt->update([
                'status' => 'resolved', 'resolution_outcome' => 'delivery_confirmed', 'resolution_notes' => $proposal->body,
                'resolved_by' => $proposal->submitted_by, 'resolved_at' => now(), 'recipient_resolution_confirmed_at' => now(),
                'resolution_proof_original_name' => $proof?->original_name, 'resolution_proof_path' => $proof?->path,
                'resolution_proof_mime_type' => $proof?->mime_type, 'resolution_proof_size' => $proof?->size ?? 0,
            ]);
        }
    }

    private function notify(RecipientAgreementCase $case, bool $applicant, string $title): void
    {
        $application = $case->application;
        $recipients = $applicant ? collect([$application->applicant]) : User::query()->where('role', 'provider')
            ->where(fn ($q) => $q->whereKey($application->scholarship->provider_id)->orWhere('parent_account_id', $application->scholarship->provider_id))->get()
            ->filter(fn ($user) => $this->access->visibleTo($user)->whereKey($case->id)->exists());
        foreach ($recipients as $recipient) {
            PortalNotification::create([
                'user_id' => $recipient->id, 'type' => 'benefit_delivery_report_updated', 'title' => $title,
                'message' => $application->scholarship->title.': '.$case->subject,
                'action_url' => $applicant ? route('dashboard.monitoring.show.section', ['application' => $application, 'section' => 'benefits'], false).'#benefit-delivery-reports'
                    : route('provider.workspaces.releases.delivery-reports', [], false),
                'deduplication_key' => "delivery-report:{$case->id}:{$case->revision}:{$recipient->id}",
            ]);
        }
    }

    private function trim(array $input): array
    {
        foreach (['details', 'benefit_period'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        return $input;
    }

    private function fingerprint(array $values, array $files): string
    {
        return hash('sha256', json_encode([$values, array_map(fn (UploadedFile $file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files)], JSON_THROW_ON_ERROR));
    }
}
