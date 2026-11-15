<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicantGuardianAgeTest extends TestCase
{
    use RefreshDatabase;

    public static function guardianAges(): array
    {
        return [
            'younger applicant' => [17, 'college', 'learner', true],
            'eighteenth birthday' => [18, 'college', 'learner', true],
            'nineteenth birthday' => [19, 'senior_high_school', 'learner', false],
            'adult with managed account' => [25, 'senior_high_school', 'parent_guardian', false],
        ];
    }

    #[DataProvider('guardianAges')]
    public function test_contact_readiness_and_guardian_evidence_follow_age_only(int $age, string $level, string $manager, bool $required): void
    {
        $this->travelTo(now()->setDate(2026, 11, 14)->startOfDay());
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => today()->subYears($age)->toDateString(),
            'education_level' => $level, 'account_managed_by' => $manager,
            'guardian_name' => null, 'guardian_relationship' => null,
            'guardian_contact' => null, 'guardian_email' => null,
        ]);
        $applicant->refresh();
        $response = $this->actingAs($applicant)->getJson('/dashboard/profile/data')->assertOk()
            ->assertJsonPath('profile_readiness.requires_guardian', $required)
            ->assertJsonPath('profile_readiness.is_minor', $age < 18);
        $missing = array_column($response->json('profile_readiness.missing'), 'key');
        foreach (['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email'] as $field) {
            $this->assertSame($required, in_array($field, $missing, true), $field);
        }
        $this->assertSame($required, in_array('guardian_record', ApplicantVerificationDocument::requiredTypesFor($applicant), true));
    }

    public function test_guardian_requirement_ends_on_the_nineteenth_birthday_not_before(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 14)->startOfDay());
        $this->assertTrue(User::applicantRequiresGuardian(['birthdate' => '2007-11-15']));
        $this->assertFalse(User::applicantRequiresGuardian(['birthdate' => '2007-11-14']));
        $this->assertFalse(User::applicantRequiresGuardian(['birthdate' => 'invalid']));
        $this->assertFalse(User::applicantRequiresGuardian(['education_level' => 'senior_high_school', 'account_managed_by' => 'parent_guardian']));
    }

    public function test_an_older_applicant_can_complete_the_profile_without_optional_guardian_fields(): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => today()->subYears(19)->toDateString(),
            'education_level' => 'senior_high_school', 'account_managed_by' => 'parent_guardian',
            'school' => 'Sample Senior High School', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'STEM', 'year_level' => 'Grade 12',
            'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90, 'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000', 'household_size' => 4,
            'current_scholarship_status' => 'none',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/adult-applicant.jpg',
            'guardian_name' => null, 'guardian_relationship' => null,
            'guardian_contact' => null, 'guardian_email' => null, 'guardian_is_account_owner' => false,
        ]);
        $applicant->refresh();
        $this->assertTrue($applicant->applicantProfileReadiness()['complete'], json_encode($applicant->applicantProfileReadiness()['missing']));
        $this->assertNotContains('guardian_record', ApplicantVerificationDocument::requiredTypesFor($applicant));
    }
}
