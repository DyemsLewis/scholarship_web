<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportReportRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_report_is_shared_with_the_program_provider_and_admin(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Community Learning Grant',
            'description' => 'A published program used to test support report routing.',
            'status' => 'published',
        ]);

        $this->actingAs($applicant)
            ->postJson('/dashboard/reports', [
                'category' => 'program',
                'scholarship_id' => $scholarship->id,
                'subject' => 'Requirement is unclear',
                'description' => 'Please clarify which school record should be uploaded.',
            ])
            ->assertCreated()
            ->assertJsonPath('report.sent_to', 'Program provider and platform support');

        $report = SupportReport::query()->firstOrFail();

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'applicant_id' => $applicant->id,
            'scholarship_id' => $scholarship->id,
            'provider_id' => $provider->id,
            'assigned_role' => 'provider',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'support_report',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'support_report',
        ]);

        $this->actingAs($provider)
            ->getJson('/provider/reports/data')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($otherProvider)
            ->getJson('/provider/reports/data')
            ->assertOk()
            ->assertJsonCount(0, 'reports');

        $this->actingAs($admin)
            ->getJson('/admin/reports/data')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);
    }

    public function test_non_program_report_is_routed_to_admin_and_visible_only_to_its_applicant(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $otherApplicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($applicant)
            ->postJson('/dashboard/reports', [
                'category' => 'technical',
                'subject' => 'Page will not load',
                'description' => 'The documents page remains blank after I open it.',
            ])
            ->assertCreated()
            ->assertJsonPath('report.sent_to', 'Platform support');

        $report = SupportReport::query()->firstOrFail();

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'applicant_id' => $applicant->id,
            'scholarship_id' => null,
            'provider_id' => null,
            'assigned_role' => 'admin',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'support_report',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/reports/data')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($provider)
            ->getJson('/provider/reports/data')
            ->assertOk()
            ->assertJsonCount(0, 'reports');

        $this->actingAs($applicant)
            ->getJson('/dashboard/reports/data')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($otherApplicant)
            ->getJson('/dashboard/reports/data')
            ->assertOk()
            ->assertJsonCount(0, 'reports');
    }

    public function test_privacy_report_is_available_and_routed_only_to_admin(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($applicant)
            ->getJson('/dashboard/reports/data')
            ->assertOk()
            ->assertJsonFragment([
                'value' => 'privacy',
                'label' => 'Privacy and personal data concern',
            ])
            ->assertJsonFragment([
                'value' => 'correction',
                'label' => 'Correct personal information',
            ]);

        $this->actingAs($applicant)
            ->postJson('/dashboard/reports', [
                'category' => 'privacy',
                'subject' => 'Request to correct personal information',
                'description' => 'Please help me review an incorrect personal detail in my account.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('privacy_request_type');

        $this->actingAs($applicant)
            ->postJson('/dashboard/reports', [
                'category' => 'privacy',
                'privacy_request_type' => 'correction',
                'subject' => 'Request to correct personal information',
                'description' => 'Please help me review an incorrect personal detail in my account.',
            ])
            ->assertCreated()
            ->assertJsonPath('report.sent_to', 'Platform support')
            ->assertJsonPath('report.privacy_request_type', 'correction')
            ->assertJsonPath('report.privacy_request_type_label', 'Correct personal information');

        $report = SupportReport::query()->firstOrFail();

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'category' => 'privacy',
            'privacy_request_type' => 'correction',
            'assigned_role' => 'admin',
            'provider_id' => null,
            'provider_status' => 'not_required',
        ]);

        $this->actingAs($provider)
            ->getJson('/provider/reports/data')
            ->assertOk()
            ->assertJsonCount(0, 'reports');

        $this->actingAs($admin)
            ->getJson('/admin/reports/data')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id)
            ->assertJsonPath('reports.0.privacy_request_type_label', 'Correct personal information');
    }

    public function test_program_provider_and_admin_complete_independent_report_states_without_conflict(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $report = SupportReport::create([
            'applicant_id' => $applicant->id,
            'provider_id' => $provider->id,
            'assigned_role' => 'provider',
            'category' => 'program',
            'subject' => 'Program schedule question',
            'description' => 'The listed schedule needs clarification from the provider.',
            'status' => 'open',
            'provider_status' => 'open',
            'admin_status' => 'open',
        ]);

        $this->actingAs($otherProvider)
            ->patchJson("/provider/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/admin/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved')
            ->assertJsonPath('report.admin_status', 'resolved')
            ->assertJsonPath('report.provider_status', 'open')
            ->assertJsonPath('report.overall_status', 'open');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'open',
            'provider_status' => 'open',
            'admin_status' => 'resolved',
            'admin_resolved_by' => $admin->id,
        ]);
        $this->assertDatabaseCount('portal_notifications', 0);

        $this->actingAs($admin)
            ->getJson('/admin/reports/data?status=resolved')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($provider)
            ->getJson('/provider/reports/data?status=open')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $report->id);

        $this->actingAs($provider)
            ->patchJson("/provider/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved')
            ->assertJsonPath('report.overall_status', 'resolved');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'resolved',
            'provider_status' => 'resolved',
            'admin_status' => 'resolved',
            'resolved_by' => $provider->id,
        ]);
        $this->assertDatabaseCount('portal_notifications', 1);

        $this->actingAs($provider)
            ->patchJson("/provider/reports/{$report->id}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('report.status', 'open')
            ->assertJsonPath('report.admin_status', 'resolved')
            ->assertJsonPath('report.overall_status', 'open');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'open',
            'provider_status' => 'open',
            'provider_resolved_by' => null,
            'admin_status' => 'resolved',
            'admin_resolved_by' => $admin->id,
            'resolved_by' => null,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'support_report_status',
        ]);
        $this->assertDatabaseCount('portal_notifications', 2);
    }

    public function test_admin_resolution_closes_an_admin_only_report_immediately(): void
    {
        Mail::fake();
        $applicant = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $report = SupportReport::create([
            'applicant_id' => $applicant->id,
            'assigned_role' => 'admin',
            'category' => 'technical',
            'subject' => 'Documents page issue',
            'description' => 'The applicant cannot open the prepared documents page.',
            'status' => 'open',
            'provider_status' => 'not_required',
            'admin_status' => 'open',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved')
            ->assertJsonPath('report.overall_status', 'resolved');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'resolved',
            'provider_status' => 'not_required',
            'admin_status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);
    }
}
