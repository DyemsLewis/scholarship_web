<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicantProfileEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public static function profileAreas(): array
    {
        return [
            'identity' => ['identity_record'],
            'enrollment' => ['school_record'],
            'income' => ['income_record'],
            'residence' => ['residency_record'],
        ];
    }

    #[DataProvider('profileAreas')]
    public function test_admin_requires_evidence_for_each_profile_area(string $type): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->applicantVerificationDocuments()->where('document_type', $type)->delete();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", ['verification_status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('missing_evidence.0', $type);

        $this->assertSame('pending', $applicant->studentProfile()->value('verification_status'));
    }

    #[DataProvider('profileAreas')]
    public function test_replacing_required_evidence_returns_the_profile_to_review(string $type): void
    {
        Storage::fake('local');
        $applicant = User::factory()->verifiedProfile()->create();

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => $type,
                'document_file' => UploadedFile::fake()->create('supporting-record.pdf', 100, 'application/pdf'),
                'terms_accepted' => true,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('user.applicant_verification_status', 'pending')
            ->assertJsonPath('user.is_profile_verified', false)
            ->assertJsonPath('profile_readiness.verified', false)
            ->assertJsonPath('profile_readiness.verification_status', 'pending')
            ->assertJsonFragment([
                'document_type' => $type,
                'supports' => ApplicantVerificationDocument::EVIDENCE_DETAILS[$type]['supports'],
            ]);

        $this->assertNotNull($response->json('prepared_document.id'));
        $this->assertNull($applicant->studentProfile()->value('verified_at'));
    }

    public function test_checklist_follows_the_applicants_guardian_context(): void
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2003-01-01',
            'education_level' => 'college',
            'account_managed_by' => 'learner',
        ]);
        $requirements = collect(ApplicantVerificationDocument::requirementsFor($applicant->fresh()));
        $this->assertCount(6, $requirements->where('required', true));
        $this->assertFalse($requirements->firstWhere('value', 'guardian_record')['required']);
        $this->assertFalse($requirements->contains('value', 'no_current_scholarship_proof'));

        $applicant->studentProfile()->update(['birthdate' => now()->subYears(16)->toDateString()]);
        $this->assertContains('guardian_record', ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()));

        $this->actingAs($applicant->fresh())->getJson('/dashboard/profile/data')
            ->assertOk()
            ->assertJsonCount(7, 'user.profile_evidence_requirements')
            ->assertJsonFragment(['value' => 'income_record', 'required' => true]);
    }

    public function test_admin_can_approve_a_profile_with_all_required_evidence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", ['verification_status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('user.is_profile_verified', true);
    }

    public function test_provider_cannot_bypass_missing_profile_evidence(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->delete();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Evidence Review Scholarship',
            'description' => 'Requires evidence for the applicant profile.',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification');
    }

    public function test_deleting_required_household_evidence_reopens_review(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->verifiedProfile()->create();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();

        $this->actingAs($applicant)
            ->deleteJson("/dashboard/profile/verification-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('user.is_profile_verified', false)
            ->assertJsonPath('user.applicant_verification_status', 'pending');
    }

    public function test_changing_evidence_backed_household_or_residence_details_reopens_review(): void
    {
        foreach ([
            ['profile_section' => 'household', 'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 5, 'current_scholarship_status' => 'none'],
            ['profile_section' => 'location', 'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ] as $changes) {
            $applicant = User::factory()->verifiedProfile()->create();

            $this->actingAs($applicant)->patchJson('/dashboard/profile', $changes)
                ->assertOk()
                ->assertJsonPath('verification_reset', true)
                ->assertJsonPath('user.is_profile_verified', false);
        }
    }
}
