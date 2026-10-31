<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\RecipientMonitoringCycle;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Admin\FinanceRecordQuery;
use App\Queries\Admin\RecordsWorkspaceQuery;
use App\Services\RecipientMonitoringCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceRecordsRefinementTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_category_filter_contains_exactly_its_displayed_category(): void
    {
        foreach (['login_failed', 'email_verification_sent', 'provider_profile_reviewed', 'account_verification_approved', 'provider_profile_updated', 'records_users_exported', 'custom_action'] as $action) {
            ActivityLog::create(['action' => $action, 'description' => $action]);
        }
        $records = app(RecordsWorkspaceQuery::class);
        $ids = [];
        foreach (['security', 'reviews', 'accounts', 'exports', 'operations'] as $category) {
            $items = $records->activity(['category' => $category, 'period' => 'all'])['items'];
            $this->assertNotEmpty($items);
            foreach ($items as $item) {
                $this->assertSame($category, $item['category']);
                $ids[] = $item['id'];
            }
        }
        $this->assertCount(7, array_unique($ids));
        $this->assertCount(7, $ids);
    }

    public function test_system_filter_includes_implicit_and_explicit_system_records_only(): void
    {
        foreach ([null, 'system', 'provider'] as $role) {
            ActivityLog::create(['actor_role' => $role, 'action' => 'custom_action', 'description' => 'Recorded event.']);
        }
        $data = app(RecordsWorkspaceQuery::class)->activity(['actor_role' => 'system', 'period' => 'all']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(['system', 'system'], $data['items']->pluck('actor_role')->all());
    }

    public function test_metadata_is_readable_without_changing_the_original_audit_snapshot(): void
    {
        $metadata = ['old_status' => 'in_progress', 'filename' => 'school_id.pdf', 'count' => 0, 'approved' => false,
            'changes' => ['status' => 'needs_information', 'payment_id' => 'pay_original_123']];
        $entry = ActivityLog::create(['actor_name' => 'Original recorded name', 'action' => 'service_updated', 'description' => 'Service updated.', 'metadata' => $metadata]);
        $record = app(RecordsWorkspaceQuery::class)->record($entry);
        $values = collect($record['metadata'])->pluck('value', 'label');
        $this->assertSame('In Progress', $values['Old Status']);
        $this->assertSame('school_id.pdf', $values['Filename']);
        $this->assertSame('0', $values['Count']);
        $this->assertSame('No', $values['Approved']);
        $this->assertSame("Status: Needs Information\nPayment Id: pay_original_123", $values['Changes']);
        $this->assertSame($metadata, $entry->fresh()->metadata);
        $this->assertSame('Original recorded name', $record['actor_name']);
    }

    public function test_finance_search_finds_representative_names_and_only_confirmed_payments(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['first_name' => 'UniqueRepresentative', 'last_name' => 'UniqueSurname']);
        $paid = $this->purchase($provider, 'paid', now());
        $this->purchase($provider, 'paid');
        $this->purchase($provider, 'pending');
        $this->purchase($provider, 'failed');
        $query = app(FinanceRecordQuery::class);
        foreach (['UniqueRepresentative', 'UniqueSurname', $provider->email, $provider->fresh()->provider_name] as $search) {
            $items = $query->payments(['period' => 'all', 'search' => $search])['items'];
            $this->assertCount(1, $items);
            $this->assertSame($paid->id, $items[0]['id']);
        }
        $exceptions = $query->exceptions(['search' => 'UniqueRepresentative'])['items'];
        $this->assertCount(2, $exceptions);
    }

    public function test_incomplete_payments_never_have_a_finance_receipt(): void
    {
        $officer = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        foreach (['pending', 'failed', 'paid'] as $status) {
            $purchase = $this->purchase($provider, $status);
            $this->actingAs($officer)->getJson("/admin/workspaces/finance/receipts/{$purchase->id}/data")->assertNotFound();
        }
        $paid = $this->purchase($provider, 'paid', now());
        $this->getJson("/admin/workspaces/finance/receipts/{$paid->id}/data")->assertOk()->assertJsonPath('receipt.id', $paid->id);
    }

    public function test_records_read_access_does_not_grant_export_or_finance_access(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create(['role' => 'admin', 'parent_account_id' => $owner->id, 'permissions' => ['view_logs']]);
        $this->actingAs($reader)->getJson('/admin/workspaces/records/activity/data')->assertOk();
        foreach (['/admin/workspaces/records/exports/data', '/admin/export/users', '/admin/workspaces/finance/payments/data'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_account_csv_keeps_passwords_out_and_escapes_spreadsheet_formulas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['username' => '=SUM(1,1)']);
        $response = $this->actingAs($admin)->get('/admin/export/users')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $this->assertStringNotContainsString($applicant->password, $csv);
        $this->assertStringNotContainsString($applicant->remember_token, $csv);
        $this->assertStringNotContainsString('Password,', $csv);
        $this->assertSame(1, ActivityLog::where('action', 'records_users_exported')->count());
    }

    public function test_monitoring_export_count_measures_cases_instead_of_unique_recipients(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Case count test', 'description' => 'Monitoring export test.', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $scholarship->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'submitted_at' => now()]);
        foreach (['First semester', 'Second semester'] as $title) {
            $cycle = RecipientMonitoringCycle::create(['scholarship_id' => $scholarship->id, 'created_by' => $provider->id, 'title' => $title, 'period_type' => 'semester', 'academic_period' => $title, 'school_year' => '2026-2027', 'opens_at' => now(), 'due_at' => now()->addMonth(), 'minimum_grade' => 85, 'grading_scale' => 'percentage', 'instructions' => 'Upload current grades.', 'status' => 'published']);
            app(RecipientMonitoringCaseService::class)->sync($cycle, $application);
        }
        $dataset = collect(app(RecordsWorkspaceQuery::class)->exportDesk()['datasets'])->firstWhere('key', 'monitoring');
        $this->assertSame(2, $dataset['records']);
    }

    private function purchase(User $provider, string $status, mixed $paidAt = null): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'service', 'plan_name' => 'Platform service',
            'amount' => 10000, 'currency' => 'PHP', 'status' => $status, 'fulfillment_status' => 'queued',
            'reference_number' => 'TEST-'.str()->random(12), 'paid_at' => $paidAt,
        ]);
    }
}
