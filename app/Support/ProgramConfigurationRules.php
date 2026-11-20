<?php

namespace App\Support;

use App\Models\Scholarship;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ProgramConfigurationRules
{
    public const FIELDS = [
        'slots_available', 'application_limit', 'application_opens_at', 'deadline',
        'expected_results_at', 'handoff_deadline', 'support_starts_at', 'support_ends_at',
    ];

    private const DATE_LABELS = [
        'application_opens_at' => 'application opening date', 'deadline' => 'application deadline',
        'expected_results_at' => 'expected final results date', 'handoff_deadline' => 'formal application deadline',
        'support_starts_at' => 'support start date', 'support_ends_at' => 'support end date',
    ];

    public static function errors(array $attributes, ?Scholarship $scholarship = null, bool $enforceFullTimeline = false): array
    {
        // Updates must be checked against the saved counterpart, not only submitted fields.
        $values = array_replace($scholarship?->only(self::FIELDS) ?? [], $attributes);
        $errors = [];
        if (filled($values['application_limit'] ?? null) && filled($values['slots_available'] ?? null)
            && (int) $values['application_limit'] < (int) $values['slots_available']) {
            $errors['application_limit'] = 'The application limit must be at least the number of recipient slots.';
        }

        $preserveHistoricalDates = in_array($scholarship?->status, ['published', 'closed'], true);
        $timelineChanged = ! $preserveHistoricalDates;
        $dates = [];
        $today = CarbonImmutable::today()->toDateString();
        foreach (self::DATE_LABELS as $field => $label) {
            $date = $dates[$field] = filled($values[$field] ?? null) ? CarbonImmutable::parse($values[$field])->toDateString() : '';
            $savedDate = $scholarship?->getAttribute($field)?->toDateString() ?? '';
            $keepsHistoricalDate = $preserveHistoricalDates && $date === $savedDate;
            $timelineChanged = $timelineChanged || ! $keepsHistoricalDate;
            if ($date !== '' && $date < $today && ! $keepsHistoricalDate) {
                $errors[$field] = "The {$label} must be today or a future date.";
            }
        }

        // Leave untouched live timelines alone, but enforce the order for edited dates and publication.
        return ($enforceFullTimeline || $timelineChanged) ? array_replace(self::timelineErrors($dates), $errors) : $errors;
    }

    private static function timelineErrors(array $dates): array
    {
        $deadline = $dates['deadline'];
        $handoff = $dates['handoff_deadline'];
        $results = $dates['expected_results_at'];
        $start = $dates['support_starts_at'];
        $end = $dates['support_ends_at'];
        $errors = [];

        if ($deadline !== '' && $dates['application_opens_at'] > $deadline) {
            $errors['application_opens_at'] = 'Applications must open on or before the application deadline.';
        }
        if ($deadline === '' && ($handoff !== '' || $results !== '' || $start !== '' || $end !== '')) {
            $errors['deadline'] = 'Set the application deadline before adding formal application, results, or support dates.';
        }
        if ($deadline !== '' && $handoff !== '' && $handoff <= $deadline) {
            $errors['handoff_deadline'] = 'The formal application deadline must be after applications close.';
        }

        $lastDeadline = max($deadline, $handoff);
        if ($lastDeadline !== '' && $results !== '' && $results <= $lastDeadline) {
            $errors['expected_results_at'] = 'Expected final results must be after the last application deadline.';
        }
        if ($start !== '' && $lastDeadline !== '' && $start <= $lastDeadline) {
            $errors['support_starts_at'] = 'Support must start after the last application deadline.';
        } elseif ($start !== '' && $results !== '' && $start < $results) {
            $errors['support_starts_at'] = 'Support cannot start before the expected final results.';
        }
        if ($end !== '' && $start === '') {
            $errors['support_starts_at'] = 'Set the support start date before the support end date.';
        } elseif ($end !== '' && $end < $start) {
            $errors['support_ends_at'] = 'The support end date must be on or after the support start date.';
        }

        return $errors;
    }

    public static function assertConsistent(array $attributes, ?Scholarship $scholarship = null): void
    {
        $errors = self::errors($attributes, $scholarship);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
