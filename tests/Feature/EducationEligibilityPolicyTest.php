<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\CompletedQualification;
use App\Support\EducationEligibilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AcceptsProfileEvidence;
use Tests\TestCase;

class EducationEligibilityPolicyTest extends TestCase
{
    use AcceptsProfileEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    public static function qualifications(): array
    {
        return collect(array_diff(array_keys(CompletedQualification::OPTIONS), ['none']))->mapWithKeys(fn ($type) => [$type => [$type]])->all();
    }

    #[DataProvider('qualifications')]
    public function test_each_completed_credential_is_only_an_explicit_match_not_a_different_qualification(string $type): void
    {
        [, $applicant, $program] = $this->context(['education_eligibility_policy' => $this->policy([$type])], ['completed_qualification' => $type]);
        $match = $this->evaluate($program, $applicant);
        $criterion = collect($match['criteria'])->firstWhere('key', 'completed_qualification');
        $this->assertSame($type === 'other' ? 'missing' : 'pass', $criterion['status']);
        $this->assertSame(CompletedQualification::OPTIONS[$type], $criterion['student_value']);
        $this->assertSame('college', $applicant->studentProfile->education_level);
        $this->assertSame($type === 'other', $criterion['needs_review']);
    }

    public function test_unlisted_credentials_need_review_until_a_provider_explicitly_marks_them_as_not_matched(): void
    {
        [, $applicant, $program] = $this->context(['education_eligibility_policy' => $this->policy(['senior_high_school'])]);
        $review = $this->evaluate($program, $applicant);
        $criterion = collect($review['criteria'])->firstWhere('key', 'completed_qualification');
        $this->assertTrue($review['is_eligible']);
        $this->assertSame('missing', $criterion['status']);
        $this->assertTrue($criterion['needs_review']);
        $this->assertSame('Needs review', $review['guidance']['label']);

        $program->update(['education_eligibility_policy' => $this->policy(['senior_high_school'], 'not_accepted')]);
        $strict = $this->evaluate($program->fresh(), $applicant);
        $this->assertFalse($strict['is_eligible']);
        $this->assertSame('completed_qualification', $strict['blocking_criteria'][0]['key']);
        $program->update(['eligibility_rule_modes' => ['completed_qualification' => 'preferred']]);
        $this->assertTrue($this->evaluate($program->fresh(), $applicant)['is_eligible']);
    }

