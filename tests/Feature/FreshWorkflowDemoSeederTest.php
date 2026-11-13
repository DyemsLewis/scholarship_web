<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationStageSession;
use App\Models\ProviderServicePurchase;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ReviewRubric;
use Database\Seeders\FreshWorkflowDemoSeeder;
use Database\Seeders\Support\WorkflowDemoAudit;
use Database\Seeders\Support\WorkflowDemoCatalog as Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreshWorkflowDemoSeederTest extends TestCase
{
    use \Tests\Concerns\ReviewsProviderDocuments;
    use RefreshDatabase;

    public function test_the_fresh_demo_covers_roles_and_leaves_each_current_decision_to_the_tester(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $this->assertSame(Catalog::ROOT, User::findOrFail(1)->username);
        $this->assertSame(12, Scholarship::count());
        $this->assertFalse(Scholarship::whereColumn('handoff_deadline', '<', 'deadline')->exists());
        $this->assertSame(3, User::where('role', 'provider')->whereNull('parent_account_id')->count());
        $this->assertSame(count(Catalog::applicants()), User::where('role', 'applicant')->count());
        $this->assertFalse(User::whereIn('username', ['marilag', 'silayan', 'dalisay', 'superadmin', 'learner.apply', 'platform.reviews'])->exists());
        $this->assertTrue(Hash::check('password123', User::where('username', Catalog::ADMIN.'.reviews')->sole()->password));
        $this->assertFalse(ScholarshipApplication::whereIn('final_outcome', ['not_selected', 'waitlisted'])->exists());
        $this->assertFalse(ScholarshipApplication::whereIn('status', ['rejected', 'exam_failed', 'interview_failed', 'withdrawn'])->exists());

        foreach (Catalog::applicants() as $record) {
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
        $this->assertSame(12, Scholarship::distinct()->count('image_path'));
        $evidence = $this->application('exam.result')->scholarship->stageEvidence()->firstOrFail();
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($evidence->path));
        $this->assertSame(User::count(), WorkflowDemoAudit::inspect()['users']);
    }

    public function test_open_intakes_have_no_selection_sessions_and_stage_examples_use_closed_rounds(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(1, 0));
        $this->seed(FreshWorkflowDemoSeeder::class);
        $intake = Scholarship::acceptingApplications()->get();
        $this->assertCount(2, $intake);
        $this->assertFalse(ApplicationStageSession::whereIn('scholarship_id', $intake->modelKeys())->exists());
        $this->assertFalse(ScholarshipApplication::whereIn('scholarship_id', $intake->modelKeys())->exists());

        $stagedPrograms = collect(['screening', 'exam.confirm', 'interview.confirm', 'decision', 'release.record'])
            ->map(fn ($scenario) => $this->application($scenario)->scholarship_id);
        $this->assertCount(5, $stagedPrograms->unique());
        foreach (ApplicationStageSession::with('scholarship')->get() as $session) {
            $this->assertTrue($session->starts_at->gt($session->scholarship->deadline->endOfDay()));
            $this->assertFalse($session->scholarship->isAcceptingApplications());
        }
        foreach (ScholarshipApplication::with('scholarship')->get() as $application) {
            $this->assertTrue($application->submitted_at->betweenIncluded(
                $application->scholarship->application_opens_at->startOfDay(),
                $application->scholarship->deadline->endOfDay(),
            ));
        }
        $this->assertTrue($this->application('exam.confirm')->stageAssignments()->firstOrFail()->session->starts_at->isFuture());
        $this->assertTrue($this->application('interview.result')->stageAssignments()->whereHas('session', fn ($query) => $query->where('stage_key', 'interview'))->sole()->session->ends_at->isPast());
    }

    public function test_the_ready_records_can_really_proceed_through_the_application_monitoring_and_release_apis(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $actor = fn ($username) => User::where('username', $username)->sole();
        $program = Scholarship::where('title', 'Nuvareya Campus Pathways Grant')->sole();
        $this->actingAs($actor(Catalog::APPLICANT.'.apply'))->postJson('/dashboard/applications', [
            'scholarship_id' => $program->id, 'document_checklist' => preg_split('/\n/', $program->requirements),
            'application_answers' => [['question_id' => 'education_plan', 'answer' => 'Continue school and prepare for college.']],
            'terms_accepted' => true,
        ])->assertCreated();
        foreach (['formal' => 'formal_application', 'exam.result' => 'exam', 'interview.result' => 'interview'] as $scenario => $stage) {
            $application = $this->application($scenario);
            $evaluation = $stage === 'formal_application' ? [] : ['evaluation' => [
                'score' => 86, 'maximum_score' => 100,
                'expected_stage_revision' => $application->stageProgresses()->where('stage_key', $stage)->value('evaluation_revision'),
                'expected_revision' => (int) $application->stageEvaluations()->where('stage_key', $stage)->max('revision'),
            ]];
            $this->actingAs($actor(Catalog::PRIMARY.'.selection'))
                ->patchJson("/provider/applications/{$application->id}/stages/{$stage}/result", ['result' => 'passed', 'notes' => 'Tester-recorded decision in the isolated test database.', ...$evaluation])
                ->assertOk();
        }
        $application = $this->application('decision');
        $reference = $this->actingAs($actor(Catalog::PRIMARY.'.decisions'))
            ->getJson("/provider/applications/{$application->id}/data?workspace=decision")
            ->assertOk()->json('application.selection_comparison.reference');
        $this->actingAs($actor(Catalog::PRIMARY.'.decisions'))->patchJson("/provider/applications/{$application->id}/final-outcome", [
            'outcome' => 'selected', 'notes' => 'Tester chooses the final outcome.',
            'comparison_reference' => $reference,
        ])->assertOk();
        $application = $this->application('exam.confirm');
        $assignment = $application->stageAssignments()->sole();
        $this->actingAs($application->applicant)->patchJson(route('dashboard.applications.stage-activities.acknowledge', [$application, $assignment]))->assertOk();

        $application = $this->application('monitoring.review');
        $case = RecipientMonitoringCase::where('scholarship_application_id', $application->id)->sole();
        $this->assertSame('attended', $case->attendance_status);
        $requirement = $case->cycle->requirements()->where('type', 'academic_progress')->sole();
        $this->actingAs($actor(Catalog::PRIMARY.'.monitoring'))->postJson(route('provider.monitoring-requirements.record', [$requirement, $application]), [
            'decision' => 'met', 'verified_grade' => 93, 'document_checked' => true,
        ])->assertCreated();
        $application = $this->application('monitoring.close');
        $case = RecipientMonitoringCase::where('scholarship_application_id', $application->id)->sole();
        $this->actingAs($actor(Catalog::PRIMARY.'.monitoring'))->patchJson("/provider/monitoring-cases/{$case->id}/close", [
            'outcome' => 'completed', 'reason' => 'All required records are verified.',
        ])->assertOk();
        $application = $this->application('onetime.record');
        $record = $application->benefitReleaseRecords()->sole();
        $this->actingAs($actor(Catalog::PRIMARY.'.releases'))->post("/provider/benefit-release-records/{$record->id}/result", [
            'status' => 'released', 'originals_verified' => true,
            'receipt_proof' => UploadedFile::fake()->create('demo-distribution.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $application = $this->application('onetime.confirm');
        $record = $application->benefitReleaseRecords()->sole();
        $this->actingAs($application->applicant)->postJson("/dashboard/benefit-release-records/{$record->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
        ])->assertCreated();

        $application = $this->application('payout.details');
        $record = $application->benefitReleaseRecords()->sole();
        $this->actingAs($application->applicant)->postJson(route('dashboard.benefit-release-records.payout-details', $record), [
            'method' => 'bank_transfer', 'institution' => 'Example Bank (demo)', 'account_holder' => $application->applicant->name,
            'account_number' => '0000000000', 'confirmed' => true,
        ])->assertOk();
        $releases = $actor(Catalog::PRIMARY.'.releases');
        $record = $this->application('payout.review')->benefitReleaseRecords()->sole();
        $this->actingAs($releases)->patchJson(route('provider.benefit-release-records.payout-details.review', $record), [
            'decision' => 'approve', 'expected_revision' => 1, 'confirmed' => true,
        ])->assertOk();
        $record = $this->application('payout.transfer')->benefitReleaseRecords()->sole();
        $this->postJson(route('provider.benefit-release-records.result', $record), [
            'status' => 'released', 'expected_payout_revision' => 1, 'transfer_sent_on' => now()->toDateString(),
            'transfer_confirmed' => true, 'receipt_proof' => UploadedFile::fake()->create('simulated-transfer.pdf', 10, 'application/pdf'),
        ])->assertOk();

        $reviewer = $actor(Catalog::ADMIN.'.reviews');
        $pendingProvider = $actor(Catalog::PENDING);
        $pendingApplicant = $actor(Catalog::APPLICANT.'.verification');
        $token = $this->acceptProviderDocuments($reviewer, $pendingProvider);
        $this->actingAs($reviewer)->patchJson('/admin/providers/'.$pendingProvider->id.'/verification', [
            'verification_status' => 'approved',
            'expected_review_token' => $token,
        ])->assertOk();
        foreach ($pendingApplicant->applicantVerificationDocuments()->get() as $evidence) {
            $this->actingAs($reviewer)
                ->patchJson("/admin/applicant-verification-documents/{$evidence->id}/review", [
                    'status' => 'approved', 'expected_revision' => $evidence->revision,
                    ...($evidence->document_type === 'academic_record' ? ['academic_grading_scale' => 'percentage', 'academic_result' => 92] : []),
                ])->assertOk();
        }
        $this->actingAs($reviewer)->patchJson('/admin/users/'.$pendingApplicant->id.'/profile-verification', [
            'expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($pendingApplicant->id),
            'verification_status' => 'approved',
        ])->assertOk();
        $pending = Scholarship::where('status', 'pending_review')->sole();
        $this->actingAs($reviewer)->patchJson("/admin/scholarships/{$pending->id}/review", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::program($pending->id), 'status' => 'published'])->assertOk();
    }

    public function test_each_specialist_workspace_can_load_the_fresh_demo(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        foreach (['reviews', 'selection', 'decisions', 'recipients', 'monitoring', 'releases', 'organization-profile', 'team', 'support', 'billing'] as $workspace) {
            $suffix = ['reviews' => 'reviewer', 'organization-profile' => 'profile'][$workspace] ?? $workspace;
            $this->actingAs(User::where('username', Catalog::PRIMARY.'.'.$suffix)->sole())
                ->getJson("/provider/workspaces/{$workspace}/dashboard/data")->assertOk();
        }
        foreach (['accounts', 'reviews', 'support', 'billing', 'finance', 'records'] as $workspace) {
            $this->actingAs(User::where('username', Catalog::ADMIN.'.'.$workspace)->sole())
                ->getJson("/admin/workspaces/{$workspace}/dashboard/data")->assertOk();
        }
        $this->actingAs(User::where('username', Catalog::ADMIN.'.manager')->sole())->getJson('/admin/workspaces/portal/data')->assertOk();
        $this->actingAs(User::where('username', Catalog::PRIMARY.'.programs')->sole())->getJson('/provider/workspaces/programs/data')->assertOk();
        foreach (Catalog::applicants() as $record) {
            $this->actingAs(User::where('username', $record['username'])->sole())->getJson('/dashboard/overview-data')->assertOk();
        }
    }

    public function test_rehearsal_can_advance_screening_sessions_agreement_and_first_release(): void
    {
        Storage::fake('local');
        $this->seed(FreshWorkflowDemoSeeder::class);
        $staff = fn (string $suffix) => User::where('username', Catalog::PRIMARY.'.'.$suffix)->sole();

        $screening = $this->application('screening');
        $partner = User::where('username', Catalog::PARTNER.'.reviewer')->sole();
        $this->actingAs($partner)->getJson(route('provider.applications.show.data', $screening).'?workspace=review')->assertForbidden();
        $this->actingAs($staff('reviewer'));
        foreach ($screening->documents as $document) {
            $this->get(route('documents.view', $document))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->patchJson(route('provider.documents.status', $document), ['status' => 'accepted'])->assertOk();
        }
        $this->patchJson(route('provider.applications.stages.result', [$screening, 'screening']), [
            'result' => 'passed',
            'rubric_scores' => collect(ReviewRubric::DEFAULT)->mapWithKeys(fn (array $criterion) => [$criterion['key'] => 92])->all(),
        ])->assertOk();
        $this->assertSame('formal_application', $screening->fresh()->workflow_stage);

        $this->actingAs($staff('selection'));
        foreach (['exam' => 10, 'interview' => 11] as $stage => $day) {
            $application = $this->application($stage.'.assign');
            $start = now()->addDays($day)->setTime(9, 0);
            $sessionId = $this->postJson(route('provider.stage-sessions.store', $application->scholarship), [
                'stage_key' => $stage, 'title' => 'Rehearsal '.$stage, 'timezone' => 'Asia/Manila', 'mode' => 'online',
                'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(),
                'meeting_url' => 'https://example.test/rehearsal-'.$stage, 'proof_required' => true,
            ])->assertCreated()->json('session.id');
            $routeParameters = [$application->scholarship, $sessionId];
            $this->postJson(route('provider.stage-sessions.assignments.store', $routeParameters), [
                'application_ids' => [$application->id],
            ])->assertCreated();
            $this->postJson(route('provider.stage-sessions.publish', $routeParameters))->assertOk()->assertJsonPath('session.status', 'scheduled');
            $this->assertSame($stage, $application->fresh()->workflow_stage);
        }
        $conducted = $this->application('exam.complete');
        $assignment = $conducted->stageAssignments()->sole();
        $this->postJson(route('provider.stage-sessions.complete', [$conducted->scholarship, $assignment->session]), [
            'confirm_attendance' => true,
        ])->assertOk()->assertJsonPath('session.status', 'completed');

        $agreement = $this->application('agreement');
        $this->actingAs($agreement->applicant)->patchJson(route('dashboard.applications.response', $agreement), [
            'response' => 'accepted', 'terms_accepted' => true,
        ])->assertOk();
        $this->assertSame('accepted', $agreement->fresh()->student_response_status);

        foreach (['release.plan', 'onetime.plan'] as $scenario) {
            $recipient = $this->application($scenario);
            $benefit = $recipient->scholarship->benefits()->where('type', 'allowance')->sole();
            $this->actingAs($staff('releases'))->postJson(route('provider.scholarships.benefit-releases.store', $recipient->scholarship), [
                'title' => 'First distribution rehearsal', 'benefit_description' => $benefit->description,
                'benefit_ids' => [$benefit->id], 'recipient_ids' => [$recipient->id],
                'release_method' => 'in_person', 'release_at' => now()->addDay()->setTime(14, 0)->toDateTimeString(),
                'location' => 'Demo learning center', 'requires_original_verification' => false,
            ])->assertCreated()->assertJsonPath('release.records.0.status', 'scheduled');
        }
    }

    private function application(string $scenario): ScholarshipApplication
    {
        return ScholarshipApplication::where('applicant_id', User::where('username', Catalog::APPLICANT.'.'.$scenario)->sole()->id)->sole();
    }
}
