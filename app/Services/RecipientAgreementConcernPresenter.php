<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\User;

final class RecipientAgreementConcernPresenter
{
    public function payload(RecipientAgreementCase $case, User $actor, bool $detailed = true): array
    {
        app(RecipientAgreementCaseAccess::class)->authorizeView($actor, $case);
        $case->loadMissing(['application.scholarship', 'application.applicant.studentProfile']);
        $provider = $actor->isProvider();
        $prefix = $provider ? 'provider' : 'dashboard';
        $author = $case->opened_by === $actor->id;
        $draft = $author && $provider && $case->status === 'draft';
        $application = $case->application;
        $data = [
            'id' => $case->id, 'revision' => $case->revision, 'status' => $case->status,
            'status_label' => Catalog::statusLabel($case->status), 'subject' => $case->subject,
            'notice_sent_at' => $case->notice_sent_at?->toIso8601String(),
            'response_due_at' => $case->response_due_at?->toIso8601String(),
            'response_received_at' => $case->response_received_at?->toIso8601String(),
            'created_at' => $case->created_at?->toIso8601String(),
            'deadline_passed' => $case->responseDeadlinePassed(),
            'applicant' => ['name' => $application->applicant->name,
                'photo_url' => $provider ? route('provider.applications.profile-photo.view', $application, false) : null],
            'program' => ['title' => $application->scholarship->title],
            'read_url' => route($prefix.'.agreement-concerns.show', $case, false),
            'can_edit' => $draft,
            'can_issue' => $draft && collect(app(RecipientAgreementCaseService::class)->concernOptions($application))->contains('key', $case->term_key),
            'can_respond' => $actor->isApplicant() && $case->notice_sent_at !== null && $case->status === 'awaiting_response',
            'edit_url' => $draft ? route('provider.agreement-concerns.update', $case, false) : null,
            'issue_url' => $draft ? route('provider.agreement-concerns.issue', $case, false) : null,
            'cancel_url' => $draft ? route('provider.agreement-concerns.cancel', $case, false) : null,
            'upload_url' => $draft ? route('provider.agreement-concerns.evidence.store', $case, false) : null,
            'response_url' => $actor->isApplicant() && $case->status === 'awaiting_response' ? route('dashboard.agreement-concerns.respond', $case, false) : null,
        ];
        if (! $detailed) {
            return $data;
        }
        $data += [
            'details' => $case->details,
            'obligation' => ['key' => $case->term_key, 'label' => Catalog::OBLIGATION_LABELS[$case->term_key] ?? 'Accepted obligation', 'text' => $case->term_text],
            'agreement' => ['version' => $case->agreement_version, 'accepted_at' => $case->agreement_accepted_at?->toIso8601String()],
            'evidence' => $case->evidence()->whereNull('response_id')->whereDoesntHave('exclusion')->get()
                ->map(fn ($file) => $this->file($file, $prefix, $draft))->all(),
            'responses' => $case->responses()->with('evidence')->get()->map(fn ($response) => [
                'id' => $response->id, 'kind' => $response->response_kind,
                'kind_label' => Catalog::RESPONSE_KINDS[$response->response_kind] ?? 'Explanation',
                'body' => $response->body, 'author_name' => $response->author_name,
                'created_at' => $response->created_at?->toIso8601String(),
                'evidence' => $response->evidence->map(fn ($file) => $this->file($file, $prefix, false))->all(),
            ])->all(),
            'history' => $case->events()->when(! $author, fn ($q) => $q->whereIn('action', ['notice_issued', 'response_submitted']))
                ->get()->map(fn ($event) => [
                    'revision' => $event->revision, 'action' => $event->action, 'note' => $event->note,
                    'actor_name' => $event->actor_name, 'created_at' => $event->created_at?->toIso8601String(),
                ])->all(),
            'notice' => 'This is a concern for review, not a confirmed breach. It does not automatically change support or appear to other providers.',
        ];

        return $data;
    }

    private function file(RecipientAgreementCaseEvidence $file, string $prefix, bool $draft): array
    {
        return [
            'id' => $file->id, 'name' => $file->original_name, 'mime_type' => $file->mime_type,
            'size' => $file->size, 'view_url' => route($prefix.'.agreement-concerns.evidence.show', $file, false),
            'remove_url' => $draft ? route('provider.agreement-concerns.evidence.exclude', ['concern' => $file->recipient_agreement_case_id, 'evidence' => $file->id], false) : null,
        ];
    }
}
