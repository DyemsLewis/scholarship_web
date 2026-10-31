<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSupportRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    public function test_admin_save_returns_the_complete_workspace_record_without_completing_provider_work(): void
    {
        [$officer, $provider, $applicant, $report] = $this->supportCase();
        $this->actingAs($officer)->patchJson("/admin/reports/{$report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'The platform upload process was checked and is working.',
        ])->assertOk()
            ->assertJsonPath('report.admin_status', 'resolved')
            ->assertJsonPath('report.overall_status', 'open')
            ->assertJsonPath('support_case.state', 'waiting')
            ->assertJsonPath('support_case.provider_status', 'open')
            ->assertJsonPath('support_case.provider_name', 'Northbridge Learning Office')
            ->assertJsonPath('support_case.reporter.name', $applicant->fresh()->name)
            ->assertJsonPath('support_case.admin_resolved_by', $officer->fresh()->name)
            ->assertJsonPath('support_case.attachment.original_name', 'support-case-upload-screen-evidence.pdf')
            ->assertJsonPath('support_case.attachment.view_url', "/admin/reports/{$report->id}/attachment");

        $this->getJson('/admin/workspaces/support/summary')->assertOk()
            ->assertJsonPath('summary.waiting', 1)->assertJsonPath('summary.resolved', 0);
        $this->assertSame('open', $report->fresh()->provider_status);
        $this->assertNull($report->fresh()->provider_resolved_by);
        $this->assertSame($provider->id, $report->fresh()->provider_id);
    }

    public function test_reopening_only_clears_platform_response_and_keeps_provider_resolution(): void
    {
        [$officer, $provider, , $report] = $this->supportCase();
        $this->actingAs($provider)->patchJson("/provider/reports/{$report->id}/status", [
            'status' => 'resolved', 'provider_response' => 'Please submit the current enrollment certificate.',
        ])->assertOk()->assertJsonPath('report.overall_status', 'open')->assertJsonMissingPath('support_case');
        $this->actingAs($officer)->patchJson("/admin/reports/{$report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'The submitted record is accessible and the case is complete.',
        ])->assertOk()->assertJsonPath('support_case.state', 'resolved');

        $providerState = $report->fresh()->only(['provider_status', 'provider_response', 'provider_resolved_by', 'provider_resolved_at']);
        $this->patchJson("/admin/reports/{$report->id}/status", ['status' => 'open', 'admin_response' => null])
            ->assertOk()->assertJsonPath('support_case.state', 'needs_response')
            ->assertJsonPath('support_case.admin_response', null)
            ->assertJsonPath('support_case.admin_resolved_by', null)
            ->assertJsonPath('support_case.provider_status', 'resolved')
            ->assertJsonPath('support_case.provider_response', 'Please submit the current enrollment certificate.');
        $this->assertEquals($providerState, $report->fresh()->only(array_keys($providerState)));
        $this->assertSame('open', $report->fresh()->status);
    }

    public function test_provider_submitted_case_closes_with_platform_response_only_and_keeps_organization_logo(): void
    {
        [$officer, $provider, , $report] = $this->supportCase();
        $provider->providerProfile()->update(['logo_path' => 'images/test-provider-logo.svg']);
        $staff = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_reports'],
        ]);
        $staff->providerProfile()->update(['first_name' => 'Lina', 'last_name' => 'Cruz', 'logo_path' => null, 'provider_name' => null]);
        $report->update(['applicant_id' => $staff->id, 'assigned_role' => 'admin', 'provider_status' => 'not_required']);

        $this->actingAs($officer)->getJson('/admin/workspaces/support/data?'.http_build_query(['search' => 'Northbridge Learning Office']))
            ->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.reporter.name', $staff->fresh()->name)
            ->assertJsonPath('items.0.reporter.type', 'Provider team')
            ->assertJsonPath('items.0.reporter.organization_name', 'Northbridge Learning Office')
            ->assertJsonPath('items.0.reporter.image_url', asset('images/test-provider-logo.svg'));
        $this->patchJson("/admin/reports/{$report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'The provider access issue has been checked and resolved.',
        ])->assertOk()->assertJsonPath('support_case.state', 'resolved')
            ->assertJsonPath('support_case.provider_status', 'not_required')
            ->assertJsonPath('support_case.requires_provider', false);
    }

    public function test_search_uses_role_profiles_instead_of_missing_legacy_identity_columns(): void
    {
        [$officer, , $applicant, $report] = $this->supportCase();
        $applicant->studentProfile()->update(['first_name' => 'Mira', 'last_name' => 'Cruz']);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['Mira', 'Cruz', $applicant->email, 'Refinement support grant'] as $search) {
            $this->actingAs($officer)->getJson('/admin/workspaces/support/data?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $report->id);
        }
        foreach ($queries as $sql) {
            if (preg_match('/^select\s+(.*?)\s+from\s+["`]?users["`]?(?:\s|$)/is', $sql, $match)) {
                $this->assertDoesNotMatchRegularExpression('/\b(first_name|last_name|contact_number)\b/i', $match[1]);
            }
        }
        $this->getJson('/admin/workspaces/support/data?search=unrelated-person')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_attachment_remains_private_and_non_support_roles_cannot_access_or_change_cases(): void
    {
        [$officer, $provider, , $report] = $this->supportCase();
        $this->actingAs($officer)->get("/admin/reports/{$report->id}/attachment")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($provider)->get("/provider/reports/{$report->id}/attachment")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $otherAdmin = User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $officer->parent_account_id, 'permissions' => ['manage_accounts'],
        ]);
        foreach (['/admin/workspaces/support/data', "/admin/workspaces/support/cases/{$report->id}/data", "/admin/reports/{$report->id}/attachment"] as $url) {
            $this->actingAs($otherAdmin)->getJson($url)->assertForbidden();
        }
        $this->patchJson("/admin/reports/{$report->id}/status", ['status' => 'resolved'])->assertForbidden();
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $this->actingAs($otherProvider)->get("/provider/reports/{$report->id}/attachment")->assertForbidden();
        $this->assertSame('open', $report->fresh()->status);
    }

    public function test_queue_order_is_stable_when_cases_share_timestamps(): void
    {
        [$officer, , , $report] = $this->supportCase();
        $second = $report->replicate();
        $second->created_at = $report->created_at;
        $second->updated_at = $report->updated_at;
        $second->save();
        $this->actingAs($officer)->getJson('/admin/workspaces/support/data?queue=needs_response')
            ->assertOk()->assertJsonPath('items.0.id', $report->id)->assertJsonPath('items.1.id', $second->id);
    }

    public function test_legacy_provider_only_case_is_visible_in_waiting_queue_and_can_finish_without_platform_work(): void
    {
        [$officer, $provider, , $report] = $this->supportCase();
        $report->update(['admin_status' => 'not_required']);
        $this->actingAs($officer)->getJson('/admin/workspaces/support/data?queue=waiting')
            ->assertOk()->assertJsonPath('items.0.id', $report->id)->assertJsonPath('items.0.state', 'waiting')
            ->assertJsonPath('items.0.coordination_label', 'Provider support');
        $this->getJson("/admin/workspaces/support/cases/{$report->id}/data")
            ->assertOk()->assertJsonPath('report.admin_status_label', 'Not Required');

        $this->actingAs($provider)->patchJson("/provider/reports/{$report->id}/status", [
            'status' => 'resolved', 'provider_response' => 'The current document requirement has been explained to the applicant.',
        ])->assertOk()->assertJsonPath('report.overall_status', 'resolved');
        $this->assertSame('not_required', $report->fresh()->admin_status);
        $this->assertNull($report->fresh()->admin_resolved_by);
        $this->assertSame($provider->id, $report->fresh()->resolved_by);
    }

    public function test_routing_json_has_a_readable_context_label_without_rendering_internal_identifiers(): void
    {
        [$officer, , , $report] = $this->supportCase();
        $this->actingAs($officer);
        foreach ([
            'Documents page' => 'Documents page',
            json_encode(['reporter_id' => 123, 'source' => 'provider_workspace']) => 'Provider Workspace',
            json_encode(['reporter_id' => 123]) => null,
        ] as $context => $label) {
            $report->update(['context' => $context]);
            $this->getJson("/admin/workspaces/support/cases/{$report->id}/data")->assertOk()
                ->assertJsonPath('report.context_label', $label);
        }
    }

    private function supportCase(): array
    {
        $root = User::factory()->create(['role' => 'admin']);
        $officer = User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_reports'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['provider_name' => 'Northbridge Learning Office']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Refinement support grant',
            'description' => 'A support test program.', 'status' => 'published',
        ]);
        Storage::disk('local')->put('support-reports/test/evidence.pdf', 'Test support evidence');
        $report = SupportReport::create([
            'applicant_id' => $applicant->id, 'provider_id' => $provider->id, 'scholarship_id' => $program->id,
            'assigned_role' => 'provider', 'category' => 'program', 'subject' => 'School document clarification',
            'description' => 'Please explain the current enrollment requirement.',
            'status' => 'open', 'provider_status' => 'open', 'admin_status' => 'open',
            'attachment_path' => 'support-reports/test/evidence.pdf',
            'attachment_original_name' => 'support-case-upload-screen-evidence.pdf',
            'attachment_mime_type' => 'application/pdf', 'attachment_size' => 21,
        ]);

        return [$officer, $provider, $applicant, $report];
    }
}
