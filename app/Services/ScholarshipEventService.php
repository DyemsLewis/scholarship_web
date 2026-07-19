<?php

namespace App\Services;

use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\ScholarshipFunnelEvent;
use App\Support\ScholarshipSelectionPlan;

class ScholarshipEventService
{
    public function syncEligibleApplications(ScholarshipEvent $event): int
    {
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
            ->filter(fn (ScholarshipEvent $event) => in_array(
                $event->type,
                ScholarshipSelectionPlan::normalize($application->scholarship->selection_stages),
                true,
            ))
            ->filter(fn (ScholarshipEvent $event) => $this->syncEventToApplication($event, $application) !== null)
            ->count();
    }

    public function syncEventToApplication(
        ScholarshipEvent $event,
        ScholarshipApplication $application,
    ): ?ApplicationSchedule {
        $application->loadMissing(['schedules', 'applicant', 'scholarship']);
        $schedule = $application->schedules->firstWhere('type', $event->type);
        $isAtStage = in_array($application->status, ScholarshipSelectionPlan::stageStatuses($event->type), true);

        if (! $schedule && ! $isAtStage) {
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
            'updated_by' => $event->updated_by ?? $event->created_by,
        ];

        if ($schedule) {
            $schedule->fill($announcementData);
            $announcementChanged = $schedule->isDirty(array_keys($announcementData));

            if ($announcementChanged) {
                $schedule->applicant_acknowledged_at = null;
                $schedule->save();
            }
        } else {
            $schedule = $application->schedules()->create([
                ...$announcementData,
                'type' => $event->type,
                'status' => 'scheduled',
                'attendance_status' => 'pending',
                'created_by' => $event->created_by,
            ]);
            $application->schedules->push($schedule);
            $announcementChanged = true;
        }

        $previousStatus = $application->status;
        $nextStatus = $isAtStage ? ScholarshipSelectionPlan::scheduledStatus($event->type) : $previousStatus;

        if ($nextStatus !== $previousStatus) {
            $updates = [
                'status' => $nextStatus,
                'decision_reason' => ScholarshipSelectionPlan::decisionReason($event->type),
                'reviewed_by' => $event->updated_by ?? $event->created_by,
                'reviewed_at' => now(),
            ];

            if ($event->type === 'distribution') {
                $updates['distribution_scheduled_for'] = $event->scheduled_at?->toDateString();
                $updates['distribution_instructions'] = $event->instructions;
            }

            $application->update($updates);

            ApplicationStatusHistory::create([
                'scholarship_application_id' => $application->id,
                'changed_by' => $event->updated_by ?? $event->created_by,
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'decision_reason' => ScholarshipSelectionPlan::decisionReason($event->type),
                'review_notes' => ucfirst(ScholarshipSelectionPlan::label($event->type)).' schedule applied from the program plan.',
                'changed_at' => now(),
            ]);

            ScholarshipFunnelEvent::record(
                $application->applicant,
                "application_status_{$nextStatus}",
                $application->scholarship,
                $application,
                'provider',
                ['scholarship_event_id' => $event->id, 'schedule_type' => $event->type],
            );
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
                'message' => "Your {$eventLabel} for {$application->scholarship?->title} is scheduled for {$event->scheduled_at?->format('M d, Y h:i A')}{$destination}. Open the application to review and acknowledge it.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        return $schedule;
    }
}
