<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_area(): void
    {
        $this->get('/admin')
            ->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_admin_area(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($provider)
            ->getJson('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_access_admin_area(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_can_access_role_specific_review_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($admin)
            ->get("/admin/providers/{$provider->id}/review")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/admin/providers/{$provider->id}/review/data")
            ->assertOk()
            ->assertJsonPath('provider.id', $provider->id)
            ->assertJsonPath('provider.role', 'provider');

        $this->actingAs($admin)
            ->get("/admin/applicants/{$applicant->id}/review")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()
            ->assertJsonPath('applicant.id', $applicant->id)
            ->assertJsonPath('applicant.role', 'applicant');

        $this->actingAs($admin)
            ->get("/admin/providers/{$applicant->id}/review")
            ->assertNotFound();

        $this->actingAs($admin)
            ->get("/admin/applicants/{$provider->id}/review")
            ->assertNotFound();

        $this->actingAs($provider)
            ->get("/admin/providers/{$provider->id}/review")
            ->assertForbidden();
    }

    public function test_provider_area_requires_provider_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($admin)
            ->get('/provider')
            ->assertForbidden();

        $this->actingAs($provider)
            ->get('/provider')
            ->assertOk();
    }

    public function test_admin_program_review_queue_filters_each_status_with_global_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);

        foreach (['pending_review', 'published', 'rejected'] as $status) {
            Scholarship::create([
                'provider_id' => $provider->id,
                'title' => str($status)->replace('_', ' ')->title().' Program',
                'description' => "Program in the {$status} review state.",
                'status' => $status,
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('selected_program_status', 'pending_review')
            ->assertJsonPath('stats.pending_programs', 1)
            ->assertJsonPath('stats.published_programs', 1)
            ->assertJsonPath('stats.rejected_programs', 1)
            ->assertJsonCount(1, 'scholarships')
            ->assertJsonPath('scholarships.0.status', 'pending_review');

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data?program_status=published')
            ->assertOk()
            ->assertJsonPath('selected_program_status', 'published')
            ->assertJsonCount(1, 'scholarships')
            ->assertJsonPath('scholarships.0.status', 'published');

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data?program_status=rejected')
            ->assertOk()
            ->assertJsonPath('selected_program_status', 'rejected')
            ->assertJsonCount(1, 'scholarships')
            ->assertJsonPath('scholarships.0.status', 'rejected');
    }

    public function test_admin_can_review_benefit_release_evidence_and_open_private_receipt(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['provider_name' => 'Tulay Aral Community Foundation']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Continuing Scholar Grant',
            'description' => 'A scholarship with documented recipient releases.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'disbursed',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester allowance',
            'release_at' => now()->subDay(),
            'benefit_description' => 'PHP 5,000 learning allowance',
            'amount' => 5000,
            'release_method' => 'in_person',
            'location' => 'Provider office',
            'requires_original_verification' => true,
            'status' => 'completed',
            'published_at' => now()->subWeek(),
        ]);
        $receiptPath = 'benefit-release-receipts/test/signed-receipt.pdf';
        Storage::disk('local')->put($receiptPath, 'signed receipt');
        $record = RecipientBenefitReleaseRecord::create([
            'recipient_benefit_release_id' => $release->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'released',
            'originals_verified' => true,
            'notes' => 'Recipient signed after receiving the allowance.',
            'receipt_original_name' => 'signed-receipt.pdf',
            'receipt_path' => $receiptPath,
            'receipt_mime_type' => 'application/pdf',
            'receipt_size' => 14,
            'recorded_by' => $provider->id,
            'recorded_at' => now(),
            'released_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('stats.benefit_records', 1)
            ->assertJsonPath('stats.benefits_released', 1)
            ->assertJsonPath('stats.benefits_with_receipts', 1)
            ->assertJsonPath('benefit_records.0.id', $record->id)
            ->assertJsonPath('benefit_records.0.provider_name', 'Tulay Aral Community Foundation')
            ->assertJsonPath('benefit_records.0.oversight_status', 'documented')
            ->assertJsonPath('benefit_records.0.evidence_type', 'receipt');

        $this->actingAs($admin)
            ->get("/admin/benefit-release-records/{$record->id}/receipt")
            ->assertOk();

        $this->actingAs($provider)
            ->get("/admin/benefit-release-records/{$record->id}/receipt")
            ->assertForbidden();
    }
}
