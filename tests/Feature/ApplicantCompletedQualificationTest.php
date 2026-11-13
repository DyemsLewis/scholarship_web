<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AcceptsProfileEvidence;
use Tests\TestCase;

class ApplicantCompletedQualificationTest extends TestCase
{
    use AcceptsProfileEvidence;
    use RefreshDatabase;

    public function test_existing_profiles_are_not_backfilled_or_given_an_extra_evidence_requirement(): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $readiness = $applicant->applicantProfileReadiness();
        $this->assertNull($applicant->studentProfile->completed_qualification);
        $this->assertNotContains('completed_qualification', array_column($readiness['missing'], 'key'));
        $this->assertTrue($readiness['verified']);
        $this->assertNotContains('qualification_record', ApplicantVerificationDocument::requiredTypesFor($applicant));

        $this->actingAs($applicant)->patchJson('/dashboard/profile', ['profile_section' => 'academic', 'school' => $applicant->studentProfile->school])
            ->assertOk()->assertJsonPath('user.completed_qualification', null)->assertJsonPath('user.is_profile_verified', true);
    }

    public function test_old_curriculum_history_does_not_replace_current_college_studies_or_restart_an_application(): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $profile = $applicant->studentProfile;
        $program = $this->program();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'interview_scheduled', 'submitted_at' => now(),
        ]);

        $this->actingAs($applicant)->patchJson('/dashboard/profile', $this->qualification())
            ->assertOk()->assertJsonPath('user.completed_qualification', 'pre_k12_high_school')
            ->assertJsonPath('user.completed_qualification_school', 'Sample Former High School')
            ->assertJsonPath('user.completed_qualification_year', 2014)
            ->assertJsonPath('user.education_level', $profile->education_level)
            ->assertJsonPath('user.course_or_strand', $profile->course_or_strand)
            ->assertJsonPath('verification_reset', true)
            ->assertJsonPath('user.is_profile_verified', false);

        $this->assertSame('interview_scheduled', $application->fresh()->status);
        $this->assertSame($profile->year_level, $applicant->studentProfile()->value('year_level'));
        $this->assertContains('qualification_record', ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()));
    }

    public static function invalidQualifications(): array
    {
        return [
            'unknown option' => [['completed_qualification' => 'automatically_equivalent'], 'completed_qualification'],
            'array option' => [['completed_qualification' => ['pre_k12_high_school']], 'completed_qualification'],
            'missing institution' => [['completed_qualification_school' => '  '], 'completed_qualification_school'],
            'future year' => [['completed_qualification_year' => 9999], 'completed_qualification_year'],
            'zero year' => [['completed_qualification_year' => '0'], 'completed_qualification_year'],
            'fractional year' => [['completed_qualification_year' => 2014.5], 'completed_qualification_year'],
            'other without a name' => [['completed_qualification' => 'other'], 'completed_qualification_details'],
        ];
    }

    #[DataProvider('invalidQualifications')]
    public function test_invalid_qualifications_do_not_change_the_profile(array $changes, string $field): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [...$this->qualification(), ...$changes])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull($applicant->studentProfile()->value('completed_qualification'));
        $this->assertSame('approved', $applicant->studentProfile()->value('verification_status'));
    }

    public function test_year_is_optional_and_partial_edits_retain_the_saved_institution(): void
    {
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [...$this->qualification(), 'completed_qualification_year' => null])
            ->assertOk()->assertJsonPath('user.completed_qualification_year', null);
        $this->actingAs($applicant->fresh())->patchJson('/dashboard/profile', ['profile_section' => 'academic', 'completed_qualification_year' => 2013])
            ->assertOk()->assertJsonPath('user.completed_qualification_year', 2013)
            ->assertJsonPath('user.completed_qualification_school', 'Sample Former High School');
    }

    public function test_other_qualification_requires_a_name_and_clearing_the_choice_removes_dependent_details(): void
    {
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            ...$this->qualification(), 'completed_qualification' => 'other', 'completed_qualification_details' => '  Named completion certificate  ',
        ])->assertOk()->assertJsonPath('user.completed_qualification_details', 'Named completion certificate');
        $this->actingAs($applicant->fresh())->patchJson('/dashboard/profile', ['profile_section' => 'academic', 'completed_qualification' => 'none'])
            ->assertOk()->assertJsonPath('user.completed_qualification_school', null)
            ->assertJsonPath('user.completed_qualification_details', null)->assertJsonPath('user.completed_qualification_year', null);
        $this->assertNotContains('qualification_record', ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()));
    }

    public function test_an_unrelated_section_cannot_change_or_invalidate_the_completed_qualification(): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            'profile_section' => 'location', 'city' => $applicant->studentProfile->city, ...array_diff_key($this->qualification(), ['profile_section' => true]),
        ])->assertOk()->assertJsonPath('user.completed_qualification', null)->assertJsonPath('user.is_profile_verified', true);
    }

    public function test_a_former_student_needs_completion_evidence_not_a_claim_of_current_enrollment(): void
    {
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            ...$this->qualification(), 'education_level' => 'other', 'enrollment_status' => 'Not currently enrolled',
            'year_level' => 'Fourth year high school', 'course_or_strand' => null, 'academic_year' => '2013-2014',
        ])->assertOk()->assertJsonPath('user.course_or_strand', null)
            ->assertJsonPath('user.year_level', 'Fourth year high school')->assertJsonPath('user.academic_year', '2013-2014');
        $required = ApplicantVerificationDocument::requiredTypesFor($applicant->fresh());
        $this->assertContains('qualification_record', $required);
        $this->assertNotContains('school_record', $required);
        $this->assertNotContains('course_or_strand', array_column($applicant->fresh()->applicantProfileReadiness()['missing'], 'key'));

        $this->actingAs($applicant->fresh())->patchJson('/dashboard/profile', ['profile_section' => 'academic', 'enrollment_status' => 'Enrolled'])->assertOk();
        $this->assertContains('school_record', ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()));
    }

    public function test_the_new_record_upload_has_no_academic_ocr_side_effects_and_is_available_for_individual_review(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->verifiedProfile()->create();
        $profile = $applicant->studentProfile;
        $this->actingAs($applicant)->patchJson('/dashboard/profile', $this->qualification())->assertOk();
        $this->acceptProfileEvidence($applicant->fresh(), $admin);
        $profile = $applicant->studentProfile()->first();
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->firstOrFail();
        $academicBefore = $academic->fresh()->getRawOriginal();

        $this->actingAs($admin)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id), 'verification_status' => 'approved'])
            ->assertUnprocessable()->assertJsonPath('missing_evidence.0', 'qualification_record');

        $upload = $this->actingAs($applicant->fresh())->postJson('/dashboard/profile/verification-documents', [
            'document_type' => 'qualification_record', 'document_file' => UploadedFile::fake()->create('diploma.pdf', 30, 'application/pdf'),
            'expected_revision' => 0, 'terms_accepted' => true,
        ])->assertCreated()->assertJsonPath('user.is_profile_verified', false);
        $this->assertNotNull($upload->json('prepared_document.id'));
        $this->assertSame($profile->gwa, $applicant->studentProfile()->first()->gwa);
        $this->assertSame($academicBefore, $academic->fresh()->getRawOriginal());
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'qualification_record')->firstOrFail();
        $this->assertFalse($document->hasCurrentAcceptance());

        $this->actingAs($admin)->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()->assertJsonFragment(['completed_qualification' => 'pre_k12_high_school']);
        $this->actingAs($admin)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
            'status' => 'approved', 'expected_revision' => $document->revision,
        ])->assertOk();
        $this->assertFalse($applicant->fresh()->publicPayload()['is_profile_verified']);
        $this->assertTrue($document->fresh()->hasCurrentAcceptance());

        $this->actingAs($applicant->fresh())->deleteJson("/dashboard/profile/verification-documents/{$document->id}", ['expected_revision' => $document->fresh()->revision])
            ->assertOk()->assertJsonPath('user.is_profile_verified', false);
    }

    public function test_changing_qualification_claims_invalidates_only_qualification_evidence(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->verifiedProfile()->create();
        $this->actingAs($applicant)->patchJson('/dashboard/profile', $this->qualification())->assertOk();
        $document = $applicant->applicantVerificationDocuments()->create([
            'document_type' => 'qualification_record', 'path' => 'qualification/diploma.pdf', 'original_name' => 'diploma.pdf', 'status' => 'submitted',
            'uploaded_by' => $applicant->id, 'mime_type' => 'application/pdf', 'size' => 50,
        ]);
        Storage::disk('local')->put($document->path, 'test diploma');
        $this->acceptProfileEvidence($applicant->fresh(), $admin);
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->firstOrFail();
        $before = $academic->fresh()->getRawOriginal();

        $this->actingAs($applicant->fresh())->patchJson('/dashboard/profile', ['profile_section' => 'academic', 'completed_qualification_year' => 2013])
            ->assertOk();
        $this->assertFalse($document->fresh()->hasCurrentAcceptance());
        $this->assertSame($before, $academic->fresh()->getRawOriginal());
        $this->assertSame(['completed_qualification_year'], $document->reviews()->where('action', 'claims_changed')->latest('id')->firstOrFail()->changed_fields);
    }

    public function test_recording_old_curriculum_history_does_not_change_senior_high_school_eligibility(): void
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $program = $this->program(['eligible_education_levels' => 'senior_high_school']);
        $service = app(ScholarshipEligibilityService::class);
        $before = $service->evaluate($program, $applicant);
        $applicant->studentProfile->fill(array_diff_key($this->qualification(), ['profile_section' => true]))->save();
        $after = $service->evaluate($program, $applicant->fresh());
        $this->assertSame($before['is_eligible'], $after['is_eligible']);
        $this->assertSame(collect($before['criteria'])->firstWhere('key', 'education_level'), collect($after['criteria'])->firstWhere('key', 'education_level'));
    }

    public function test_mobile_profile_updates_support_the_same_fields_and_preserve_them_for_older_clients(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->verifiedProfile()->create();
        $this->acceptProfileEvidence($applicant);
        $applicant->studentProfile()->update(['verification_status' => 'approved']);
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->firstOrFail();
        $academicBefore = $academic->fresh()->getRawOriginal();
        $token = 'qualification-test-token';
        MobileApiToken::create(['user_id' => $applicant->id, 'name' => 'test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $body = ['first_name' => $applicant->first_name, 'last_name' => $applicant->last_name, 'middle_initial' => $applicant->middle_initial, 'contact_number' => $applicant->contact_number, 'current_scholarship_status' => 'none'];
        $this->withToken($token)->patchJson('/api/mobile/profile', [...$body, ...array_diff_key($this->qualification(), ['profile_section' => true])])
            ->assertOk();
        $this->withToken($token)->patchJson('/api/mobile/profile', $body)->assertOk();
        $this->assertSame('pre_k12_high_school', $applicant->studentProfile()->value('completed_qualification'));
        $this->assertSame(2014, $applicant->studentProfile()->value('completed_qualification_year'));
        $this->assertSame($academicBefore, $academic->fresh()->getRawOriginal());
        $this->assertTrue($academic->fresh()->hasCurrentAcceptance());
    }

    private function qualification(): array
    {
        return ['profile_section' => 'academic', 'completed_qualification' => 'pre_k12_high_school', 'completed_qualification_school' => 'Sample Former High School', 'completed_qualification_year' => 2014];
    }

    private function program(array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => User::factory()->create(['role' => 'provider'])->id, 'title' => 'Qualification history test',
            'description' => 'Does not assume credential equivalence.', 'status' => 'published', 'deadline' => now()->addMonth(), ...$attributes,
        ]);
    }
}
