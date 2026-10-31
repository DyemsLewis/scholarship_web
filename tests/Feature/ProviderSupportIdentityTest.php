<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderSupportIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    public function test_support_queue_loads_normalized_names_contact_and_photo_without_legacy_user_columns(): void
    {
        [, $staff, , , $report] = $this->supportCase();
        $this->assertFalse(Schema::hasColumn('users', 'first_name'));
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($staff)->getJson('/provider/workspaces/support/data')
            ->assertOk()
            ->assertJsonPath('next_task.applicant', 'Risa T. Dela Cruz')
            ->assertJsonPath('reports.0.id', $report->id)
            ->assertJsonPath('reports.0.applicant.name', 'Risa T. Dela Cruz')
            ->assertJsonPath('reports.0.applicant.contact_number', '09175550001')
            ->assertJsonPath('reports.0.applicant.profile_photo_url', route('provider.reports.applicant-photo', $report, false));

        // SQLite can accept quoted missing columns as strings, masking the MySQL failure.
        $userQueries = 0;
        foreach ($queries as $sql) {
            if (preg_match('/^select\s+(.*?)\s+from\s+["`]?([a-z_]+)["`]?(?:\s|$)/is', $sql, $match) && $match[2] === 'users') {
                $userQueries++;
                $this->assertDoesNotMatchRegularExpression('/\b(first_name|last_name|contact_number)\b/i', $match[1]);
            }
        }
        $this->assertGreaterThan(0, $userQueries);
        $this->assertSame('open', $report->fresh()->provider_status);
    }

    public function test_reporter_search_uses_profiles_and_preserves_program_and_organization_scope(): void
    {
        [$owner, $staff, $applicant, $program, $report] = $this->supportCase();
        $hiddenProgram = $this->program($owner, 'Other program');
        $hiddenReport = $this->incomingReport($owner, $applicant, $hiddenProgram);
        $otherOwner = User::factory()->create(['role' => 'provider']);
        $this->incomingReport($otherOwner, $applicant, $this->program($otherOwner, 'Outside program'));
        $staff->update(['assigned_program_ids' => [$program->id]]);

        foreach (['Risa', 'Dela Cruz', $applicant->email] as $search) {
            $this->actingAs($staff->fresh())
                ->getJson('/provider/workspaces/support/data?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.id', $report->id);
        }
        $this->actingAs($staff->fresh())->getJson('/provider/workspaces/support/data?search=does-not-match')
            ->assertOk()->assertJsonCount(0, 'reports');
        $this->getJson('/provider/workspaces/support/data?program_id='.$hiddenProgram->id)->assertForbidden();
        $this->getJson('/provider/workspaces/support/cases/'.$hiddenReport->id.'/data')->assertForbidden();
    }

    public function test_case_details_include_profile_names_for_managed_provider_and_admin_resolvers(): void
    {
        [, $staff, , , $report] = $this->supportCase();
        $admin = $this->admin();
        $report->update([
            'provider_status' => 'resolved', 'provider_resolved_by' => $staff->id, 'provider_resolved_at' => now(),
            'admin_status' => 'resolved', 'admin_resolved_by' => $admin->id, 'admin_resolved_at' => now(), 'status' => 'resolved',
        ]);

        $this->actingAs($staff)->getJson('/provider/workspaces/support/cases/'.$report->id.'/data')
            ->assertOk()
            ->assertJsonPath('report.applicant.name', 'Risa T. Dela Cruz')
            ->assertJsonPath('report.applicant.contact_number', '09175550001')
            ->assertJsonPath('report.provider_resolved_by', 'Mara Q. Santos')
            ->assertJsonPath('report.admin_resolved_by', 'Tala Reyes');
    }

    public function test_provider_submitted_reports_keep_staff_identity_and_can_be_searched_by_profile(): void
    {
        [, $staff, , $program] = $this->supportCase();
        $response = $this->actingAs($staff)->postJson('/provider/reports', [
            'category' => 'technical', 'scholarship_id' => $program->id,
            'subject' => 'Page does not finish loading', 'description' => 'The program page remains blank after refreshing.',
        ])->assertCreated()
            ->assertJsonPath('report.applicant.name', 'Mara Q. Santos')
            ->assertJsonPath('report.applicant.contact_number', '09175550002')
            ->assertJsonPath('report.submitted_by_provider', true)
            ->assertJsonPath('report.can_update_status', false);

        foreach (['Mara', 'Santos', 'Northbound Education Office'] as $search) {
            $this->getJson('/provider/workspaces/support/data?'.http_build_query(['queue' => 'submitted', 'search' => $search]))
                ->assertOk()->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.id', $response->json('report.id'));
        }
    }

    public function test_support_responses_keep_independent_resolution_states_and_correct_identity_payloads(): void
    {
        [, $staff, $applicant, , $report] = $this->supportCase();
        $admin = $this->admin();
        $this->actingAs($staff)->patchJson('/provider/reports/'.$report->id.'/status', [
            'status' => 'resolved', 'provider_response' => 'Please submit the enrollment certificate issued by your school.',
        ])->assertOk()
            ->assertJsonPath('report.provider_status', 'resolved')
            ->assertJsonPath('report.admin_status', 'open')
            ->assertJsonPath('report.overall_status', 'open')
            ->assertJsonPath('report.applicant.name', 'Risa T. Dela Cruz')
            ->assertJsonPath('report.provider_resolved_by', 'Mara Q. Santos');

        $this->actingAs($admin)->patchJson('/admin/reports/'.$report->id.'/status', ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('report.overall_status', 'resolved')->assertJsonPath('report.admin_resolved_by', 'Tala Reyes');
        $this->getJson('/admin/reports/data?status=resolved')->assertOk()
            ->assertJsonPath('reports.0.applicant.name', 'Risa T. Dela Cruz')
            ->assertJsonPath('reports.0.provider_resolved_by', 'Mara Q. Santos');
        $this->actingAs($applicant)->getJson('/dashboard/reports/data')->assertOk()
            ->assertJsonPath('reports.0.overall_status', 'resolved')->assertJsonMissingPath('reports.0.applicant');
    }

    public function test_support_cases_still_reject_other_organizations_and_staff_without_support_permission(): void
    {
        [$owner, , , , $report] = $this->supportCase();
        $otherOwner = User::factory()->create(['role' => 'provider']);
        $this->actingAs($otherOwner)->getJson('/provider/workspaces/support/cases/'.$report->id.'/data')->assertForbidden();
        $staff = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => ['manage_programs'],
        ]);
        $this->actingAs($staff)->getJson('/provider/workspaces/support/data')->assertForbidden();
        $this->getJson('/provider/workspaces/support/cases/'.$report->id.'/data')->assertForbidden();
    }

    private function supportCase(): array
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['provider_name' => 'Northbound Education Office']);
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => ['manage_reports']]);
        $staff->providerProfile()->update([
            'first_name' => 'Mara', 'middle_initial' => 'Q', 'last_name' => 'Santos',
            'provider_name' => 'Northbound Education Office', 'contact_number' => '09175550002',
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update([
            'first_name' => 'Risa', 'middle_initial' => 'T', 'last_name' => 'Dela Cruz', 'suffix' => null,
            'contact_number' => '09175550001', 'profile_photo_path' => 'support/profile.jpg',
            'profile_photo_original_name' => 'profile.jpg', 'profile_photo_mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('support/profile.jpg', 'Test profile image');
        $program = $this->program($owner, 'Support identity grant');

        return [$owner->fresh(), $staff->fresh(), $applicant->fresh(), $program, $this->incomingReport($owner, $applicant, $program)];
    }

    private function program(User $owner, string $title): Scholarship
    {
        return Scholarship::create(['provider_id' => $owner->id, 'title' => $title, 'description' => 'Support scope fixture.', 'status' => 'published']);
    }

    private function incomingReport(User $owner, User $applicant, Scholarship $program): SupportReport
    {
        return SupportReport::create([
            'applicant_id' => $applicant->id, 'provider_id' => $owner->id, 'scholarship_id' => $program->id,
            'assigned_role' => 'provider', 'category' => 'program', 'subject' => 'Enrollment clarification',
            'description' => 'Please explain the enrollment document requirement.',
            'status' => 'open', 'provider_status' => 'open', 'admin_status' => 'open',
        ]);
    }

    private function admin(): User
    {
        $root = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_reports']]);
        $admin->adminProfile()->update(['first_name' => 'Tala', 'middle_initial' => null, 'last_name' => 'Reyes', 'display_name' => 'Tala Reyes']);

        return $admin->fresh();
    }
}
