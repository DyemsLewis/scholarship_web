<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ProviderVerificationDocument;
use App\Models\User;
use Illuminate\Support\Str;

final class OrganizationProfileManagerDashboardQuery
{
    /** @return array<string, mixed> */
    public function get(User $manager): array
    {
        $owner = $manager->providerOrganizationOwner()->loadMissing('providerProfile');
        $profile = $owner->providerProfile;
        $documents = $owner->providerVerificationDocuments()
            ->latest('uploaded_at')
            ->latest('id')
            ->get();
        $areas = collect([
            $this->area('public', 'Public profile', [
                'Organization name' => $profile?->provider_name,
                'Provider type' => $profile?->provider_type,
                'Description' => $profile?->provider_description,
                'Logo' => $profile?->logo_path,
            ], '/provider/workspaces/organization-profile/public'),
            $this->area('contact', 'Public contact', [
                'Contact email' => $profile?->provider_contact_email,
                'Contact number' => $profile?->provider_contact_number,
            ], '/provider/workspaces/organization-profile/contact'),
            $this->area('location', 'Location and reach', [
                'Office address' => $profile?->provider_address,
                'Service area' => $profile?->service_area,
            ], '/provider/workspaces/organization-profile/contact'),
            $this->area('verification', 'Legal verification', [
                'Legal name' => $profile?->legal_name,
                'Registration authority' => $profile?->registration_authority,
                'Registration number' => $profile?->registration_number,
                'Verification proof' => $documents->isNotEmpty() ? 'uploaded' : null,
            ], '/provider/workspaces/organization-profile/verification'),
        ]);
        $totalFields = $areas->sum('total');
        $completeFields = $areas->sum('complete');
        $completion = $totalFields > 0 ? (int) round(($completeFields / $totalFields) * 100) : 0;
        $verification = match (true) {
            $profile?->verification_status === 'approved' => 'Verified',
            $profile?->verification_status === 'rejected' => 'Changes requested',
            $documents->isEmpty() => 'Not submitted',
            default => 'Pending review',
        };
        $priorities = $areas
            ->where('ready', false)
            ->map(fn (array $area): array => $this->areaItem($area));

        if ($profile?->verification_status === 'rejected') {
            $priorities->prepend([
                'id' => 'verification-rejected',
                'title' => 'Replace rejected verification proof',
                'subtitle' => $profile->verification_notes ?: 'Administrator changes are required.',
                'image_url' => null,
                'status' => ['label' => 'Changes requested', 'tone' => 'attention'],
                'meta' => ['Open the legal verification page', 'Upload corrected organization evidence'],
                'action' => ['label' => 'Review proof', 'url' => '/provider/workspaces/organization-profile/verification'],
            ]);
        }

        $recentDocuments = $documents
            ->take(5)
            ->map(fn (ProviderVerificationDocument $document): array => [
                'id' => 'document-'.$document->id,
                'title' => $document->original_name ?: Str::headline($document->document_type),
                'subtitle' => Str::headline($document->document_type),
                'image_url' => null,
                'status' => [
                    'label' => match ($document->status) {
                        'approved' => 'Approved',
                        'rejected' => 'Changes requested',
                        default => 'Pending review',
                    },
                    'tone' => $document->status === 'approved'
                        ? 'positive'
                        : ($document->status === 'rejected' ? 'attention' : 'neutral'),
                ],
                'meta' => [
                    $document->uploaded_at ? 'Uploaded '.$document->uploaded_at->format('M d, Y') : 'Upload date unavailable',
                    $document->review_notes ?: 'Organization verification evidence',
                ],
                'action' => ['label' => 'Open verification', 'url' => '/provider/workspaces/organization-profile/verification'],
            ]);

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'organization-profile-manager',
                'eyebrow' => 'Organization identity',
                'title' => 'Profile dashboard',
                'description' => 'Keep public organization details complete and legal proof ready for review.',
                'icon' => 'fa-solid fa-building-shield',
                'organization_name' => $profile?->provider_name ?: $owner->name,
                'access_scope' => 'Organization profile',
            ],
            primaryAction: [
                'label' => $priorities->isNotEmpty() ? 'Continue profile setup' : 'Review profile readiness',
                'url' => $priorities->first()['action']['url'] ?? '/provider/workspaces/organization-profile/readiness',
            ],
            metrics: [
                ['key' => 'completion', 'label' => 'Profile completion', 'value' => $completion.'%', 'detail' => $completeFields.' of '.$totalFields.' required details', 'tone' => $completion === 100 ? 'positive' : 'attention', 'url' => '/provider/workspaces/organization-profile/readiness'],
                ['key' => 'ready', 'label' => 'Ready areas', 'value' => $areas->where('ready', true)->count(), 'detail' => 'Of '.$areas->count().' profile areas'],
                ['key' => 'verification', 'label' => 'Verification', 'value' => $verification, 'detail' => $documents->count().' proof file'.($documents->count() === 1 ? '' : 's'), 'tone' => $profile?->verification_status === 'approved' ? 'positive' : ($profile?->verification_status === 'rejected' ? 'attention' : 'neutral'), 'url' => '/provider/workspaces/organization-profile/verification'],
                ['key' => 'updated', 'label' => 'Last updated', 'value' => $profile?->updated_at?->format('M d') ?: 'Not set', 'detail' => $profile?->updated_at?->format('Y') ?: 'Complete the profile'],
            ],
            sections: [
                [
                    'key' => 'profile-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Profile tasks',
                    'description' => 'Only incomplete or rejected profile areas appear here.',
                    'empty_message' => 'All required profile areas are complete.',
                    'action' => ['label' => 'Open readiness', 'url' => '/provider/workspaces/organization-profile/readiness'],
                    'items' => $priorities,
                ],
                [
                    'key' => 'verification-files',
                    'eyebrow' => 'Verification record',
                    'title' => 'Recent proof files',
                    'description' => 'Latest organization evidence submitted for administrator review.',
                    'empty_message' => 'No organization verification proof has been uploaded.',
                    'action' => ['label' => 'Manage proof', 'url' => '/provider/workspaces/organization-profile/verification'],
                    'items' => $recentDocuments,
                ],
            ],
        );
    }

    /** @param array<string, mixed> $fields */
    private function area(string $key, string $title, array $fields, string $url): array
    {
        $missing = collect($fields)->filter(fn (mixed $value): bool => blank($value))->keys()->values();

        return [
            'key' => $key,
            'title' => $title,
            'url' => $url,
            'missing' => $missing,
            'complete' => count($fields) - $missing->count(),
            'total' => count($fields),
            'ready' => $missing->isEmpty(),
        ];
    }

    /** @param array<string, mixed> $area */
    private function areaItem(array $area): array
    {
        $missing = $area['missing'];

        return [
            'id' => 'area-'.$area['key'],
            'title' => 'Complete '.$area['title'],
            'subtitle' => $missing->count().' required detail'.($missing->count() === 1 ? '' : 's').' missing',
            'image_url' => null,
            'status' => ['label' => 'Incomplete', 'tone' => 'attention'],
            'meta' => [
                'Add '.$missing->take(2)->implode(' and '),
                $area['complete'].' of '.$area['total'].' complete',
            ],
            'action' => ['label' => 'Complete area', 'url' => $area['url']],
        ];
    }
}
