<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OutsideScholarshipProfileRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_profile_readiness_and_public_payload_no_longer_require_or_display_external_status(): void
    {
        $applicant = $this->applicant();
        $this->assertTrue($applicant->applicantProfileReadiness()['complete']);
        $applicant->studentProfile()->update(['current_scholarship_status' => 'receiving', 'current_scholarship_details' => 'Historical external declaration']);
        $applicant = $applicant->fresh();
        $this->assertTrue($applicant->applicantProfileReadiness()['complete']);
        $this->assertArrayNotHasKey('current_scholarship_status', $applicant->publicPayload());
        $this->assertArrayNotHasKey('current_scholarship_details', $applicant->publicPayload());
        $this->assertNotContains('current_scholarship_status', array_column($applicant->applicantProfileReadiness()['missing'], 'key'));
    }

    public function test_household_can_be_saved_without_external_status_and_old_clients_cannot_change_retired_fields(): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['current_scholarship_status' => 'receiving', 'current_scholarship_details' => 'Historical external declaration']);
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            'profile_section' => 'household', 'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 5,
        ])->assertOk()->assertJsonPath('profile_readiness.complete', true)
            ->assertJsonMissingPath('user.current_scholarship_status')->assertJsonMissingPath('user.current_scholarship_details');
        $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            'profile_section' => 'household', 'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 6,
            'current_scholarship_status' => 'none', 'current_scholarship_details' => 'Must not replace historical data',
        ])->assertOk();
        $profile = $applicant->fresh()->studentProfile;
        $this->assertSame(6, $profile->household_size);
        $this->assertSame('receiving', $profile->current_scholarship_status);
        $this->assertSame('Historical external declaration', $profile->current_scholarship_details);
    }

    public function test_mobile_profile_updates_do_not_require_or_write_the_retired_fields(): void
    {
        $applicant = $this->applicant();
        $token = 'outside-scholarship-removal-test-token';
        MobileApiToken::create(['user_id' => $applicant->id, 'name' => 'test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $body = ['first_name' => $applicant->first_name, 'last_name' => $applicant->last_name, 'contact_number' => $applicant->contact_number, 'household_size' => 5];
        $this->withToken($token)->patchJson('/api/mobile/profile', $body)->assertOk()
            ->assertJsonMissingPath('user.current_scholarship_status')->assertJsonMissingPath('user.current_scholarship_details');
        $this->withToken($token)->patchJson('/api/mobile/profile', [...$body, 'current_scholarship_status' => 'receiving', 'current_scholarship_details' => 'Ignored old-client input'])->assertOk();
        $this->assertNull($applicant->fresh()->studentProfile->current_scholarship_status);
        $this->assertNull($applicant->studentProfile->current_scholarship_details);
    }

    public function test_old_declarations_never_block_or_satisfy_an_external_check(): void
    {
        $applicant = $this->applicant();
        $program = $this->program();
        foreach ([null, 'none', 'receiving', 'pending', 'completed', 'legacy_value'] as $status) {
            $applicant->studentProfile()->update(['current_scholarship_status' => $status]);
            $match = (new ScholarshipEligibilityService())->evaluate($program, $applicant->fresh());
            $criterion = collect($match['criteria'])->firstWhere('key', 'current_scholarship_support');
            $this->assertSame('pass', $criterion['status']);
            $this->assertTrue($match['is_eligible']);
            $this->assertSame('No other active scholarship recorded in this platform', $criterion['student_value']);
            $this->assertStringContainsString('Providers may request application documents', $criterion['note']);
        }
        $preview = (new ScholarshipEligibilityService())->evaluate($program, null, false);
        $this->assertSame('missing', collect($preview['criteria'])->firstWhere('key', 'current_scholarship_support')['status']);
    }

    public function test_active_platform_awards_are_checked_even_without_a_profile_declaration(): void
    {
        $applicant = $this->applicant();
        $program = $this->program();
        $other = $this->program();
        $award = ScholarshipApplication::create(['scholarship_id' => $other->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected', 'submitted_at' => now()]);
        foreach (['awarded', 'distribution_scheduled', 'disbursed', 'renewed'] as $status) {
            $award->update(['status' => $status]);
            $match = (new ScholarshipEligibilityService())->evaluate($program, $applicant);
            $this->assertFalse($match['is_eligible']);
            $this->assertSame('fail', collect($match['criteria'])->firstWhere('key', 'current_scholarship_support')['status']);
        }
        $sameProgram = (new ScholarshipEligibilityService())->evaluate($other, $applicant);
        $this->assertSame('pass', collect($sameProgram['criteria'])->firstWhere('key', 'current_scholarship_support')['status']);
        $award->update(['status' => 'benefits_terminated']);
        $ended = (new ScholarshipEligibilityService())->evaluate($program, $applicant);
        $this->assertSame('pass', collect($ended['criteria'])->firstWhere('key', 'current_scholarship_support')['status']);
    }

    public function test_providers_can_still_request_outside_scholarship_documents_during_application(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $name = 'Certificate of no other scholarship from the school';
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Document-based outside scholarship check', 'status' => 'draft', 'requirements' => $name,
            'exclude_current_scholarship_recipients' => true,
        ])->assertCreated()->assertJsonPath('scholarship.requirements', $name);
        $program = Scholarship::findOrFail($response->json('scholarship.id'));
        $this->assertContains($name, (new ScholarshipEligibilityService())->documentRequirements($program));
    }

    public function test_new_application_snapshots_omit_the_retired_declaration(): void
    {
        $applicant = $this->applicant();
        $applicant->studentProfile()->update(['current_scholarship_status' => 'receiving', 'current_scholarship_details' => 'Historical external declaration']);
        $application = ScholarshipApplication::create(['scholarship_id' => $this->program()->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $application = app(ApplicationWorkflowService::class)->start($application);
        $snapshot = $application->submission_snapshot['current']['applicant'];
        $this->assertSame($applicant->name, $snapshot['name']);
        $this->assertArrayNotHasKey('current_scholarship_status', $snapshot);
        $this->assertArrayNotHasKey('current_scholarship_details', $snapshot);
    }

    private function applicant(): User
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2000-05-10', 'account_managed_by' => 'learner', 'citizenship_status' => 'filipino',
            'education_level' => 'college', 'school' => 'Test University', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Mathematics', 'year_level' => '1st year', 'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90, 'grading_scale' => 'percentage', 'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 4,
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR', 'profile_photo_path' => 'profile-photos/tests/scholarship-check.jpg',
            'current_scholarship_status' => null, 'current_scholarship_details' => null,
        ]);

        return $applicant->fresh();
    }

    private function program(): Scholarship
    {
        return Scholarship::create(['provider_id' => User::factory()->create(['role' => 'provider'])->id,
            'title' => 'Platform support eligibility test', 'description' => 'Checks active awards recorded in the platform.',
            'status' => 'published', 'deadline' => now()->addMonth()->toDateString(), 'exclude_current_scholarship_recipients' => true]);
    }
}
