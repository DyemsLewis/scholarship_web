<?php

namespace App\Support;

use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class MonitoringPlanDateRules
{
    public static function assertConsistent(array $values, Scholarship $program, ?RecipientMonitoringPlan $plan): void
    {
        $start = filled($values['starts_on'] ?? null) ? CarbonImmutable::parse($values['starts_on'])->startOfDay() : $program->support_starts_at;
        $end = filled($values['ends_on'] ?? null) ? CarbonImmutable::parse($values['ends_on'])->startOfDay() : $program->support_ends_at;
        $savedEnd = $plan ? ($plan->ends_on ?? $program->support_ends_at) : null;
        $keepsHistoricalEnd = $plan?->status === 'active' && $end && $savedEnd && $end->isSameDay($savedEnd);
        $errors = [];
        if ($start && $end && $end->isBefore($start)) {
            $errors['ends_on'] = 'The monitoring end date must be on or after the start date.';
        } elseif ($end?->isBefore(CarbonImmutable::today()) && ! $keepsHistoricalEnd) {
            $errors['ends_on'] = 'The monitoring end date must be today or a future date.';
        }
        foreach (['starts_on' => $start, 'ends_on' => $end] as $field => $date) {
            if ($date && (($program->support_starts_at && $date->isBefore($program->support_starts_at))
                || ($program->support_ends_at && $date->isAfter($program->support_ends_at)))) {
                $errors[$field] = 'Keep the monitoring dates within the program support period.';
            }
        }
        // Existing appointments must remain inside their plan when its dates change.
        if ($plan && ($plan->starts_on?->toDateString() !== ($values['starts_on'] ?? null)
            || $plan->ends_on?->toDateString() !== ($values['ends_on'] ?? null))) {
            foreach ($plan->cycles()->where('status', '!=', 'cancelled')->with('cases:id,recipient_monitoring_cycle_id,appointment_at')->get() as $cycle) {
                $dates = [$cycle->opens_at, $cycle->due_at, $cycle->handoff_at, ...$cycle->cases->pluck('appointment_at')];
                foreach (array_filter($dates) as $date) {
                    if ($start && $date->copy()->startOfDay()->isBefore($start)) $errors['starts_on'] = 'The start date cannot exclude an existing check-in or appointment.';
                    if ($end && $date->copy()->startOfDay()->isAfter($end)) $errors['ends_on'] = 'The end date cannot exclude an existing check-in or appointment.';
                }
            }
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }
}
