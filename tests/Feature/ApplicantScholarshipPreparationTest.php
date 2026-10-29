<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicantScholarshipDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicantScholarshipPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_all_detail_sections_share_an_additive_preparation_contract_without_creating_an_application(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship();
        $preparation = null;

        foreach (['summary', 'eligibility', 'award', 'application', 'provider'] as $section) {
            $response = $this->actingAs($applicant)->getJson("/dashboard/scholarships/{$scholarship->id}/data?section={$section}")
                ->assertOk()->assertJsonPath('detail.version', 3)
                ->assertJsonPath('detail.preparation.version', 1)
                ->assertJsonPath('detail.preparation.mode', 'live')
                ->assertJsonPath('detail.preparation.can_start_application', true)
                ->assertJsonCount(4, 'detail.preparation.steps')
                ->assertJsonPath('detail.preparation.submission.revalidated_on_submit', true)
                ->assertJsonMissingPath('detail.preparation.can_submit_application');
            $actual = $response->json('detail.preparation');
            $this->assertSame($preparation ?? $actual, $actual);
            $preparation = $actual;
        }

        $this->assertSame(['profile', 'eligibility', 'requirements', 'submit'], array_column($preparation['steps'], 'key'));
        $this->assertSame('submit', $preparation['next_step']);
        $this->assertSame(route('dashboard.applications.start', $scholarship, false), $preparation['next_action']['href']);
        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('student_documents', 0);
    }

    public function test_missing_files_are_submission_tasks_not_start_blockers(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship(['requirements' => "School ID\nAcademic record"]);
        $detail = $this->detail($applicant, $scholarship);

        $this->assertTrue($detail['decision']['can_apply']);
        $this->assertTrue($detail['preparation']['can_start_application']);
        $this->assertSame('requirements', $detail['preparation']['next_step']);
        $this->assertSame('application', $detail['preparation']['next_action']['section']);
        $step = $detail['preparation']['steps'][2];
        $this->assertFalse($step['blocks_start']);
        $this->assertSame('submission', $step['required_before']);
        $this->assertSame(2, $step['details']['missing_file_count']);
        $this->assertSame(['School ID', 'Academic record'], $step['details']['missing_files']);

        $this->actingAs($applicant)->postJson('/dashboard/applications', [
            'scholarship_id' => $scholarship->id, 'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonPath('missing_documents', ['School ID', 'Academic record']);
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_available_saved_files_are_not_presented_as_attached_or_approved(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship(['requirements' => 'School ID']);
        $this->document($applicant, 'School ID');
        $detail = $this->detail($applicant, $scholarship, 'application');
        $step = $detail['preparation']['steps'][2];

        $this->assertSame('saved', $step['status']);
        $this->assertSame('Files saved', $step['status_label']);
        $this->assertSame(1, $step['details']['saved_file_count']);
        $this->assertSame(0, $step['details']['missing_file_count']);
        $this->assertStringContainsString('does not mean attached or approved', $step['description']);
        $this->assertSame('ready', $detail['content']['requirements']['readiness']['state']);
        $this->assertSame('submit', $detail['preparation']['next_step']);
        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_a_saved_record_with_a_missing_file_is_not_ready_in_either_detail_contract(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship(['requirements' => 'School ID']);
        $this->document($applicant, 'School ID', false);
        $detail = $this->detail($applicant, $scholarship, 'application');

        $this->assertSame(0, $detail['preparation']['steps'][2]['details']['saved_file_count']);
        $this->assertSame(['School ID'], $detail['preparation']['steps'][2]['details']['missing_files']);
        $this->assertSame('needs_files', $detail['content']['requirements']['readiness']['state']);
        $this->assertSame(1, $detail['content']['requirements']['readiness']['missing_count']);
        $this->actingAs($applicant)->postJson('/dashboard/applications', [
            'scholarship_id' => $scholarship->id, 'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonPath('missing_documents', ['School ID']);
    }

    public function test_only_this_applicants_reusable_documents_count_not_verification_evidence_or_other_accounts(): void
    {
        $applicant = $this->applicant();
        $other = $this->applicant();
        $scholarship = $this->scholarship(['requirements' => 'School ID']);
        $this->document($other, 'School ID');
        $detail = $this->detail($applicant, $scholarship);

        $this->assertSame('verified', $detail['preparation']['steps'][0]['status']);
        $this->assertSame(0, $detail['preparation']['steps'][2]['details']['saved_file_count']);
        $this->assertSame('requirements', $detail['preparation']['next_step']);
        $this->assertSame(1, $this->detail($other, $scholarship)['preparation']['steps'][2]['details']['saved_file_count']);
    }

    public function test_duplicate_required_names_count_once_like_submission(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship(['requirements' => "School ID\nSchool ID"]);
        $this->document($applicant, 'School ID');
        $detail = $this->detail($applicant, $scholarship, 'application');

        $this->assertSame(1, $detail['preparation']['steps'][2]['details']['required_file_count']);
        $this->assertSame(1, $detail['content']['requirements']['readiness']['required_count']);
        $this->assertSame(0, $detail['preparation']['steps'][2]['details']['missing_file_count']);
    }

    public function test_profile_review_does_not_require_initial_files_but_still_counts_valid_questions(): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship([
            'application_mode' => 'provider_review', 'requirements' => 'School ID',
            'optional_requirements' => 'Award certificate', 'post_qualification_requirements' => 'Original transcript',
            'application_questions' => [
                ['id' => 'reason', 'prompt' => 'Why are you applying?', 'required' => true],
                ['id' => 'other', 'prompt' => 'Other information', 'required' => false],
                ['id' => 'invalid', 'prompt' => '', 'required' => true],
            ],
        ]);
        $detail = $this->detail($applicant, $scholarship, 'application');
        $step = $detail['preparation']['steps'][2];

        $this->assertSame('not_required', $step['status']);
        $this->assertSame(0, $step['details']['required_file_count']);
        $this->assertSame(2, $step['details']['question_count']);
        $this->assertSame(1, $step['details']['required_question_count']);
        $this->assertSame('application_form', $step['details']['questions_completed_in']);
        $this->assertSame('submit', $detail['preparation']['next_step']);
        $this->actingAs($applicant)->postJson('/dashboard/applications', [
            'scholarship_id' => $scholarship->id, 'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('application_answers');
    }

    public function test_optional_and_later_files_do_not_become_initial_submission_requirements(): void
    {
        $detail = $this->detail($this->applicant(), $this->scholarship([
            'optional_requirements' => 'Award certificate', 'post_qualification_requirements' => 'Original transcript',
        ]));

        $this->assertSame('not_required', $detail['preparation']['steps'][2]['status']);
        $this->assertSame([], $detail['preparation']['steps'][2]['details']['missing_files']);
        $this->assertTrue($detail['preparation']['can_start_application']);
    }

    public function test_missing_profile_details_take_priority_over_eligibility_and_files(): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['school' => null]);
        $detail = $this->detail($applicant->fresh(), $this->scholarship([
            'eligible_education_levels' => 'Elementary', 'requirements' => 'School ID',
        ]));

        $this->assertSame('profile_incomplete', $detail['preparation']['state']);
        $this->assertSame('profile', $detail['preparation']['next_step']);
        $this->assertSame('/dashboard/profile', $detail['preparation']['next_action']['href']);
        $this->assertSame('incomplete', $detail['preparation']['steps'][0]['status']);
        $this->assertContains('school', array_column($detail['preparation']['steps'][0]['details']['missing_fields'], 'key'));
        $this->assertFalse($detail['preparation']['can_start_application']);
    }

    public static function verificationStates(): array
    {
        return ['pending' => ['pending', 'pending', 'Waiting for review'],
            'returned' => ['rejected', 'changes_required', 'Changes requested'],
            'no evidence' => ['unsubmitted', 'evidence_missing', 'Evidence needed']];
    }

    #[DataProvider('verificationStates')]
    public function test_verification_states_have_different_guidance_and_remain_start_blockers(string $storedStatus, string $status, string $label): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['verification_status' => $storedStatus]);
        if ($storedStatus === 'unsubmitted') {
            $applicant->applicantVerificationDocuments()->delete();
        }
        $detail = $this->detail($applicant->fresh(), $this->scholarship());

        $this->assertSame('profile_unverified', $detail['preparation']['state']);
        $this->assertSame('profile', $detail['preparation']['next_step']);
        $this->assertSame($status, $detail['preparation']['steps'][0]['status']);
        $this->assertSame($label, $detail['preparation']['steps'][0]['status_label']);
        $this->assertTrue($detail['preparation']['steps'][0]['blocks_start']);
        $this->assertFalse($detail['preparation']['can_start_application']);
        if ($storedStatus === 'pending') {
            $this->assertStringNotContainsString('Upload', $detail['preparation']['steps'][0]['description']);
        }
    }

    public function test_a_preference_difference_does_not_become_a_required_rule_or_block_starting(): void
    {
        $detail = $this->detail($this->applicant(), $this->scholarship([
            'eligible_courses' => 'BS Nursing', 'eligibility_rule_modes' => ['course' => 'preferred'],
        ]));

        $this->assertSame('preferences_differ', $detail['preparation']['steps'][1]['status']);
        $this->assertSame(1, $detail['preparation']['steps'][1]['details']['preferred_difference_count']);
        $this->assertSame(0, $detail['preparation']['steps'][1]['details']['required_mismatch_count']);
        $this->assertFalse($detail['preparation']['steps'][1]['blocks_start']);
        $this->assertTrue($detail['preparation']['can_start_application']);
    }

    public function test_partial_evidence_is_not_misrepresented_as_only_waiting_for_review(): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $applicant->applicantVerificationDocuments()->where('document_type', 'recent_school_id')->delete();
        $detail = $this->detail($applicant->fresh(), $this->scholarship());
        $profileStep = $detail['preparation']['steps'][0];

        $this->assertSame('evidence_missing', $profileStep['status']);
        $this->assertSame([['key' => 'recent_school_id', 'label' => 'Recent school ID']], $profileStep['details']['missing_evidence']);
        $this->assertSame('profile', $detail['preparation']['next_step']);
        $this->assertFalse($detail['preparation']['can_start_application']);
    }

    public function test_manual_grade_review_is_not_a_confirmed_match_or_a_new_blocker(): void
    {
        $detail = $this->detail($this->applicant(), $this->scholarship([
            'minimum_gwa' => 1.75, 'minimum_grade_scale' => 'grade_point',
        ]));

        $this->assertSame('needs_review', $detail['preparation']['steps'][1]['status']);
        $this->assertSame(1, $detail['preparation']['steps'][1]['details']['provider_review_count']);
        $this->assertTrue($detail['preparation']['can_start_application']);
        $this->assertFalse($detail['preparation']['steps'][1]['blocks_start']);
    }

    public function test_required_mismatch_points_to_eligibility_without_encouraging_inaccurate_profile_changes(): void
    {
        $detail = $this->detail($this->applicant(), $this->scholarship([
            'eligible_education_levels' => 'Elementary', 'eligible_courses' => 'BS Nursing',
            'eligibility_rule_modes' => ['course' => 'preferred'],
        ]));

        $this->assertSame('eligibility', $detail['preparation']['next_step']);
        $this->assertSame('eligibility', $detail['preparation']['next_action']['section']);
        $this->assertSame('blocked', $detail['preparation']['steps'][1]['status']);
        $this->assertSame(1, $detail['preparation']['steps'][1]['details']['required_mismatch_count']);
        $this->assertStringContainsString('inaccurate', $detail['preparation']['steps'][1]['description']);
        $this->assertFalse($detail['preparation']['can_start_application']);
    }

    public static function unavailableStates(): array
    {
        return ['upcoming' => ['upcoming'], 'closed' => ['closed'], 'capacity reached' => ['full']];
    }

    #[DataProvider('unavailableStates')]
    public function test_unavailable_intakes_keep_the_original_action_instead_of_telling_applicants_to_start(string $state): void
    {
        $applicant = $this->applicant();
        $scholarship = $this->scholarship(match ($state) {
            'upcoming' => ['application_opens_at' => now()->addWeek()->toDateString()],
            'closed' => ['deadline' => now()->subDay()->toDateString()],
            default => ['application_limit' => 1],
        });
        if ($state === 'full') {
            ScholarshipApplication::create(['scholarship_id' => $scholarship->id,
                'applicant_id' => $this->applicant()->id, 'status' => 'submitted', 'submitted_at' => now()]);
        }
        $detail = $this->detail($applicant, $scholarship);

        $this->assertSame($state, $detail['preparation']['state']);
        $this->assertNull($detail['preparation']['next_step']);
        $this->assertSame($detail['decision']['action'], $detail['preparation']['next_action']);
        $this->assertFalse($detail['preparation']['can_start_application']);
        $this->assertTrue($detail['preparation']['steps'][3]['action']['disabled']);
    }

    public function test_existing_application_has_priority_even_after_closure_and_profile_changes(): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['school' => null]);
        $scholarship = $this->scholarship(['deadline' => now()->subDay()->toDateString()]);
        $application = ScholarshipApplication::create(['scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $detail = $this->detail($applicant->fresh(), $scholarship);

        $this->assertSame('applied', $detail['preparation']['state']);
        $this->assertSame('submitted', $detail['preparation']['steps'][3]['status']);
        $this->assertSame(route('dashboard.applications.show', $application, false), $detail['preparation']['next_action']['href']);
        $this->assertFalse($detail['preparation']['can_start_application']);
    }

    public function test_email_is_a_submission_requirement_without_changing_the_existing_start_decision(): void
    {
        $applicant = $this->applicant();
        $applicant->forceFill(['email_verified_at' => null])->save();
        $scholarship = $this->scholarship();
        $detail = $this->detail($applicant->fresh(), $scholarship);

        $this->assertTrue($detail['preparation']['can_start_application']);
        $this->assertFalse($detail['preparation']['submission']['email_verified']);
        $this->assertSame('email_unverified', $detail['preparation']['steps'][3]['status']);
        $this->assertSame('Review email verification', $detail['preparation']['next_action']['label']);
        $this->actingAs($applicant)->postJson('/dashboard/applications', [
            'scholarship_id' => $scholarship->id, 'terms_accepted' => true,
        ])->assertForbidden()->assertJsonPath('verification_required', true);
    }

    public function test_opening_and_deadline_today_do_not_create_off_by_one_date_blockers(): void
    {
        $this->travelTo(now()->setTime(21, 30));
        $detail = $this->detail($this->applicant(), $this->scholarship([
            'application_opens_at' => now()->toDateString(), 'deadline' => now()->toDateString(),
        ]));

        $this->assertTrue($detail['preparation']['can_start_application']);
        $this->assertSame('eligible', $detail['preparation']['state']);
    }

    public function test_all_preview_scenarios_are_read_only_and_do_not_reuse_the_provider_profile_or_files(): void
    {
        $scholarship = $this->scholarship(['requirements' => 'School ID', 'eligible_education_levels' => 'college']);
        $viewer = $scholarship->provider;
        $this->document($viewer, 'School ID');
        $service = app(ApplicantScholarshipDetailService::class);

        foreach (['general', 'eligible', 'ineligible'] as $scenario) {
            $detail = $service->buildPreview($scholarship, $viewer, 'summary', $scenario);
            $guide = $detail['preparation'];
            $this->assertSame('preview', $guide['mode']);
            $this->assertFalse($guide['can_start_application']);
            $this->assertFalse($guide['has_applied']);
            $this->assertTrue($guide['next_action']['disabled']);
            foreach ($guide['steps'] as $step) {
                $this->assertFalse($step['blocks_start']);
                if ($step['action']['kind'] === 'link') {
                    $this->assertStringStartsWith("/provider/previews/programs/{$scholarship->id}/", $step['action']['href']);
                } else {
                    $this->assertTrue($step['action']['disabled']);
                    $this->assertNull($step['action']['href']);
                }
            }
            $this->assertNull($guide['steps'][2]['details']['manage_files_href']);
            if ($scenario === 'general') {
                $this->assertSame(['not_compared'], array_values(array_unique(array_column($guide['steps'], 'status'))));
                $this->assertNull($guide['steps'][2]['details']['saved_file_count']);
                $this->assertNull($guide['submission']['email_verified']);
            } else {
                $this->assertSame('verified', $guide['steps'][0]['status']);
                $this->assertSame($scenario === 'ineligible' ? 'blocked' : 'ready', $guide['steps'][1]['status']);
            }
        }

        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->assertDatabaseCount('scholarship_funnel_events', 0);
    }

    private function detail(User $applicant, Scholarship $scholarship, string $section = 'summary'): array
    {
        return app(ApplicantScholarshipDetailService::class)->build($scholarship, $applicant, $section);
    }

    private function document(User $user, string $name, bool $exists = true): void
    {
        $path = "student-documents/{$user->id}/school-id.pdf";
        if ($exists) {
            Storage::disk('local')->put($path, 'Test document');
        }
        $user->studentDocuments()->create(['document_name' => $name, 'original_name' => 'school-id.pdf',
            'path' => $path, 'mime_type' => 'application/pdf', 'size' => 13, 'uploaded_at' => now()]);
    }

    private function applicant(): User
    {
        $user = User::factory()->verifiedProfile()->create();
        $user->studentProfile()->update([
            'birthdate' => '2005-06-01', 'account_managed_by' => 'learner',
            'education_level' => 'college', 'school' => 'Test University', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology', 'year_level' => '1st year',
            'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90, 'grading_scale' => 'percentage', 'income_bracket' => 'Below PHP 10,000',
            'household_size' => 4, 'current_scholarship_status' => 'none', 'city' => 'Quezon City',
            'province' => 'Metro Manila', 'region' => 'NCR', 'profile_photo_path' => 'profile-photos/test.jpg',
        ]);

        return $user->fresh();
    }

    private function scholarship(array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => User::factory()->create(['role' => 'provider'])->id,
            'title' => 'Application Preparation Scholarship', 'category' => 'Academic merit',
            'description' => 'A test program for applicant preparation guidance.',
            'status' => 'published', 'application_mode' => 'online', 'deadline' => now()->addMonth()->toDateString(),
            'selection_stages' => ['screening', 'formal_application', 'decision'], ...$attributes,
        ]);
    }
}
