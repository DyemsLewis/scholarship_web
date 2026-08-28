<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderBulkApplicationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_can_bulk_approve_ready_applicants_for_a_configured_exam(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($provider, ['screening', 'exam', 'formal_application', 'decision']);
        $first = $this->application($scholarship, 'submitted');
        $second = $this->application($scholarship, 'under_review');

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/applications/bulk-advance", [
                'application_ids' => [$first->id, $second->id],
                'target_stage' => 'pass_prescreening',
            ])->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertSame('exam_qualified', $first->fresh()->status);
        $this->assertSame('exam_qualified', $second->fresh()->status);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $first->applicant_id,
            'type' => 'application_status',
            'title' => 'Pre-screening passed',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $second->applicant_id,
            'type' => 'application_status',
            'title' => 'Pre-screening passed',
        ]);
    }

    public function test_bulk_selection_accepts_only_applicants_at_the_final_decision_stage(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($provider, ['screening', 'formal_application', 'decision']);
        $approved = $this->application($scholarship, 'under_review');
        $underReview = $this->application($scholarship, 'under_review');
        $workflow = app(ApplicationWorkflowService::class);
        $approved = $workflow->start($approved);
        $approved = $workflow->recordStageResult($approved, 'screening', 'passed', $provider);
        $approved = $workflow->recordStageResult($approved, 'formal_application', 'passed', $provider);

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/applications/bulk-advance", [
                'application_ids' => [$approved->id, $underReview->id],
                'target_stage' => 'selected',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('application_ids');

        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('under_review', $underReview->fresh()->status);

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/applications/bulk-advance", [
                'application_ids' => [$approved->id],
                'target_stage' => 'selected',
            ])->assertOk()
            ->assertJsonPath('updated_count', 1);

        $this->assertSame('awarded', $approved->fresh()->status);
    }

    public function test_bulk_exam_approval_keeps_document_and_program_ownership_safeguards(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($provider, ['screening', 'exam', 'formal_application', 'decision'], 'School ID');
        $otherProgram = $this->program($otherProvider, ['screening', 'exam', 'formal_application', 'decision']);
        $pendingDocumentApplication = $this->application($scholarship, 'submitted', ['School ID']);
        $foreignApplication = $this->application($otherProgram, 'submitted');
        ApplicationDocument::create([
            'scholarship_application_id' => $pendingDocumentApplication->id,
            'uploaded_by' => $pendingDocumentApplication->applicant_id,
            'document_name' => 'School ID',
            'original_name' => 'school-id.pdf',
            'path' => 'test/school-id.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/applications/bulk-advance", [
                'application_ids' => [$pendingDocumentApplication->id],
                'target_stage' => 'pass_prescreening',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('application_ids');

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/applications/bulk-advance", [
                'application_ids' => [$foreignApplication->id],
                'target_stage' => 'pass_prescreening',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('application_ids');

        $this->assertSame('submitted', $pendingDocumentApplication->fresh()->status);
        $this->assertSame('submitted', $foreignApplication->fresh()->status);
    }

    private function program(User $provider, array $stages, ?string $requirements = null): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to test guarded provider bulk approvals.',
            'requirements' => $requirements,
            'selection_stages' => $stages,
            'slots_available' => 10,
            'status' => 'published',
        ]);
    }

    private function application(Scholarship $scholarship, string $status, array $checklist = []): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => $status,
            'document_checklist' => $checklist,
            'submitted_at' => now(),
        ]);
    }
}
