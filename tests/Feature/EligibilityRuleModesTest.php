<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EligibilityRuleModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public static function rules(): array
    {
        return [
            'academic' => ['academic', ['minimum_gwa' => 99, 'minimum_grade_scale' => 'percentage'], []],
            'education' => ['education_level', ['eligible_education_levels' => 'elementary'], []],
            'course' => ['course', ['eligible_courses' => 'ABM'], []],
            'school' => ['school_type', ['eligible_school_types' => 'private'], ['school_type' => 'public']],
            'year' => ['year_level', ['eligible_year_levels' => 'Grade 12'], []],
            'residence' => ['location', ['eligible_locations' => 'Cebu City'], []],
            'income' => ['income', ['income_requirement' => 'Above PHP 60,000'], []],
            'other award' => ['current_scholarship_support', ['exclude_current_scholarship_recipients' => true], ['current_scholarship_status' => 'receiving']],
            'enrollment' => ['enrollment_status', ['eligibility_conditions' => [[
                'key' => 'currently_enrolled', 'statement' => 'Must be currently enrolled.', 'verification_type' => 'automatic',
            ]]], ['enrollment_status' => 'Graduated']],
        ];
    }

    #[DataProvider('rules')]
    public function test_every_rule_defaults_to_required_and_can_be_explicitly_preferred(string $key, array $programFields, array $profileFields): void
    {
        [, $applicant, $program] = $this->context($programFields);
        $applicant->studentProfile()->update($profileFields);
        $service = app(ScholarshipEligibilityService::class);
        $required = $service->evaluate($program, $applicant->fresh());
        $this->assertFalse($required['is_eligible']);
        $criterion = collect($required['criteria'])->firstWhere('key', $key);
        $this->assertSame('required', $criterion['rule_mode']);
        $this->assertSame('fail', $criterion['status']);
        $this->assertTrue($criterion['is_blocking']);

        $program->update(['eligibility_rule_modes' => [$key => 'preferred']]);
        $preferred = $service->evaluate($program->fresh(), $applicant->fresh());
        $criterion = collect($preferred['criteria'])->firstWhere('key', $key);
        $this->assertTrue($preferred['is_eligible']);
        $this->assertSame([], $preferred['blocking_criteria']);
        $this->assertSame('fail', $criterion['status']);
        $this->assertFalse($criterion['is_blocking']);
        $this->assertSame($key, $preferred['preferred_mismatches'][0]['key']);
        $this->assertSame($required['score'], $preferred['score']);
    }

    public static function channels(): array
    {
        return ['website' => ['web'], 'mobile' => ['mobile']];
    }

    #[DataProvider('channels')]
    public function test_preference_mismatch_allows_submission_and_records_the_original_rule_mode(string $channel): void
    {
        [$provider, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $this->submit($channel, $applicant, $program)->assertCreated();
        $application = ScholarshipApplication::where('scholarship_id', $program->id)->firstOrFail();
        $original = $application->eligibility_breakdown;
        $this->assertSame('preferred', $original['rule_modes']['course']);
        $this->assertSame('preferred', collect($original['criteria'])->firstWhere('key', 'course')['rule_mode']);

        $program->update(['eligibility_rule_modes' => ['course' => 'required']]);
        app(DecisionSupportService::class)->syncApplication($application->fresh(), 'program_policy_changed');
        $this->assertSame($original, $application->fresh()->eligibility_breakdown);
        $latest = $application->dssSnapshots()->latest('id')->firstOrFail()->eligibility_breakdown;
        $this->assertSame('required', $latest['rule_modes']['course']);
    }

    #[DataProvider('channels')]
    public function test_required_mismatch_still_blocks_even_if_applicant_sends_a_preferred_override(string $channel): void
    {
        [, $applicant, $program] = $this->context(['eligible_courses' => 'ABM']);
        $this->submit($channel, $applicant, $program, ['eligibility_rule_modes' => ['course' => 'preferred']])
            ->assertUnprocessable()->assertJsonPath('blocking_criteria.0.key', 'course');
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_a_required_mismatch_blocks_even_when_another_failed_rule_is_preferred(): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligible_locations' => 'Cebu City',
            'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        $this->assertFalse($match['is_eligible']);
        $this->assertSame(['location'], array_column($match['blocking_criteria'], 'key'));
    }

    public function test_automatic_condition_flags_respect_the_modes_of_the_underlying_rules(): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
            'eligibility_conditions' => [[
                'key' => 'learner_group', 'statement' => 'Preferred learner group.', 'verification_type' => 'automatic',
            ]],
        ]);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        $this->assertSame('fail', $match['condition_results'][0]['status']);
        $this->assertFalse($match['condition_results'][0]['is_blocking']);
        $this->assertSame('preferred', $match['condition_results'][0]['rule_mode']);
    }

    public function test_manual_academic_conditions_do_not_report_an_automatic_pass(): void
    {
        [, $applicant, $program] = $this->context([
            'minimum_grade_scale' => 'pass_fail',
            'eligibility_conditions' => [[
                'key' => 'academic_performance', 'statement' => 'Academic evidence is required.', 'verification_type' => 'automatic',
            ]],
        ]);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        $this->assertSame('Needs review', $match['guidance']['label']);
        $this->assertSame('provider_review', $match['condition_results'][0]['status']);
        $this->assertTrue($match['condition_results'][0]['needs_review']);
        $this->assertStringContainsString('not a confirmed match', $match['condition_results'][0]['note']);
    }

    public function test_incomparable_grades_need_review_instead_of_being_treated_as_a_match_or_failure(): void
    {
        [, $applicant, $program] = $this->context(['minimum_gwa' => 1.75, 'minimum_grade_scale' => 'grade_point']);
        $match = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        $academic = collect($match['criteria'])->firstWhere('key', 'academic');
        $this->assertTrue($match['is_eligible']);
        $this->assertSame('missing', $academic['status']);
        $this->assertTrue($academic['needs_review']);
        $this->assertFalse($academic['is_blocking']);
        $this->assertSame('academic', $match['review_criteria'][0]['key']);
        $this->assertStringContainsString('not confirmed matches', $match['difference_summary']);
    }

    public function test_provider_api_accepts_array_and_json_modes_and_preserves_them_when_omitted(): void
    {
        [$provider] = $this->context();
        foreach ([['course' => 'preferred'], json_encode(['course' => 'preferred'])] as $modes) {
            $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
                'title' => 'Rule modes draft', 'status' => 'draft', 'eligibility_rule_modes' => $modes,
            ])->assertCreated()->assertJsonPath('scholarship.eligibility_rule_modes.course', 'preferred')
                ->assertJsonPath('scholarship.eligibility_rule_modes.location', 'required');
            $id = $response->json('scholarship.id');
            $this->putJson("/provider/scholarships/{$id}", ['title' => 'Updated draft', 'status' => 'draft'])
                ->assertOk()->assertJsonPath('scholarship.eligibility_rule_modes.course', 'preferred');
            $this->putJson("/provider/scholarships/{$id}", ['title' => 'Updated draft', 'status' => 'draft', 'eligibility_rule_modes' => []])
                ->assertOk()->assertJsonPath('scholarship.eligibility_rule_modes.course', 'required');
        }
    }

    public function test_invalid_modes_or_platform_gate_overrides_are_rejected(): void
    {
        [$provider] = $this->context();
        foreach ([['documents' => 'preferred'], ['profile_verification' => 'preferred'], ['course' => 'optional'], ['course' => null], null, 'not-json', '"preferred"'] as $modes) {
            $this->actingAs($provider)->postJson('/provider/scholarships', [
                'title' => 'Invalid policy', 'status' => 'draft', 'eligibility_rule_modes' => $modes,
            ])->assertUnprocessable();
        }
    }

    #[DataProvider('channels')]
    public function test_preferred_rules_do_not_bypass_profile_verification_or_document_requirements(string $channel): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
            'application_mode' => 'online', 'requirements' => 'Special provider certificate',
        ]);
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $this->submit($channel, $applicant->fresh(), $program)->assertForbidden()
            ->assertJsonPath('profile_verification_required', true);
        $applicant->studentProfile()->update(['verification_status' => 'approved']);
        $this->submit($channel, $applicant->fresh(), $program)->assertUnprocessable()
            ->assertJsonPath('missing_documents.0', 'Special provider certificate');
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    #[DataProvider('channels')]
    public function test_preferences_do_not_bypass_dates_application_limits_or_duplicates(string $channel): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
            'deadline' => now()->subDay()->toDateString(),
        ]);
        $this->submit($channel, $applicant, $program)->assertUnprocessable()
            ->assertJsonPath('message', 'This scholarship is no longer accepting applications.');

        $program->update(['deadline' => now()->addMonth()->toDateString(), 'application_opens_at' => now()->addWeek()->toDateString()]);
        $this->submit($channel, $applicant, $program)->assertUnprocessable()
            ->assertJsonPath('message', 'Applications open on '.$program->application_opens_at->format('M d, Y').'.');

        $program->update(['application_opens_at' => null, 'application_limit' => 1]);
        ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $this->submit($channel, $applicant, $program)->assertUnprocessable()
            ->assertJsonPath('message', 'This scholarship has reached its application limit.');

        $program->update(['application_limit' => null]);
        $this->submit($channel, $applicant, $program)->assertCreated();
        $this->submit($channel, $applicant, $program)->assertUnprocessable()
            ->assertJsonPath('message', 'You already submitted an application for this scholarship.');
    }

    public function test_provider_review_separates_the_submission_from_changed_rules_and_profile_values(): void
    {
        [$provider, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $this->submit('web', $applicant, $program)->assertCreated();
        $application = ScholarshipApplication::where('scholarship_id', $program->id)->sole();
        $savedEligibility = $application->eligibility_breakdown;
        $savedSubmission = $application->submission_snapshot;
        $program->update(['eligibility_rule_modes' => ['course' => 'required']]);
        $applicant->studentProfile()->update(['course_or_strand' => 'HUMSS']);

        $this->actingAs($provider)->getJson("/provider/applications/{$application->id}/data?workspace=review")
            ->assertOk()->assertJsonPath('workspace.mode', 'review')
            ->assertJsonPath('application.dss_explanation.comparison.state', 'preferred_difference')
            ->assertJsonPath('application.dss_explanation.comparison.required_not_met', 0)
            ->assertJsonPath('application.dss_explanation.comparison.preferred_not_met', 1)
            ->assertJsonPath('application.dss_explanation.eligibility_context.basis', 'submission')
            ->assertJsonPath('application.dss_explanation.eligibility_context.has_changes', true)
            ->assertJsonPath('application.dss_explanation.eligibility_context.rule_changes.0', 'Track / strand / course')
            ->assertJsonPath('application.dss_explanation.eligibility_context.profile_changes.0', 'Track / strand / course')
            ->assertJsonPath('application.dss_explanation.eligibility_context.current_comparison.summary.state', 'required_mismatch');
        $this->assertSame($savedEligibility, $application->fresh()->eligibility_breakdown);
        $this->assertSame($savedSubmission, $application->fresh()->submission_snapshot);
        $this->assertSame($application->status, $application->fresh()->status);
        $this->assertNull($application->fresh()->final_outcome);
    }

    public function test_legacy_saved_checks_do_not_inherit_current_preferred_modes(): void
    {
        [, $applicant, $program] = $this->context([
            'eligible_courses' => 'ABM', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]);
        $legacy = ['score' => 0, 'criteria' => [[
            'key' => 'course', 'label' => 'Course', 'status' => 'fail',
            'student_value' => 'BS Information Technology', 'requirement' => 'ABM',
        ]]];
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'submitted', 'submitted_at' => now(), 'eligibility_breakdown' => $legacy,
            'submission_snapshot' => ['current' => ['applicant' => ['name' => $applicant->name]]],
        ]);
        $service = app(DecisionSupportService::class);
        $service->syncApplication($application, 'review_refresh');
        $explanation = $service->explainApplication($application->fresh());
        $this->assertSame('required_mismatch', $explanation['comparison']['state']);
        $this->assertTrue($explanation['eligibility_context']['legacy_required_defaults']);
        $this->assertTrue($explanation['eligibility_context']['has_changes']);
        $this->assertSame('preferred_difference', $explanation['eligibility_context']['current_comparison']['summary']['state']);
        $this->assertSame($legacy, $application->fresh()->eligibility_breakdown);
    }

    #[DataProvider('rules')]
    public function test_dss_uses_the_same_nine_rule_checks_as_submission(string $key, array $programFields, array $profileFields): void
    {
        [, $applicant, $program] = $this->context($programFields);
        $applicant->studentProfile()->update($profileFields);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        app(DecisionSupportService::class)->syncApplication($application->fresh(), 'review_test');
        $snapshot = $application->fresh()->dssSnapshots()->latest('id')->firstOrFail();
        $criterion = collect($snapshot->eligibility_breakdown['criteria'])->firstWhere('key', $key);
        $this->assertNotNull($criterion);
        $this->assertSame('fail', $criterion['status']);
        $this->assertTrue($criterion['is_blocking']);
        $this->assertSame('required', $criterion['rule_mode']);
        $this->assertArrayNotHasKey('documents', collect($snapshot->eligibility_breakdown['criteria'])->keyBy('key')->all());
        $this->assertSame('2.2', $snapshot->methodology_version);
        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_unrestricted_mode_changes_do_not_create_a_false_snapshot_change_notice(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $this->submit('web', $applicant, $program)->assertCreated();
        $application = ScholarshipApplication::where('scholarship_id', $program->id)->sole();
        $program->update(['eligibility_rule_modes' => ['course' => 'preferred']]);
        $this->actingAs($provider)->getJson("/provider/applications/{$application->id}/data?workspace=review")
            ->assertOk()
            ->assertJsonPath('application.dss_explanation.eligibility_context.has_changes', false)
            ->assertJsonPath('application.dss_explanation.eligibility_context.current_comparison', null);
    }

    public function test_selection_and_recipient_payloads_do_not_expose_review_comparisons(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $this->submit('web', $applicant, $program)->assertCreated();
        $application = ScholarshipApplication::where('scholarship_id', $program->id)->sole();
        foreach (['selection', 'recipient'] as $workspace) {
            $this->actingAs($provider)->getJson("/provider/applications/{$application->id}/data?workspace={$workspace}")
                ->assertOk()->assertJsonPath('workspace.mode', $workspace)
                ->assertJsonPath('application.eligibility_breakdown', null)
                ->assertJsonPath('application.dss_breakdown', null)
                ->assertJsonPath('application.dss_explanation', null);
        }
    }

    private function context(array $programFields = []): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01', 'account_managed_by' => 'learner',
            'education_level' => 'college', 'school' => 'Test University', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology', 'school_type' => 'public', 'year_level' => '1st year',
            'academic_year' => '2026-2027', 'academic_term' => 'first_semester', 'gwa' => 90, 'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000', 'household_size' => 4, 'current_scholarship_status' => 'none',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/rule-modes.jpg',
        ]);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Eligibility policy test', 'description' => 'Tests required and preferred criteria.',
            'status' => 'published', 'deadline' => now()->addMonth()->toDateString(),
            'application_mode' => 'provider_review', ...$programFields,
        ]);

        return [$provider, $applicant->fresh(), $program];
    }

    private function submit(string $channel, User $applicant, Scholarship $program, array $extra = [])
    {
        $payload = ['scholarship_id' => $program->id, 'terms_accepted' => true, ...$extra];
        if ($channel === 'web') {
            return $this->actingAs($applicant)->postJson('/dashboard/applications', $payload);
        }
        $token = bin2hex(random_bytes(20));
        MobileApiToken::create([
            'user_id' => $applicant->id, 'name' => 'rule-modes', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);

        return $this->withToken($token)->postJson('/api/mobile/applications', $payload);
    }
}
