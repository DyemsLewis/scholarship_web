<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationStageSession;
use App\Models\ProviderServicePurchase;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Database\Seeders\FreshWorkflowDemoSeeder;
use Database\Seeders\Support\WorkflowDemoCatalog;
use Database\Seeders\Support\WorkflowDemoAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FreshWorkflowDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fresh_demo_covers_roles_and_leaves_each_current_decision_to_the_tester(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $this->assertSame('superadmin', User::findOrFail(1)->username);
        $this->assertSame(7, Scholarship::count());
        $this->assertFalse(Scholarship::whereColumn('handoff_deadline', '<', 'deadline')->exists());
        $this->assertSame(3, User::where('role', 'provider')->whereNull('parent_account_id')->count());
        $this->assertSame(count(WorkflowDemoCatalog::applicants()), User::where('role', 'applicant')->count());
        $this->assertFalse(User::whereIn('username', ['aralbridge', 'futureweave', 'miracelesteandrada'])->exists());
        $this->assertTrue(Hash::check('password123', User::where('username', 'platform.reviews')->sole()->password));
        $this->assertFalse(ScholarshipApplication::whereIn('final_outcome', ['not_selected', 'waitlisted'])->exists());
        $this->assertFalse(ScholarshipApplication::whereIn('status', ['rejected', 'exam_failed', 'interview_failed', 'withdrawn'])->exists());

        foreach (WorkflowDemoCatalog::applicants() as $record) {
            $user = User::where('username', $record['username'])->sole();
            Storage::disk('local')->assertExists($user->studentProfile->profile_photo_path);
            if (in_array($record['scenario'], ['profile', 'verification'], true)) {
                $this->assertFalse($user->applicantProfileReadiness()['verified']);
                continue;
            }
            $this->assertTrue($user->applicantProfileReadiness()['complete'], $record['username']);
            $this->assertTrue($user->applicantProfileReadiness()['verified'], $record['username']);
            $this->assertSame([], ApplicantVerificationDocument::missingRequiredTypesFor($user));
        }
        foreach (['formal' => 'formal_application', 'exam.assign' => 'exam', 'exam.result' => 'exam',
            'interview.assign' => 'interview', 'interview.result' => 'interview', 'decision' => 'decision'] as $scenario => $stage) {
            $application = $this->application($scenario);
            $this->assertSame($stage, $application->workflow_stage);
            $this->assertNull($application->final_outcome);
            $this->assertNull($application->stageProgresses()->where('stage_key', $stage)->sole()->result);
        }
        $this->assertNull($this->application('agreement')->student_responded_at);
        $this->assertSame('ready_to_close', RecipientMonitoringCase::where('scholarship_application_id', $this->application('monitoring.close')->id)->sole()->status);
        $this->assertFalse($this->application('support.decision')->supportDecisions()->exists());
        $this->assertSame(0, $this->application('onetime.plan')->scholarship->monitoringCycles()->count());
        $this->assertFalse(ProviderServicePurchase::where('status', 'failed')->exists());
        $this->assertSame(7, Scholarship::distinct()->count('image_path'));
        $evidence = $this->application('exam.result')->scholarship->stageEvidence()->firstOrFail();
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($evidence->path));
        $this->assertSame(User::count(), WorkflowDemoAudit::inspect()['users']);
    }

    public function test_the_ready_records_can_really_proceed_through_the_application_monitoring_and_release_apis(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $actor = fn ($username) => User::where('username', $username)->sole();
        $program = Scholarship::where('title', 'Marilag Continuing Learners Scholarship')->sole();
        $this->actingAs($actor('learner.apply'))->postJson('/dashboard/applications', [
            'scholarship_id' => $program->id, 'document_checklist' => preg_split('/\n/', $program->requirements),
            'application_answers' => [['question_id' => 'education_plan', 'answer' => 'Continue school and prepare for college.']],
            'terms_accepted' => true,
        ])->assertCreated();
        foreach (['formal' => 'formal_application', 'exam.result' => 'exam', 'interview.result' => 'interview'] as $scenario => $stage) {
            $application = $this->application($scenario);
            $this->actingAs($actor('marilag.selection'))
                ->patchJson("/provider/applications/{$application->id}/stages/{$stage}/result", ['result' => 'passed', 'notes' => 'Tester-recorded decision in the isolated test database.'])
                ->assertOk();
        }
        $application = $this->application('decision');
        $this->actingAs($actor('marilag.decisions'))->patchJson("/provider/applications/{$application->id}/final-outcome", [
            'outcome' => 'selected', 'notes' => 'Tester chooses the final outcome.',
        ])->assertOk();
        $application = $this->application('monitoring.close');
        $case = RecipientMonitoringCase::where('scholarship_application_id', $application->id)->sole();
        $this->actingAs($actor('marilag.monitoring'))->patchJson("/provider/monitoring-cases/{$case->id}/close", [
            'outcome' => 'completed', 'reason' => 'All required records are verified.',
        ])->assertOk();
        $application = $this->application('onetime.record');
        $record = $application->benefitReleaseRecords()->sole();
        $this->actingAs($actor('marilag.releases'))->post("/provider/benefit-release-records/{$record->id}/result", [
            'status' => 'released', 'originals_verified' => true,
            'receipt_proof' => UploadedFile::fake()->create('demo-distribution.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $application = $this->application('onetime.confirm');
        $record = $application->benefitReleaseRecords()->sole();
        $this->actingAs($application->applicant)->postJson("/dashboard/benefit-release-records/{$record->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
        ])->assertCreated();

        $this->actingAs($actor('platform.reviews'))->patchJson('/admin/providers/'.$actor('dalisay')->id.'/verification', [
            'verification_status' => 'approved',
        ])->assertOk();
        $this->actingAs($actor('platform.reviews'))->patchJson('/admin/users/'.$actor('learner.verification')->id.'/profile-verification', [
            'verification_status' => 'approved', 'academic_grading_scale' => 'percentage', 'academic_result' => 92,
        ])->assertOk();
        $pending = Scholarship::where('status', 'pending_review')->sole();
        $this->actingAs($actor('platform.reviews'))->patchJson("/admin/scholarships/{$pending->id}/review", ['status' => 'published'])->assertOk();
    }

    public function test_each_specialist_workspace_can_load_the_fresh_demo(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        foreach (['reviews', 'selection', 'decisions', 'recipients', 'monitoring', 'releases', 'organization-profile', 'team', 'support', 'billing'] as $workspace) {
            $suffix = ['reviews' => 'reviewer', 'organization-profile' => 'profile'][$workspace] ?? $workspace;
            $this->actingAs(User::where('username', 'marilag.'.$suffix)->sole())
                ->getJson("/provider/workspaces/{$workspace}/dashboard/data")->assertOk();
        }
        foreach (['accounts', 'reviews', 'support', 'billing', 'finance', 'records'] as $workspace) {
            $this->actingAs(User::where('username', 'platform.'.$workspace)->sole())
                ->getJson("/admin/workspaces/{$workspace}/dashboard/data")->assertOk();
        }
        $this->actingAs(User::where('username', 'platform.manager')->sole())->getJson('/admin/workspaces/portal/data')->assertOk();
        $this->actingAs(User::where('username', 'marilag.programs')->sole())->getJson('/provider/workspaces/programs/data')->assertOk();
        foreach (WorkflowDemoCatalog::applicants() as $record) {
            $this->actingAs(User::where('username', $record['username'])->sole())->getJson('/dashboard/overview-data')->assertOk();
        }
    }

    private function application(string $scenario): ScholarshipApplication
    {
        return ScholarshipApplication::where('applicant_id', User::where('username', 'learner.'.$scenario)->sole()->id)->sole();
    }
}
