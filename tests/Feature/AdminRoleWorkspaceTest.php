<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\RecipientMonitoringCycle;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\RecipientMonitoringCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_manager_lands_in_a_dedicated_workspace_instead_of_super_admin_pages(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $accountManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($accountManager)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/accounts');

        $this->actingAs($accountManager)
            ->get('/admin/manage-users')
            ->assertRedirect('/admin/workspaces/accounts/directory');

        $this->actingAs($accountManager)
            ->get('/admin/accounts/create')
            ->assertRedirect('/admin/workspaces/accounts/create');

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/accounts')
            ->assertOk()
            ->assertViewIs('admin-account-manager-dashboard');

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/accounts/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'account-manager')
            ->assertJsonPath('metrics.2.value', 1)
            ->assertJsonCount(4, 'metrics');

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/accounts/directory')
            ->assertOk()
            ->assertViewIs('admin-account-directory');

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/accounts/attention')
            ->assertOk()
            ->assertViewIs('admin-account-attention');

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/accounts/create')
            ->assertOk()
            ->assertViewIs('admin-account-create');

        $this->actingAs($accountManager)
            ->get("/admin/workspaces/accounts/{$applicant->id}")
            ->assertOk()
            ->assertViewIs('admin-account-record');

        $this->actingAs($accountManager)
            ->getJson("/admin/workspaces/accounts/{$applicant->id}/data")
            ->assertOk()
            ->assertJsonPath('user.id', $applicant->id)
            ->assertJsonPath('user.role', 'applicant');

        $this->actingAs($accountManager)
            ->get("/admin/workspaces/accounts/{$primaryAdmin->id}")
            ->assertForbidden();

        $this->actingAs($primaryAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertViewIs('admin-super-admin-workspace');

        $this->actingAs($primaryAdmin)
            ->get('/admin/manage-users')
            ->assertOk()
            ->assertViewIs('admin-users');
    }

    public function test_primary_admin_uses_a_governance_workspace_instead_of_specialist_queues(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $accountManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
            'account_status' => 'active',
        ]);

        $this->actingAs($primaryAdmin)
            ->getJson('/admin/dashboard/data')
            ->assertOk()
            ->assertJsonPath('summary.managed_admins', 1)
            ->assertJsonPath('summary.active_admins', 1)
            ->assertJsonPath('summary.covered_roles', 1)
            ->assertJsonCount(7, 'role_coverage')
            ->assertJsonStructure(['operational_areas', 'escalations']);

        $this->actingAs($primaryAdmin)
            ->get('/admin/governance/team')
            ->assertOk()
            ->assertViewIs('admin-super-admin-area');

        $this->actingAs($primaryAdmin)
            ->getJson('/admin/governance/team/data')
            ->assertOk()
            ->assertJsonPath('summary.team_members', 1)
            ->assertJsonPath('members.0.id', $accountManager->id)
            ->assertJsonPath('members.0.title', 'Account manager')
            ->assertJsonPath('members.0.edit_url', "/admin/accounts/{$accountManager->id}/edit");

        $this->actingAs($primaryAdmin)
            ->get('/admin/governance/platform')
            ->assertOk()
            ->assertViewIs('admin-super-admin-area');

        $this->actingAs($primaryAdmin)
            ->getJson('/admin/governance/platform/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'operational_areas', 'activity']);

        $this->actingAs($accountManager)
            ->get('/admin/governance/team')
            ->assertForbidden();

        $this->actingAs($accountManager)
            ->getJson('/admin/governance/platform/data')
            ->assertForbidden();
    }

    public function test_account_manager_workspace_data_is_task_focused_and_hides_admin_accounts(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $accountManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
        ]);
        $applicant = User::factory()->create([
            'role' => 'applicant',
            'email_verified_at' => null,
            'must_reset_password' => true,
        ]);
        User::factory()->create([
            'role' => 'provider',
            'account_status' => 'suspended',
        ]);

        $response = $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/accounts/data')
            ->assertOk()
            ->assertJsonPath('stats.total_users', 2)
            ->assertJsonPath('stats.admins', 0)
            ->assertJsonPath('stats.unverified_users', 1)
            ->assertJsonPath('stats.password_resets_required', 1)
            ->assertJsonPath('stats.suspended_users', 1)
            ->assertJsonCount(2, 'users');

        $this->assertNotContains('admin', collect($response->json('users'))->pluck('role')->all());

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/accounts/data?attention=password_reset')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $applicant->id);

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/accounts/data?attention=needs_attention')
            ->assertOk()
            ->assertJsonCount(2, 'users');
    }

    public function test_admin_without_account_permission_cannot_open_account_manager_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $reviewOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/accounts')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/accounts/directory')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/accounts/dashboard/data')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/accounts/data')
            ->assertForbidden();
    }

    public function test_review_officer_uses_a_dedicated_review_workspace_and_detail_routes(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $reviewOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::query()->create([
            'provider_id' => $provider->id,
            'title' => 'Review Officer Test Program',
            'description' => 'A program awaiting a focused publication review.',
            'status' => 'pending_review',
        ]);
        $application = ScholarshipApplication::query()->create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'disbursed',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $cycle = RecipientMonitoringCycle::query()->create([
            'scholarship_id' => $program->id,
            'created_by' => $provider->id,
            'title' => 'Review officer monitoring check-in',
            'period_type' => 'semester',
            'due_at' => now()->addWeek()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $case = app(RecipientMonitoringCaseService::class)->sync($cycle, $application);

        $this->actingAs($reviewOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/reviews');

        $this->actingAs($reviewOfficer)
            ->get('/admin/reviews?type=programs')
            ->assertRedirect('/admin/workspaces/reviews/programs');

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews')
            ->assertOk()
            ->assertViewIs('admin-review-overview');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/providers/data')
            ->assertOk()
            ->assertJsonPath('items.0.id', $provider->id);

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/summary')
            ->assertOk()
            ->assertJsonPath('summary.provider_pending', 1)
            ->assertJsonPath('summary.program_pending', 1);

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'review-officer')
            ->assertJsonPath('metrics.0.value', 2)
            ->assertJsonCount(4, 'metrics')
            ->assertJsonCount(2, 'sections');

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews/programs')
            ->assertOk()
            ->assertViewIs('admin-review-program-queue');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/programs/data')
            ->assertOk()
            ->assertJsonPath('items.0.id', $program->id);

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/programs/{$program->id}")
            ->assertOk()
            ->assertViewIs('admin-review-program-record');

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews/applicants')
            ->assertOk()
            ->assertViewIs('admin-review-applicant-queue');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/applicants/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.id', $applicant->id);

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/applicants/{$applicant->id}")
            ->assertOk()
            ->assertViewIs('admin-review-applicant-record');

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews/benefits')
            ->assertOk()
            ->assertViewIs('admin-review-benefit-queue');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/benefits/data')
            ->assertOk();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews/monitoring')
            ->assertOk()
            ->assertViewIs('admin-review-monitoring-queue');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/monitoring/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.application_id', $application->id)
            ->assertJsonPath('items.0.case_id', $case->id);

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/monitoring/{$application->id}")
            ->assertOk()
            ->assertViewIs('admin-review-monitoring-record');

        $this->actingAs($reviewOfficer)
            ->patchJson("/admin/providers/{$provider->id}/verification", [
                'verification_status' => 'rejected',
                'verification_notes' => 'Registration proof needs replacement.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.verification_status', 'rejected');

        $this->actingAs($reviewOfficer)
            ->patchJson("/admin/scholarships/{$program->id}/review", [
                'status' => 'rejected',
                'review_notes' => 'Applicant terms need clarification.',
            ])
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'rejected');

        $this->actingAs($reviewOfficer)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'rejected',
                'verification_notes' => 'A readable academic record is required.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $applicant->id,
            'verification_status' => 'rejected',
        ]);

        $this->actingAs($reviewOfficer)
            ->postJson("/admin/monitoring/{$application->id}/oversight-reviews", [
                'outcome' => 'reviewed',
            ])
            ->assertCreated()
            ->assertJsonPath('record.last_review.outcome', 'reviewed');

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/providers/{$provider->id}")
            ->assertOk()
            ->assertViewIs('admin-review-provider-record');

        $this->actingAs($reviewOfficer)
            ->get("/admin/providers/{$provider->id}/review?section=proof")
            ->assertRedirect("/admin/workspaces/reviews/providers/{$provider->id}?section=proof");

        $this->actingAs($primaryAdmin)
            ->get('/admin/reviews')
            ->assertOk()
            ->assertViewIs('admin-reviews');
    }

    public function test_admin_without_review_permission_cannot_open_review_officer_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $accountManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
        ]);

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/reviews')
            ->assertForbidden();

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/reviews/providers/data')
            ->assertForbidden();

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/reviews/dashboard/data')
            ->assertForbidden();
    }

    public function test_support_officer_uses_a_dedicated_workspace_with_a_focused_report_queue(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $supportOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Support officer',
            'permissions' => ['manage_reports'],
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $report = SupportReport::query()->create([
            'applicant_id' => $applicant->id,
            'assigned_role' => 'admin',
            'category' => 'privacy',
            'privacy_request_type' => 'correction',
            'subject' => 'Correct my account record',
            'description' => 'The saved account record contains information that needs correction.',
        ]);
        $waitingReport = SupportReport::query()->create([
            'applicant_id' => $applicant->id,
            'assigned_role' => 'provider',
            'category' => 'program',
            'subject' => 'Program schedule needs confirmation',
            'description' => 'The platform response is complete while the provider response remains open.',
            'status' => 'open',
            'provider_status' => 'open',
            'admin_status' => 'resolved',
            'admin_response' => 'Platform records were checked and no technical issue was found.',
        ]);
        $resolvedReport = SupportReport::query()->create([
            'applicant_id' => $applicant->id,
            'assigned_role' => 'admin',
            'category' => 'technical',
            'subject' => 'Resolved document concern',
            'description' => 'The document access concern has already been resolved.',
            'status' => 'resolved',
            'provider_status' => 'not_required',
            'admin_status' => 'resolved',
            'admin_response' => 'Document access was restored for the applicant.',
        ]);

        $this->actingAs($supportOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/support');

        $this->actingAs($supportOfficer)
            ->get('/admin/reports?status=resolved')
            ->assertRedirect('/admin/workspaces/support/resolved');

        $this->actingAs($supportOfficer)
            ->get('/admin/workspaces/support')
            ->assertOk()
            ->assertViewIs('admin-support-overview');

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/summary')
            ->assertOk()
            ->assertJsonPath('summary.needs_response', 1)
            ->assertJsonPath('summary.waiting', 1)
            ->assertJsonPath('summary.resolved', 1)
            ->assertJsonPath('summary.privacy_open', 1)
            ->assertJsonPath('summary.next_case.id', $report->id);

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'support-officer')
            ->assertJsonPath('sections.0.items.0.id', $report->id)
            ->assertJsonCount(4, 'metrics')
            ->assertJsonCount(2, 'sections');

        foreach (['needs-response', 'waiting', 'resolved'] as $queue) {
            $this->actingAs($supportOfficer)
                ->get("/admin/workspaces/support/{$queue}")
                ->assertOk()
                ->assertViewIs('admin-support-case-directory');
        }

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/data?queue=needs_response&category=privacy&search=account')
            ->assertOk()
            ->assertJsonPath('items.0.id', $report->id)
            ->assertJsonPath('items.0.state', 'needs_response')
            ->assertJsonCount(1, 'items');

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/data?queue=waiting')
            ->assertOk()
            ->assertJsonPath('items.0.id', $waitingReport->id)
            ->assertJsonPath('items.0.state', 'waiting');

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/data?queue=resolved')
            ->assertOk()
            ->assertJsonPath('items.0.id', $resolvedReport->id)
            ->assertJsonPath('items.0.state', 'resolved');

        $this->actingAs($supportOfficer)
            ->get("/admin/workspaces/support/cases/{$report->id}")
            ->assertOk()
            ->assertViewIs('admin-support-case-record');

        $this->actingAs($supportOfficer)
            ->getJson("/admin/workspaces/support/cases/{$report->id}/data")
            ->assertOk()
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.description', $report->description)
            ->assertJsonPath('report.admin_status', 'open');

        $this->actingAs($supportOfficer)
            ->patchJson("/admin/reports/{$report->id}/status", [
                'status' => 'resolved',
                'admin_response' => 'The requested account correction has been reviewed and completed.',
            ])
            ->assertOk()
            ->assertJsonPath('report.admin_response', 'The requested account correction has been reviewed and completed.');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'resolved',
            'admin_status' => 'resolved',
            'admin_response' => 'The requested account correction has been reviewed and completed.',
        ]);

        $this->actingAs($primaryAdmin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertViewIs('admin-reports');
    }

    public function test_admin_without_report_permission_cannot_open_support_officer_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $accountManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
        ]);

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/support')
            ->assertForbidden();

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/support/data')
            ->assertForbidden();

        $this->actingAs($accountManager)
            ->getJson('/admin/workspaces/support/dashboard/data')
            ->assertForbidden();

        $report = SupportReport::query()->create([
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'assigned_role' => 'admin',
            'category' => 'technical',
            'subject' => 'Restricted support case',
            'description' => 'Only support officers should be able to open this case.',
        ]);

        $this->actingAs($accountManager)
            ->get("/admin/workspaces/support/cases/{$report->id}")
            ->assertForbidden();
    }

    public function test_billing_officer_uses_a_dedicated_service_delivery_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $billingOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Billing officer',
            'permissions' => ['manage_billing'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted Setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'queued',
            'reference_number' => 'SVC-BILLING-001',
            'paid_at' => now(),
        ]);
        $activePurchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'application_cycle_support',
            'plan_name' => 'Application Cycle Support',
            'amount' => 250000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'in_progress',
            'reference_number' => 'SVC-BILLING-002',
            'paid_at' => now(),
        ]);
        $waitingPurchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'integration_consultation',
            'plan_name' => 'Integration Consultation',
            'amount' => 150000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'needs_information',
            'reference_number' => 'SVC-BILLING-003',
            'paid_at' => now(),
        ]);
        $completedPurchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Completed Setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'completed',
            'reference_number' => 'SVC-BILLING-004',
            'paid_at' => now()->subWeek(),
            'fulfilled_at' => now(),
        ]);

        $this->actingAs($billingOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/billing');

        $this->actingAs($billingOfficer)
            ->get('/admin/billing')
            ->assertRedirect('/admin/workspaces/billing');

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/billing')
            ->assertOk()
            ->assertViewIs('admin-billing-overview');

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/billing/summary')
            ->assertOk()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.waiting', 1)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.next_request.id', $purchase->id);

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/billing/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'billing-officer')
            ->assertJsonPath('sections.0.items.0.id', $purchase->id)
            ->assertJsonCount(4, 'metrics')
            ->assertJsonCount(2, 'sections');

        foreach (['ready', 'active', 'waiting', 'completed'] as $queue) {
            $this->actingAs($billingOfficer)
                ->get("/admin/workspaces/billing/queues/{$queue}")
                ->assertOk()
                ->assertViewIs('admin-billing-request-directory');
        }

        foreach ([
            'ready' => $purchase,
            'active' => $activePurchase,
            'waiting' => $waitingPurchase,
            'completed' => $completedPurchase,
        ] as $queue => $expectedPurchase) {
            $this->actingAs($billingOfficer)
                ->getJson("/admin/workspaces/billing/queue-data?queue={$queue}")
                ->assertOk()
                ->assertJsonPath('items.0.id', $expectedPurchase->id)
                ->assertJsonPath('items.0.state', $queue);
        }

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/billing/data?fulfillment_status=ready')
            ->assertOk()
            ->assertJsonPath('counts.ready', 1)
            ->assertJsonPath('purchases.0.id', $purchase->id);

        $this->actingAs($billingOfficer)
            ->get("/admin/workspaces/billing/requests/{$purchase->id}")
            ->assertOk()
            ->assertViewIs('admin-billing-request-record')
            ->assertSee('data-page="adminServiceOverview"', false);

        foreach ([
            'delivery' => 'adminServiceDelivery',
            'files' => 'adminServiceFiles',
            'updates' => 'adminServiceUpdates',
            'meeting' => 'adminServiceMeeting',
        ] as $section => $workspacePage) {
            $this->actingAs($billingOfficer)
                ->get("/admin/workspaces/billing/requests/{$purchase->id}/{$section}")
                ->assertOk()
                ->assertViewIs('admin-billing-request-record')
                ->assertSee('data-page="'.$workspacePage.'"', false);
        }

        $this->actingAs($billingOfficer)
            ->get("/admin/billing/{$purchase->id}")
            ->assertRedirect("/admin/workspaces/billing/requests/{$purchase->id}");

        $this->actingAs($primaryAdmin)
            ->get('/admin/billing')
            ->assertOk()
            ->assertViewIs('admin-billing');

        $this->actingAs($primaryAdmin)
            ->get("/admin/billing/{$purchase->id}")
            ->assertRedirect("/admin/workspaces/billing/requests/{$purchase->id}");
    }

    public function test_admin_without_billing_permission_cannot_open_billing_officer_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $supportOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Support officer',
            'permissions' => ['manage_reports'],
        ]);

        $this->actingAs($supportOfficer)
            ->get('/admin/workspaces/billing')
            ->assertForbidden();

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/billing/data')
            ->assertForbidden();

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/billing/dashboard/data')
            ->assertForbidden();

        $purchase = ProviderServicePurchase::query()->create([
            'provider_id' => User::factory()->create(['role' => 'provider'])->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Restricted service',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'ready',
            'reference_number' => 'SVC-RESTRICTED-001',
            'paid_at' => now(),
        ]);

        $this->actingAs($supportOfficer)
            ->get("/admin/workspaces/billing/requests/{$purchase->id}")
            ->assertForbidden();
    }

    public function test_finance_officer_has_focused_payment_workspaces(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $financeOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Finance officer',
            'permissions' => ['view_finance'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'application_cycle',
            'plan_name' => 'Application Cycle Support',
            'amount' => 125000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'in_progress',
            'reference_number' => 'SVC-FINANCE-001',
            'paid_at' => now(),
        ]);
        $pendingPurchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted Program Setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'pending',
            'fulfillment_status' => 'queued',
            'reference_number' => 'SVC-FINANCE-002',
        ]);
        $failedPurchase = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'application_cycle',
            'plan_name' => 'Application Cycle Support',
            'amount' => 125000,
            'currency' => 'PHP',
            'status' => 'failed',
            'fulfillment_status' => 'queued',
            'reference_number' => 'SVC-FINANCE-003',
            'failure_message' => 'Payment was declined.',
            'failed_at' => now(),
        ]);

        $this->actingAs($financeOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/finance');

        $this->actingAs($financeOfficer)
            ->get('/admin/finance')
            ->assertRedirect('/admin/workspaces/finance');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance')
            ->assertOk()
            ->assertViewIs('admin-finance-overview');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance/receipts')
            ->assertRedirect('/admin/workspaces/finance/payments');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance/payments')
            ->assertOk()
            ->assertViewIs('admin-finance-payments');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance/exceptions')
            ->assertOk()
            ->assertViewIs('admin-finance-exceptions');

        $this->actingAs($financeOfficer)
            ->getJson('/admin/workspaces/finance/summary')
            ->assertOk()
            ->assertJsonPath('summary.month', 125000)
            ->assertJsonPath('summary.successful_payments', 1)
            ->assertJsonPath('summary.pending_payments', 1)
            ->assertJsonPath('summary.failed_payments', 1)
            ->assertJsonPath('summary.exceptions', 2);

        $this->actingAs($financeOfficer)
            ->getJson('/admin/workspaces/finance/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'finance-officer')
            ->assertJsonPath('metrics.0.value', 2)
            ->assertJsonCount(4, 'metrics')
            ->assertJsonCount(2, 'sections');

        $this->actingAs($financeOfficer)
            ->getJson('/admin/workspaces/finance/payments/data?period=month')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.id', $purchase->id);

        $this->actingAs($financeOfficer)
            ->getJson('/admin/workspaces/finance/exceptions/data')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonFragment(['id' => $pendingPurchase->id, 'status' => 'pending'])
            ->assertJsonFragment(['id' => $failedPurchase->id, 'status' => 'failed']);

        $this->actingAs($financeOfficer)
            ->get("/admin/workspaces/finance/receipts/{$purchase->id}")
            ->assertOk()
            ->assertViewIs('admin-finance-receipt');

        $this->actingAs($financeOfficer)
            ->getJson("/admin/workspaces/finance/receipts/{$purchase->id}/data")
            ->assertOk()
            ->assertJsonPath('receipt.id', $purchase->id)
            ->assertJsonPath('receipt.receipt_number', 'SVC-FINANCE-001');

        $this->actingAs($financeOfficer)
            ->get("/admin/workspaces/finance/receipts/{$pendingPurchase->id}")
            ->assertNotFound();

        $this->actingAs($primaryAdmin)
            ->get('/admin/finance')
            ->assertOk()
            ->assertViewIs('admin-finance');
    }

    public function test_admin_without_finance_permission_cannot_open_finance_officer_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $billingOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Billing officer',
            'permissions' => ['manage_billing'],
        ]);

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/finance')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/finance/receipts')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/finance/payments')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/finance/exceptions')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/finance/summary')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/finance/dashboard/data')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/finance/payments/data')
            ->assertForbidden();

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/finance/exceptions/data')
            ->assertForbidden();
    }

    public function test_records_officer_has_focused_activity_and_export_workspaces(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $recordsOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Records officer',
            'permissions' => ['view_logs', 'export_data'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $entry = ActivityLog::query()->create([
            'user_id' => $provider->id,
            'actor_name' => $provider->name,
            'actor_role' => 'provider',
            'action' => 'provider_profile_updated',
            'description' => 'Provider updated the organization record.',
            'ip_address' => '127.0.0.1',
        ]);

        $this->actingAs($recordsOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/records');

        $this->actingAs($recordsOfficer)
            ->get('/admin/logs?action=provider_profile_updated')
            ->assertRedirect('/admin/workspaces/records/activity?action=provider_profile_updated');

        $this->actingAs($recordsOfficer)
            ->get('/admin/workspaces/records')
            ->assertOk()
            ->assertViewIs('admin-records-officer-dashboard');

        $this->actingAs($recordsOfficer)
            ->getJson('/admin/workspaces/records/dashboard/data')
            ->assertOk()
            ->assertJsonPath('workspace.key', 'records-officer')
            ->assertJsonPath('metrics.0.value', 1)
            ->assertJsonCount(4, 'metrics');

        $this->actingAs($recordsOfficer)
            ->get('/admin/workspaces/records/activity')
            ->assertOk()
            ->assertViewIs('admin-records-activity');

        $this->actingAs($recordsOfficer)
            ->get('/admin/workspaces/records/exports')
            ->assertOk()
            ->assertViewIs('admin-records-exports');

        $this->actingAs($recordsOfficer)
            ->getJson('/admin/workspaces/records/activity/data?actor_role=provider&period=all&search=organization')
            ->assertOk()
            ->assertJsonPath('items.0.id', $entry->id)
            ->assertJsonPath('items.0.category', 'accounts')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'items');

        $this->actingAs($recordsOfficer)
            ->get("/admin/workspaces/records/activity/{$entry->id}")
            ->assertOk()
            ->assertViewIs('admin-records-activity-record');

        $this->actingAs($recordsOfficer)
            ->getJson("/admin/workspaces/records/activity/{$entry->id}/data")
            ->assertOk()
            ->assertJsonPath('entry.id', $entry->id)
            ->assertJsonPath('entry.actor_role', 'provider')
            ->assertJsonPath('entry.ip_address', '127.0.0.1');

        $this->actingAs($recordsOfficer)
            ->getJson('/admin/workspaces/records/exports/data')
            ->assertOk()
            ->assertJsonPath('datasets.0.key', 'users')
            ->assertJsonPath('datasets.0.records', 3)
            ->assertJsonCount(3, 'datasets')
            ->assertJsonCount(0, 'recent_exports');

        $this->actingAs($recordsOfficer)
            ->get('/admin/export/users')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $exportEntry = ActivityLog::query()->where('action', 'records_users_exported')->firstOrFail();
        $this->assertSame($recordsOfficer->id, $exportEntry->user_id);
        $this->assertSame(3, $exportEntry->metadata['record_count']);

        $this->actingAs($recordsOfficer)
            ->getJson('/admin/workspaces/records/exports/data')
            ->assertOk()
            ->assertJsonPath('recent_exports.0.id', $exportEntry->id)
            ->assertJsonPath('recent_exports.0.action', 'records_users_exported');

        $this->actingAs($primaryAdmin)
            ->get('/admin/logs')
            ->assertOk()
            ->assertViewIs('admin-logs');
    }

    public function test_admin_without_records_permissions_cannot_open_records_officer_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $reviewOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/records')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/records/dashboard/data')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/records/activity')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/records/activity/data')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/records/exports')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/records/exports/data')
            ->assertForbidden();
    }

    public function test_portal_manager_has_a_dedicated_operations_workspace_and_focused_pages(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $portalManager = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Portal manager',
            'permissions' => [
                'manage_accounts',
                'manage_reviews',
                'manage_reports',
                'manage_billing',
                'view_finance',
                'view_logs',
                'export_data',
            ],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $service = ProviderServicePurchase::query()->create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Portal Managed Setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'ready',
            'reference_number' => 'SVC-PORTAL-001',
            'paid_at' => now(),
        ]);

        $this->actingAs($portalManager)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/portal');

        $this->actingAs($portalManager)
            ->get('/admin/workspaces/portal')
            ->assertOk()
            ->assertViewIs('admin-portal-manager-workspace');

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/data')
            ->assertOk()
            ->assertJsonStructure(['summary' => [
                'account_attention',
                'pending_reviews',
                'open_reports',
                'active_services',
                'month_revenue',
                'activity_today',
                'total_attention',
            ], 'priorities']);

        foreach ([
            '/admin/workspaces/portal/accounts',
            '/admin/workspaces/portal/reviews',
            '/admin/workspaces/portal/support',
            '/admin/workspaces/portal/billing',
            '/admin/workspaces/portal/finance',
            '/admin/workspaces/portal/finance/receipts',
            '/admin/workspaces/portal/records/activity',
            '/admin/workspaces/portal/records/exports',
        ] as $url) {
            $this->actingAs($portalManager)
                ->get($url)
                ->assertOk()
                ->assertViewIs('admin-portal-manager-area');
        }

        $this->actingAs($portalManager)
            ->get("/admin/workspaces/portal/reviews/providers/{$provider->id}")
            ->assertRedirect('/admin/workspaces/portal/reviews?focus=providers');

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/accounts/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'users']);

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/reviews/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'groups']);

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/support/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'cases']);

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/billing/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'queues']);

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/finance/data')
            ->assertOk()
            ->assertJsonStructure(['summary', 'payments', 'exceptions']);

        $this->actingAs($portalManager)
            ->getJson('/admin/workspaces/portal/records/data')
            ->assertOk()
            ->assertJsonStructure(['activity', 'exports']);

        $this->actingAs($portalManager)
            ->get('/admin/reviews?type=providers')
            ->assertRedirect('/admin/workspaces/portal/reviews?type=providers');

        $this->actingAs($portalManager)
            ->get('/admin/providers/'.$provider->id.'/review?section=proof')
            ->assertRedirect('/admin/workspaces/portal/reviews/providers/'.$provider->id.'?section=proof');

        $this->actingAs($portalManager)
            ->get('/admin/manage-users')
            ->assertRedirect('/admin/workspaces/portal/accounts');

        $this->actingAs($portalManager)
            ->get('/admin/reports?status=open')
            ->assertRedirect('/admin/workspaces/portal/support?status=open');

        $this->actingAs($portalManager)
            ->get('/admin/billing')
            ->assertRedirect('/admin/workspaces/portal/billing');

        $this->actingAs($portalManager)
            ->get("/admin/billing/{$service->id}")
            ->assertRedirect("/admin/workspaces/billing/requests/{$service->id}");

        $this->actingAs($portalManager)
            ->get("/admin/workspaces/billing/requests/{$service->id}")
            ->assertOk()
            ->assertSee('data-page="adminServiceOverview"', false);

        $this->actingAs($portalManager)
            ->get('/admin/finance')
            ->assertRedirect('/admin/workspaces/portal/finance');

        $this->actingAs($portalManager)
            ->get('/admin/logs')
            ->assertRedirect('/admin/workspaces/portal/records/activity');

        $this->actingAs($primaryAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertViewIs('admin-super-admin-workspace');
    }

    public function test_specialist_admin_cannot_open_portal_manager_workspace(): void
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $reviewOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/portal')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/portal/data')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/portal/reviews')
            ->assertForbidden();
    }
}
