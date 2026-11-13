<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\RecipientMonitoringCase;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipientCheckInAppointmentService
{
    public function update(RecipientMonitoringCase $case, User $actor, array $input): void
    {
        DB::transaction(function () use ($case, $actor, $input): void {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($case->scholarship_application_id);
            $case->refresh();
            $cycle = $case->cycle;
            if (! $cycle->isInPerson() || $cycle->status !== 'open' || $case->isClosed()
                || $application->student_response_status !== 'accepted'
                || $application->status === 'benefits_terminated'
                || in_array($application->supportDecisions()->first()?->decision, ['completed', 'terminated'], true)) {
                throw ValidationException::withMessages(['appointment' => 'This recipient does not have an open in-person appointment.']);
            }

            $previous = $cycle->handoffDetails($case);
            $previousAttendance = $case->attendance_status;
            $reason = trim($input['reason'] ?? '');
            if ($input['action'] === 'reschedule') {
                if ($previousAttendance === 'attended') {
                    throw ValidationException::withMessages(['appointment' => 'An attended appointment cannot be rescheduled.']);
                }
                $time = CarbonImmutable::parse($input['appointment_at'], config('app.timezone'));
                $location = Str::squish($input['appointment_location']);
                $plan = $cycle->plan;
                if (! $time->isFuture()
                    || ($plan?->starts_on && $time->startOfDay()->lt($plan->starts_on))
                    || ($plan?->ends_on && $time->startOfDay()->gt($plan->ends_on))) {
                    throw ValidationException::withMessages(['appointment_at' => 'Choose a future date and time within the monitoring plan.']);
                }
                if ($time->equalTo($cycle->handoffTime($case)) && $location === $previous['location']) {
                    throw ValidationException::withMessages(['appointment_at' => 'Choose a different time or venue.']);
                }

                $case->update(['appointment_at' => $time, 'appointment_location' => $location, 'address_details' => $input['address_details'] ?? null, 'attendance_status' => null]);
                $current = $cycle->handoffDetails($case);
                app(RecipientMonitoringReminderService::class)->markCaseResolved($case);
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-monitoring-check-in:{$cycle->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_monitoring_request',
                    'title' => 'In-person check-in rescheduled',
                    'message' => "{$cycle->title}: your new appointment is {$current['scheduled_label']} at {$current['location']}.",
                    'action_url' => route('dashboard.monitoring.check-ins.show', ['application' => $application, 'record' => 'check-in-'.$cycle->id], false),
                    'read_at' => null,
                ]);
                $action = 'appointment_rescheduled';
                $metadata = ['previous_appointment' => $previous, 'appointment' => $current, 'previous_attendance' => $previousAttendance];
            } else {
                $attendance = $input['attendance_status'];
                if ($cycle->handoffTime($case)->isFuture()) {
                    throw ValidationException::withMessages(['attendance_status' => 'Record attendance once the appointment begins.']);
                }
                if ($attendance === $previousAttendance) {
                    return;
                }
                if ($previousAttendance !== null && mb_strlen($reason) < 10) {
                    throw ValidationException::withMessages(['reason' => 'Explain why the saved attendance is changing.']);
                }
                if ($attendance === 'no_show' && $application->monitoringSubmissions()
                    ->where('recipient_monitoring_cycle_id', $cycle->id)
                    ->whereIn('review_status', ['met', 'not_met'])
                    ->exists()) {
                    throw ValidationException::withMessages(['attendance_status' => 'Attendance cannot be marked missed after a document result is recorded.']);
                }

                $case->update(['attendance_status' => $attendance]);
                $action = 'attendance_recorded';
                $metadata = ['previous_attendance' => $previousAttendance, 'attendance' => $attendance, 'appointment' => $previous];
            }

            $case->events()->create([
                'actor_id' => $actor->id,
                'action' => $action,
                'from_status' => $case->status,
                'to_status' => $case->status,
                'reason' => $reason ?: null,
                'metadata' => $metadata,
            ]);
            app(RecipientMonitoringCaseService::class)->sync($cycle, $application);
        });
    }
}
