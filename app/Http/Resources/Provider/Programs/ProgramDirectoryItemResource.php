<?php

namespace App\Http\Resources\Provider\Programs;

use App\Domain\Provider\Programs\ProgramLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramDirectoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lifecycle = app(ProgramLifecycle::class);
        $monitoringPlan = $this->monitoringPlan;
        $monitoringStatus = $monitoringPlan?->status;

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'cycle' => $this->program_cycle,
            'logo_url' => $this->logoUrl(),
            'image_url' => $this->logoUrl(),
            'status' => $lifecycle->status($this->status),
            'phase' => $lifecycle->phase($this->status),
            'application_period' => [
                'opens' => $this->application_opens_at?->format('Y-m-d'),
                'opens_display' => $this->application_opens_at?->format('M d, Y'),
                'deadline' => $lifecycle->deadline($this->resource),
            ],
            'applications' => [
                'received' => (int) ($this->applications_count ?? 0),
                'limit' => $this->application_limit === null ? null : (int) $this->application_limit,
            ],
            'slots' => $this->slots_available === null ? null : (int) $this->slots_available,
            'monitoring' => [
                'status' => $monitoringStatus,
                'status_label' => match ($monitoringStatus) {
                    'active' => 'Active',
                    'draft' => 'Draft',
                    default => 'Not set up',
                },
                'status_tone' => match ($monitoringStatus) {
                    'active' => 'positive',
                    'draft' => 'attention',
                    default => 'neutral',
                },
                'frequency_label' => $monitoringPlan?->frequency
                    ? str($monitoringPlan->frequency)->headline()->toString()
                    : null,
                'requirements_count' => (int) ($monitoringPlan?->requirements_count ?? 0),
                'check_ins_count' => (int) ($this->monitoring_cycles_count ?? 0),
                'plan_url' => "/provider/monitoring/{$this->id}/plan",
                'can_edit_plan' => $request->user()?->hasPortalPermission('manage_programs') ?? false,
                'can_publish_check_in' => $request->user()?->hasPortalPermission('manage_monitoring') ?? false,
            ],
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_display' => $this->updated_at?->format('M d, Y'),
            'record_url' => $lifecycle->recordUrl($this->resource),
        ];
    }
}
