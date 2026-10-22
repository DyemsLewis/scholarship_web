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

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'cycle' => $this->program_cycle,
            'image_url' => $this->coverImageUrl(),
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
            'updated_at' => $this->updated_at?->toISOString(),
            'updated_display' => $this->updated_at?->format('M d, Y'),
            'record_url' => $lifecycle->recordUrl($this->resource),
        ];
    }
}
