<?php

namespace App\Services;

use App\Models\ApplicationSchedule;
use App\Models\PortalNotification;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Support\ScholarshipSelectionPlan;

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

        $application = $this->workflowService->initialize($application);
        $application->loadMissing(['schedules', 'applicant', 'scholarship']);
        $schedule = $application->schedules->firstWhere('type', $event->type);
        $isAtStage = $application->workflow_stage === $event->type;

        if (! $isAtStage || ($schedule && $schedule->status !== 'scheduled')) {
            return null;
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
            $schedule = $application->schedules()->create([
                ...$announcementData,
                'type' => $event->type,
                'status' => 'scheduled',
                'attendance_status' => 'not_required',
                'created_by' => $event->created_by,
            ]);
            $application->schedules->push($schedule);
            $announcementChanged = true;
        }

        if ($announcementChanged) {
            $eventLabel = ScholarshipSelectionPlan::label($event->type);
            $destination = $event->mode === 'online'
                ? ' online'
                : ' at '.($event->venue ?: $event->location_address ?: 'the provider location');

            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => ucfirst($eventLabel).' schedule posted',
                'message' => "Your {$eventLabel} for {$application->scholarship?->title} is scheduled for {$event->scheduled_at?->format('M d, Y h:i A')}{$destination}. Open the application to review the details.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        return $schedule;
    }
}
