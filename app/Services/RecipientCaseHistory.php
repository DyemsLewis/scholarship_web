<?php

namespace App\Services;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvent;
use App\Models\User;

final class RecipientCaseHistory
{
    public function record(RecipientAgreementCase $case, User $actor, string $action, ?string $from, string $note, ?array $payload = null): void
    {
        $actor = User::query()->findOrFail($actor->id);
        $event = new RecipientAgreementCaseEvent;
        $event->forceFill([
            'recipient_agreement_case_id' => $case->id, 'revision' => $case->revision,
            'action' => $action, 'from_status' => $from, 'to_status' => $case->status,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => $actor->role,
            'note' => $note, 'payload' => $payload, 'created_at' => now(),
        ])->save();
    }
}
