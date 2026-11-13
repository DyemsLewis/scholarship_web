<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\User;
use App\Support\AdminWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProviderVerificationReviewService
{
    public function summary(User $provider): array
    {
        $provider->loadMissing(['providerProfile', 'providerVerificationDocuments']);
        $documents = $provider->providerVerificationDocuments;
        $accepted = $documents->filter(fn ($document) => $document->reviewStatus() === 'approved')->count();
        $replacement = $documents->filter(fn ($document) => $document->reviewStatus() === 'rejected')->count();
        $unavailable = $documents->filter(fn ($document) => ! Storage::disk('local')->exists($document->path))->count();

        return [
            'total' => $documents->count(),
            'accepted' => $accepted,
            'replacement_requested' => $replacement,
            'awaiting_review' => $documents->count() - $accepted - $replacement,
            'unavailable_files' => $unavailable,
            'can_approve' => $documents->isNotEmpty() && $accepted === $documents->count() && ! $unavailable,
            'token' => $this->token($provider),
        ];
    }

    public function documentMetadata(ProviderVerificationDocument $document): array
    {
        return [
            'revision' => $document->revision ?? 1,
            'review_status' => $document->reviewStatus(),
            'individually_reviewed' => (bool) ($document->reviewed_by && $document->reviewed_at),
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'file_available' => Storage::disk('local')->exists($document->path),
        ];
    }

    public function token(User $provider): string
    {
        $provider->loadMissing(['providerProfile', 'providerVerificationDocuments']);
        $profile = $provider->providerProfile;
        $documents = $provider->providerVerificationDocuments->sortBy('id')->map(fn ($document) => [
            $document->id, $document->revision, $document->path, $document->status,
            $document->reviewed_by, $document->reviewed_at?->toISOString(),
        ])->values()->all();

        return hash_hmac('sha256', json_encode([
            $provider->id, $profile?->getAttributes(), $documents,
            ActivityLog::query()->where('action', 'provider_verification_updated')->where('metadata->provider_id', $provider->id)->max('id'),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function reviewDocument(Request $request, ProviderVerificationDocument $document, array $data): ProviderVerificationDocument
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('manage_reviews')
            && ! AdminWorkspace::usesPortalManagerWorkspace($request->user()), 403);

        return DB::transaction(function () use ($request, $document, $data) {
            $provider = User::query()->lockForUpdate()->findOrFail($document->provider_id);
            abort_unless($provider->isProvider() && ! $provider->isManagedAccount(), 404);
            $profile = $provider->providerProfile()->lockForUpdate()->firstOrFail();
            $current = $provider->providerVerificationDocuments()->lockForUpdate()->find($document->id);
            abort_if(! $current || $current->revision !== (int) $data['expected_revision'], 409,
                'This proof changed while you were reviewing it. Reload the organization record.');
            if ($data['status'] === 'approved' && ! Storage::disk('local')->exists($current->path)) {
                throw ValidationException::withMessages(['status' => 'The file is unavailable. Request a replacement instead.']);
            }

            $previous = $current->reviewStatus();
            $current->update([
                'status' => $data['status'], 'review_notes' => $data['review_notes'] ?? null,
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'revision' => $current->revision + 1,
            ]);
            if ($profile->verification_status !== 'pending') {
                $profile->update(['verification_status' => 'pending', 'verification_notes' => null,
                    'verified_by' => null, 'verified_at' => null]);
            }
            ActivityLog::record($request->user(), 'provider_document_reviewed',
                "{$request->user()->name} reviewed organization proof for {$provider->name}.", $request, [
                    'provider_id' => $provider->id, 'document_id' => $current->id,
                    'document_type' => $current->document_type, 'revision' => $current->revision,
                    'from_status' => $previous, 'to_status' => $current->status,
                    'review_notes' => $current->review_notes,
                ]);
            $this->notify($provider, 'provider_document_review',
                $current->status === 'approved' ? 'Organization proof accepted' : 'Replace organization proof',
                $current->original_name.': '.($current->status === 'approved'
                    ? 'Accepted. Final organization verification is a separate decision.'
                    : $current->review_notes));

            return $current->fresh();
        });
    }

    public function decide(Request $request, User $provider, array $data): User
    {
        abort_unless($request->user()?->isAdmin() && $request->user()->hasPortalPermission('manage_reviews')
            && ! AdminWorkspace::usesPortalManagerWorkspace($request->user()), 403);

        return DB::transaction(function () use ($request, $provider, $data) {
            $current = User::query()->lockForUpdate()->findOrFail($provider->id);
            $profile = $current->providerProfile()->lockForUpdate()->firstOrFail();
            $current->setRelation('providerProfile', $profile);
            $current->setRelation('providerVerificationDocuments', $current->providerVerificationDocuments()->lockForUpdate()->get());
            if (isset($data['expected_review_token']) && ! hash_equals($this->token($current), $data['expected_review_token'])) {
                abort(409, 'The organization or its proof changed. Reload and check the current reviews before deciding.');
            }
            if ($data['verification_status'] === 'approved') {
                if ($current->providerVerificationDocuments->isEmpty()) {
                    throw ValidationException::withMessages(['verification_status' => 'The provider must upload at least one proof before the account can be approved.']);
                }
                if (! $this->summary($current)['can_approve']) {
                    throw ValidationException::withMessages(['verification_status' => 'Accept every current document individually before approving the provider account.']);
                }
                if ($current->providerVerificationDocuments->contains(fn ($document) => ! Storage::disk('local')->exists($document->path))) {
                    throw ValidationException::withMessages(['verification_status' => 'A supporting file is unavailable. Request a replacement before approval.']);
                }
            }
            $status = $data['verification_status'];
            $notes = $data['verification_notes'] ?? null;
            $profile->update([
                'verification_status' => $status, 'verification_notes' => $notes,
                'verified_by' => $status === 'pending' ? null : $request->user()->id,
                'verified_at' => $status === 'pending' ? null : now(),
            ]);
            ActivityLog::record($request->user(), 'provider_verification_updated',
                "{$request->user()->name} marked provider {$current->name} as {$status}.", $request, [
                    'provider_id' => $current->id, 'verification_status' => $status,
                    'verification_notes' => $notes,
                    'review_fingerprint' => app(AdminReviewWorkflowService::class)->fingerprint('provider', $current),
                    'document_reviews' => $current->providerVerificationDocuments->map(fn ($document) => [
                        'id' => $document->id, 'revision' => $document->revision, 'status' => $document->reviewStatus(),
                    ])->all(),
                ]);
            $this->notify($current, 'provider_verification', 'Provider verification updated',
                "Your provider account is now {$status}.".($notes ? " Review note: {$notes}" : ''));

            return $current->fresh(['providerProfile', 'providerVerificationDocuments.reviewer']);
        });
    }

    private function notify(User $provider, string $type, string $title, string $message): void
    {
        User::query()->where(fn ($query) => $query->whereKey($provider->id)->orWhere('parent_account_id', $provider->id))
            ->get()->filter(fn (User $user) => $user->hasPortalPermission('manage_profile'))
            ->each(fn (User $user) => PortalNotification::create([
                'user_id' => $user->id, 'type' => $type, 'title' => $title,
                'message' => $message, 'action_url' => '/provider/workspaces/organization-profile/verification',
            ]));
    }
}
