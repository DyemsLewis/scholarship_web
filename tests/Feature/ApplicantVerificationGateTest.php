<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicantVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    public static function unverifiedProfiles(): array
    {
        return [
            'website pending' => ['web', 'pending'],
            'website changes requested' => ['web', 'rejected'],
            'website no evidence' => ['web', 'unsubmitted'],
            'mobile pending' => ['mobile', 'pending'],
            'mobile changes requested' => ['mobile', 'rejected'],
            'mobile no evidence' => ['mobile', 'unsubmitted'],
        ];
    }

    #[DataProvider('unverifiedProfiles')]
    public function test_complete_but_unverified_profiles_cannot_submit(string $channel, string $status): void
    {
        Mail::fake();
        $applicant = $this->completeApplicant();
        $this->setVerificationStatus($applicant, $status);
        $scholarship = $this->scholarship();

        $this->assertTrue($applicant->fresh()->applicantProfileReadiness()['complete']);

        $this->submit($channel, $applicant, $scholarship)
            ->assertForbidden()
            ->assertJsonPath('profile_verification_required', true)
            ->assertJsonPath('profile_readiness.verified', false)
            ->assertJsonPath('profile_readiness.verification_status', $status)
            ->assertJsonPath('profile_url', '/dashboard/profile?section=verification');

        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_approved_status_without_academic_evidence_does_not_unlock_applications(): void
    {
        $applicant = $this->completeApplicant();
        $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->delete();

        $this->submit('web', $applicant->fresh(), $this->scholarship())
            ->assertForbidden()
            ->assertJsonPath('profile_readiness.verification_status', 'unsubmitted');

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_an_approved_profile_can_submit_on_the_website_and_mobile(): void
    {
        Mail::fake();
        $applicant = $this->completeApplicant();

        foreach (['web', 'mobile'] as $channel) {
            $scholarship = $this->scholarship();
            $this->submit($channel, $applicant->fresh(), $scholarship)->assertCreated();

            $this->assertDatabaseHas('scholarship_applications', [
                'scholarship_id' => $scholarship->id,
                'applicant_id' => $applicant->id,
            ]);
        }

        $this->assertDatabaseCount('scholarship_applications', 2);
    }

    public function test_the_catalog_wizard_mobile_and_dashboard_explain_the_verification_gate(): void
    {
        $applicant = $this->completeApplicant();
        $this->setVerificationStatus($applicant, 'pending');
        $scholarship = $this->scholarship();

        $this->actingAs($applicant->fresh())
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'profile_unverified')
            ->assertJsonPath('detail.decision.can_apply', false)
            ->assertJsonPath('detail.decision.action.href', '/dashboard/profile?section=verification')
            ->assertJsonPath('detail.content.readiness.0.status', 'Verification required');

        $this->actingAs($applicant->fresh())
            ->getJson('/dashboard/applications/data')
            ->assertOk()
            ->assertJsonPath('profile_readiness.complete', true)
            ->assertJsonPath('profile_readiness.verified', false)
            ->assertJsonPath('scholarships.0.can_start_application', false);

        $this->withToken($this->mobileToken($applicant))
            ->getJson('/api/mobile/profile')
            ->assertOk()
            ->assertJsonPath('scholarships.0.can_start_application', false);

        $this->actingAs($applicant->fresh())
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('dashboard.primary_action.kind', 'profile')
            ->assertJsonPath('dashboard.primary_action.href', '/dashboard/profile?section=verification')
            ->assertJsonPath('dashboard.primary_action.title', 'Your profile is awaiting verification');
    }

    public function test_reopening_verification_blocks_a_form_that_was_previously_ready(): void
    {
        Mail::fake();
        $applicant = $this->completeApplicant();
        $scholarship = $this->scholarship();

        $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('detail.decision.can_apply', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'pending',
                'verification_notes' => 'The updated academic record requires another review.',
            ])
            ->assertOk();

        $this->submit('web', $applicant->fresh(), $scholarship)
            ->assertForbidden()
            ->assertJsonPath('profile_verification_required', true);

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_existing_application_is_accessible_while_profile_verification_is_pending(): void
    {
        Mail::fake();
        $applicant = $this->completeApplicant();
        $scholarship = $this->scholarship();
        $this->submit('web', $applicant, $scholarship)->assertCreated();
        $application = ScholarshipApplication::query()->sole();
        $this->setVerificationStatus($applicant, 'pending');

        $this->actingAs($applicant->fresh())
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'applied')
            ->assertJsonPath('detail.decision.action.href', "/dashboard/applications/{$application->id}");

        $this->actingAs($applicant->fresh())
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk();
    }

    public function test_an_applicant_without_applications_can_be_reviewed_by_an_admin(): void
    {
        $applicant = $this->completeApplicant();
        $this->setVerificationStatus($applicant, 'pending');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('applicants.0.id', $applicant->id)
            ->assertJsonPath('applicants.0.applicant_verification_status', 'pending');

        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    private function submit(string $channel, User $applicant, Scholarship $scholarship)
    {
        $payload = ['scholarship_id' => $scholarship->id, 'terms_accepted' => true];

        return $channel === 'web'
            ? $this->actingAs($applicant)->postJson('/dashboard/applications', $payload)
            : $this->withToken($this->mobileToken($applicant))->postJson('/api/mobile/applications', $payload);
    }

    private function setVerificationStatus(User $applicant, string $status): void
    {
        $applicant->studentProfile()->update(['verification_status' => $status]);
        if ($status === 'unsubmitted') {
            $applicant->applicantVerificationDocuments()->delete();
        }
        $applicant->unsetRelation('studentProfile');
        $applicant->unsetRelation('applicantVerificationDocuments');
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'account_managed_by' => 'learner',
            'education_level' => 'college',
            'school' => 'Test University',
            'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'household_size' => 4,
            'current_scholarship_status' => 'none',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/verification-gate.jpg',
        ]);

        return $applicant->fresh();
    }

    private function scholarship(): Scholarship
    {
        $provider = User::factory()->create(['role' => 'provider']);

        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Verified Profile Scholarship',
            'description' => 'Requires profile verification before applying.',
            'application_mode' => 'provider_review',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
    }

    private function mobileToken(User $applicant): string
    {
        $token = 'verification-gate-'.bin2hex(random_bytes(12));
        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'verification-gate-test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
