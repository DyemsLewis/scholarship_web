<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\AcademicRecordOcrService;
use App\Support\Terms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

trait HandlesProviderProfileManagement
{
    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $user = $request->user()->loadMissing(['providerProfile']);
        $providerOwner = $user->providerOrganizationOwner();
        $canManageVerification = $user->hasPortalPermission('manage_profile');

        return response()->json([
            'user' => [
                ...$this->providerStaffPayload($user),
                'activity_summary' => $this->providerProfileActivitySummary($providerOwner),
                'verification_documents_count' => $providerOwner
                    ->providerVerificationDocuments()
                    ->count(),
            ],
            'verification_documents' => $canManageVerification
                ? $providerOwner
                    ->providerVerificationDocuments()
                    ->latest()
                    ->get()
                    ->map(fn (ProviderVerificationDocument $document) => $this->verificationDocumentPayload($document))
                    ->values()
                : [],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $user = $request->user();
        $providerOwner = $user->providerOrganizationOwner();
        $canManageOrganization = $user->hasPortalPermission('manage_profile');
        $profileSection = (string) $request->input('profile_section', 'all');

        $request->validate([
            'profile_section' => ['nullable', Rule::in(['all', 'organization', 'representative'])],
        ]);

        $editingRepresentative = in_array($profileSection, ['all', 'representative'], true);
        $editingOrganization = $canManageOrganization
            && in_array($profileSection, ['all', 'organization'], true);

        abort_if($profileSection === 'organization' && ! $canManageOrganization, 403);

        $rules = ['profile_section' => ['nullable', Rule::in(['all', 'organization', 'representative'])]];

        if ($editingRepresentative) {
            $rules += [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
                'contact_number' => ['required', 'string', 'max:30', new PhoneNumber],
                'representative_position' => ['nullable', 'string', 'max:255'],
            ];
        }

        if ($editingOrganization) {
            $rules += [
                'provider_name' => ['required', 'string', 'max:255'],
                'provider_type' => ['nullable', Rule::in(['school', 'foundation', 'government', 'company', 'non_profit', 'other'])],
                'provider_website' => ['nullable', 'string', 'max:255'],
                'provider_address' => ['nullable', 'string', 'max:500'],
                'provider_description' => ['nullable', 'string', 'max:1500'],
                'provider_mission' => ['nullable', 'string', 'max:1000'],
                'provider_year_established' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year],
                'provider_service_area' => ['nullable', 'string', 'max:500'],
                'provider_contact_email' => ['nullable', 'email', 'max:255'],
                'provider_contact_number' => ['nullable', 'string', 'max:30', new PhoneNumber],
                'provider_contact_department' => ['nullable', 'string', 'max:255'],
                'provider_office_hours' => ['nullable', 'string', 'max:255'],
                'legal_name' => ['nullable', 'string', 'max:255'],
                'registration_authority' => ['nullable', 'string', 'max:255'],
                'registration_number' => ['nullable', 'string', 'max:255'],
                'registration_date' => ['nullable', 'date', 'before_or_equal:today'],
            ];
        }

        $validated = $request->validate($rules);

        $representativeProfile = $editingRepresentative ? [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => strtoupper($validated['middle_initial']),
            'contact_number' => $validated['contact_number'],
            'representative_position' => $validated['representative_position'] ?? null,
        ] : [];
        $emailChanged = $editingRepresentative
            && strcasecmp($user->email, $validated['email']) !== 0;
        $profile = $providerOwner->providerProfile;

        $organizationProfile = $editingOrganization ? [
            'provider_name' => $validated['provider_name'],
            'provider_type' => $validated['provider_type'] ?? null,
            'provider_website' => $validated['provider_website'] ?? null,
            'provider_address' => $validated['provider_address'] ?? null,
            'provider_description' => $validated['provider_description'] ?? null,
            'mission' => $validated['provider_mission'] ?? null,
            'year_established' => $validated['provider_year_established'] ?? null,
            'service_area' => $validated['provider_service_area'] ?? null,
            'provider_contact_email' => strtolower(trim((string) ($validated['provider_contact_email']
                ?? $profile?->provider_contact_email
                ?? $providerOwner->email))),
            'provider_contact_number' => $validated['provider_contact_number']
                ?? $profile?->provider_contact_number
                ?? $profile?->contact_number
                ?? ($validated['contact_number'] ?? null),
            'contact_department' => $validated['provider_contact_department'] ?? null,
            'office_hours' => $validated['provider_office_hours'] ?? null,
            'legal_name' => $validated['legal_name'] ?? null,
            'registration_authority' => $validated['registration_authority'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'registration_date' => $validated['registration_date'] ?? null,
            'verification_status' => $profile?->verification_status ?? 'pending',
            'verification_notes' => $profile?->verification_notes,
            'verified_by' => $profile?->verified_by,
            'verified_at' => $profile?->verified_at,
        ] : [];
        $organizationChanged = $editingOrganization
            && $profile?->verification_status === 'approved'
            && collect([
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
                'legal_name',
                'registration_authority',
                'registration_number',
                'registration_date',
            ])->contains(fn (string $field): bool => $this->comparableScholarshipValue($profile?->{$field})
                !== $this->comparableScholarshipValue($organizationProfile[$field] ?? null));

        if ($organizationChanged) {
            $organizationProfile = [
                ...$organizationProfile,
                'verification_status' => 'pending',
                'verification_notes' => null,
                'verified_by' => null,
                'verified_at' => null,
            ];
        }

        DB::transaction(function () use (
            $user,
            $providerOwner,
            $validated,
            $representativeProfile,
            $organizationProfile,
            $profile,
            $editingRepresentative,
            $editingOrganization,
            $emailChanged,
            $organizationChanged,
        ): void {
            if ($editingRepresentative) {
                $user->fill([
                    'email' => $validated['email'],
                    'username' => $validated['username'],
                ]);

                if ($emailChanged) {
                    $user->email_verified_at = null;
                }

                $user->save();
            }

            $user->providerProfile()->updateOrCreate([
                'user_id' => $user->id,
            ], [
                ...$representativeProfile,
                ...$organizationProfile,
            ]);

            if ($editingOrganization && ! $providerOwner->is($user)) {
                $providerOwner->providerProfile()->updateOrCreate([
                    'user_id' => $providerOwner->id,
                ], [
                    'first_name' => $profile?->first_name,
                    'last_name' => $profile?->last_name,
                    'middle_initial' => $profile?->middle_initial,
                    'contact_number' => $profile?->contact_number,
                    ...$organizationProfile,
                ]);
            }

            if ($organizationChanged) {
                $providerOwner->providerVerificationDocuments()->update([
                    'status' => 'submitted',
                    'review_notes' => null,
                ]);
            }
        });

        if ($organizationChanged) {
            User::query()
                ->where('role', 'admin')
                ->where('account_status', 'active')
                ->get()
                ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
                ->each(fn (User $admin) => PortalNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'provider_profile_verification',
                    'title' => 'Verified provider profile changed',
                    'message' => "{$organizationProfile['provider_name']} changed verified organization details and needs another review.",
                    'action_url' => route('admin.providers.review.show', $providerOwner, false),
                ]));
        }

