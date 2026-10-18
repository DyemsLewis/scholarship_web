<?php

namespace App\Http\Resources\Provider\Applications;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationStageEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'scope' => $this->scope,
            'category' => $this->category,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'event_date' => $this->event_date?->toDateString(),
            'note' => $this->note,
            'visibility' => $this->visibility,
            'is_current' => (bool) $this->is_current,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'uploaded_by' => $this->uploader ? [
                'id' => (int) $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null,
            'view_url' => route('provider.stage-evidence.view', $this->resource, false),
        ];
    }
}
