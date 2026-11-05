<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\User;

final class BenefitDeliveryReportPresenter
{
    public function payload(RecipientAgreementCase $case, User $actor, bool $detailed = true): array
    {
        app(RecipientAgreementCaseAccess::class)->authorizeView($actor, $case);
        $case->loadMissing(['application.applicant.studentProfile', 'application.scholarship', 'proposal']);
        $provider = $actor->isProvider();
        $prefix = $provider ? 'provider' : 'dashboard';
        $open = in_array($case->status, BenefitDeliveryReportService::OPEN, true);
        $data = [
            'id' => $case->id, 'revision' => $case->revision, 'status' => $case->status,
            'status_label' => $case->status === 'closed' ? ($case->outcome === 'clarified' ? 'Clarification accepted' : 'Receipt confirmed')
                : ($case->status === 'awaiting_provider' && $case->follow_up_on ? 'Correction planned' : Catalog::statusLabel($case->status)),
            'subject' => $case->subject, 'issue_kind' => $case->issue_kind,
            'issue_label' => Catalog::DELIVERY_ISSUES[$case->issue_kind] ?? 'Delivery report',
            'benefit_period' => $case->benefit_period, 'follow_up_on' => $case->follow_up_on?->format('Y-m-d'),
            'created_at' => $case->created_at?->toIso8601String(), 'closed_at' => $case->closed_at?->toIso8601String(),
            'applicant' => ['name' => $case->application->applicant->name,
                'photo_url' => $provider ? route('provider.applications.profile-photo.view', $case->application, false) : null],
            'program' => ['title' => $case->application->scholarship->title],
            'read_url' => route($prefix.'.delivery-reports.show', $case, false),
            'can_propose' => $provider && $open, 'can_respond' => ! $provider && $open,
            'propose_url' => $provider && $open ? route('provider.delivery-reports.propose', $case, false) : null,
            'response_url' => ! $provider && $open ? route('dashboard.delivery-reports.respond', $case, false) : null,
        ];
        if (! $detailed) {
            return $data;
        }
        $responses = $case->responses()->reorder('id')->with('evidence')->get();
        $choices = $case->status === 'awaiting_recipient'
            ? ($case->proposal?->response_kind === 'delivery_proposed'
                ? [['value' => 'receipt_confirmed', 'label' => 'I received the reported benefit'], ['value' => 'still_unresolved', 'label' => 'This is still unresolved']]
                : [['value' => 'clarification_accepted', 'label' => 'My delivery question was answered'], ['value' => 'still_unresolved', 'label' => 'This is still unresolved']])
            : [['value' => 'additional_information', 'label' => 'Add information for the provider']];
        $records = $provider && $open ? app(BenefitDeliveryReportService::class)->deliveryRecords($case)->with('release')
            ->get()->map(fn ($record) => ['id' => $record->id, 'label' => $record->release->title.' - '.($record->released_at?->format('M d, Y') ?? 'Recorded release')])->all() : [];

        return $data + [
            'details' => $case->details, 'benefit' => $case->benefit_snapshot,
            'agreement_recorded' => $case->agreement_accepted_at !== null && filled($case->agreement_version),
            'outcome' => $case->outcome, 'response_choices' => $open ? $choices : [], 'delivery_records' => $records,
            'evidence' => $case->evidence()->whereNull('response_id')->whereDoesntHave('exclusion')->get()->map(fn ($file) => $this->file($file, $prefix))->all(),
            'responses' => $responses->map(fn ($response) => [
                'id' => $response->id, 'kind' => $response->response_kind,
                'label' => Catalog::DELIVERY_REPLIES[$response->response_kind] ?? match ($response->response_kind) {
                    'receipt_confirmed' => 'Applicant confirmed receipt', 'clarification_accepted' => 'Applicant accepted the clarification',
                    'still_unresolved' => 'Applicant reported an unresolved issue', 'additional_information' => 'Additional applicant information',
                    default => 'Earlier recorded response',
                },
                'body' => $response->body, 'author_name' => $response->author_name,
                'created_at' => $response->created_at?->toIso8601String(), 'delivered_on' => $response->delivered_on?->format('Y-m-d'),
                'follow_up_on' => $response->follow_up_on?->format('Y-m-d'),
                'evidence' => $response->evidence->map(fn ($file) => $this->file($file, $prefix))->all(),
            ])->all(),
            'history' => $case->events()->get()->map(fn ($event) => ['revision' => $event->revision, 'note' => $event->note,
                'actor_name' => $event->actor_name, 'created_at' => $event->created_at?->toIso8601String()])->all(),
            'notice' => 'A correction plan or provider proof is not confirmed receipt. This report does not change the application decision or label either party as in breach.',
        ];
    }

    private function file(RecipientAgreementCaseEvidence $file, string $prefix): array
    {
        return ['id' => $file->id, 'name' => $file->original_name, 'mime_type' => $file->mime_type, 'size' => $file->size,
            'view_url' => route($prefix.'.delivery-reports.evidence', $file, false)];
    }
}