        if ($emailChanged) {
            $emailVerificationSent = false;

            try {
                $user->sendEmailVerificationNotification();
                $emailVerificationSent = true;
            } catch (Throwable $error) {
                ActivityLog::record(
                    $user,
                    'email_verification_email_failed',
                    "Email verification link could not be sent to {$user->email}.",
                    $request,
                    ['error' => $error->getMessage()],
                );
            }

            PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'email_verification',
                'title' => 'Verify your email address',
            ], [
                'message' => $emailVerificationSent
                    ? 'A verification link was sent to your new email address.'
                    : 'Your new email address is not verified. Resend the verification link from the portal.',
                'action_url' => null,
                'read_at' => null,
            ]);
        }

        ActivityLog::record(
            $user,
            'provider_profile_updated',
            ($providerOwner->providerProfile?->provider_name ?: $user->name ?: 'Provider').' updated profile details.',
            $request,
            ['provider_id' => $providerOwner->id, 'updated_by' => $user->id, 'profile_section' => $profileSection],
        );

        return response()->json([
            'message' => $organizationChanged
                ? 'Provider profile updated and returned for admin verification.'
                : ($emailChanged
                    ? 'Profile updated. Verify your new email address before publishing programs.'
                    : ($profileSection === 'organization'
                        ? 'Provider details updated successfully.'
                        : ($profileSection === 'representative'
                            ? 'Representative details updated successfully.'
                            : 'Provider profile updated successfully.'))),
            'user' => [
                ...$this->providerStaffPayload($user->fresh(['providerProfile'])),
                'activity_summary' => $this->providerProfileActivitySummary($providerOwner),
            ],
            'email_changed' => $emailChanged,
            'verification_reset' => $organizationChanged,
        ]);
    }

    public function uploadProviderLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_profile'), 403);

        $request->validate([
            'logo_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $providerOwner = $request->user()->providerOrganizationOwner();
        $profile = $providerOwner->providerProfile()->firstOrCreate([
            'user_id' => $providerOwner->id,
        ]);
        $oldLogoPath = $profile->logo_path;
        $logoPath = $this->storeProviderLogo($request);

        $profile->update(['logo_path' => $logoPath]);
        $this->deleteProviderLogoIfUnused($oldLogoPath);

        ActivityLog::record(
            $request->user(),
            'provider_logo_updated',
            ($profile->provider_name ?: $providerOwner->name ?: 'Provider').' updated the organization logo.',
            $request,
            ['provider_id' => $providerOwner->id],
        );

        return response()->json([
            'message' => 'Provider logo updated successfully.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
        ]);
    }

    public function uploadVerificationDocument(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'document_type' => ['required', Rule::in([
                'organization_registration',
                'authorization_letter',
                'valid_id',
                'school_or_office_proof',
                'other',
            ])],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'terms_accepted' => ['accepted'],
        ]);

        $file = $validated['document_file'];
        $providerOwner = $request->user()->providerOrganizationOwner();
        $path = $file->store("provider-verification/{$providerOwner->id}", 'local');

        $document = ProviderVerificationDocument::create([
            'provider_id' => $providerOwner->id,
            'uploaded_by' => $request->user()->id,
            'document_type' => $validated['document_type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'status' => 'submitted',
            'uploaded_at' => now(),
            'terms_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        $returnedToReview = $providerOwner->providerProfile?->verification_status === 'rejected';

        if ($returnedToReview) {
            $providerOwner->providerProfile()->update([
                'verification_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
            ]);
        }

        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'provider_verification_document',
                'title' => 'Provider document uploaded',
                'message' => "{$providerOwner->provider_name} uploaded a verification document.",
                'action_url' => '/admin/reviews',
            ]));

        ActivityLog::record(
            $request->user(),
            'provider_verification_document_uploaded',
            "{$request->user()->name} uploaded a provider verification document.",
            $request,
            ['document_id' => $document->id, 'document_type' => $document->document_type],
        );

        return response()->json([
            'message' => $returnedToReview
                ? 'Verification proof uploaded and returned for admin review.'
                : 'Verification proof uploaded for admin review.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
            'document' => $this->verificationDocumentPayload($document),
            'verification_documents' => $providerOwner
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ], 201);
    }

    public function deleteVerificationDocument(Request $request, ProviderVerificationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $providerOwner = $request->user()->providerOrganizationOwner();
        abort_unless($document->provider_id === $providerOwner->id, 403);
        $returnedToReview = $providerOwner->providerProfile?->verification_status === 'approved';

        DB::transaction(function () use ($document, $providerOwner, $returnedToReview): void {
            $document->delete();

            if ($returnedToReview) {
                $providerOwner->providerProfile()->update([
                    'verification_status' => 'pending',
                    'verification_notes' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ]);
            }
        });

        if (Storage::disk('local')->exists($document->path)) {
            Storage::disk('local')->delete($document->path);
        }

        if ($returnedToReview) {
            User::query()
                ->where('role', 'admin')
                ->get()
                ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
                ->each(fn (User $admin) => PortalNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'provider_verification_document',
                    'title' => 'Provider proof changed',
                    'message' => "{$providerOwner->provider_name} removed verification proof and needs another review.",
                    'action_url' => '/admin/reviews',
                ]));
        }

        ActivityLog::record(
            $request->user(),
            'provider_verification_document_deleted',
            "{$request->user()->name} removed a provider verification document.",
            $request,
            [
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'provider_id' => $providerOwner->id,
                'returned_to_review' => $returnedToReview,
            ],
        );

        return response()->json([
            'message' => $returnedToReview
                ? 'Verification document removed. Publishing is paused until an admin reviews the provider account again.'
                : 'Verification document removed.',
            'user' => $this->providerStaffPayload($request->user()->fresh(['providerProfile'])),
            'returned_to_review' => $returnedToReview,
            'verification_documents' => $providerOwner
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ]);
    }

    public function downloadVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless(
            $request->user()?->isProvider()
                && $document->provider_id === $request->user()->providerOrganizationId(),
            403,
        );
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless(
            $request->user()?->isProvider()
                && $document->provider_id === $request->user()->providerOrganizationId(),
            403,
        );
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storeProviderLogo(Request $request): string
    {
        $file = $request->file('logo_file');
        $directory = public_path('uploads/providers');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $file->hashName();
        $file->move($directory, $filename);

        return "uploads/providers/{$filename}";
    }

    private function deleteProviderLogoIfUnused(?string $logoPath): void
    {
        $normalizedPath = ltrim(str_replace('\\', '/', (string) $logoPath), '/');

        if (! str_starts_with($normalizedPath, 'uploads/providers/')
            || Scholarship::query()->where('image_path', $normalizedPath)->exists()) {
            return;
        }

        $absolutePath = public_path($normalizedPath);

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function providerStaffPayload(User $user): array
    {
        $owner = $user->providerOrganizationOwner()->loadMissing('providerProfile');
        $profile = $owner->providerProfile;
        $representativeProfile = $user->providerProfile;

        return [
            ...$user->publicPayload(),
            'provider_name' => $profile?->provider_name,
            'provider_type' => $profile?->provider_type,
            'provider_website' => $profile?->provider_website,
            'provider_address' => $profile?->provider_address,
            'provider_description' => $profile?->provider_description,
            'provider_mission' => $profile?->mission,
            'provider_year_established' => $profile?->year_established,
            'provider_service_area' => $profile?->service_area,
            'provider_logo_path' => $profile?->logo_path,
            'provider_logo_url' => filled($profile?->logo_path)
                ? asset(ltrim($profile->logo_path, '/'))
                : null,
            'provider_contact_email' => $profile?->provider_contact_email,
            'provider_contact_number' => $profile?->provider_contact_number,
            'provider_contact_department' => $profile?->contact_department,
            'provider_office_hours' => $profile?->office_hours,
            'representative_position' => $representativeProfile?->representative_position,
            ...($user->hasPortalPermission('manage_profile') ? [
                'legal_name' => $profile?->legal_name,
                'registration_authority' => $profile?->registration_authority,
                'registration_number' => $profile?->registration_number,
                'registration_date' => $profile?->registration_date?->format('Y-m-d'),
            ] : []),
            'verification_status' => $profile?->verification_status,
            'verification_notes' => $profile?->verification_notes,
        ];
    }

    private function providerProfileActivitySummary(User $provider): array
    {
        $programs = Scholarship::query()->where('provider_id', $provider->id);
        $applications = ScholarshipApplication::query()
            ->whereHas('scholarship', fn (Builder $query) => $query->where('provider_id', $provider->id));

        return [
            'programs' => (clone $programs)->count(),
            'published_programs' => (clone $programs)->where('status', 'published')->count(),
            'applications' => (clone $applications)->count(),
            'selected_recipients' => (clone $applications)
                ->where(function (Builder $query): void {
                    $query->where('final_outcome', 'selected')
                        ->orWhereIn('status', [...self::AWARD_SLOT_STATUSES, 'benefits_terminated']);
                })
                ->count(),
            'benefits_released' => RecipientBenefitReleaseRecord::query()
                ->where('status', 'released')
                ->whereHas('release.scholarship', fn (Builder $query) => $query->where('provider_id', $provider->id))
                ->count(),
        ];
    }

    private function verificationDocumentPayload(ProviderVerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'ocr_status' => $document->ocr_status ?? AcademicRecordOcrService::STATUS_NOT_REQUESTED,
            'ocr_provider' => $document->ocr_provider,
            'ocr_grade' => $document->ocr_grade,
            'ocr_grading_scale' => $document->ocr_grading_scale,
            'ocr_label' => $document->ocr_label,
            'ocr_message' => $document->ocr_message,
            'ocr_processed_at' => $document->ocr_processed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('provider.verification-documents.view', $document),
            'download_url' => route('provider.verification-documents.download', $document),
        ];
    }

}
