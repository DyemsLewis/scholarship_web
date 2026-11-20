<?php

namespace App\Support;

use App\Models\Scholarship;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProgramApplicationRules
{
    public static function errors(array $attributes, ?Scholarship $program = null): array
    {
        $value = static fn (string $key) => array_key_exists($key, $attributes) ? $attributes[$key] : $program?->{$key};

        if ($value('application_mode') === 'provider_review') {
            $conditions = ScholarshipEligibilityCondition::normalize((array) $value('eligibility_conditions'));

            return in_array('required_documents', array_column($conditions, 'key'), true)
                ? ['application_mode' => 'This program has an eligibility condition that requires documents. Choose Portal review or Portal + in-person.']
                : [];
        }

        $required = array_fill_keys(array_map(self::documentKey(...), self::lines($value('requirements'))), true);
        foreach (self::lines($value('optional_requirements')) as $label) {
            if (isset($required[self::documentKey($label)])) {
                $name = self::commonDocumentLabel($label) ?? $label;

                return ['optional_requirements' => "{$name} is listed as both required and optional. Keep it in only one pre-screening list."];
            }
        }

        return [];
    }

    public static function assertConsistent(array $attributes, ?Scholarship $program = null): void
    {
        $errors = self::errors($attributes, $program);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function usesHandoffVenue(?string $mode): bool
    {
        return in_array($mode, ['onsite', 'hybrid'], true);
    }

    public static function usesHandoffLink(?string $mode): bool
    {
        return in_array($mode, ['online', 'hybrid'], true);
    }

    public static function handoffChannel(?Scholarship $program): array
    {
        $mode = $program?->handoff_mode ?: 'provider_contact';
        $venue = self::usesHandoffVenue($mode);
        $url = self::usesHandoffLink($mode) ? trim((string) $program?->handoff_url) : '';
        $safeUrl = filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);

        return [
            'mode' => $mode,
            'location_name' => $venue ? $program?->handoff_location_name : null,
            'location_address' => $venue ? $program?->handoff_location_address : null,
            'latitude' => $venue ? $program?->handoff_latitude : null,
            'longitude' => $venue ? $program?->handoff_longitude : null,
            'map_url' => $venue ? $program?->handoffMapUrl() : null,
            'url' => $safeUrl ? $url : null,
        ];
    }

    private static function lines(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $value)), static fn (string $line): bool => $line !== ''));
    }

    private static function key(string $value): string
    {
        return Str::lower(Str::squish($value));
    }

    private static function documentKey(string $value): string
    {
        return self::key(self::commonDocumentLabel($value) ?? $value);
    }

    private static function commonDocumentLabel(string $value): ?string
    {
        static $catalog;

        if ($catalog === null) {
            $catalog = [];
            foreach (json_decode(file_get_contents(resource_path('data/program-documents.json')), true, flags: JSON_THROW_ON_ERROR) as $item) {
                foreach ([$item['label'], ...$item['aliases']] as $alias) {
                    $catalog[self::key($alias)] = $item['label'];
                }
            }
        }

        return $catalog[self::key($value)] ?? null;
    }
}
