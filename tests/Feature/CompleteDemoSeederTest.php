<?php

namespace Tests\Feature;

use App\Models\ApplicationStageSession;
use App\Models\ProviderServiceLifecycleEvent;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use Database\Seeders\CompleteDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_positive_stage_by_stage_demo_from_an_empty_database(): void
    {
        Storage::fake('local');

        $this->seed(CompleteDemoSeeder::class);

        $this->assertSame(1, User::query()->where('role', 'admin')->whereNull('parent_account_id')->count());
        $this->assertSame(7, User::query()->where('role', 'admin')->whereNotNull('parent_account_id')->count());
        $this->assertSame(2, User::query()->where('role', 'provider')->whereNull('parent_account_id')->count());
        $this->assertSame(24, User::query()->where('role', 'provider')->whereNotNull('parent_account_id')->count());
        $this->assertSame(10, User::query()->where('role', 'applicant')->count());
        $this->assertSame('superadmin@findscholarship.test', User::query()->findOrFail(1)->email);
        $this->assertSame('owner@aralbridge.test', User::query()->findOrFail(2)->email);
        $this->assertSame('aralbridge', User::query()->findOrFail(2)->username);
        $this->assertDatabaseMissing('users', ['username' => 'harborlight']);
        $this->assertDatabaseMissing('users', ['username' => 'sulongdunong']);
        $this->assertDatabaseMissing('users', ['username' => 'provider.pending']);
        $this->assertDatabaseMissing('users', ['username' => 'provider.revision']);

        $this->assertSame(5, Scholarship::query()->count());
        $this->assertSame(4, Scholarship::query()->where('status', 'published')->count());
        $this->assertSame(1, Scholarship::query()->where('status', 'closed')->count());
        $this->assertSame(0, Scholarship::query()->whereIn('status', ['draft', 'pending_review', 'rejected'])->count());
        $this->assertSame(5, Scholarship::query()->distinct()->count('image_path'));
        $this->assertDatabaseHas('provider_profiles', ['provider_name' => 'FutureWeave Education Trust']);
        $this->assertDatabaseHas('scholarships', ['title' => 'FutureWeave STEM Discovery Grant']);
        $this->assertDatabaseHas('scholarships', ['title' => 'FutureWeave School Essentials Award']);

        $aralBridgePrimary = Scholarship::query()->where('title', 'AralBridge Scholars Pathway')->firstOrFail();
        $aralBridgeSecondary = Scholarship::query()->where('title', 'AralBridge College Start Grant')->firstOrFail();
        $futureWeavePrimary = Scholarship::query()->where('title', 'FutureWeave STEM Discovery Grant')->firstOrFail();
        $futureWeaveSecondary = Scholarship::query()->where('title', 'FutureWeave School Essentials Award')->firstOrFail();

        $this->assertNull(User::query()->where('username', 'aralbridge.manager')->firstOrFail()->assigned_program_ids);
        $this->assertSame(
            [$aralBridgeSecondary->id],
            User::query()->where('username', 'aralbridge.programs')->firstOrFail()->assignedProviderProgramIds(),
        );
        $this->assertSame(
            [$aralBridgePrimary->id],
            User::query()->where('username', 'aralbridge.reviewer')->firstOrFail()->assignedProviderProgramIds(),
        );
        $this->assertSame(
            [$aralBridgePrimary->id],
            User::query()->where('username', 'aralbridge.team')->firstOrFail()->assignedProviderProgramIds(),
        );
        $this->assertSame(
            [$futureWeaveSecondary->id],
            User::query()->where('username', 'futureweave.programs')->firstOrFail()->assignedProviderProgramIds(),
        );
        $this->assertSame(
            [$futureWeavePrimary->id],
            User::query()->where('username', 'futureweave.reviewer')->firstOrFail()->assignedProviderProgramIds(),
        );
        $this->assertFalse(
            User::query()->where('username', 'aralbridge.reviewer')->firstOrFail()
                ->canAccessProviderProgram($aralBridgeSecondary),
        );

        $successfulApplicant = User::query()
            ->where('username', 'theogabrielalonzo')
            ->firstOrFail();
        $successfulApplication = ScholarshipApplication::query()
            ->where('applicant_id', $successfulApplicant->id)
            ->firstOrFail();
        $screeningApplicant = User::query()->where('username', 'miracelesteandrada')->firstOrFail();
        $activeMonitoringApplicant = User::query()->where('username', 'nayaelisemanalo')->firstOrFail();
        $activeMonitoringApplication = ScholarshipApplication::query()
            ->where('applicant_id', $activeMonitoringApplicant->id)
            ->firstOrFail();

        $this->assertSame(10, ScholarshipApplication::query()->count());
        $this->assertSame('awarded', $successfulApplication->status);
        $this->assertSame('complete', $successfulApplication->workflow_stage);
        $this->assertSame('closed', $successfulApplication->application_state);
        $this->assertSame('selected', $successfulApplication->final_outcome);
        $this->assertSame('accepted', $successfulApplication->student_response_status);
        $this->assertSame(0, ScholarshipApplication::query()->whereIn('status', [
            'rejected',
            'exam_failed',
            'interview_failed',
            'not_awarded',
            'withdrawn',
            'benefits_terminated',
        ])->count());
        $this->assertSame(0, ScholarshipApplication::query()->whereIn('application_state', [
            'needs_correction',
            'withdrawn',
        ])->count());

        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => $screeningApplicant->id,
            'status' => 'submitted',
            'workflow_stage' => 'screening',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'noeladrianpangan')->value('id'),
            'workflow_stage' => 'formal_application',
            'application_state' => 'in_provider_process',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'talabeatrizsoriano')->value('id'),
            'workflow_stage' => 'exam',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'ivomiguellacuesta')->value('id'),
            'workflow_stage' => 'exam',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'enzorafaelmarasigan')->value('id'),
            'workflow_stage' => 'interview',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'amihanclairerobles')->value('id'),
            'workflow_stage' => 'interview',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'liramaesarmiento')->value('id'),
            'workflow_stage' => 'decision',
            'application_state' => 'awaiting_decision',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'applicant_id' => User::query()->where('username', 'kianpaulovillareal')->value('id'),
            'final_outcome' => 'selected',
            'student_response_status' => null,
        ]);

        $this->assertSame(4, ApplicationStageSession::query()->count());
        $this->assertSame(2, ApplicationStageSession::query()->where('status', 'scheduled')->count());
        $this->assertSame(2, ApplicationStageSession::query()->where('status', 'completed')->count());
        $this->assertSame(5, $successfulApplication->stageProgresses()->where('status', 'passed')->count());
        $this->assertSame(0, $successfulApplication->stageProgresses()->whereIn('status', [
            'pending',
            'current',
            'not_passed',
        ])->count());

        $this->assertSame(1, RecipientMonitoringPlan::query()->where('status', 'active')->count());
        $this->assertSame(1, RecipientMonitoringCycle::query()->where('status', 'open')->count());
        $this->assertSame(0, RecipientMonitoringCycle::query()->where('status', '!=', 'open')->count());
        $this->assertSame(1, RecipientMonitoringCase::query()->where('status', 'closed')->count());
        $this->assertSame(1, RecipientMonitoringCase::query()->where('status', 'under_review')->count());
        $this->assertDatabaseHas('recipient_monitoring_cases', [
            'scholarship_application_id' => $activeMonitoringApplication->id,
            'status' => 'under_review',
            'due_status' => 'open',
            'next_action_role' => 'provider',
            'next_action' => 'review_requirements',
        ]);
        $this->assertDatabaseHas('recipient_monitoring_cases', [
            'scholarship_application_id' => $successfulApplication->id,
            'status' => 'closed',
            'outcome' => 'completed',
            'next_action_role' => null,
            'next_action' => null,
        ]);
        $this->assertSame(3, $successfulApplication->monitoringSubmissions()->count());
        $this->assertSame(0, $successfulApplication->monitoringSubmissions()
            ->whereIn('review_status', ['pending', 'needs_correction', 'not_met'])
            ->count());
        $this->assertDatabaseCount('recipient_monitoring_adjustment_requests', 0);
        $this->assertDatabaseCount('recipient_monitoring_interventions', 0);

        $this->assertSame(2, RecipientBenefitRelease::query()->where('status', 'completed')->count());
        $this->assertSame(1, RecipientBenefitRelease::query()->where('status', 'scheduled')->count());
        $this->assertDatabaseCount('recipient_benefit_release_records', 4);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 3);
        $this->assertSame(2, $successfulApplication->benefitReleaseRecords()->where('status', 'released')->count());
        $this->assertSame(1, $activeMonitoringApplication->benefitReleaseRecords()->where('status', 'scheduled')->count());
        $this->assertSame(3, RecipientBenefitEntitlement::query()
            ->where('scholarship_application_id', $successfulApplication->id)
            ->where('status', 'fulfilled')
            ->count());
        $this->assertDatabaseHas('recipient_support_decisions', [
            'scholarship_application_id' => $successfulApplication->id,
            'decision' => 'completed',
        ]);

        $this->assertSame(1, SupportReport::query()->where('status', 'resolved')->count());
        $this->assertSame(0, SupportReport::query()->where('status', '!=', 'resolved')->count());
        $this->assertDatabaseMissing('provider_profiles', ['verification_status' => 'pending']);
        $this->assertDatabaseMissing('provider_profiles', ['verification_status' => 'rejected']);
        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => 2,
            'verification_status' => 'approved',
        ]);
        $this->assertSame(
            10,
            User::query()
                ->where('role', 'applicant')
                ->whereHas('studentProfile', fn ($query) => $query
                    ->where('profile_photo_original_name', 'default-applicant-avatar.jpg'))
                ->count(),
        );

        $completedService = ProviderServicePurchase::query()->firstOrFail();
        $this->assertSame(1, ProviderServicePurchase::query()->count());
        $this->assertSame('paid', $completedService->status);
        $this->assertSame('completed', $completedService->fulfillment_status);
        $this->assertSame(0, ProviderServiceRequest::query()->count());
        $this->assertSame(2, ProviderServiceLifecycleEvent::query()->count());

        Storage::disk('local')->assertExists(
            "demo-showcase/profile-photos/{$successfulApplicant->id}/default-applicant-avatar.jpg",
        );
        Storage::disk('local')->assertExists(
            "demo-showcase/application-documents/{$successfulApplication->id}/certificate-of-enrollment.png",
        );
        Storage::disk('local')->assertExists(
            "demo-showcase/profile-photos/{$screeningApplicant->id}/default-applicant-avatar.jpg",
        );
        $this->assertSame(
            hash_file('sha256', public_path('images/demo/default-applicant-avatar.jpg')),
            hash('sha256', Storage::disk('local')->get(
                "demo-showcase/profile-photos/{$screeningApplicant->id}/default-applicant-avatar.jpg",
            )),
            'The demo seeder must use the neutral, non-person applicant avatar.',
        );

        $this->actingAs(User::query()->findOrFail(1))
            ->get(route('admin.index'))
            ->assertOk();

        $this->actingAs(User::query()->findOrFail(2))
            ->get(route('provider.index'))
            ->assertOk();
        $this->get(route('provider.applications.data'))
            ->assertOk();

        $partnerProvider = User::query()->where('username', 'futureweave')->firstOrFail();
        $this->actingAs($partnerProvider)
            ->get(route('provider.index'))
            ->assertOk();

        $providerBilling = User::query()->where('username', 'aralbridge.billing')->firstOrFail();
        $this->actingAs($providerBilling)
            ->get('/provider/workspaces/billing')
            ->assertOk();
        $this->getJson('/provider/workspaces/billing/data?queue=completed')
            ->assertOk()
            ->assertJsonPath('summary.requests', 0)
            ->assertJsonPath('summary.needs_action', 0)
            ->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.waiting', 0)
            ->assertJsonPath('summary.completed', 1);

        $adminBilling = User::query()->where('username', 'admin.billing')->firstOrFail();
        $this->actingAs($adminBilling)
            ->get('/admin/workspaces/billing')
            ->assertOk();
        $this->getJson('/admin/workspaces/billing/summary')
            ->assertOk()
            ->assertJsonPath('summary.ready', 0)
            ->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.waiting', 0)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.meeting_requests', 0);

        $this->actingAs($successfulApplicant)
            ->get(route('dashboard.monitoring.show', $successfulApplication))
            ->assertOk();
        $this->get(route('dashboard.applications.show', $successfulApplication))
            ->assertOk();
    }
}
