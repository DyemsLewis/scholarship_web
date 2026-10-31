<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminReviewRefinementTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_queue_formats_education_without_changing_strand_acronyms(): void
    {
        $reviewer = $this->reviewer();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update([
            'education_level' => 'senior_high_school',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
        ]);

        $this->actingAs($reviewer)->getJson('/admin/workspaces/reviews/applicants/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.education', 'Senior High School - STEM - Grade 12');

        $this->assertSame('senior_high_school', $applicant->fresh()->studentProfile->education_level);
    }

    public function test_benefit_queue_includes_photo_and_named_private_preview_without_changing_release_state(): void
    {
        Storage::fake('local');
        $reviewer = $this->reviewer();
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update(['profile_photo_path' => 'profile-photos/demo-placeholder.png']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Review fixture grant',
            'description' => 'A test program.', 'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'disbursed', 'document_checklist' => [], 'submitted_at' => now(),
        ]);
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $program->id, 'created_by' => $provider->id,
            'title' => 'Learning allowance', 'release_at' => now()->subDay(),
            'benefit_description' => 'A documented allowance.', 'amount' => 5000,
            'release_method' => 'in_person', 'status' => 'completed', 'published_at' => now()->subWeek(),
        ]);
        Storage::disk('local')->put('benefit-release-receipts/test/receipt.pdf', 'test receipt');
        $record = RecipientBenefitReleaseRecord::create([
            'recipient_benefit_release_id' => $release->id, 'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id, 'status' => 'released', 'originals_verified' => true,
            'receipt_original_name' => 'learning-allowance-signed-receipt.pdf',
            'receipt_path' => 'benefit-release-receipts/test/receipt.pdf',
            'receipt_mime_type' => 'application/pdf', 'receipt_size' => 12,
            'recorded_by' => $provider->id, 'recorded_at' => now(), 'released_at' => now(),
        ]);

        $this->actingAs($reviewer)->getJson('/admin/workspaces/reviews/benefits/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.status', 'released')
            ->assertJsonPath('items.0.oversight_status', 'documented')
            ->assertJsonPath('items.0.receipt_name', 'learning-allowance-signed-receipt.pdf')
            ->assertJsonPath('items.0.receipt_url', route('admin.benefit-release-records.receipt', $record))
            ->assertJsonPath('items.0.photo_url', route('admin.applicants.profile-photo.view', $applicant));
        $this->get(route('admin.benefit-release-records.receipt', $record))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('released', $record->fresh()->status);
        $this->assertSame('disbursed', $application->fresh()->status);

        Storage::disk('local')->delete($record->receipt_path);
        $this->getJson('/admin/workspaces/reviews/benefits/data?status=all')->assertOk()
            ->assertJsonPath('items.0.receipt_url', null)->assertJsonPath('items.0.receipt_name', null);

        $accountManager = User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $reviewer->parent_account_id,
            'account_title' => 'Account manager', 'permissions' => ['manage_accounts'],
        ]);
        $this->actingAs($accountManager)->getJson('/admin/workspaces/reviews/benefits/data?status=all')->assertForbidden();
        $this->get(route('admin.benefit-release-records.receipt', $record))->assertForbidden();
        $this->actingAs($provider)->getJson('/admin/workspaces/reviews/benefits/data?status=all')->assertForbidden();
    }

    private function reviewer(): User
    {
        $primary = User::factory()->create(['role' => 'admin']);

        return User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $primary->id,
            'account_title' => 'Review officer', 'permissions' => ['manage_reviews'],
        ]);
    }
}
