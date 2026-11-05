<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\User;
use Illuminate\Support\Str;

final class RecipientCaseReviewPresenter
{
    public function payload(RecipientAgreementCase $case, User $actor, bool $detailed = true): array
    {
        $service = app(RecipientCaseReviewService::class);
        $service->authorize($actor, $case);
        $admin = $actor->isAdmin();
        $prefix = $admin ? 'admin' : ($actor->isProvider() ? 'provider' : 'dashboard');
        $case->loadMissing(['application.applicant.studentProfile', 'application.scholarship.provider.providerProfile', 'latestReview']);
        $delivery = $case->case_type === Catalog::BENEFIT_DELIVERY;
        $pending = in_array($case->review_state, ['requested', 'awaiting_information', 'appeal_pending'], true);
        $data = [
            'id' => $case->id, 'revision' => $case->revision, 'subject' => $case->subject, 'case_type' => $case->case_type,
            'type_label' => $delivery ? 'Benefit delivery' : 'Agreement concern',
            'review_state' => $case->review_state, 'review_state_label' => match ($case->review_state) {
                'requested' => 'Awaiting admin review', 'awaiting_information' => 'More information requested', 'appeal_pending' => 'Appeal awaiting independent review', 'reviewed' => 'Review outcome recorded', default => 'Not escalated',
            },
            'finding_label' => $pending ? 'Not final / under review' : (RecipientCaseReviewService::FINDINGS[$case->finding] ?? 'Not assessed'),
            'applicant' => ['name' => $case->application->applicant->name, 'photo_url' => $admin ? route('admin.applicants.profile-photo.view', $case->application->applicant, false) : ($actor->isProvider() ? route('provider.applications.profile-photo.view', $case->application, false) : null)],
            'program' => ['title' => $case->application->scholarship->title],
            'organization' => $case->application->scholarship->provider?->providerProfile?->provider_name,
            'escalated_at' => $case->escalated_at?->toIso8601String(),
            'read_url' => route($prefix.'.recipient-cases.show', $case, false),
            'can_request' => ! $admin && ! $case->review_state && $case->status !== 'closed' && ($delivery || $case->response_received_at || $case->responseDeadlinePassed()),
            'can_statement' => ! $admin && $pending,
            'can_appeal' => ! $admin && $case->review_state === 'reviewed' && $case->latest_review_id !== null,
            'can_review' => $admin && $pending && ($case->review_state !== 'appeal_pending' || $case->latestReview?->reviewed_by !== $actor->id),
            'independent_reviewer_required' => $admin && $case->review_state === 'appeal_pending' && $case->latestReview?->reviewed_by === $actor->id,
        ];
        if (! $detailed) {
            return $data;
        }
        $filePayload = fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'mime_type' => $file->mime_type, 'size' => $file->size, 'view_url' => route($prefix.'.recipient-cases.evidence', $file, false)];
        $actions = $admin ? ['information', 'outcome'] : ['request', 'statement', 'appeal'];

        return $data + [
            'privacy_url' => route($prefix.'.recipient-case-privacy.show', $case, false),
            'details' => $case->details, 'source_label' => $delivery ? ($case->benefit_snapshot['title'] ?? 'Assigned benefit') : (Catalog::OBLIGATION_LABELS[$case->term_key] ?? 'Accepted obligation'),
            'source_text' => $delivery ? ($case->benefit_snapshot['description'] ?? '') : $case->term_text,
            'accepted_consequences' => $service->acceptedConsequences($case),
            'response_due_at' => $case->response_due_at?->toIso8601String(), 'response_received_at' => $case->response_received_at?->toIso8601String(),
            'actions' => collect($actions)->mapWithKeys(fn ($action) => [$action => route($prefix.'.recipient-cases.'.$action, $case, false)])->all(),
            'evidence' => $case->evidence()->whereDoesntHave('exclusion')->get()->map($filePayload)->all(),
            'statements' => $case->responses()->with('evidence')->get()->map(fn ($response) => ['id' => $response->id, 'kind' => $response->response_kind,
                'label' => Str::headline($response->response_kind ?: 'Statement'), 'author_name' => $response->author_name, 'body' => $response->body,
                'created_at' => $response->created_at?->toIso8601String(), 'evidence' => $response->evidence->map($filePayload)->all()])->all(),
            'reviews' => $case->reviews()->get()->map(fn ($review) => ['id' => $review->id, 'reviewer_name' => $review->reviewer_name,
                'finding_label' => RecipientCaseReviewService::FINDINGS[$review->finding], 'recommendation_label' => RecipientCaseReviewService::RECOMMENDATIONS[$review->recommendation],
                'rationale' => $review->rationale, 'is_appeal' => $review->is_appeal, 'appeal_due_at' => $review->appeal_due_at->toIso8601String(), 'created_at' => $review->created_at?->toIso8601String(),
                'current' => $case->latest_review_id === $review->id, 'under_appeal' => $case->latest_review_id === $review->id && $case->review_state === 'appeal_pending'])->all(),
            'history' => $case->events()->whereNotIn('action', ['draft_created', 'draft_revised', 'evidence_added', 'evidence_excluded'])->get()->map(fn ($event) => ['note' => $event->note, 'actor_name' => $event->actor_name, 'created_at' => $event->created_at?->toIso8601String()])->all(),
        ];
    }
}
