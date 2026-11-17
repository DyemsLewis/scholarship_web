<?php

namespace App\Support;

use App\Rules\PhilippineLandPin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VenueAddress
{
    public const REGIONS = ['Region I', 'Region II', 'Region III', 'Region IV-A', 'MIMAROPA', 'Region V', 'Region VI', 'NIR', 'Region VII', 'Region VIII', 'Region IX', 'Region X', 'Region XI', 'Region XII', 'NCR', 'CAR', 'Region XIII', 'BARMM'];

    public static function rules(string $field = 'address_details', bool $partial = false): array
    {
        return [
            $field => ['nullable', 'array:venue,region,province,city,barangay,street,latitude,longitude'],
            $field.'.venue' => [$partial ? 'nullable' : 'required_with:'.$field, 'string', 'max:120'],
            $field.'.region' => [$partial ? 'nullable' : 'required_with:'.$field, Rule::in(self::REGIONS)],
            $field.'.province' => [$partial ? 'nullable' : 'required_with:'.$field, 'string', 'max:100'],
            $field.'.city' => [$partial ? 'nullable' : 'required_with:'.$field, 'string', 'max:100'],
            $field.'.barangay' => ['nullable', 'string', 'max:100'],
            $field.'.street' => ['nullable', 'string', 'max:160'],
            $field.'.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:'.$field.'.longitude', new PhilippineLandPin],
            $field.'.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:'.$field.'.latitude', new PhilippineLandPin],
        ];
    }

    public static function format(array $details, bool $includeVenue = true): string
    {
        return collect($includeVenue ? ['venue', 'street', 'barangay', 'city', 'province', 'region'] : ['street', 'barangay', 'city', 'province', 'region'])
            ->map(fn ($key) => is_string($details[$key] ?? null) ? preg_replace('/\s+/u', ' ', trim($details[$key])) : '')
            ->filter()->unique()->implode(', ');
    }

    public static function prepare(Request $request, string $addressField, ?string $venueField = null, bool $enabled = true): void
    {
        if (! $enabled) {
            $request->merge(['address_details' => null, $addressField => null, 'latitude' => null, 'longitude' => null, ...($venueField ? [$venueField => null] : [])]);
            return;
        }
        $details = $request->input('address_details');
        if (! is_array($details) || $details === []) {
            return;
        }
        // Keep the original display fields for existing maps, notices, and exports.
        $request->merge([
            $addressField => self::format($details, $venueField === null),
            'latitude' => $details['latitude'] ?? null,
            'longitude' => $details['longitude'] ?? null,
            ...($venueField ? [$venueField => is_string($details['venue'] ?? null) ? trim($details['venue']) : null] : []),
        ]);
    }
}
