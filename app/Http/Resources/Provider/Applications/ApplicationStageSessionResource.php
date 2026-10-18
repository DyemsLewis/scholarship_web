<?php

namespace App\Http\Resources\Provider\Applications;

use App\Models\ApplicationStageAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationStageSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeAssignments = $this->relationLoaded('assignments')
            ? $this->assignments->whereIn('status', ['assigned', 'acknowledged'])
            : collect();
        $activeCount = $this->relationLoaded('assignments')
            ? $activeAssignments->count()
            : (int) ($this->active_assignments_count ?? 0);

        return [
            'id' => (int) $this->id,
            'program' => $this->whenLoaded('scholarship', fn () => [
                'id' => (int) $this->scholarship->id,
                'title' => $this->scholarship->title,
            ]),
            'stage_key' => $this->stage_key,
            'title' => $this->title,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'mode' => $this->mode,
            'venue' => $this->venue,
            'location_address' => $this->location_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'meeting_url' => $this->meeting_url,
            'instructions' => $this->instructions,
            'capacity' => [
                'limit' => $this->capacity === null ? null : (int) $this->capacity,
                'assigned' => $activeCount,
                'remaining' => $this->capacity === null
                    ? null
                    : max(0, (int) $this->capacity - $activeCount),
            ],
            'proof_required' => (bool) $this->proof_required,
            'counts' => [
                'proof' => (int) ($this->current_evidence_count ?? ($this->relationLoaded('evidence')
                    ? $this->evidence->where('is_current', true)->count()
                    : 0)),
                'pending_attendance' => (int) ($this->pending_attendance_count ?? 0),
            ],
            'published_at' => $this->published_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments
                ->map(fn (ApplicationStageAssignment $assignment): array => [
                    'id' => (int) $assignment->id,
                    'status' => $assignment->status,
                    'notes' => $assignment->notes,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'acknowledged_at' => $assignment->acknowledged_at?->toIso8601String(),
                    'application' => $assignment->application ? [
                        'id' => (int) $assignment->application->id,
                        'applicant' => $assignment->application->applicant ? [
                            'id' => (int) $assignment->application->applicant->id,
                            'name' => $assignment->application->applicant->name,
                            'profile_photo_url' => $assignment->application->applicant->studentProfile?->profile_photo_path
                                ? route('provider.applications.profile-photo.view', $assignment->application, false)
                                : null,
                        ] : null,
                        'result_url' => route('provider.applications.show', [
                            'application' => $assignment->application,
                            'workspace' => 'selection',
                            'return_to' => '/provider/workspaces/selection/results',
                        ], false),
                    ] : null,
                    'attendance' => $assignment->attendance ? [
                        'status' => $assignment->attendance->status,
                        'notes' => $assignment->attendance->notes,
                        'recorded_at' => $assignment->attendance->recorded_at?->toIso8601String(),
                    ] : null,
                    'evidence' => ApplicationStageEvidenceResource::collection($assignment->evidence),
                ])
                ->values()),
            'evidence' => $this->whenLoaded(
                'evidence',
                fn () => ApplicationStageEvidenceResource::collection($this->evidence),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