    public function test_current_study_mismatches_are_not_overridden_by_a_matching_completed_credential(): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_education_levels' => 'senior_high_school', 'education_eligibility_policy' => $this->policy(['pre_k12_high_school']),
        ]);
        $match = $this->evaluate($program, $applicant);
        $this->assertFalse($match['is_eligible']);
        $this->assertSame('education_level', $match['blocking_criteria'][0]['key']);
        $this->assertSame('pass', collect($match['criteria'])->firstWhere('key', 'completed_qualification')['status']);
    }

    public function test_a_degree_being_studied_is_not_a_completed_degree(): void
    {
        [, $applicant, $program] = $this->context(['education_eligibility_policy' => $this->policy(['college'], 'not_accepted')], ['completed_qualification' => 'none']);
        $match = $this->evaluate($program, $applicant);
        $this->assertFalse($match['is_eligible']);
        $this->assertSame('No completed qualification yet', collect($match['criteria'])->firstWhere('key', 'completed_qualification')['student_value']);
    }

    public function test_unfamiliar_study_descriptions_do_not_become_false_matches_or_automatic_rejections(): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_education_levels' => 'senior_high_school', 'eligible_year_levels' => 'Grade 12', 'eligible_courses' => 'STEM',
        ], ['education_level' => 'other', 'year_level' => 'Fourth year high school', 'course_or_strand' => null, 'enrollment_status' => 'Not currently enrolled']);
        $match = $this->evaluate($program, $applicant);
        $this->assertTrue($match['is_eligible']);
        foreach (['education_level', 'course', 'year_level'] as $key) {
            $criterion = collect($match['criteria'])->firstWhere('key', $key);
            $this->assertSame('missing', $criterion['status']);
            $this->assertTrue($criterion['needs_review']);
        }
    }

    public function test_older_readable_level_labels_do_not_soften_a_clear_course_mismatch(): void
    {
        [, $applicant, $program] = $this->context(['eligible_courses' => 'ABM'], ['education_level' => 'Senior high school', 'course_or_strand' => 'STEM']);
        $course = collect($this->evaluate($program, $applicant)['criteria'])->firstWhere('key', 'course');
        $this->assertSame('fail', $course['status']);
        $this->assertTrue($course['is_blocking']);
        $this->assertFalse($course['needs_review']);
    }

    public function test_pending_enrollment_is_only_accepted_when_the_provider_lists_it(): void
    {
        [, $applicant, $program] = $this->context([
            'eligibility_conditions' => [['key' => 'currently_enrolled', 'statement' => 'Must be enrolled.', 'verification_type' => 'automatic']],
        ], ['enrollment_status' => 'Transfer enrollment pending']);
        $this->assertFalse($this->evaluate($program, $applicant)['is_eligible']);
        $program->update(['eligibility_conditions' => [], 'education_eligibility_policy' => $this->policy([], enrollment: ['Transfer enrollment pending'])]);
        $this->assertTrue($this->evaluate($program->fresh(), $applicant)['is_eligible']);
        $applicant->studentProfile()->update(['enrollment_status' => 'Unclassified school status']);
        $unknown = $this->evaluate($program, $applicant->fresh());
        $this->assertTrue(collect($unknown['criteria'])->firstWhere('key', 'enrollment_status')['needs_review']);
    }

    public static function channels(): array
    {
        return ['web' => ['web'], 'mobile' => ['mobile']];
    }

    #[DataProvider('channels')]
    public function test_both_channels_use_the_same_review_rules_and_keep_the_original_comparison(string $channel): void
    {
        [$provider, $applicant, $program] = $this->context([
            'education_eligibility_policy' => $this->policy(['senior_high_school'], enrollment: ['Returning enrollment pending']),
            'eligible_courses' => 'BS Information Technology', 'eligible_year_levels' => '1st year',
        ], ['enrollment_status' => 'Returning enrollment pending', 'course_or_strand' => null, 'year_level' => null]);
        $this->verifyProfile($applicant);
        $this->assertTrue($applicant->fresh()->applicantProfileReadiness()['complete']);
        $this->submit($channel, $applicant->fresh(), $program)->assertCreated();
        $application = ScholarshipApplication::where('applicant_id', $applicant->id)->firstOrFail();
        $saved = $application->eligibility_breakdown;
        $this->assertTrue(collect($saved['criteria'])->firstWhere('key', 'completed_qualification')['needs_review']);
        $this->assertTrue(collect($saved['criteria'])->firstWhere('key', 'course')['needs_review']);
        $program->update(['education_eligibility_policy' => $this->policy(['senior_high_school'], 'not_accepted', ['Returning enrollment pending'])]);
        app(DecisionSupportService::class)->syncApplication($application->fresh(), 'education_policy_changed');
        $this->assertSame($saved, $application->fresh()->eligibility_breakdown);
        $this->assertSame('submitted', $application->fresh()->status);
        $current = $application->dssSnapshots()->latest('id')->firstOrFail();
        $this->assertSame('pre_k12_high_school', $current->applicant_inputs['completed_qualification']);
        $this->assertSame('not_accepted', $current->scholarship_inputs['education_eligibility_policy']['unlisted_qualification']);
        $explanation = app(DecisionSupportService::class)->explainApplication($application->fresh());
        $this->assertContains('Completed qualification', $explanation['eligibility_context']['rule_changes']);
    }

    #[DataProvider('channels')]
    public function test_applicants_cannot_override_the_providers_strict_credential_rule(string $channel): void
    {
        [, $applicant, $program] = $this->context(['education_eligibility_policy' => $this->policy(['college'], 'not_accepted')]);
        $this->verifyProfile($applicant);
        $this->submit($channel, $applicant->fresh(), $program, ['education_eligibility_policy' => $this->policy(['pre_k12_high_school'])])
            ->assertUnprocessable()->assertJsonPath('blocking_criteria.0.key', 'completed_qualification');
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_provider_can_save_array_or_formdata_json_policies_and_older_edits_preserve_them(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        foreach ([$this->policy(['als_secondary', 'pre_k12_high_school']), json_encode($this->policy(['als_secondary', 'pre_k12_high_school']))] as $policy) {
            $saved = $this->actingAs($provider)->postJson('/provider/scholarships', ['title' => 'Credential rules draft', 'status' => 'draft', 'education_eligibility_policy' => $policy])
                ->assertCreated()->assertJsonPath('scholarship.education_eligibility_policy.requires_completed_qualification', true);
            $id = $saved->json('scholarship.id');
            $this->getJson("/provider/programs/{$id}/data")->assertOk()
                ->assertJsonPath('program.education_eligibility_policy.requires_completed_qualification', true);
            $this->putJson("/provider/scholarships/{$id}", ['title' => 'Older client edit', 'status' => 'draft'])
                ->assertOk()->assertJsonPath('scholarship.education_eligibility_policy.accepted_qualifications', ['pre_k12_high_school', 'als_secondary']);
        }
    }

    public function test_invalid_and_contradictory_policies_are_rejected_even_for_drafts(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        foreach (['invalid-json', ['requires_completed_qualification' => true], $this->policy(['not_a_qualification']), $this->policy(['none']), $this->policy(['college', 'college']), [...$this->policy(['college']), 'enrollment_statuses' => ['Anything']]] as $policy) {
            $this->actingAs($provider)->postJson('/provider/scholarships', ['title' => 'Invalid draft', 'status' => 'draft', 'education_eligibility_policy' => $policy])->assertUnprocessable();
        }
        $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Contradictory enrollment', 'status' => 'draft',
            'education_eligibility_policy' => $this->policy([], enrollment: ['Incoming student']),
            'eligibility_conditions' => json_encode([['key' => 'currently_enrolled', 'statement' => 'Must be enrolled.', 'verification_type' => 'automatic']]),
        ])->assertUnprocessable()->assertJsonValidationErrors('education_eligibility_policy.enrollment_statuses');
    }

    public function test_default_policy_does_not_create_a_qualification_rule_or_false_legacy_changes(): void
    {
        [, $applicant, $program] = $this->context();
        $this->assertNull($program->education_eligibility_policy);
        $this->assertNull(collect($this->evaluate($program, $applicant)['criteria'])->firstWhere('key', 'completed_qualification'));
        $this->assertFalse(EducationEligibilityPolicy::configured(null));
    }

    public function test_legacy_enrollment_comparisons_do_not_report_a_policy_change_merely_for_new_metadata(): void
    {
        [, $applicant, $program] = $this->context(['eligibility_conditions' => [['key' => 'currently_enrolled', 'statement' => 'Must be enrolled.', 'verification_type' => 'automatic']]]);
        $saved = $this->evaluate($program, $applicant);
        $saved['criteria'] = array_map(function (array $item): array {
            unset($item['rule_context']);

            return $item;
        }, $saved['criteria']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now(), 'eligibility_breakdown' => $saved]);
        $explanation = app(DecisionSupportService::class)->explainApplication($application);
        $this->assertFalse($explanation['eligibility_context']['has_changes']);
    }

    private function policy(array $accepted, string $unlisted = 'review', array $enrollment = []): array
    {
        return ['requires_completed_qualification' => $accepted !== [], 'accepted_qualifications' => $accepted, 'unlisted_qualification' => $unlisted, 'enrollment_statuses' => $enrollment];
    }

    private function context(array $rules = [], array $profile = []): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '1998-06-01', 'account_managed_by' => 'learner', 'education_level' => 'college',
            'school' => 'Demonstration University', 'enrollment_status' => 'Enrolled', 'course_or_strand' => 'BS Information Technology',
            'school_type' => 'public', 'year_level' => '1st year', 'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90, 'grading_scale' => 'percentage', 'income_bracket' => 'Below PHP 10,000', 'household_size' => 4,
            'current_scholarship_status' => 'none', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/education.jpg', 'completed_qualification' => 'pre_k12_high_school',
            'completed_qualification_school' => 'Demonstration High School', 'completed_qualification_year' => 2014, ...$profile,
        ]);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Education rules test', 'description' => 'Explicit acceptance, not assumed equivalence.',
            'status' => 'published', 'deadline' => now()->addMonth()->toDateString(), 'application_mode' => 'provider_review', ...$rules]);

        return [$provider, $applicant->fresh(), $program];
    }

    private function evaluate(Scholarship $program, User $applicant): array
    {
        return app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
    }

    private function verifyProfile(User $applicant): void
    {
        foreach (ApplicantVerificationDocument::requiredTypesFor($applicant) as $type) {
            $applicant->applicantVerificationDocuments()->firstOrCreate(['document_type' => $type], [
                'uploaded_by' => $applicant->id, 'path' => "education/{$applicant->id}/{$type}.pdf", 'original_name' => $type.'.pdf', 'mime_type' => 'application/pdf', 'size' => 20,
            ]);
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->acceptProfileEvidence($applicant, $admin);
        $this->actingAs($admin)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['verification_status' => 'approved'])->assertOk();
    }

    private function submit(string $channel, User $applicant, Scholarship $program, array $extra = [])
    {
        $body = ['scholarship_id' => $program->id, 'terms_accepted' => true, ...$extra];
        if ($channel === 'web') {
            return $this->actingAs($applicant)->postJson('/dashboard/applications', $body);
        }
        $token = bin2hex(random_bytes(20));
        MobileApiToken::create(['user_id' => $applicant->id, 'name' => 'education', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);

        return $this->withToken($token)->postJson('/api/mobile/applications', $body);
    }
}
