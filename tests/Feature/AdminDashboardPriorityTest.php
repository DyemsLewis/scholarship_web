<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\Scholarship;
use App\Models\User;
use App\Queries\Admin\AccountDirectoryQuery;
use App\Queries\Admin\BillingWorkQueue;
use App\Queries\Admin\Dashboards\AccountManagerDashboardQuery;
use App\Queries\Admin\Dashboards\BillingOfficerDashboardQuery;
use App\Queries\Admin\Dashboards\FinanceOfficerDashboardQuery;
use App\Queries\Admin\Dashboards\RecordsOfficerDashboardQuery;
use App\Queries\Admin\Dashboards\ReviewOfficerDashboardQuery;
use App\Queries\Admin\Dashboards\SupportOfficerDashboardQuery;
use App\Queries\Admin\ReviewQueueQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recommendation_uses_oldest_evidence_not_largest_queue_or_account_update(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $provider->providerVerificationDocuments()->create($this->proof($provider, 'organization_registration', now()->subDays(8)));
        foreach (range(1, 3) as $day) {
            $applicant = User::factory()->create(['role' => 'applicant']);
            $applicant->applicantVerificationDocuments()->create($this->proof($applicant, 'academic_record', now()->subDays($day)));
        }
        $provider->touch();
        $payload = app(ReviewOfficerDashboardQuery::class)->get();
        $this->assertSame($provider->name, $payload['priority']['title']);
        $this->assertSame('actionable', $payload['priority']['state']);
        $this->assertStringContainsString('8d ago', $payload['priority']['timing']);
        $this->assertStringStartsWith('/admin/', $payload['priority']['action']['url']);
        $this->assertSame(1, $payload['metrics'][0]['value']);
        $this->assertSame(3, $payload['metrics'][2]['value']);
        $this->assertNull($payload['metrics'][3]['url']);
    }

    public function test_pending_applicant_queue_uses_latest_evidence_submission_and_stable_order(): void
    {
        $first = User::factory()->create(['role' => 'applicant']);
        $second = User::factory()->create(['role' => 'applicant']);
        $first->applicantVerificationDocuments()->create($this->proof($first, 'academic_record', now()->subDays(10)));
        $second->applicantVerificationDocuments()->create($this->proof($second, 'academic_record', now()->subDays(5)));
        $first->touch();
        $queues = app(ReviewQueueQuery::class);
        $this->assertSame($first->id, $queues->applicants([])['items']->first()['id']);
        $first->applicantVerificationDocuments()->create($this->proof($first, 'identity_record', now()));
        $this->assertSame($second->id, $queues->applicants([])['items']->first()['id']);
    }

    public function test_program_opening_can_raise_priority_and_clickable_counts_match_queues(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Opening today', 'description' => 'Test program',
            'status' => 'pending_review', 'application_opens_at' => today(), 'deadline' => today()->addMonth(),
        ]);
        $payload = app(ReviewOfficerDashboardQuery::class)->get();
        $this->assertSame($program->title, $payload['priority']['title']);
        $this->assertStringContainsString('opening date', $payload['priority']['reason']);
        foreach (['providers', 'programs', 'applicants'] as $index => $key) {
            $this->assertSame(app(ReviewQueueQuery::class)->{$key}([])['pagination']['total'], $payload['metrics'][$index]['value']);
            $this->assertStringContainsString('/'.$key.'?status=', $payload['metrics'][$index]['url']);
        }
    }

    public function test_program_review_timestamp_only_changes_on_entry_into_review(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Timestamp test', 'description' => 'Test', 'status' => 'draft']);
        $this->assertNull($program->review_requested_at);
        $program->update(['status' => 'pending_review']);
        $submitted = $program->fresh()->review_requested_at->toISOString();
        $this->travel(2)->hours();
        $program->update(['title' => 'Updated title']);
        $this->assertSame($submitted, $program->fresh()->review_requested_at->toISOString());
        $program->update(['status' => 'draft']);
        $program->update(['status' => 'pending_review']);
        $this->assertNotSame($submitted, $program->fresh()->review_requested_at->toISOString());
    }

    public function test_provider_without_proof_is_waiting_not_a_ready_verification(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $payload = app(ReviewOfficerDashboardQuery::class)->get();
        $this->assertSame('waiting', $payload['priority']['state']);
        $this->assertSame('Provider', $payload['priority']['owner']);
        $this->assertNull($payload['priority']['timing']);
    }

    public function test_billing_recommends_overdue_active_work_before_new_paid_requests(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->purchase($provider, ['fulfillment_status' => 'ready']);
        $overdue = $this->purchase($provider, [
            'fulfillment_status' => 'in_progress', 'current_action_owner' => 'platform',
            'action_due_at' => today()->subDays(2),
        ]);
        $waiting = $this->purchase($provider, [
            'fulfillment_status' => 'needs_information', 'current_action_owner' => 'provider',
            'action_due_at' => today()->subDays(10),
        ]);
        $summary = app(BillingWorkQueue::class)->summary();
        $this->assertSame($overdue->id, $summary['next_request']['id']);
        $payload = app(BillingOfficerDashboardQuery::class)->get();
        $this->assertStringContainsString('overdue', $payload['priority']['reason']);
        $this->assertStringContainsString((string) $overdue->id, $payload['priority']['action']['url']);
        $this->assertSame($payload['priority']['action']['url'], $payload['primary_action']['url']);
        $this->assertSame('needs_information', $waiting->fresh()->fulfillment_status);
        $this->assertSame('paid', $overdue->fresh()->status);
    }

    public function test_finance_distinguishes_failed_review_from_pending_gateway_wait(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $pending = $this->purchase($provider, ['status' => 'pending']);
        $finance = app(FinanceOfficerDashboardQuery::class);
        $this->assertSame('waiting', $finance->get()['priority']['state']);
        $failed = $this->purchase($provider, ['status' => 'failed', 'failed_at' => now(), 'failure_message' => 'Checkout expired.']);
        $payload = $finance->get();
        $this->assertSame('actionable', $payload['priority']['state']);
        $this->assertStringContainsString(urlencode($failed->reference_number), $payload['priority']['action']['url']);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_account_priorities_respect_scope_and_directory_order(): void
    {
        $primary = User::factory()->create(['role' => 'admin', 'account_status' => 'suspended']);
        $officer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $primary->id, 'permissions' => ['manage_accounts']]);
        $applicant = User::factory()->create(['role' => 'applicant', 'email_verified_at' => null]);
        $dashboard = app(AccountManagerDashboardQuery::class)->get($officer);
        $directory = app(AccountDirectoryQuery::class)->payload($officer, ['attention' => 'needs_attention']);
        $this->assertSame($applicant->id, $directory['users']->first()['id']);
        $this->assertSame($applicant->name, $dashboard['priority']['title']);
        $this->assertSame('waiting', $dashboard['priority']['state']);
        $this->assertSame(1, $dashboard['metrics'][1]['value']);
    }

    public function test_records_failed_sign_in_count_opens_the_exact_filter(): void
    {
        $officer = User::factory()->create(['role' => 'admin']);
        foreach (['login_failed', 'login', 'logout'] as $action) {
            ActivityLog::create(['action' => $action, 'description' => 'Recorded test event', 'actor_role' => 'system']);
        }
        $payload = app(RecordsOfficerDashboardQuery::class)->get($officer);
        $this->assertSame(1, $payload['metrics'][1]['value']);
        $this->assertStringContainsString('action=login_failed', $payload['metrics'][1]['url']);
        $this->assertSame('reference', $payload['priority']['state']);
    }

    public function test_empty_queues_are_clear_and_recommendations_keep_workspace_permissions(): void
    {
        $this->assertSame('clear', app(ReviewOfficerDashboardQuery::class)->get()['priority']['state']);
        $this->assertSame('clear', app(SupportOfficerDashboardQuery::class)->get()['priority']['state']);
        $primary = User::factory()->create(['role' => 'admin']);
        $officer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $primary->id, 'permissions' => ['manage_accounts']]);
        $this->actingAs($officer)->getJson('/admin/workspaces/reviews/dashboard/data')->assertForbidden();
        $this->actingAs($officer)->getJson('/admin/workspaces/billing/dashboard/data')->assertForbidden();
        $this->actingAs($officer)->getJson('/admin/workspaces/finance/dashboard/data')->assertForbidden();
    }

    private function proof(User $user, string $type, mixed $uploadedAt): array
    {
        return ['uploaded_by' => $user->id, 'document_type' => $type, 'original_name' => 'proof.pdf', 'path' => 'proof/test.pdf', 'uploaded_at' => $uploadedAt];
    }

    private function purchase(User $provider, array $attributes): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'assisted_setup',
            'plan_name' => 'Service request', 'amount' => 10000, 'currency' => 'PHP', 'status' => 'paid',
            'fulfillment_status' => 'ready', 'reference_number' => 'SVC-'.fake()->unique()->numerify('########'),
            'paid_at' => now(), ...$attributes,
        ]);
    }
}
