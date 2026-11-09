<?php

namespace App\Http\Resources\Provider\Programs;

use App\Domain\Provider\Programs\ProgramLifecycle;
use App\Queries\Provider\Programs\ProgramWorkspaceLinks;
use App\Support\EducationEligibilityPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProgramRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lifecycle = app(ProgramLifecycle::class);
        $actor = $request->user();
        $canManage = $actor->hasPortalPermission('manage_programs');
        $workspaces = app(ProgramWorkspaceLinks::class)->forProgram($actor, $this->resource);
        $stages = collect($this->selection_stages ?? [])
            ->map(function ($stage): ?string {
                $value = is_array($stage)
                    ? ($stage['label'] ?? $stage['name'] ?? $stage['type'] ?? null)
                    : $stage;

                return filled($value) ? Str::headline((string) $value) : null;
            })
            ->filter()
            ->values()
            ->all();

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'cycle' => $this->program_cycle,
            'description' => $this->description,
            'education_eligibility_policy' => EducationEligibilityPolicy::normalize($this->education_eligibility_policy),
            'eligibility_rule_modes' => $this->eligibilityRuleModes(),
            'logo_url' => $this->logoUrl(),
            'cover_image_url' => $this->coverImageUrl(),
            'status' => $lifecycle->status($this->status),
            'timeline' => [
                'opens' => $this->application_opens_at?->format('M d, Y'),
                'deadline' => $lifecycle->deadline($this->resource),
                'results' => $this->expected_results_at?->format('M d, Y'),
                'support_starts' => $this->support_starts_at?->format('M d, Y'),
                'support_ends' => $this->support_ends_at?->format('M d, Y'),
            ],
            'capacity' => [
                'applications' => (int) ($this->applications_count ?? 0),
                'application_limit' => $this->application_limit === null ? null : (int) $this->application_limit,
                'recipient_slots' => $this->slots_available === null ? null : (int) $this->slots_available,
            ],
            'support' => [
                'summary' => $this->benefitSummary(),
                'benefit_count' => $this->benefits->count(),
            ],
            'selection_stages' => $stages,
            'workspace_links' => $workspaces['links'],
            'actions' => [
                'directory_url' => $canManage ? route('provider.programs', [], false) : null,
                'preview_url' => $canManage ? route('provider.previews.programs.show', $this->resource, false) : null,
                'preview_data_url' => $canManage ? route('provider.previews.programs.data', $this->resource, false) : null,
                'monitoring_plan_url' => $workspaces['monitoring_plan_url'],
                'edit_url' => ! $canManage || $this->status === 'pending_review'
                    ? null
                    : route('provider.programs.edit.step', [
                        'scholarship' => $this->id,
                        'step' => 'basics',
                    ], false),
            ],
            'updated_display' => $this->updated_at?->format('M d, Y h:i A'),
        ];
    }
}
