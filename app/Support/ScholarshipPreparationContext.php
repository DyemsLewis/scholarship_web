<?php

namespace App\Support;

use App\Models\Scholarship;
use Illuminate\Http\Request;

class ScholarshipPreparationContext
{
    public const SECTIONS = ['summary', 'eligibility', 'award', 'application', 'provider'];

    public static function fromRequest(Request $request): ?array
    {
        $id = $request->query('preparing_scholarship');
        if (! $request->user()?->isApplicant() || ! is_string($id) || ! preg_match('/^[1-9][0-9]*$/', $id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9007199254740991]]) === false) {
            return null;
        }

        $scholarship = Scholarship::query()->with('provider.providerProfile')->find($id);
        if (! $scholarship?->isPubliclyVisible()) {
            return null;
        }

        $section = $request->query('return_section');
        $section = in_array($section, self::SECTIONS, true) ? $section : 'application';

        return [
            'scholarship_id' => $scholarship->id,
            'scholarship_title' => $scholarship->title,
            'return_section' => $section,
            // Never accept a client-supplied redirect URL.
            'return_href' => $section === 'summary'
                ? route('dashboard.scholarships.show', $scholarship, false)
                : route('dashboard.scholarships.show.section', ['scholarship' => $scholarship, 'section' => $section], false),
        ];
    }
}
