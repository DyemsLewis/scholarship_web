<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\HandlesProviderRoleWorkspaceData;
use App\Models\ScholarshipApplication;
use App\Models\StudentProfile;
use App\Models\User;
use Tests\TestCase;

class ProviderApplicantEducationTest extends TestCase
{
    public function test_shared_directory_formatting_handles_enum_and_legacy_education_labels(): void
    {
        $formatter = new class {
            use HandlesProviderRoleWorkspaceData;

            public function label(ScholarshipApplication $application): string
            {
                return $this->providerApplicantEducationSummary($application);
            }
        };
        foreach ([
            'senior_high_school' => 'Senior High School',
            'junior_high_school' => 'Junior High School',
            'college' => 'College / University',
            'tvet' => 'TVET / Vocational',
            'als' => 'ALS / Alternative Learning',
            'preschool' => 'Preschool / Kindergarten',
            'elementary' => 'Elementary',
            'Senior High School' => 'Senior High School',
            'TVET / Vocational' => 'TVET / Vocational',
            'other_program' => 'Other Program',
        ] as $value => $expected) {
            $profile = new StudentProfile(['education_level' => $value, 'course_or_strand' => 'STEM', 'year_level' => 'Grade 12']);
            $user = (new User)->setRelation('studentProfile', $profile);
            $application = (new ScholarshipApplication)->setRelation('applicant', $user);
            $this->assertSame($expected.' - STEM - Grade 12', $formatter->label($application));
            $this->assertSame($value, $profile->education_level);
        }
        $this->assertSame('', $formatter->label((new ScholarshipApplication)->setRelation('applicant', null)));
    }
}
