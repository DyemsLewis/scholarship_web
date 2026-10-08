<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Database\Seeders\CompleteDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_complete_workflow_and_exception_demo_from_an_empty_database(): void
    {
        Storage::fake('local');

        $this->seed(CompleteDemoSeeder::class);

        $this->assertSame(1, User::query()->where('role', 'admin')->whereNull('parent_account_id')->count());
        $this->assertSame(7, User::query()->where('role', 'admin')->whereNotNull('parent_account_id')->count());
        $this->assertSame(3, User::query()->where('role', 'provider')->whereNull('parent_account_id')->count());
        $this->assertSame(12, User::query()->where('role', 'provider')->whereNotNull('parent_account_id')->count());
        $this->assertSame(24, User::query()->where('role', 'applicant')->count());
        $this->assertSame('admin@findscholarship.test', User::query()->findOrFail(1)->email);
        $this->assertSame('programs@tulayaral.test', User::query()->findOrFail(2)->email);

        $this->assertSame(6, Scholarship::query()->count());
        $this->assertSame(24, ScholarshipApplication::query()->count());
        foreach (['draft', 'pending_review', 'closed', 'rejected'] as $status) {
            $this->assertDatabaseHas('scholarships', ['status' => $status]);
        }
        $this->assertSame(2, Scholarship::query()->where('status', 'published')->count());
        $this->assertDatabaseHas('scholarships', [
            'title' => 'Tulay Aral One-Time College Readiness Award',
            'slots_available' => 1,
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'workflow_stage' => 'screening',
            'application_state' => 'needs_correction',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'workflow_stage' => 'exam',
            'application_state' => 'in_provider_process',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'workflow_stage' => 'interview',
            'application_state' => 'in_provider_process',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'workflow_stage' => 'decision',
            'application_state' => 'awaiting_decision',
        ]);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'rejected']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'exam_failed']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'interview_failed']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'not_awarded']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'withdrawn']);
        $this->assertDatabaseHas('scholarship_applications', ['student_response_status' => 'declined']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'benefits_terminated']);
        $this->assertDatabaseHas('scholarship_applications', ['status' => 'renewed']);
        $this->assertSame(1, ScholarshipApplication::query()->where('final_outcome', 'waitlisted')->count());
        $this->assertSame(10, ScholarshipApplication::query()->where('final_outcome', 'selected')->count());
        $this->assertSame(7, ScholarshipApplication::query()->where('student_response_status', 'accepted')->count());

        $this->assertSame(1, RecipientMonitoringPlan::query()->where('status', 'active')->count());
        $this->assertSame(2, RecipientMonitoringCycle::query()->where('status', 'open')->count());
        $this->assertSame(3, RecipientBenefitRelease::query()->count());
        $this->assertDatabaseHas('recipient_monitoring_submissions', ['review_status' => 'pending']);
        $this->assertDatabaseHas('recipient_monitoring_submissions', ['review_status' => 'needs_correction']);
        $this->assertDatabaseHas('recipient_monitoring_submissions', ['review_status' => 'not_met']);
        $this->assertDatabaseHas('recipient_monitoring_submissions', ['review_status' => 'excused']);
        $this->assertDatabaseHas('recipient_monitoring_adjustment_requests', [
            'request_type' => 'extension',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('recipient_monitoring_adjustment_requests', [
            'request_type' => 'exception',
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('recipient_monitoring_interventions', ['status' => 'open']);
        $this->assertDatabaseHas('recipient_benefit_release_records', ['status' => 'withheld']);
        $this->assertDatabaseHas('recipient_benefit_receipt_responses', [
            'response_type' => 'issue',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('recipient_support_decisions', ['decision' => 'completed']);
        $this->assertDatabaseHas('recipient_support_decisions', ['decision' => 'renewed']);
        $this->assertDatabaseHas('recipient_support_decisions', [
            'decision' => 'terminated',
            'response_status' => 'open',
        ]);
        $this->assertDatabaseHas('support_reports', ['status' => 'open']);
        $this->assertDatabaseHas('support_reports', ['status' => 'resolved']);
        $this->assertDatabaseHas('provider_profiles', ['verification_status' => 'pending']);
        $this->assertDatabaseHas('provider_profiles', ['verification_status' => 'rejected']);
        $this->assertSame(2, $this->app['db']->table('provider_service_purchases')->count());
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => 1,
            'assigned_reviewer_id' => User::query()->where('username', 'tulay.reviewer')->value('id'),
        ]);

        Storage::disk('local')->assertExists('demo-showcase/profile-photos/3/applicant-01.jpg');

        $this->actingAs(User::query()->findOrFail(1))
            ->get(route('admin.index'))
            ->assertOk();

        $this->actingAs(User::query()->findOrFail(2))
            ->get(route('provider.index'))
            ->assertOk();
        $this->get(route('provider.applications.data'))
            ->assertOk();

        $monitoringApplication = ScholarshipApplication::query()
            ->where('student_response_status', 'accepted')
            ->whereHas('monitoringSubmissions', fn ($query) => $query->where('review_status', 'pending'))
            ->firstOrFail();
        $this->actingAs($monitoringApplication->applicant)
            ->get(route('dashboard.monitoring.show', $monitoringApplication))
            ->assertOk();
        $this->get(route('dashboard.applications.show', $monitoringApplication))
            ->assertOk();
    }
}
