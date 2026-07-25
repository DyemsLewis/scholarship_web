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

    public function test_program_report_is_routed_only_to_the_program_provider(): void
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
            ->assertJsonPath('report.sent_to', 'Program provider');

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
        $this->assertDatabaseMissing('portal_notifications', [
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
            ->assertJsonCount(0, 'reports');
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

    public function test_only_the_assigned_staff_role_can_resolve_a_report(): void
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
        ]);

        $this->actingAs($otherProvider)
            ->patchJson("/provider/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/admin/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertForbidden();

        $this->actingAs($provider)
            ->patchJson("/provider/reports/{$report->id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved');

        $this->assertDatabaseHas('support_reports', [
            'id' => $report->id,
            'status' => 'resolved',
            'resolved_by' => $provider->id,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'support_report_status',
        ]);
    }
}
