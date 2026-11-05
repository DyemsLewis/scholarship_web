<?php

namespace Tests\Feature;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientCaseAccessLog;
use App\Models\RecipientCasePrivacyReview;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientAgreementCaseService;
use App\Services\RecipientCasePrivacyService;
use App\Services\RecipientCaseReviewService;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipientCasePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->freezeTime();
    }

    public function test_privacy_assessments_never_enable_sharing_or_modify_an_award_or_finding(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $before = $application->fresh()->getRawOriginal();
        $caseBefore = $case->getRawOriginal();
        $body = $this->body($case);
        $this->actingAs($admin)->postJson($this->url('admin', $case), $body + ['sharing_enabled' => true, 'disclosure_status' => 'approved'])->assertOk()->assertJsonPath('privacy.sharing_enabled', false)->assertJsonPath('privacy.assessment_state', 'recorded')->assertJsonCount(0, 'privacy.shared_fields');
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        foreach (['status', 'finding', 'outcome', 'disclosure_status', 'support_decision_id', 'review_state'] as $key) {
            $this->assertSame($caseBefore[$key], $case->fresh()->getRawOriginal($key));
        }
        $this->assertFalse($case->fresh()->canShareWithOtherProviders());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 1);
        $this->actingAs($provider)->getJson($this->url('provider', $case))->assertOk()->assertJsonPath('privacy.can_record', false)->assertDontSee('Internal assessment rationale')->assertDontSee('recorder_name')->assertDontSee('basis_reference');
        $this->actingAs($applicant)->getJson($this->url('dashboard', $case))->assertOk()->assertJsonPath('privacy.sharing_enabled', false)->assertDontSee('request_hash')->assertDontSee('Internal assessment rationale');
    }

    public function test_a_second_provider_has_no_access_even_with_the_same_applicant_and_a_forged_approved_flag(): void
    {
        [, $applicant, , , $case] = $this->concern();
        [$other, , , $otherApplication] = $this->concern();
        $otherApplication->update(['applicant_id' => $applicant->id]);
        $case->forceFill(['disclosure_status' => 'approved', 'finding' => 'confirmed'])->save();
        $this->actingAs($other)->getJson($this->url('provider', $case))->assertForbidden();
        $this->getJson('/provider/recipient-cases/'.$case->id)->assertForbidden();
        $this->get('/provider/recipient-case-evidence/'.$case->evidence()->sole()->id)->assertForbidden();
        $this->assertFalse($case->canShareWithOtherProviders());
    }

    public function test_privacy_access_requires_the_correct_role_active_account_and_program_assignment(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_recipients'], 'assigned_program_ids' => []]);
        $this->actingAs($staff)->getJson($this->url('provider', $case))->assertForbidden();
        $limited = User::factory()->create(['role' => 'admin', 'parent_account_id' => $admin->id, 'permissions' => ['manage_support']]);
        $this->actingAs($limited)->getJson($this->url('admin', $case))->assertForbidden();
        $this->actingAs($admin)->getJson($this->url('dashboard', $case))->assertForbidden();
        $provider->update(['account_status' => 'suspended']);
        $this->actingAs($provider->fresh())->getJson($this->url('provider', $case))->assertForbidden();
    }

    public function test_a_privacy_assessment_requires_reasons_a_recheck_and_explicit_acknowledgment_not_an_approval(): void
    {
        [, , $admin, , $case] = $this->concern();
        foreach ([['assessment' => 'approved'], ['rationale' => ['malformed']], ['purpose' => 'short'], ['confirmed' => false], ['recheck_on' => now()->subDay()->toDateString()], ['recheck_on' => now()->addYears(2)->toDateString()], ['audit_retention_hold' => null]] as $change) {
            $this->actingAs($admin)->postJson($this->url('admin', $case), [...$this->body($case), ...$change])->assertUnprocessable();
        }
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 0);
    }

    public function test_duplicate_and_stale_privacy_requests_are_safe_and_an_involved_party_cannot_self_assess(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $body = $this->body($case);
        $this->actingAs($admin)->postJson($this->url('admin', $case), $body)->assertOk();
        $this->postJson($this->url('admin', $case), $body)->assertOk();
        $this->postJson($this->url('admin', $case), [...$body, 'rationale' => 'The same key must never accept a different assessment.'])->assertConflict();
        $this->postJson($this->url('admin', $case), $this->body($case))->assertConflict();
        $provider->update(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $this->actingAs($provider->fresh())->postJson($this->url('admin', $case), $this->body($case->fresh()))->assertUnprocessable();
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 1);
    }

    public function test_case_changes_supersede_assessments_and_an_expired_recheck_does_not_release_a_hold(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $this->actingAs($admin)->postJson($this->url('admin', $case), [...$this->body($case), 'audit_retention_hold' => true])->assertOk();
        $this->travel(31)->days();
        $this->getJson($this->url('admin', $case))->assertOk()->assertJsonPath('privacy.assessment_state', 'recheck_due')->assertJsonPath('privacy.audit_retention_hold', true);
        $case = $case->fresh();
        app(RecipientCaseReviewService::class)->request($provider, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'details' => 'Please independently review the accepted term and response.', 'confirmed' => true]);
        $this->getJson($this->url('admin', $case))->assertOk()->assertJsonPath('privacy.assessment_state', 'superseded')->assertJsonPath('privacy.audit_retention_hold', true)->assertJsonPath('privacy.sharing_enabled', false);
    }

    public function test_an_appeal_supersedes_the_assessment_without_sharing_or_reversing_a_support_decision(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $service = app(RecipientCaseReviewService::class);
        $case = $service->request($provider, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'details' => 'Please independently review the accepted term and response.', 'confirmed' => true]);
        $case = $service->outcome($admin, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'details' => 'The source proof and response support this finding after independent review.', 'confirmed' => true, 'finding' => 'confirmed', 'recommendation' => 'no_support_change', 'evidence_ids' => $case->evidence()->pluck('id')->all(), 'terms_reviewed' => true, 'responses_reviewed' => true]);
        $this->actingAs($admin)->postJson($this->url('admin', $case), $this->body($case))->assertOk();
        $case = $case->fresh();
        $service->appeal($applicant, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'details' => 'Please review new information about the accepted obligation.', 'confirmed' => true]);
        $this->getJson($this->url('admin', $case))->assertOk()->assertJsonPath('privacy.assessment_state', 'superseded')->assertJsonPath('privacy.sharing_enabled', false);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
    }

    public function test_a_failed_case_history_write_rolls_back_the_privacy_assessment(): void
    {
        [, , $admin, , $case] = $this->concern();
        Event::listen('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent', fn () => throw new \RuntimeException('No history storage'));
        try {
            app(RecipientCasePrivacyService::class)->record($admin, $case, $case->revision, $this->body($case));
            $this->fail('Expected transaction rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('No history storage', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent');
        }
        $this->assertSame($case->revision, $case->fresh()->revision);
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 0);
    }

    public function test_case_and_evidence_access_is_minimally_audited_and_repeated_reads_are_coalesced(): void
    {
        [, $applicant, , , $case] = $this->concern();
        $this->actingAs($applicant)->getJson('/dashboard/recipient-cases/'.$case->id.'?secret=should-not-be-stored')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/dashboard/recipient-cases/'.$case->id)->assertOk();
        $this->get('/dashboard/recipient-case-evidence/'.$case->evidence()->sole()->id)->assertOk();
        $this->assertDatabaseCount('recipient_case_access_logs', 2);
        $this->travel(6)->minutes();
        $this->getJson('/dashboard/recipient-cases/'.$case->id)->assertOk();
        $this->assertDatabaseCount('recipient_case_access_logs', 3);
        $this->assertStringNotContainsString('should-not-be-stored', json_encode(DB::table('recipient_case_access_logs')->get()));
        $this->assertSame(['id', 'recipient_agreement_case_id', 'evidence_id', 'actor_id', 'actor_role', 'resource', 'route_name', 'outcome', 'dedupe_key', 'created_at'], Schema::getColumnListing('recipient_case_access_logs'));
    }

    public function test_access_history_is_scoped_and_nonadmins_do_not_receive_staff_names_or_denied_actors(): void
    {
        [, $applicant, $admin, , $case] = $this->concern();
        $other = User::factory()->create(['role' => 'provider']);
        $this->actingAs($admin)->getJson('/admin/recipient-cases/'.$case->id)->assertOk();
        $this->actingAs($other)->getJson('/provider/recipient-cases/'.$case->id)->assertForbidden();
        $this->actingAs($applicant)->getJson($this->url('dashboard', $case))->assertOk()->assertJsonCount(1, 'access.data')->assertJsonPath('access.data.0.actor_label', 'Platform review team')->assertDontSee('actor_name')->assertDontSee('Blocked access')->assertDontSee('dedupe_key');
        $this->actingAs($admin)->getJson($this->url('admin', $case))->assertOk()->assertJsonCount(3, 'access.data')->assertJsonPath('access.data.1.outcome_label', 'Blocked access')->assertJsonPath('access.data.1.actor_name', $other->name);
    }

    public function test_successful_private_reads_fail_closed_when_audit_storage_fails_but_denials_remain_denials(): void
    {
        [, $applicant, , , $case] = $this->concern();
        Event::listen('eloquent.creating: App\\Models\\RecipientCaseAccessLog', fn () => throw new \RuntimeException('No access storage'));
        try {
            $this->actingAs($applicant)->getJson('/dashboard/recipient-cases/'.$case->id)->assertStatus(503);
            $this->getJson($this->url('dashboard', $case))->assertStatus(503);
            $other = User::factory()->create(['role' => 'applicant']);
            $this->actingAs($other)->getJson('/dashboard/recipient-cases/'.$case->id)->assertForbidden();
            $this->getJson('/dashboard/recipient-cases/9999999')->assertNotFound();
            $this->get('/login')->assertRedirect();
        } finally {
            Event::forget('eloquent.creating: App\\Models\\RecipientCaseAccessLog');
        }
    }

    public function test_audit_retention_respects_latest_explicit_holds_and_does_not_delete_case_records(): void
    {
        [, $applicant, $admin, , $case] = $this->concern();
        $this->actingAs($applicant)->getJson('/dashboard/recipient-cases/'.$case->id)->assertOk();
        DB::table('recipient_case_access_logs')->update(['created_at' => now()->subDays(91)]);
        $this->actingAs($admin)->postJson($this->url('admin', $case), [...$this->body($case), 'audit_retention_hold' => true])->assertOk();
        $this->travel(31)->days();
        $this->artisan('case-privacy:prune-access')->assertSuccessful();
        $this->assertDatabaseCount('recipient_case_access_logs', 1);
        $this->actingAs($admin)->postJson($this->url('admin', $case), $this->body($case->fresh()))->assertOk();
        $this->actingAs($applicant)->get('/dashboard/recipient-case-evidence/'.$case->evidence()->sole()->id)->assertOk();
        $this->artisan('case-privacy:prune-access', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('recipient_case_access_logs', 2);
        $this->artisan('case-privacy:prune-access')->assertSuccessful();
        $this->assertDatabaseCount('recipient_case_access_logs', 1);
        $this->assertDatabaseCount('recipient_agreement_cases', 1);
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 2);
        Storage::disk('local')->assertExists($case->evidence()->sole()->path);
        $this->assertGreaterThan(0, $case->events()->count());
    }

    public function test_privacy_records_and_access_logs_cannot_be_edited_or_deleted_through_models(): void
    {
        [, $applicant, $admin, , $case] = $this->concern();
        $this->actingAs($admin)->postJson($this->url('admin', $case), $this->body($case))->assertOk();
        $this->actingAs($applicant)->getJson('/dashboard/recipient-cases/'.$case->id)->assertOk();
        foreach ([RecipientCasePrivacyReview::firstOrFail(), RecipientCaseAccessLog::firstOrFail()] as $row) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update' ? $row->forceFill(['created_at' => now()->subDay()])->save() : $row->delete();
                    $this->fail('Immutable record modified.');
                } catch (\LogicException $error) {
                    $this->assertStringContainsString('append-only', $error->getMessage());
                }
            }
        }
    }

    public function test_privacy_schema_compiles_for_mysql_without_a_hosting_connection(): void
    {
        $connection = new class(static fn () => throw new \RuntimeException('No network.'), 'audit', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB', 'prefix_indexes' => true]) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.36';
            }
        };
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_03_000002_add_recipient_case_privacy_controls.php');
            $queries = $connection->pretend(fn () => $migration->up());
            $this->assertNotEmpty($queries);
            foreach ($queries as $query) {
                preg_match_all('/(?:constraint|index|key|table) `([^`]+)`/i', $query['query'], $names);
                foreach ($names[1] as $name) {
                    $this->assertLessThanOrEqual(64, strlen($name));
                }
            }
            $this->assertNotEmpty($connection->pretend(fn () => $migration->down()));
        } finally {
            Schema::swap($original);
        }
    }

    private function concern(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Private Case Award', 'description' => 'Test award.', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete', 'student_response_status' => 'accepted', 'submitted_at' => now()->subMonth(), 'provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'Submit enrollment records.', 'noncompliance_consequence' => 'Support may end after notice, response, and review.']], 'provider_contract_terms_version' => 'privacy-v1', 'student_response_terms_version' => 'privacy-v1', 'provider_contract_terms_accepted_at' => now()->subWeeks(2)]);
        $service = app(RecipientAgreementCaseService::class);
        $case = $service->createDraft($provider, $application, 'agreement_concern', ['request_key' => (string) Str::uuid(), 'subject' => 'Enrollment record needs clarification', 'details' => 'Please explain the enrollment record for this award.', 'term_key' => 'recipient_expectation.responsibilities']);
        $service->attachDraftEvidence($provider, $case, 1, UploadedFile::fake()->createWithContent('enrollment.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"));
        $case = $service->issueConcern($provider, $case->fresh(), 2, ['response_days' => 7, 'confirmed' => true]);
        $case = $service->respondToConcern($applicant, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'response_kind' => 'explanation', 'details' => 'The enrollment record is available for independent review.', 'confirmed' => true]);

        return [$provider, $applicant, $admin, $application, $case];
    }

    private function url(string $prefix, RecipientAgreementCase $case): string
    {
        return '/'.$prefix.'/recipient-cases/'.$case->id.'/privacy';
    }

    private function body(RecipientAgreementCase $case): array
    {
        return ['expected_revision' => $case->revision, 'request_key' => (string) Str::uuid(), 'assessment' => 'blocked', 'audience' => 'originating_award', 'purpose' => 'Check necessity and privacy safeguards.', 'rationale' => 'Internal assessment rationale: disclosure is not authorized or necessary.', 'basis_reference' => 'Internal policy review, not authorization', 'audit_retention_hold' => false, 'recheck_on' => now()->addDays(30)->toDateString(), 'confirmed' => true];
    }
}
