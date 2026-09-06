<?php

namespace App\Services;

use App\Models\ApplicationSchedule;
use App\Models\PortalNotification;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Support\Facades\DB;

class ScholarshipEventService
{
    public function __construct(private readonly ApplicationWorkflowService $workflowService) {}

    public function syncEligibleApplications(ScholarshipEvent $event): int
    {
        if ($event->status !== 'scheduled' || ! ScholarshipSelectionPlan::isSchedulable($event->type)) {
            return 0;
        }

        $applications = ScholarshipApplication::query()
            ->where('scholarship_id', $event->scholarship_id)
            ->with(['applicant', 'schedules', 'scholarship'])
            ->get();

        return $applications
            ->filter(fn (ScholarshipApplication $application) => $this->syncEventToApplication($event, $application) !== null)
            ->count();
    }

    public function syncApplication(ScholarshipApplication $application): int
    {
        $application->loadMissing(['scholarship.events', 'schedules', 'applicant']);

        return $application->scholarship->events
            ->where('status', 'scheduled')
            ->filter(fn (ScholarshipEvent $event) => ScholarshipSelectionPlan::isSchedulable($event->type))
            ->filter(fn (ScholarshipEvent $event) => $this->syncEventToApplication($event, $application) !== null)
            ->count();
    }

    public function syncEventToApplication(
        ScholarshipEvent $event,
        ScholarshipApplication $application,
    ): ?ApplicationSchedule {
        if ($event->status !== 'scheduled' || ! ScholarshipSelectionPlan::isSchedulable($event->type)) {
            return null;
        }

        [$schedule, $announcementChanged, $lockedApplication] = DB::transaction(function () use ($event, $application): array {
            $lockedApplication = ScholarshipApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedApplication) {
                return [null, false, null];
            }

            $lockedApplication = $this->workflowService->initialize($lockedApplication);
            $lockedApplication->loadMissing(['schedules', 'applicant', 'scholarship']);
            $schedule = $lockedApplication->schedules->firstWhere('type', $event->type);
            $isAtStage = $lockedApplication->workflow_stage === $event->type;

            if (! $isAtStage || ($schedule && $schedule->status !== 'scheduled')) {
                return [null, false, $lockedApplication];
            }

            $announcementData = [
                'title' => $event->title,
                'scheduled_at' => $event->scheduled_at,
                'mode' => $event->mode,
                'venue' => $event->venue,
                'location_address' => $event->location_address,
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'online_url' => $event->online_url,
                'instructions' => $event->instructions,
                'attendance_status' => 'not_required',
                'updated_by' => $event->updated_by ?? $event->created_by,
            ];

            if ($schedule) {
                $schedule->fill($announcementData);
                $announcementChanged = $schedule->isDirty(array_keys($announcementData));

                if ($announcementChanged) {
                    $schedule->save();
                }
            } else {
                $schedule = $lockedApplication->schedules()->create([
                    ...$announcementData,
                    'type' => $event->type,
                    'status' => 'scheduled',
                    'attendance_status' => 'not_required',
                    'created_by' => $event->created_by,
                ]);
                $announcementChanged = true;
            }

            return [$schedule, $announcementChanged, $lockedApplication];
        });

        if (! $schedule || ! $lockedApplication) {
            return null;
        }

        if ($announcementChanged) {
            $eventLabel = ScholarshipSelectionPlan::label($event->type);
            $destination = $event->mode === 'online'
                ? ' online'
                : ' at '.($event->venue ?: $event->location_address ?: 'the provider location');

            PortalNotification::create([
                'user_id' => $lockedApplication->applicant_id,
                'type' => 'application_schedule',
                'title' => ucfirst($eventLabel).' schedule posted',
                'message' => "Your {$eventLabel} for {$lockedApplication->scholarship?->title} is scheduled for {$event->scheduled_at?->format('M d, Y h:i A')}{$destination}. Open the application to review the details.",
                'action_url' => route('dashboard.applications.show', $lockedApplication, false),
            ]);
        }

        return $schedule;
    }
}
