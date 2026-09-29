<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_filtered_monitoring_oversight_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$provider, $scholarship, $application] = $this->recipientRecord('Admin Export Recipient');
        $cycle = RecipientMonitoringCycle::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'Overdue semester update',
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'due_at' => now()->subDay()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'published',
            'published_at' => now()->subMonth(),
        ]);
        RecipientMonitoringCycleRequirement::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'type' => 'grade_report',
            'title' => 'Semester grade report',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/export/monitoring?status=attention')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Application ID', $content);
        $this->assertStringContainsString('Admin Export Recipient', $content);
        $this->assertStringContainsString('Requirement is overdue', $content);

        $this->actingAs($admin)
            ->get("/admin/export/monitoring?application_id={$application->id}")
            ->assertOk()
            ->assertDownload("recipient-monitoring-application-{$application->id}.csv");
    }

    public function test_provider_export_is_scoped_to_its_program(): void
    {
        [$provider, $scholarship] = $this->recipientRecord('Provider Export Recipient');
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $otherProvider->providerProfile()->update(['verification_status' => 'approved']);

        $response = $this->actingAs($provider)
            ->get("/provider/scholarships/{$scholarship->id}/monitoring/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Agreement Status', $content);
        $this->assertStringContainsString('Provider Export Recipient', $content);

        $this->actingAs($otherProvider)
            ->get("/provider/scholarships/{$scholarship->id}/monitoring/export")
            ->assertForbidden();
    }

    private function recipientRecord(string $applicantName): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Tulay Aral Community Foundation',
            'verification_status' => 'approved',
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update([
            'first_name' => $applicantName,
            'last_name' => 'Student',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Monitoring Export',
            'description' => 'A monitored scholarship used to verify reporting.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'document_checklist' => [],
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'submitted_at' => now()->subMonth(),
        ]);

        return [$provider, $scholarship, $application];
    }
}
