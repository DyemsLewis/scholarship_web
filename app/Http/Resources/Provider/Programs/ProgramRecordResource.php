<?php

namespace App\Http\Resources\Provider\Programs;

use App\Domain\Provider\Programs\ProgramLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProgramRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lifecycle = app(ProgramLifecycle::class);
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
            'actions' => [
                'edit_url' => $this->status === 'pending_review'
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
