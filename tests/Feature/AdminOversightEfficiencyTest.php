<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Admin\BillingWorkQueue;
use App\Queries\Admin\FinanceRecordQuery;
use App\Queries\Admin\PortalManagerWorkspaceQuery;
use App\Queries\Admin\SuperAdminWorkspaceQuery;
use App\Support\AdminAuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOversightEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiting_account_steps_and_historical_events_are_not_staff_owned_work(): void
    {
        User::factory()->create(['role' => 'applicant', 'email_verified_at' => null, 'must_reset_password' => true]);
        foreach (range(1, 3) as $index) {
            ActivityLog::create(['action' => 'login_failed', 'description' => 'Recorded failed sign-in.']);
        }
        $overview = app(PortalManagerWorkspaceQuery::class)->overview();
        $areas = $overview['priorities']->keyBy('key');
        $this->assertSame(0, $areas['accounts']['count']);
        $this->assertSame(1, $areas['accounts']['waiting']);
        $this->assertSame('reference', $areas['records']['state']);
        $this->assertSame(3, $areas['records']['reference']);
        $this->assertSame(0, $overview['summary']['total_attention']);
        $this->assertSame(1, $overview['summary']['waiting_total']);
        $this->assertCount(0, app(SuperAdminWorkspaceQuery::class)->overview()['escalations']);
    }

    public function test_support_handoff_is_visible_as_one_account_action_and_a_waiting_support_case(): void
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $report = SupportReport::create([
            'applicant_id' => $applicant->id, 'assigned_role' => 'admin', 'category' => 'account',
            'subject' => 'Access issue', 'description' => 'Private source report stays with Support.',
            'status' => 'open', 'admin_status' => 'open', 'provider_status' => 'not_required',
        ]);
        $report->accountHandoffs()->create(['user_id' => $applicant->id, 'task' => 'sign_in', 'instruction' => 'Check the access problem.', 'status' => 'pending']);
        $query = app(PortalManagerWorkspaceQuery::class);
        $overview = $query->overview();
        $areas = $overview['priorities']->keyBy('key');
        $this->assertSame(1, $overview['summary']['total_attention']);
        $this->assertSame(1, $areas['accounts']['count']);
        $this->assertSame(0, $areas['support']['count']);
        $this->assertSame(1, $areas['support']['waiting']);
        $this->assertSame('Referred by Support', $query->accounts()['users'][0]['issue']);
        $support = $query->support();
        $this->assertCount(0, $support['cases']['items']);
        $this->assertSame('Account Manager', $support['waiting']['items'][0]['responsible_role']);
        $this->assertArrayNotHasKey('action_url', $support['waiting']['items'][0]);
        $this->assertArrayNotHasKey('description', $support['waiting']['items'][0]);
    }

    public function test_in_progress_delivery_and_closed_finance_follow_up_are_counted_without_waiting_overlap(): void
    {
        $active = $this->purchase(['fulfillment_status' => 'in_progress', 'current_action_owner' => 'platform']);
        $waiting = $this->purchase(['fulfillment_status' => 'needs_information', 'current_action_owner' => 'provider']);
        $closed = $this->purchase(['fulfillment_status' => 'cancelled']);
        $closed->financeReviews()->create(['state' => 'with_billing', 'request_note' => 'Clarify the external refund step.', 'source_fingerprint' => str_repeat('a', 64)]);
        $waiting->financeReviews()->create(['state' => 'with_billing', 'request_note' => 'Check the original payment record.', 'source_fingerprint' => str_repeat('b', 64)]);
        $summary = app(BillingWorkQueue::class)->summary();
        $this->assertSame(3, $summary['actionable']);
        $this->assertSame(0, $summary['waiting_on_provider']);
        $data = app(PortalManagerWorkspaceQuery::class)->services();
        $ids = $data['queues']['actionable']['items']->pluck('id');
        $this->assertTrue($ids->contains($closed->id));
        $this->assertTrue($ids->contains($active->id));
        foreach ($data['queues'] as $queue) {
            foreach ($queue['items'] as $item) {
                $this->assertArrayNotHasKey('action_url', $item);
            }
        }
    }

    public function test_finance_separates_gateway_and_billing_waits_from_its_own_review_work(): void
    {
        $this->purchase(['status' => 'pending', 'paid_at' => null]);
        $billing = $this->purchase(['refund_status' => 'manual_refund_pending']);
        $finance = $this->purchase(['refund_status' => 'manual_refund_pending']);
        $billing->financeReviews()->create(['state' => 'with_billing', 'request_note' => 'Pending Billing check.', 'source_fingerprint' => str_repeat('a', 64)]);
        $finance->financeReviews()->create(['state' => 'with_finance', 'request_note' => 'Check the response.', 'source_fingerprint' => str_repeat('b', 64)]);
        $summary = app(FinanceRecordQuery::class)->summary();
        $this->assertSame(3, $summary['exceptions']);
        $this->assertSame(1, $summary['needs_review']);
        $this->assertSame(2, $summary['waiting']);
        $this->assertSame(1, app(FinanceRecordQuery::class)->exceptions(['status' => 'needs_review'])['pagination']['total']);
        $area = app(PortalManagerWorkspaceQuery::class)->overview()['priorities']->firstWhere('key', 'finance');
        $this->assertSame(1, $area['count']);
        $this->assertSame(2, $area['waiting']);
    }

    public function test_provider_without_proof_is_waiting_not_an_automatic_review_action(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $overview = app(PortalManagerWorkspaceQuery::class)->overview();
        $area = $overview['priorities']->firstWhere('key', 'reviews');
        $this->assertSame(0, $area['count']);
        $this->assertSame(1, $area['waiting']);
        $this->assertSame(0, $overview['summary']['total_attention']);
    }

    public function test_active_staff_with_incomplete_access_or_missing_permissions_do_not_provide_coverage(): void
    {
        $root = User::factory()->create(['role' => 'admin']);
        foreach ([
            ['account_title' => 'Account manager', 'permissions' => ['manage_accounts'], 'email_verified_at' => null],
            ['account_title' => 'Finance officer', 'permissions' => ['view_finance'], 'must_reset_password' => true],
            ['account_title' => 'Review officer', 'permissions' => []],
        ] as $record) {
            User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'account_status' => 'active', ...$record]);
        }
        User::factory()->create(['role' => 'applicant', 'account_status' => 'suspended']);
        $data = app(SuperAdminWorkspaceQuery::class)->overview();
        $this->assertSame(3, $data['summary']['active_admins']);
        $this->assertSame(0, $data['summary']['available_admins']);
        $this->assertSame(0, $data['summary']['covered_roles']);
        $this->assertSame('accounts', $data['escalations'][0]['key']);
        $this->assertSame(0, $data['escalations'][0]['active_owners']);
        $this->assertArrayNotHasKey('url', $data['operational_areas'][0]);
        $this->assertStringStartsWith('/admin/governance/team?role=', $data['escalations'][0]['owner_url']);
        $members = app(SuperAdminWorkspaceQuery::class)->team()['members'];
        $this->assertEqualsCanonicalizing(['Email verification needed', 'Password reset required', 'Role permissions missing'], $members->pluck('access_state')->all());
    }

    public function test_super_admin_can_follow_role_coverage_to_a_filtered_team_view_without_losing_global_totals(): void
    {
        $root = User::factory()->create(['role' => 'admin']);
        $finance = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['view_finance'], 'account_title' => 'Finance officer']);
        User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_reports']]);
        $this->actingAs($root)->getJson('/admin/governance/team/data?role=finance_officer')
            ->assertOk()->assertJsonPath('summary.team_members', 2)->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.id', $finance->id)->assertJsonCount(7, 'coverage');
        $this->getJson('/admin/governance/team/data?role=unknown')->assertUnprocessable();
        $this->actingAs($finance)->getJson('/admin/governance/team/data?role=finance_officer')->assertForbidden();
    }

    public function test_related_audit_history_matches_exact_entity_ids_and_preserves_frozen_details(): void
    {
        $root = User::factory()->create(['role' => 'admin']);
        $records = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['view_logs']]);
        $entry = ActivityLog::create(['action' => 'service_finance_request_billing', 'description' => 'Original review event.', 'metadata' => ['purchase_id' => 12, 'review_status' => 'with_billing']]);
        ActivityLog::create(['action' => 'service_finance_billing_responded', 'description' => 'Later response.', 'metadata' => ['purchase_id' => '12']]);
        ActivityLog::create(['action' => 'unrelated', 'description' => 'Other record.', 'metadata' => ['purchase_id' => 112, 'scholarship_id' => 12]]);
        ActivityLog::create(['action' => 'unrelated', 'description' => 'String prefix is not an ID.', 'metadata' => ['purchase_id' => '12suffix']]);
        $this->actingAs($records)->getJson('/admin/workspaces/records/activity/data?record_type=service&record_id=12&period=all')
            ->assertOk()->assertJsonPath('can_export', false)->assertJsonPath('pagination.total', 2)->assertDontSee('Other record.')->assertDontSee('String prefix is not an ID.');
        $this->getJson("/admin/workspaces/records/activity/{$entry->id}/data")
            ->assertOk()->assertJsonPath('entry.description', 'Original review event.')
            ->assertJsonPath('entry.related_trails.0.label', 'Related service history');
        $this->assertSame(['purchase_id' => 12, 'review_status' => 'with_billing'], $entry->fresh()->metadata);
        foreach (AdminAuditTrail::links($entry->metadata) as $link) {
            $this->assertStringStartsWith('/admin/workspaces/records/activity?', $link['url']);
        }
        $purchase = $this->purchase();
        $this->patchJson("/admin/billing/{$purchase->id}/cancellation", ['decision' => 'approved'])->assertForbidden();
        $this->get('/admin/export/users')->assertForbidden();
    }

    public function test_invalid_related_record_filters_are_rejected_and_unprivileged_roles_cannot_read_trails(): void
    {
        $root = User::factory()->create(['role' => 'admin']);
        foreach (['record_type=service', 'record_id=1', 'record_type=table_name&record_id=1', 'record_type=service&record_id=-1'] as $filter) {
            $this->actingAs($root)->getJson('/admin/workspaces/records/activity/data?'.$filter)->assertUnprocessable();
        }
        $provider = User::factory()->create(['role' => 'provider']);
        $this->actingAs($provider)->getJson('/admin/workspaces/records/activity/data?record_type=service&record_id=1')->assertForbidden();
        $this->assertSame([], AdminAuditTrail::links(['purchase_id' => 'bad', 'applicant_id' => -3]));
    }

    public function test_platform_recipient_count_excludes_unaccepted_declined_and_closed_support(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Oversight recipient count', 'description' => 'A test program.', 'status' => 'published']);
        foreach ([null, 'declined', 'accepted', 'accepted'] as $index => $response) {
            $applicant = User::factory()->create(['role' => 'applicant']);
            $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
                'status' => 'disbursed', 'final_outcome' => 'selected', 'student_response_status' => $response]);
            if ($index === 3) {
                $application->supportDecisions()->create(['applicant_id' => $applicant->id, 'decision' => 'completed',
                    'reason_category' => 'support_period_ended', 'reason' => 'Support was completed.', 'effective_on' => today(), 'decided_at' => now()]);
            }
        }
        $this->assertSame(1, app(SuperAdminWorkspaceQuery::class)->platform()['summary']['active_recipients']);
    }

    private function purchase(array $attributes = []): ProviderServicePurchase
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        return ProviderServicePurchase::create(['provider_id' => $provider->id, 'created_by' => $provider->id,
            'plan_code' => 'oversight', 'plan_name' => 'Oversight service', 'reference_number' => 'OVERSIGHT-'.str()->random(8),
            'amount' => 10000, 'currency' => 'PHP', 'status' => 'paid', 'paid_at' => now(), 'fulfillment_status' => 'ready', ...$attributes]);
    }
}
