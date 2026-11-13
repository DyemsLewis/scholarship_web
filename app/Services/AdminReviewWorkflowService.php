<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminReviewWorkflowService
{
    private const EVENTS = [
        'applicant' => ['applicant_profile_verification_updated', 'applicant_id'],
        'provider' => ['provider_verification_updated', 'provider_id'],
        'program' => ['scholarship_review_updated', 'scholarship_id'],
    ];

    public function token(string $type, Model $record): string
    {
        $state = match ($type) {
            'applicant' => $record->studentProfile?->only(['verification_status', 'verification_notes', 'verified_by', 'verified_at']),
            'provider' => $record->providerProfile?->only(['verification_status', 'verification_notes', 'verified_by', 'verified_at']),
            default => [$record->status, $record->review_requested_at?->toISOString()],
        };

        return $this->hash([$type, $record->id, $state, $this->fingerprint($type, $record), $this->events($type, $record)->value('id')]);
    }

    public function assertCurrent(string $type, Model $record, string $expected): void
    {
        abort_unless(hash_equals($this->token($type, $record), $expected), 409,
            'This record or its evidence changed after you opened it. Reload the current review before saving your decision.');
    }

    public function fingerprint(string $type, Model $record): array
    {
        $groups = [];
        if ($type === 'applicant') {
            $record->loadMissing(['studentProfile', 'applicantVerificationDocuments']);
            $attributes = $record->studentProfile?->getAttributes() ?? [];
            foreach (ApplicantEvidenceReviewService::CLAIMS as $key => $fields) {
                $groups['details:'.$key] = $this->hash(Arr::only($attributes, $fields));
            }
            $groups['details:contact'] = $this->hash([$record->email, Arr::only($attributes, ['contact_number', 'gender', 'profile_photo_path', 'profile_photo_review_status'])]);
            foreach ($record->applicantVerificationDocuments->sortBy('document_type') as $document) {
                $groups['evidence:'.$document->document_type] = $this->hash($document->only(['id', 'revision', 'path', 'status', 'reviewed_revision', 'reviewed_by', 'reviewed_at']));
            }
        } elseif ($type === 'provider') {
            $record->loadMissing(['providerProfile', 'providerVerificationDocuments']);
            $groups['details:organization'] = $this->hash(Arr::except($record->providerProfile?->getAttributes() ?? [], ['updated_at', 'verification_status', 'verification_notes', 'verified_by', 'verified_at']));
            foreach ($record->providerVerificationDocuments->sortBy('document_type') as $document) {
                $groups['evidence:'.$document->document_type] = $this->hash($document->only(['id', 'revision', 'path', 'status', 'reviewed_by', 'reviewed_at']));
            }
        } else {
            $record->loadMissing(['benefits', 'events', 'provider.providerProfile']);
            $groups['details:program'] = $this->hash(Arr::except(Arr::only($record->getAttributes(), $record->getFillable()), ['views_count', 'status']));
            $groups['details:benefits'] = $this->hash($record->benefits->sortBy('id')->map->getAttributes()->values()->all());
            $groups['details:activities'] = $this->hash($record->events->sortBy('id')->map->getAttributes()->values()->all());
            $groups['details:provider_verification'] = $this->hash([
                $record->provider?->account_status, $record->provider?->email_verified_at,
                $record->provider?->providerProfile?->only(['verification_status', 'verified_at']),
            ]);
        }
        ksort($groups);

        return $groups;
    }

    public function context(string $type, Model $record): array
    {
        $fingerprint = $this->fingerprint($type, $record);
        $previous = $this->events($type, $record)->first();
        $baseline = $previous?->metadata['review_fingerprint'] ?? null;
        $changed = is_array($baseline) ? collect(array_unique([...array_keys($baseline), ...array_keys($fingerprint)]))
            ->filter(fn (string $key): bool => ($baseline[$key] ?? null) !== ($fingerprint[$key] ?? null))->values()->all() : [];
        $summary = $this->readiness($type, $record);

        return [
            ...$summary,
            'outcome' => $type === 'program' ? $record->status : ($type === 'provider' ? $record->providerProfile?->verification_status : $record->studentProfile?->verification_status),
            'token' => $this->token($type, $record),
            'has_comparison' => is_array($baseline),
            'changed_keys' => $changed,
            'changes' => collect($changed)->map(fn (string $key): string => $this->changeLabel($key))->unique()->values()->all(),
            'previous_note' => $previous?->metadata['verification_notes'] ?? $previous?->metadata['review_notes'] ?? null,
            'history' => $this->events($type, $record)->limit(5)->get()->map(fn (ActivityLog $entry): array => [
                'id' => $entry->id, 'reviewer' => $entry->actor_name ?: 'Reviewer',
                'outcome' => $entry->metadata['verification_status'] ?? $entry->metadata['to_status'] ?? null,
                'note' => $entry->metadata['verification_notes'] ?? $entry->metadata['review_notes'] ?? null,
                'at' => $entry->created_at?->toISOString(),
            ])->all(),
        ];
    }

    private function readiness(string $type, Model $record): array
    {
        if ($type === 'program') {
            $blockers = [];
            try {
                app(ScholarshipPublicationGuard::class)->assertPublishable($record);
            } catch (ValidationException $error) {
                $blockers = array_values(array_unique(Arr::flatten($error->errors())));
            }

            return ['ready' => $blockers === [], 'blockers' => $blockers, 'review_count' => 0, 'waiting_count' => count($blockers), 'accepted_count' => 0];
        }
        if ($type === 'provider') {
            $summary = app(ProviderVerificationReviewService::class)->summary($record);
            $reviewCount = $record->providerVerificationDocuments->filter(fn ($document): bool => $document->reviewStatus() !== 'rejected'
                && ($document->reviewStatus() !== 'approved' || ! Storage::disk('local')->exists($document->path)))->count();

            return ['ready' => $summary['can_approve'], 'blockers' => [], 'review_count' => $reviewCount,
                'waiting_count' => $summary['replacement_requested'] + ($summary['total'] === 0 ? 1 : 0), 'accepted_count' => $summary['accepted']];
        }
        $summary = app(ApplicantEvidenceReviewService::class)->summary($record);
        $reviewCount = $record->applicantVerificationDocuments->filter(fn ($document): bool => in_array($document->document_type, $summary['blocking_types'], true)
            && $document->status !== 'rejected')->count();

        return ['ready' => $summary['required_evidence_accepted'], 'blockers' => [], 'review_count' => $reviewCount,
            'waiting_count' => count($summary['missing_types']) + count($summary['replacement_types']), 'accepted_count' => $summary['accepted_count']];
    }

    private function events(string $type, Model $record): Builder
    {
        [$action, $field] = self::EVENTS[$type];

        return ActivityLog::query()->where('action', $action)->where('metadata->'.$field, $record->id)->latest('id');
    }

    private function hash(mixed $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function changeLabel(string $key): string
    {
        [$scope, $name] = explode(':', $key, 2);
        $label = ApplicantVerificationDocument::EVIDENCE_DETAILS[$name]['label'] ?? Str::headline($name);

        return $scope === 'details' ? $label.' details' : $label;
    }
}
