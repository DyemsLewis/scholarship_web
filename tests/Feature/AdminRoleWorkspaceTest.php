<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\SupportReport;
use App\Models\User;
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

        $this->actingAs($accountManager)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/accounts');

        $this->actingAs($accountManager)
            ->get('/admin/manage-users')
            ->assertRedirect('/admin/workspaces/accounts');

        $this->actingAs($accountManager)
            ->get('/admin/accounts/create')
            ->assertRedirect('/admin/workspaces/accounts?create=1');

        $this->actingAs($accountManager)
            ->get('/admin/workspaces/accounts')
            ->assertOk()
            ->assertViewIs('admin-account-manager-workspace');

        $this->actingAs($primaryAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertViewIs('admin');

        $this->actingAs($primaryAdmin)
            ->get('/admin/manage-users')
            ->assertOk()
            ->assertViewIs('admin-users');
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

        $this->actingAs($reviewOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/reviews');

        $this->actingAs($reviewOfficer)
            ->get('/admin/reviews?type=programs')
            ->assertRedirect('/admin/workspaces/reviews?type=programs');

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews')
            ->assertOk()
            ->assertViewIs('admin-review-officer-workspace');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/data')
            ->assertOk()
            ->assertJsonPath('stats.providers', 1)
            ->assertJsonPath('providers.0.id', $provider->id);

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/providers/{$provider->id}")
            ->assertOk()
            ->assertViewIs('admin-provider-review');

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
            ->getJson('/admin/workspaces/reviews/data')
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

        $this->actingAs($supportOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/support');

        $this->actingAs($supportOfficer)
            ->get('/admin/reports?status=resolved')
            ->assertRedirect('/admin/workspaces/support?status=resolved');

        $this->actingAs($supportOfficer)
            ->get('/admin/workspaces/support')
            ->assertOk()
            ->assertViewIs('admin-support-officer-workspace');

        $this->actingAs($supportOfficer)
            ->getJson('/admin/workspaces/support/data?category=privacy&search=account')
            ->assertOk()
            ->assertJsonPath('summary.needs_action', 1)
            ->assertJsonPath('summary.privacy_open', 1)
            ->assertJsonPath('reports.0.id', $report->id)
            ->assertJsonCount(1, 'reports');

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

        $this->actingAs($billingOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/billing');

        $this->actingAs($billingOfficer)
            ->get('/admin/billing')
            ->assertRedirect('/admin/workspaces/billing');

        $this->actingAs($billingOfficer)
            ->get('/admin/workspaces/billing')
            ->assertOk()
            ->assertViewIs('admin-billing-officer-workspace');

        $this->actingAs($billingOfficer)
            ->getJson('/admin/workspaces/billing/data?fulfillment_status=ready')
            ->assertOk()
            ->assertJsonPath('counts.ready', 1)
            ->assertJsonPath('purchases.0.id', $purchase->id);

        $this->actingAs($billingOfficer)
            ->get("/admin/workspaces/billing/requests/{$purchase->id}")
            ->assertOk()
            ->assertViewIs('admin-service-workspace');

        $this->actingAs($billingOfficer)
            ->get("/admin/billing/{$purchase->id}")
            ->assertRedirect("/admin/workspaces/billing/requests/{$purchase->id}");

        $this->actingAs($primaryAdmin)
            ->get('/admin/billing')
            ->assertOk()
            ->assertViewIs('admin-billing');
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
    }

    public function test_finance_officer_has_separate_overview_and_receipt_pages(): void
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

        $this->actingAs($financeOfficer)
            ->get('/admin')
            ->assertRedirect('/admin/workspaces/finance');

        $this->actingAs($financeOfficer)
            ->get('/admin/finance')
            ->assertRedirect('/admin/workspaces/finance');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance')
            ->assertOk()
            ->assertViewIs('admin-finance-officer-workspace');

        $this->actingAs($financeOfficer)
            ->get('/admin/workspaces/finance/receipts')
            ->assertOk()
            ->assertViewIs('admin-finance-officer-workspace');

        $this->actingAs($financeOfficer)
            ->getJson('/admin/workspaces/finance/data?period=month')
            ->assertOk()
            ->assertJsonPath('summary.month', 125000)
            ->assertJsonPath('receipts.0.id', $purchase->id);

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
            ->getJson('/admin/workspaces/finance/data')
            ->assertForbidden();
    }

    public function test_records_officer_has_separate_activity_and_export_pages(): void
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
            ->assertRedirect('/admin/workspaces/records/activity');

        $this->actingAs($recordsOfficer)
            ->get('/admin/logs?action=provider_profile_updated')
            ->assertRedirect('/admin/workspaces/records/activity?action=provider_profile_updated');

        $this->actingAs($recordsOfficer)
            ->get('/admin/workspaces/records/activity')
            ->assertOk()
            ->assertViewIs('admin-records-officer-workspace');

        $this->actingAs($recordsOfficer)
            ->get('/admin/workspaces/records/exports')
            ->assertOk()
            ->assertViewIs('admin-records-officer-workspace');

        $this->actingAs($recordsOfficer)
            ->getJson('/admin/workspaces/records/activity/data?actor_role=provider&search=organization')
            ->assertOk()
            ->assertJsonPath('entries.0.id', $entry->id)
            ->assertJsonPath('roles.provider', 1)
            ->assertJsonCount(1, 'entries');

        $this->actingAs($recordsOfficer)
            ->get('/admin/export/users')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

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
            ->get('/admin/workspaces/records/activity')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/records/activity/data')
            ->assertForbidden();

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/records/exports')
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
            ]]);

        foreach ([
            '/admin/workspaces/portal/accounts' => 'admin-account-manager-workspace',
            '/admin/workspaces/portal/reviews' => 'admin-review-officer-workspace',
            '/admin/workspaces/portal/support' => 'admin-support-officer-workspace',
            '/admin/workspaces/portal/billing' => 'admin-billing-officer-workspace',
            '/admin/workspaces/portal/finance' => 'admin-finance-officer-workspace',
            '/admin/workspaces/portal/finance/receipts' => 'admin-finance-officer-workspace',
            '/admin/workspaces/portal/records/activity' => 'admin-records-officer-workspace',
            '/admin/workspaces/portal/records/exports' => 'admin-records-officer-workspace',
        ] as $url => $view) {
            $this->actingAs($portalManager)
                ->get($url)
                ->assertOk()
                ->assertViewIs($view);
        }

        $this->actingAs($portalManager)
            ->get("/admin/workspaces/portal/reviews/providers/{$provider->id}")
            ->assertOk()
            ->assertViewIs('admin-provider-review');

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
            ->get('/admin/finance')
            ->assertRedirect('/admin/workspaces/portal/finance');

        $this->actingAs($portalManager)
            ->get('/admin/logs')
            ->assertRedirect('/admin/workspaces/portal/records/activity');

        $this->actingAs($primaryAdmin)
            ->get('/admin')
            ->assertOk()
            ->assertViewIs('admin');
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
