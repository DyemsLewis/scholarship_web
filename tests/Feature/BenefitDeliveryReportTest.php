<?php

namespace Tests\Feature;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\Dashboards\BenefitReleaseOfficerDashboardQuery;
use App\Services\ApplicantApplicationDetailService;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientBenefitEntitlementService;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BenefitDeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->freezeTime();
    }

    public function test_missing_unscheduled_benefit_can_be_reported_without_blocking_the_first_actual_release(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $before = $application->fresh()->getRawOriginal();
        $report = $this->submit($applicant, $application, 'entitlement', $entitlement->id);
        $this->assertSame('awaiting_provider', $report->status);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertNull($report->agreement_accepted_at);
        $this->assertNull($report->agreement_version);
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $this->reply($report, 'correction_planned') + ['follow_up_on' => now()->addDays(2)->toDateString()])->assertOk()->assertJsonPath('report.status_label', 'Correction planned');
        $this->assertSame('ready', $entitlement->fresh()->status);
        $record = $this->release($provider, $application, $entitlement);
        $this->proposal($provider, $report->fresh(), $record);
        $this->assertSame('issue_open', $entitlement->fresh()->status);
        $this->assertNull($entitlement->fresh()->fulfilled_at);
        $detail = app(ApplicantApplicationDetailService::class)->build($application->fresh(), $applicant, 'summary');
        $this->assertSame('benefit-delivery-reports', $detail['decision']['action']['target']);
        $this->assertStringEndsWith('#benefit-delivery-reports', $detail['decision']['action']['href']);
        $this->confirm($applicant, $report->fresh());
        $this->assertSame('fulfilled', $entitlement->fresh()->status);
        $this->assertSame('confirmed', $record->receiptResponse()->sole()->status);
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_provider_cannot_close_a_report_and_applicant_can_dispute_proof_before_confirming(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $original = $record->getRawOriginal();
        $report = $this->submit($applicant, $application, 'release', $record->id);
        $receipt = $record->receiptResponse()->sole();
        $this->actingAs($provider)->postJson('/provider/benefit-receipt-responses/'.$receipt->id.'/resolve', [
            'resolution_outcome' => 'no_change', 'resolution_notes' => 'The provider believes no change is necessary.',
        ])->assertUnprocessable()->assertJsonPath('report.id', $report->id);
        $this->assertSame('open', $receipt->fresh()->status);
        $this->actingAs($applicant)->postJson('/dashboard/benefit-release-records/'.$record->id.'/response', [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
        ])->assertUnprocessable();
        $this->proposal($provider, $report, $record);
        $this->assertSame('issue_open', $entitlement->fresh()->status);
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'still_unresolved'))
            ->assertOk()->assertJsonPath('report.status', 'awaiting_provider');
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'receipt_confirmed') + ['received_on' => now()->toDateString()])->assertConflict();
        $this->proposal($provider, $report->fresh(), $record);
        $this->confirm($applicant, $report->fresh());
        $this->assertNotNull($receipt->fresh()->recipient_resolution_confirmed_at);
        $this->assertSame('resolved', $receipt->fresh()->status);
        $this->assertSame('fulfilled', $entitlement->fresh()->status);
        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertSame('not_assessed', $report->fresh()->finding);
        $this->assertFalse($report->fresh()->canShareWithOtherProviders());
    }

    public function test_actual_delivery_requires_matching_released_record_real_proof_and_nonfuture_dates(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $report = $this->submit($applicant, $application, 'release', $record->id);
        $body = $this->reply($report, 'delivery_proposed') + ['delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString()];
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $body)->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->actingAs($provider)->post($this->url($report).'/proposal', [...$body, 'delivered_on' => now()->addDay()->toDateString(), 'evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertUnprocessable();
        $record->update(['status' => 'prepared']);
        $this->actingAs($provider)->post($this->url($report).'/proposal', $body + ['evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('recipient_agreement_case_responses', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $record->update(['status' => 'released']);
        $this->proposal($provider, $report, $record);
        $proof = $report->evidence()->first();
        Storage::disk('local')->put($proof->path, 'changed bytes');
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'receipt_confirmed') + ['received_on' => now()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->assertSame('awaiting_recipient', $report->fresh()->status);
        $this->assertNull($entitlement->fresh()->fulfilled_at);
    }

    public function test_information_only_question_closes_without_fulfilling_the_benefit(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $report = $this->submit($applicant, $application, 'entitlement', $entitlement->id, ['issue_kind' => 'delivery_question']);
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $this->reply($report, 'information_provided'))->assertOk()->assertJsonPath('report.status', 'awaiting_recipient');
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'clarification_accepted'))->assertOk()->assertJsonPath('report.status', 'closed');
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertNull($entitlement->fresh()->fulfilled_at);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
    }

    public function test_recurring_period_is_required_and_does_not_dispute_an_unrelated_period_release(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award('monthly');
        $body = $this->body('entitlement', $entitlement->id);
        $this->actingAs($applicant)->postJson($this->history($application), $body)->assertUnprocessable()->assertJsonValidationErrors('benefit_period');
        $report = $this->submit($applicant, $application, 'entitlement', $entitlement->id, ['benefit_period' => 'August 2026']);
        $record = $this->release($provider, $application, $entitlement);
        $this->assertFalse(app(BenefitDeliveryReportService::class)->openForRecord($record)->exists());
        $this->assertSame('released', $record->allocations()->sole()->status);
        $this->assertSame('active', $entitlement->fresh()->status);
        $this->proposal($provider, $report, $record);
        $this->assertTrue(app(BenefitDeliveryReportService::class)->openForRecord($record)->exists());
        $this->confirm($applicant, $report->fresh());
        $this->assertSame('active', $entitlement->fresh()->status);
    }

    public function test_future_release_can_be_queried_but_not_reported_as_already_overdue(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $record->release->update(['release_at' => now()->addWeek()]);
        $record->update(['status' => 'scheduled']);
        $this->actingAs($applicant)->postJson($this->history($application), $this->body('release', $record->id))->assertUnprocessable()->assertJsonValidationErrors('issue_kind');
        $this->submit($applicant, $application, 'release', $record->id, ['issue_kind' => 'delivery_question']);
    }

    public function test_a_later_recurring_period_can_be_reported_even_after_an_earlier_receipt_was_confirmed(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award('monthly');
        $record = $this->release($provider, $application, $entitlement);
        $this->actingAs($applicant)->postJson('/dashboard/benefit-release-records/'.$record->id.'/response', ['response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true])->assertCreated();
        $original = $record->receiptResponse()->sole()->getRawOriginal();
        $this->actingAs($applicant)->getJson($this->history($application))->assertOk()->assertJsonPath('options.0.period_required', true);
        $report = $this->submit($applicant, $application, 'entitlement', $entitlement->id, ['benefit_period' => 'The following month']);
        $this->assertSame($original, $record->receiptResponse()->sole()->getRawOriginal());
        $this->assertSame('fulfilled', $record->allocations()->sole()->status);
        $this->assertFalse(app(BenefitDeliveryReportService::class)->openForRecord($record)->exists());
        $this->assertSame('active', $entitlement->fresh()->status);
        $this->assertSame('awaiting_provider', $report->status);
    }

    public function test_same_request_retries_are_idempotent_and_stale_or_forged_requests_are_rejected(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $body = $this->body('entitlement', $entitlement->id);
        $first = $this->actingAs($applicant)->postJson($this->history($application), $body)->assertCreated()->json('report.id');
        $this->actingAs($applicant)->postJson($this->history($application), $body)->assertCreated()->assertJsonPath('report.id', $first);
        $this->actingAs($applicant)->postJson($this->history($application), [...$body, 'details' => 'Different details using the same request key.'])->assertConflict();
        $report = RecipientAgreementCase::findOrFail($first);
        $reply = $this->reply($report, 'information_provided');
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $reply)->assertOk();
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $reply)->assertOk();
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', [...$reply, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('recipient_agreement_cases', 1);
        $this->assertDatabaseCount('recipient_agreement_case_responses', 1);
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'clarification_accepted'))->assertConflict();
    }

    public function test_closed_delivery_question_does_not_block_normal_receipt_confirmation(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $report = $this->submit($applicant, $application, 'release', $record->id, ['issue_kind' => 'delivery_question']);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
        $this->actingAs($provider)->postJson($this->url($report).'/proposal', $this->reply($report, 'information_provided'))->assertOk();
        $this->actingAs($applicant)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report->fresh(), 'clarification_accepted'))->assertOk();
        $this->actingAs($applicant)->postJson('/dashboard/benefit-release-records/'.$record->id.'/response', ['response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true])->assertCreated();
        $this->assertSame('fulfilled', $entitlement->fresh()->status);
    }

    public function test_reporting_a_previous_cancelled_entitlement_does_not_reactivate_support(): void
    {
        [, $applicant, $application, $entitlement] = $this->award();
        $application->update(['status' => 'benefits_terminated']);
        $entitlement->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->submit($applicant, $application, 'entitlement', $entitlement->id);
        $this->assertSame('cancelled', $entitlement->fresh()->status);
        $this->assertSame('benefits_terminated', $application->fresh()->status);
    }

    public function test_delivery_proof_cannot_belong_to_another_award_or_be_reused_for_another_recurring_period(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award('monthly');
        $first = $this->submit($applicant, $application, 'entitlement', $entitlement->id, ['benefit_period' => 'First period']);
        $second = $this->submit($applicant, $application, 'entitlement', $entitlement->id, ['benefit_period' => 'Second period']);
        $record = $this->release($provider, $application, $entitlement);
        $this->proposal($provider, $first, $record);
        $body = $this->reply($second, 'delivery_proposed') + ['delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString(), 'evidence' => [$this->pdf()]];
        $this->actingAs($provider)->post($this->url($second).'/proposal', $body, ['Accept' => 'application/json'])->assertUnprocessable();
        [$otherProvider, , $otherAward, $otherEntitlement] = $this->award();
        $otherRecord = $this->release($otherProvider, $otherAward, $otherEntitlement);
        $this->actingAs($provider)->post($this->url($second).'/proposal', [...$body, 'delivery_record_id' => $otherRecord->id, 'evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame('awaiting_provider', $second->fresh()->status);
    }

    public function test_unpublished_unreleased_sources_are_not_visible_or_reportable(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $record->update(['status' => 'scheduled']);
        $record->release->update(['published_at' => null]);
        $this->actingAs($applicant)->getJson($this->history($application))->assertOk()->assertJsonPath('options.0.type', 'entitlement');
        $this->actingAs($applicant)->postJson($this->history($application), $this->body('release', $record->id))->assertNotFound();
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
    }

    public function test_missed_appointment_can_be_resolved_using_a_real_replacement_release_without_rewriting_the_old_result(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $missed = $this->release($provider, $application, $entitlement);
        $missed->update(['status' => 'missed']);
        app(RecipientBenefitEntitlementService::class)->syncReleaseResult($missed->fresh());
        $original = $missed->fresh()->getRawOriginal();
        $report = $this->submit($applicant, $application, 'release', $missed->id);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $replacement = $this->release($provider, $application, $entitlement);
        $this->assertSame('issue_open', $entitlement->fresh()->status);
        $this->proposal($provider, $report, $replacement);
        $this->confirm($applicant, $report->fresh());
        $this->assertSame('fulfilled', $entitlement->fresh()->status);
        $this->assertSame($original, $missed->fresh()->getRawOriginal());
        $this->assertSame('confirmed', $replacement->receiptResponse()->sole()->status);
    }

    public function test_confirmation_of_one_report_does_not_fulfill_another_reported_benefit_in_the_same_release(): void
    {
        [$provider, $applicant, $application, $firstEntitlement] = $this->award();
        $application->scholarship->benefits()->create(['type' => 'school_supplies', 'title' => 'Supplies package', 'frequency' => 'one_time']);
        $application = $application->fresh();
        app(RecipientBenefitEntitlementService::class)->ensureForApplication($application);
        $secondEntitlement = $application->benefitEntitlements()->whereKeyNot($firstEntitlement->id)->sole();
        $first = $this->submit($applicant, $application, 'entitlement', $firstEntitlement->id);
        $second = $this->submit($applicant, $application, 'entitlement', $secondEntitlement->id);
        $record = $this->release($provider, $application, $firstEntitlement);
        app(RecipientBenefitEntitlementService::class)->allocate($record, $application->scholarship->benefits()->whereKey($secondEntitlement->scholarship_benefit_id)->get());
        app(RecipientBenefitEntitlementService::class)->syncReleaseResult($record->fresh());
        $this->proposal($provider, $first, $record);
        $this->confirm($applicant, $first->fresh());
        $this->assertSame('fulfilled', $firstEntitlement->fresh()->status);
        $this->assertSame('issue_open', $secondEntitlement->fresh()->status);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
        $this->proposal($provider, $second, $record);
        $this->confirm($applicant, $second->fresh());
        $this->assertSame('fulfilled', $secondEntitlement->fresh()->status);
        $this->assertSame('confirmed', $record->receiptResponse()->sole()->status);
    }

    public function test_schema_compiles_for_mysql_without_contacting_hosting(): void
    {
        $connection = new class(static function (): never {
            throw new \RuntimeException('No hosting connection is permitted.');
        }, 'schema_audit', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB', 'prefix_indexes' => true]) extends MySqlConnection {

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
            $migration = require database_path('migrations/2026_11_02_000003_add_benefit_delivery_reports.php');
            $queries = $connection->pretend(fn () => $migration->up());
            $this->assertNotEmpty($queries);
            foreach ($queries as $query) {
                preg_match_all('/(?:constraint|index|key|table) `([^`]+)`/i', $query['query'], $names);
                foreach ($names[1] as $name) {
                    $this->assertLessThanOrEqual(64, strlen($name), $name);
                }
            }
            $this->assertNotEmpty($connection->pretend(fn () => $migration->down()));
        } finally {
            Schema::swap($original);
        }
    }

    public function test_cross_account_roles_program_assignments_and_private_proof_are_scoped(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $report = $this->submit($applicant, $application, 'entitlement', $entitlement->id);
        $dashboard = app(BenefitReleaseOfficerDashboardQuery::class)->get($provider);
        $this->assertSame('/provider/workspaces/releases/delivery-reports', $dashboard['primary_action']['url']);
        $other = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($other)->getJson($this->url($report, 'dashboard'))->assertForbidden();
        $this->actingAs($other)->postJson($this->history($application), $this->body('entitlement', $entitlement->id))->assertForbidden();
        $wrongRole = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_recipients'], 'assigned_program_ids' => [$application->scholarship_id]]);
        $this->actingAs($wrongRole)->getJson($this->url($report))->assertForbidden();
        $unassigned = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_benefit_releases'], 'assigned_program_ids' => [99999]]);
        $this->actingAs($unassigned)->getJson($this->url($report))->assertForbidden();
        $record = $this->release($provider, $application, $entitlement);
        $this->proposal($provider, $report, $record);
        $proof = $report->evidence()->sole();
        $this->actingAs($other)->get('/dashboard/delivery-report-evidence/'.$proof->id)->assertForbidden();
        $this->actingAs($applicant)->get('/dashboard/delivery-report-evidence/'.$proof->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $payload = $this->actingAs($provider)->getJson($this->url($report))->assertOk()->getContent();
        foreach (['recipient-agreement-cases/', 'request_hash', 'agreement_snapshot', 'open_delivery_key'] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
        $this->actingAs($provider)->getJson('/provider/workspaces/releases/delivery-reports/data')->assertOk()->assertJsonCount(1, 'reports.data');
        $this->actingAs($unassigned)->getJson('/provider/workspaces/releases/delivery-reports/data')->assertOk()->assertJsonCount(0, 'reports.data');
    }

    public function test_existing_issue_and_provider_history_are_preserved_without_writes_on_get(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        Storage::disk('local')->put('earlier-provider.pdf', "%PDF-1.4\nEarlier proof");
        $receipt = $record->receiptResponse()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'response_type' => 'issue',
            'issue_type' => 'damaged_item', 'issue_details' => 'The device provided was damaged.', 'status' => 'resolved', 'responded_at' => now()->subDay(),
            'resolution_outcome' => 'no_change', 'resolution_notes' => 'Earlier provider explanation.', 'resolved_at' => now(), 'resolved_by' => $provider->id,
            'resolution_proof_path' => 'earlier-provider.pdf', 'resolution_proof_original_name' => 'earlier-provider.pdf', 'resolution_proof_mime_type' => 'application/pdf', 'resolution_proof_size' => 24,
        ]);
        $before = $receipt->fresh()->getRawOriginal();
        $this->actingAs($applicant)->getJson($this->history($application))->assertOk()->assertJsonCount(1, 'legacy_reports.data');
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
        $id = $this->actingAs($applicant)->postJson('/dashboard/delivery-receipts/'.$receipt->id.'/report')->assertOk()->assertJsonCount(1, 'report.responses')->json('report.id');
        $report = RecipientAgreementCase::findOrFail($id);
        $this->assertSame($before, $receipt->fresh()->getRawOriginal());
        $this->assertNull($report->agreement_accepted_at);
        $this->assertSame('legacy_provider_response', $report->responses()->sole()->response_kind);
        $this->proposal($provider, $report, $record);
        $this->confirm($applicant, $report->fresh());
        $this->assertStringContainsString('Earlier provider explanation.', $report->responses()->where('response_kind', 'legacy_provider_response')->sole()->body);
        $this->assertTrue(Storage::disk('local')->exists('earlier-provider.pdf'));
        $this->assertSame('The device provided was damaged.', $receipt->fresh()->issue_details);
    }

    public function test_database_failure_rolls_back_proof_statement_and_case_changes(): void
    {
        [$provider, $applicant, $application, $entitlement] = $this->award();
        $record = $this->release($provider, $application, $entitlement);
        $report = $this->submit($applicant, $application, 'release', $record->id);
        Event::listen('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent', fn () => throw new \RuntimeException('History storage unavailable'));
        $this->withoutExceptionHandling();
        try {
            app(BenefitDeliveryReportService::class)->propose($provider, $report, 1, $this->reply($report, 'delivery_proposed') + ['delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString()], [$this->pdf()]);
            $this->fail('Expected storage failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('History storage unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent');
        }
        $this->assertDatabaseCount('recipient_agreement_case_responses', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame(1, $report->fresh()->revision);
        $this->assertSame('awaiting_provider', $report->fresh()->status);
    }

    private function award(string $frequency = 'one_time'): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Delivery Test Award', 'description' => 'Delivery test.', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected', 'student_response_status' => 'accepted', 'application_state' => 'closed', 'workflow_stage' => 'complete', 'submitted_at' => now()->subMonth()]);
        $benefit = $program->benefits()->create(['type' => 'allowance', 'title' => 'Learning support', 'amount' => 2000, 'frequency' => $frequency]);
        app(RecipientBenefitEntitlementService::class)->ensureForApplication($application);

        return [$provider, $applicant, $application, $application->benefitEntitlements()->sole()];
    }

    private function release(User $provider, ScholarshipApplication $application, RecipientBenefitEntitlement $entitlement): RecipientBenefitReleaseRecord
    {
        $release = RecipientBenefitRelease::create(['scholarship_id' => $application->scholarship_id, 'created_by' => $provider->id, 'title' => 'Learning support delivery', 'benefit_description' => 'Assigned learning support', 'amount' => 2000, 'release_at' => now()->subDay(), 'release_method' => 'bank_transfer', 'status' => 'published', 'published_at' => now()->subDays(2)]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id, 'status' => 'released', 'recorded_by' => $provider->id, 'recorded_at' => now(), 'released_at' => now(), 'notes' => 'Original distribution record.']);
        app(RecipientBenefitEntitlementService::class)->allocate($record, $application->scholarship->benefits()->whereKey($entitlement->scholarship_benefit_id)->get());
        app(RecipientBenefitEntitlementService::class)->syncReleaseResult($record->fresh());

        return $record->fresh();
    }

    private function submit(User $actor, ScholarshipApplication $application, string $type, int $id, array $extra = []): RecipientAgreementCase
    {
        $id = $this->actingAs($actor)->postJson($this->history($application), [...$this->body($type, $id), ...$extra])->assertCreated()->json('report.id');

        return RecipientAgreementCase::findOrFail($id);
    }

    private function proposal(User $actor, RecipientAgreementCase $report, RecipientBenefitReleaseRecord $record): void
    {
        $this->actingAs($actor)->post($this->url($report).'/proposal', $this->reply($report, 'delivery_proposed') + ['delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString(), 'evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('report.status', 'awaiting_recipient');
    }

    private function confirm(User $actor, RecipientAgreementCase $report): void
    {
        $this->actingAs($actor)->postJson($this->url($report, 'dashboard').'/response', $this->reply($report, 'receipt_confirmed') + ['received_on' => now()->toDateString()])->assertOk()->assertJsonPath('report.status', 'closed');
    }

    private function body(string $type, int $id): array
    {
        return ['request_key' => (string) Str::uuid(), 'source_type' => $type, 'source_id' => $id, 'issue_kind' => 'not_received', 'details' => 'The assigned learning support has not reached me yet.', 'confirmed' => true];
    }

    private function reply(RecipientAgreementCase $report, string $kind): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $report->revision, 'response_kind' => $kind, 'details' => 'Delivery information and dates for the reported benefit.', 'confirmed' => true];
    }

    private function history(ScholarshipApplication $application): string
    {
        return '/dashboard/applications/'.$application->id.'/delivery-reports';
    }

    private function url(RecipientAgreementCase $report, string $role = 'provider'): string
    {
        return '/'.$role.'/delivery-reports/'.$report->id;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('delivery-proof.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
}
