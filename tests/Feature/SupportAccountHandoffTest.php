<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\SupportAccountHandoff;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Admin\Dashboards\AccountManagerDashboardQuery;
use App\Services\SupportAccountHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportAccountHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private User $manager;

    private User $learner;

    private SupportReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $root = User::factory()->create(['role' => 'admin']);
        $this->support = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_reports']]);
        $this->manager = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_accounts']]);
        $this->learner = User::factory()->create(['role' => 'applicant']);
        $this->report = SupportReport::create([
            'applicant_id' => $this->learner->id, 'assigned_role' => 'admin', 'category' => 'privacy',
            'subject' => 'Private security concern', 'description' => 'Sensitive original account report.',
            'context' => 'Private affected area', 'attachment_path' => 'private-proof.pdf',
            'status' => 'open', 'admin_status' => 'open', 'provider_status' => 'not_required',
        ]);
    }

    public function test_handoff_round_trip_retains_support_ownership_and_never_auto_changes_access_or_profile(): void
    {
        $original = $this->learner->fresh()->only(['account_status', 'email_verified_at', 'must_reset_password']);
        $this->refer()->assertOk()->assertJsonPath('support_case.state', 'waiting')
            ->assertJsonPath('support_case.responsible_role', 'Account Manager')
            ->assertJsonPath('support_case.admin_status', 'open');
        $handoff = SupportAccountHandoff::sole();
        $this->actingAs($this->manager)->getJson("/admin/workspaces/accounts/{$this->learner->id}/data")
            ->assertOk()->assertJsonPath('account_handoffs.0.instruction', 'Check the reported sign-in access issue.')
            ->assertJsonMissingPath('account_handoffs.0.report')->assertJsonMissingPath('account_handoffs.0.attachment');
        $this->complete($handoff)->assertOk()->assertJsonPath('account_handoffs.0.status', 'completed');
        $this->assertEquals($original, $this->learner->fresh()->only(array_keys($original)));
        $this->assertSame('open', $this->report->fresh()->status);
        $this->assertSame('open', $this->report->fresh()->admin_status);
        $this->actingAs($this->support)->getJson('/admin/workspaces/support/summary')
            ->assertJsonPath('summary.needs_response', 1)->assertJsonPath('summary.waiting', 0);
        $this->patchJson("/admin/reports/{$this->report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'Please complete the password reset using the link sent to your email.',
            'expected_support_token' => $this->caseToken(),
        ])->assertOk()->assertJsonPath('support_case.state', 'resolved');
        $this->assertDatabaseHas('activity_logs', ['action' => 'support_account_handoff_requested']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'support_account_handoff_completed']);
        $this->assertSame(0, PortalNotification::where('user_id', $this->learner->id)->where('type', 'support_account_handoff')->count());
    }

    public function test_referrals_are_prioritized_and_all_attention_counts_match_the_actual_queue(): void
    {
        User::factory()->create(['role' => 'applicant', 'account_status' => 'suspended', 'created_at' => now()->subMonth()]);
        $this->refer()->assertOk();
        $payload = app(AccountManagerDashboardQuery::class)->get($this->manager);
        $this->assertSame('Account Manager', $payload['priority']['owner']);
        $this->assertSame($this->learner->id, $payload['sections'][0]['items'][0]['id']);
        $this->actingAs($this->manager)->getJson('/admin/workspaces/accounts/data?attention=needs_attention')
            ->assertOk()->assertJsonPath('users.0.id', $this->learner->id)
            ->assertJsonPath('users.0.pending_support_handoff', true)
            ->assertJsonPath('stats.attention_total', 2)->assertJsonPath('pagination.total', 2);
        $this->getJson('/admin/workspaces/accounts/data?attention=support_handoff')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('stats.support_handoffs', 1);
        $this->actingAs($this->support)->getJson('/admin/workspaces/support/summary')
            ->assertJsonPath('summary.needs_response', 0)->assertJsonPath('summary.waiting', 1)->assertJsonPath('summary.privacy_open', 0);
        $this->getJson('/admin/workspaces/support/data?queue=waiting')
            ->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.state_label', 'With Account Manager');
    }

    public function test_pending_task_blocks_platform_resolution_even_when_client_omits_snapshot(): void
    {
        $this->refer()->assertOk();
        $this->patchJson("/admin/reports/{$this->report->id}/status", ['status' => 'resolved', 'admin_response' => 'An otherwise valid response.'])
            ->assertStatus(409);
        $this->assertSame('open', $this->report->fresh()->status);
    }

    public function test_stale_case_tokens_cannot_overwrite_a_new_handoff_or_returned_result(): void
    {
        $old = $this->caseToken();
        $this->refer()->assertOk();
        $this->postJson($this->referUrl(), ['expected_support_token' => $old, 'task' => 'email', 'instruction' => 'Send another verification email.'])->assertStatus(409);
        $this->complete(SupportAccountHandoff::sole())->assertOk();
        $this->actingAs($this->support)->patchJson("/admin/reports/{$this->report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'This old tab did not see the returned result.', 'expected_support_token' => $old,
        ])->assertStatus(409);
        $this->assertDatabaseCount('support_account_handoffs', 1);
    }

    public function test_duplicate_and_completed_tasks_cannot_be_completed_or_cancelled_twice(): void
    {
        $this->refer()->assertOk();
        $this->refer()->assertStatus(409);
        $handoff = SupportAccountHandoff::sole();
        $oldToken = app(SupportAccountHandoffService::class)->payload($handoff)['token'];
        $this->complete($handoff)->assertOk();
        $this->actingAs($this->manager)->patchJson($this->completeUrl($handoff), ['result' => 'Attempting a duplicate return.', 'expected_handoff_token' => $oldToken])->assertStatus(409);
        $this->actingAs($this->support)->patchJson($this->cancelUrl($handoff), ['result' => 'Attempting a stale cancellation.', 'expected_handoff_token' => $oldToken])->assertStatus(409);
        $this->assertSame('completed', $handoff->fresh()->status);
        $this->assertSame(1, ActivityLog::where('action', 'support_account_handoff_completed')->count());
    }

    public function test_cancel_requires_a_reason_preserves_history_and_returns_the_case_to_support(): void
    {
        $this->refer()->assertOk();
        $handoff = SupportAccountHandoff::sole();
        $token = app(SupportAccountHandoffService::class)->payload($handoff)['token'];
        $this->actingAs($this->support)->patchJson($this->cancelUrl($handoff), ['result' => '', 'expected_handoff_token' => $token])->assertUnprocessable();
        $this->patchJson($this->cancelUrl($handoff), ['result' => 'The reporter confirmed access was restored.', 'expected_handoff_token' => $token])
            ->assertOk()->assertJsonPath('support_case.state', 'needs_response')
            ->assertJsonPath('support_case.account_handoffs.0.status', 'cancelled');
        $this->refer()->assertOk();
        $this->assertDatabaseCount('support_account_handoffs', 2);
    }

    public function test_specialists_cannot_take_each_others_actions_or_view_private_source_reports(): void
    {
        $this->refer()->assertOk();
        $handoff = SupportAccountHandoff::sole();
        $data = ['expected_handoff_token' => app(SupportAccountHandoffService::class)->payload($handoff)['token'], 'result' => 'Checked the access state.'];
        $this->patchJson($this->completeUrl($handoff), $data)->assertForbidden();
        $this->actingAs($this->manager)->patchJson($this->cancelUrl($handoff), $data)->assertForbidden();
        $this->getJson("/admin/workspaces/support/cases/{$this->report->id}/data")->assertForbidden();
        $this->getJson("/admin/reports/{$this->report->id}/attachment")->assertForbidden();
        $this->postJson($this->referUrl(), ['task' => 'email'])->assertForbidden();
        $this->actingAs($this->support)->getJson("/admin/workspaces/accounts/{$this->learner->id}/data")->assertForbidden();
        $this->actingAs($this->learner)->patchJson($this->completeUrl($handoff), $data)->assertForbidden();
    }

    public function test_handoff_cannot_be_applied_to_a_different_account_or_case(): void
    {
        $this->refer()->assertOk();
        $handoff = SupportAccountHandoff::sole();
        $other = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($this->manager)->patchJson("/admin/workspaces/accounts/{$other->id}/support-handoffs/{$handoff->id}", [])->assertNotFound();
        $second = $this->report->replicate();
        $second->save();
        $this->actingAs($this->support)->patchJson("/admin/workspaces/support/cases/{$second->id}/account-handoffs/{$handoff->id}/cancel", [])->assertNotFound();
    }

    public function test_provider_response_remains_independent_when_an_account_task_is_pending(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->report->update(['provider_id' => $provider->id, 'assigned_role' => 'provider', 'provider_status' => 'open']);
        $this->refer()->assertOk();
        $this->actingAs($provider)->patchJson("/provider/reports/{$this->report->id}/status", [
            'status' => 'resolved', 'provider_response' => 'The enrollment requirement has been explained.',
        ])->assertOk()->assertJsonPath('report.overall_status', 'open')->assertJsonMissingPath('report.account_handoffs');
        $this->complete(SupportAccountHandoff::sole())->assertOk();
        $this->assertSame('resolved', $this->report->fresh()->provider_status);
        $this->assertSame('open', $this->report->fresh()->admin_status);
    }

    public function test_reporters_and_records_do_not_receive_internal_handoff_text(): void
    {
        $this->refer()->assertOk();
        $this->actingAs($this->learner)->getJson('/dashboard/reports/data')
            ->assertOk()->assertDontSee('Check the reported sign-in access issue.')
            ->assertJsonMissingPath('reports.0.account_handoffs');
        $metadata = ActivityLog::where('action', 'support_account_handoff_requested')->sole()->metadata;
        $this->assertArrayNotHasKey('instruction', $metadata);
        $this->assertArrayNotHasKey('description', $metadata);
    }

    public function test_provider_staff_can_be_referred_without_sharing_the_private_case_and_invalid_tasks_are_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->report->update(['applicant_id' => $provider->id]);
        $this->actingAs($this->support)->postJson($this->referUrl(), ['expected_support_token' => $this->caseToken(), 'task' => 'approve_profile', 'instruction' => 'Please approve the profile.'])->assertUnprocessable();
        $this->refer()->assertOk();
        $this->actingAs($this->manager)->getJson("/admin/workspaces/accounts/{$provider->id}/data")
            ->assertOk()->assertJsonCount(1, 'account_handoffs')->assertDontSee('Sensitive original account report.');
    }

    public function test_portal_manager_oversight_cannot_take_specialist_handoff_actions(): void
    {
        $this->refer()->assertOk();
        $handoff = SupportAccountHandoff::sole();
        $portalManager = User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $this->support->parent_account_id,
            'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS,
        ]);
        $this->actingAs($portalManager)->postJson($this->referUrl(), [])->assertForbidden();
        $this->patchJson($this->completeUrl($handoff), [])->assertForbidden();
        $this->patchJson($this->cancelUrl($handoff), [])->assertForbidden();
        $this->patchJson("/admin/reports/{$this->report->id}/status", ['status' => 'resolved'])->assertForbidden();
        $this->assertSame('pending', $handoff->fresh()->status);
    }

    public function test_closed_and_provider_only_cases_cannot_be_referred_and_snapshot_is_required(): void
    {
        $this->actingAs($this->support)->postJson($this->referUrl(), ['task' => 'password', 'instruction' => 'Check password access.'])->assertUnprocessable();
        $this->report->update(['status' => 'resolved', 'admin_status' => 'resolved']);
        $this->refer()->assertStatus(409);
        $this->report->update(['status' => 'open', 'admin_status' => 'not_required']);
        $this->refer()->assertStatus(409);
        $this->assertDatabaseCount('support_account_handoffs', 0);
    }

    private function caseToken(): string
    {
        return app(SupportAccountHandoffService::class)->caseToken($this->report->fresh());
    }

    private function refer()
    {
        return $this->actingAs($this->support)->postJson($this->referUrl(), ['expected_support_token' => $this->caseToken(), 'task' => 'sign_in', 'instruction' => 'Check the reported sign-in access issue.']);
    }

    private function complete(SupportAccountHandoff $handoff)
    {
        return $this->actingAs($this->manager)->patchJson($this->completeUrl($handoff), ['expected_handoff_token' => app(SupportAccountHandoffService::class)->payload($handoff->fresh())['token'], 'result' => 'Sign-in is available. The account holder should complete the password reset.']);
    }

    private function referUrl(): string
    {
        return "/admin/workspaces/support/cases/{$this->report->id}/account-handoffs";
    }

    private function completeUrl(SupportAccountHandoff $handoff): string
    {
        return "/admin/workspaces/accounts/{$handoff->user_id}/support-handoffs/{$handoff->id}";
    }

    private function cancelUrl(SupportAccountHandoff $handoff): string
    {
        return "/admin/workspaces/support/cases/{$this->report->id}/account-handoffs/{$handoff->id}/cancel";
    }
}
