<?php

namespace App\Http\Resources\Provider\Programs;

use App\Domain\Provider\Programs\ProgramLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramDashboardItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lifecycle = app(ProgramLifecycle::class);

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'cycle' => $this->program_cycle,
            'status' => $lifecycle->status($this->status),
            'deadline' => $lifecycle->deadline($this->resource),
            'updated_display' => $this->updated_at?->format('M d, Y'),
            'record_url' => $lifecycle->recordUrl($this->resource),
            'priority_label' => match ($this->status) {
                'rejected' => 'Address requested changes',
                'draft' => 'Complete the program draft',
                default => null,
            },
        ];
    }
}
