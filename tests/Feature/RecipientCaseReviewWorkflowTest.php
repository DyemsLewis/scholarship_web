<?php

namespace Tests\Feature;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientCaseReview;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientAgreementCaseService;
use App\Services\RecipientBenefitEntitlementService;
use App\Services\RecipientCaseReviewService;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipientCaseReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->freezeTime();
    }

    public function test_an_independent_review_records_reasons_without_applying_a_support_penalty(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $before = $application->fresh()->getRawOriginal();
        $this->act($provider, $case, 'request')->assertOk()->assertJsonPath('case.review_state', 'requested');
        $this->actingAs($admin)->getJson('/admin/workspaces/reviews/recipient-cases/data')->assertOk()->assertJsonCount(1, 'cases.data');
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertOk()->assertJsonPath('case.review_state', 'reviewed');
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $review = $case->fresh()->latestReview;
        $this->assertSame('confirmed', $review->finding);
        $this->assertSame('provider_decision_review', $review->recommendation);
        $this->assertFalse($case->fresh()->canShareWithOtherProviders());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->actingAs($applicant)->getJson('/dashboard/recipient-cases/'.$case->id)->assertOk()->assertJsonPath('case.can_appeal', true)->assertJsonCount(1, 'case.reviews');
    }

    public function test_an_applicant_gets_the_response_opportunity_and_silence_does_not_prove_a_concern(): void
    {
        [$provider, $applicant, $admin, , $case] = $this->concern(false);
        $this->act($provider, $case, 'request')->assertUnprocessable();
        $this->travel(8)->days();
        $this->act($provider, $case->fresh(), 'request')->assertOk();
        $this->assertSame('not_assessed', $case->fresh()->finding);
        $this->act($admin, $case->fresh(), 'information')->assertOk()->assertJsonPath('case.review_state', 'awaiting_information');
        $this->act($applicant, $case->fresh(), 'statement', [], [$this->pdf('explanation.pdf')])->assertOk()->assertJsonPath('case.review_state', 'requested');
        $this->act($admin, $case->fresh(), 'outcome', [...$this->outcome($case), 'finding' => 'excused', 'recommendation' => 'support_plan'])->assertOk();
        $this->assertSame('excused', $case->fresh()->finding);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
    }

    public function test_appeals_require_another_reviewer_and_preserve_the_original_outcome(): void
    {
        [$provider, $applicant, $admin, , $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertOk();
        $original = $case->fresh()->latestReview->getRawOriginal();
        $this->act($applicant, $case->fresh(), 'appeal')->assertOk()->assertJsonPath('case.review_state', 'appeal_pending');
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->act($admin, $case->fresh(), 'information')->assertUnprocessable();
        $other = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $this->act($other, $case->fresh(), 'information')->assertOk();
        $this->assertSame('appeal_pending', $case->fresh()->review_state);
        $this->act($other, $case->fresh(), 'outcome', [...$this->outcome($case), 'finding' => 'not_established', 'recommendation' => 'no_support_change'])->assertOk()->assertJsonCount(2, 'case.reviews');
        $this->assertSame($original, RecipientCaseReview::findOrFail($original['id'])->getRawOriginal());
        $this->assertTrue($case->fresh()->latestReview->is_appeal);
        $this->assertSame('not_established', $case->fresh()->finding);
    }

    public function test_case_based_termination_requires_a_current_review_and_an_expired_appeal_opportunity(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $decision = ['decision' => 'terminated', 'reason_category' => 'document_noncompliance', 'effective_on' => now()->toDateString(), 'notice_given_on' => now()->toDateString(), 'reason' => 'Reviewed failure to complete the accepted enrollment obligation.', 'confirmed' => true];
        $this->actingAs($provider)->postJson('/provider/applications/'.$application->id.'/support-decision', $decision)->assertUnprocessable()->assertJsonValidationErrors('agreement_case_review_id');
        $this->act($provider, $case, 'request')->assertOk();
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertOk();
        $review = $case->fresh()->latestReview;
        $this->actingAs($provider)->postJson('/provider/applications/'.$application->id.'/support-decision', $decision + ['agreement_case_review_id' => $review->id])->assertUnprocessable();
        $this->travel(15)->days();
        $decision['effective_on'] = $decision['notice_given_on'] = now()->toDateString();
        $this->actingAs($provider)->postJson('/provider/applications/'.$application->id.'/support-decision', $decision + ['agreement_case_review_id' => $review->id])->assertOk();
        $this->assertSame('benefits_terminated', $application->fresh()->status);
        $this->assertDatabaseHas('recipient_support_decisions', ['scholarship_application_id' => $application->id, 'agreement_case_review_id' => $review->id]);
        $this->assertNotNull($case->fresh()->support_decision_id);
        $this->act($applicant, $case->fresh(), 'appeal')->assertOk();
        $this->assertSame('benefits_terminated', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_case_reviews', 1);
        $decisionId = $case->fresh()->support_decision_id;
        $restore = ['resolution_outcome' => 'support_reinstated', 'resolution_notes' => 'Support will resume after the independent appeal review.', 'support_ends_on' => now()->addMonths(3)->toDateString(), 'next_period_terms' => 'The original agreed enrollment duties continue during the support period.'];
        $this->actingAs($provider)->postJson('/provider/support-decisions/'.$decisionId.'/resolve', $restore)->assertUnprocessable();
        $other = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $this->act($other, $case->fresh(), 'outcome', [...$this->outcome($case), 'finding' => 'excused', 'recommendation' => 'no_support_change'])->assertOk();
        $this->assertSame('benefits_terminated', $application->fresh()->status);
        $this->actingAs($provider)->postJson('/provider/support-decisions/'.$decisionId.'/resolve', $restore)->assertOk();
        $this->assertSame('renewed', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_case_reviews', 2);
    }

    public function test_pending_appeals_and_foreign_or_neutral_reviews_cannot_authorize_termination(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertOk();
        $reviewId = $case->fresh()->latest_review_id;
        $this->travel(15)->days();
        $this->act($applicant, $case->fresh(), 'appeal')->assertOk();
        try {
            app(RecipientCaseReviewService::class)->authorizeSupportDecision($application, ['decision' => 'terminated', 'reason_category' => 'other', 'agreement_case_review_id' => $reviewId]);
            $this->fail('Appeal bypassed.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('agreement_case_review_id', $error->errors());
        }
        [, , , $otherApplication] = $this->concern();
        try {
            app(RecipientCaseReviewService::class)->authorizeSupportDecision($otherApplication, ['decision' => 'terminated', 'reason_category' => 'misrepresentation', 'agreement_case_review_id' => $reviewId]);
            $this->fail('Foreign award bypassed.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('agreement_case_review_id', $error->errors());
        }
    }

    public function test_review_requires_cited_unchanged_case_proof_real_terms_and_explicit_acknowledgments(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        $this->act($admin, $case->fresh(), 'outcome', [...$this->outcome($case), 'evidence_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('evidence_ids');
        $this->act($admin, $case->fresh(), 'outcome', [...$this->outcome($case), 'responses_reviewed' => false])->assertUnprocessable();
        $proof = $case->evidence()->first();
        Storage::disk('local')->put($proof->path, 'tampered');
        $this->act($admin, $case->fresh(), 'outcome', $this->outcome($case))->assertUnprocessable();
        $this->assertDatabaseCount('recipient_case_reviews', 0);
        $this->assertSame('not_assessed', $case->fresh()->finding);
    }

    public function test_duplicate_and_stale_commands_do_not_create_duplicate_reviews_or_notifications(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $body = $this->body($case);
        $this->actingAs($provider)->postJson('/provider/recipient-cases/'.$case->id.'/request', $body)->assertOk();
        $this->actingAs($provider)->postJson('/provider/recipient-cases/'.$case->id.'/request', $body)->assertOk();
        $this->actingAs($provider)->postJson('/provider/recipient-cases/'.$case->id.'/request', [...$body, 'details' => 'Different contents of the same request key.'])->assertConflict();
        $this->act($admin, $case, 'outcome', $this->outcome($case))->assertConflict();
        $this->assertDatabaseCount('recipient_case_reviews', 0);
    }

    public function test_malformed_review_text_is_validation_feedback_not_a_server_error(): void
    {
        [$provider, , , , $case] = $this->concern();
        $this->act($provider, $case, 'request', ['details' => ['not a string']])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertNull($case->fresh()->review_state);
    }

    public function test_changing_a_party_to_an_admin_role_does_not_allow_self_review(): void
    {
        [$provider, , , , $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        $provider->update(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $this->act($provider->fresh(), $case->fresh(), 'outcome', $this->outcome($case))->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('recipient_case_reviews', 0);
    }

    public function test_reviews_are_hidden_from_other_applicants_other_providers_and_unprivileged_admins(): void
    {
        [$provider, $applicant, $admin, $application, $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        foreach ([User::factory()->create(['role' => 'applicant']), User::factory()->create(['role' => 'provider']), User::factory()->create(['role' => 'admin', 'parent_account_id' => $admin->id, 'permissions' => ['manage_support']])] as $other) {
            $prefix = $other->isApplicant() ? 'dashboard' : ($other->isProvider() ? 'provider' : 'admin');
            $this->actingAs($other)->getJson('/'.$prefix.'/recipient-cases/'.$case->id)->assertForbidden();
        }
        $this->actingAs($admin)->getJson('/admin/recipient-cases/'.$case->id)->assertOk()->assertDontSee('request_hash')->assertDontSee('recipient-agreement-cases/');
        $file = $case->evidence()->first();
        $this->actingAs($applicant)->get('/dashboard/recipient-case-evidence/'.$file->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_funding_remains_separate_from_misconduct_and_direct_withdrawal_is_blocked(): void
    {
        [, , , $application] = $this->concern();
        $service = app(RecipientCaseReviewService::class);
        $this->assertNull($service->authorizeSupportDecision($application, ['decision' => 'terminated', 'reason_category' => 'provider_funding_ended']));
        $this->assertDatabaseCount('recipient_case_reviews', 0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(RecipientCaseReviewService::WITHDRAWAL_REQUEST_REQUIRED);
        $service->authorizeSupportDecision($application, ['decision' => 'terminated', 'reason_category' => 'recipient_withdrew']);
    }

    public function test_delivery_review_cannot_penalize_the_applicant_or_overwrite_confirmed_receipt(): void
    {
        [$provider, $applicant, $admin, $application] = $this->concern();
        $releaseOfficer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_benefit_releases'], 'assigned_program_ids' => [$application->scholarship_id]]);
        $benefit = $application->scholarship->benefits()->create(['type' => 'allowance', 'title' => 'Learning support', 'frequency' => 'one_time']);
        $entitlements = app(RecipientBenefitEntitlementService::class);
        $application = $application->fresh();
        $entitlements->ensureForApplication($application);
        $entitlement = $application->benefitEntitlements()->sole();
        $delivery = app(BenefitDeliveryReportService::class);
        $report = $delivery->submit($applicant, $application, ['request_key' => (string) Str::uuid(), 'source_type' => 'entitlement', 'source_id' => $entitlement->id, 'issue_kind' => 'not_received', 'details' => 'The assigned benefit has not yet arrived.', 'confirmed' => true]);
        $this->act($applicant, $report, 'request')->assertOk();
        $this->act($admin, $report->fresh(), 'outcome', [...$this->outcome($report), 'evidence_ids' => []], [$this->pdf()])->assertUnprocessable()->assertJsonValidationErrors('recommendation');
        $release = RecipientBenefitRelease::create(['scholarship_id' => $application->scholarship_id, 'created_by' => $provider->id, 'title' => 'Learning allowance', 'release_at' => now()->subDay(), 'benefit_description' => 'Assigned allowance', 'release_method' => 'bank_transfer', 'status' => 'completed', 'published_at' => now()]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'released', 'recorded_by' => $provider->id, 'released_at' => now()]);
        $entitlements->allocate($record, $application->scholarship->benefits()->whereKey($benefit->id)->get());
        $entitlements->syncReleaseResult($record->fresh());
        $report = $delivery->propose($provider, $report->fresh(), $report->fresh()->revision, ['request_key' => (string) Str::uuid(), 'response_kind' => 'delivery_proposed', 'details' => 'Actual delivery is supported by this proof.', 'delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString(), 'confirmed' => true], [$this->pdf()]);
        $report = $delivery->respond($applicant, $report, $report->revision, ['request_key' => (string) Str::uuid(), 'response_kind' => 'receipt_confirmed', 'received_on' => now()->toDateString(), 'confirmed' => true]);
        $before = $entitlement->fresh()->getRawOriginal();
        $receipt = $record->receiptResponse()->sole()->getRawOriginal();
        $this->act($admin, $report, 'outcome', [...$this->outcome($report), 'recommendation' => 'delivery_follow_up'])->assertOk();
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $releaseOfficer->id, 'type' => 'recipient_case_review', 'action_url' => '/provider/workspaces/releases/delivery-reports?status=all']);
        $this->assertSame('closed', $report->fresh()->status);
        $this->assertSame('receipt_confirmed', $report->fresh()->outcome);
        $this->assertSame($before, $entitlement->fresh()->getRawOriginal());
        $this->assertSame($receipt, $record->receiptResponse()->sole()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
    }

    public function test_failed_audit_write_rolls_back_the_review_and_new_private_proof(): void
    {
        [$provider, , $admin, , $case] = $this->concern();
        $this->act($provider, $case, 'request')->assertOk();
        $beforeFiles = Storage::disk('local')->allFiles();
        Event::listen('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent', fn () => throw new \RuntimeException('Review history unavailable'));
        try {
            app(RecipientCaseReviewService::class)->outcome($admin, $case->fresh(), $case->fresh()->revision, [...$this->body($case->fresh()), ...$this->outcome($case)], [$this->pdf('new-proof.pdf')]);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Review history unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: App\\Models\\RecipientAgreementCaseEvent');
        }
        $this->assertDatabaseCount('recipient_case_reviews', 0);
        $this->assertSame('requested', $case->fresh()->review_state);
        $this->assertSame($beforeFiles, Storage::disk('local')->allFiles());
    }

    public function test_review_schema_compiles_for_mysql_without_a_network_connection(): void
    {
        $connection = new class(static function (): never {
            throw new \RuntimeException('No hosting connection permitted.');
        }, 'audit', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB', 'prefix_indexes' => true]) extends MySqlConnection {

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
            $migration = require database_path('migrations/2026_11_03_000001_add_recipient_case_reviews.php');
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

    private function concern(bool $respond = true): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Case Review Award', 'description' => 'Test award.', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete', 'student_response_status' => 'accepted', 'submitted_at' => now()->subMonth(),
            'provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'Submit enrollment records.', 'noncompliance_consequence' => 'Support may end after notice, response, and review.']], 'provider_contract_terms_version' => 'review-v1', 'student_response_terms_version' => 'review-v1', 'provider_contract_terms_accepted_at' => now()->subWeeks(2)]);
        $service = app(RecipientAgreementCaseService::class);
        $case = $service->createDraft($provider, $application, 'agreement_concern', ['request_key' => (string) Str::uuid(), 'subject' => 'Enrollment evidence needs clarification', 'details' => 'The school enrollment record needs an explanation.', 'term_key' => 'recipient_expectation.responsibilities']);
        $service->attachDraftEvidence($provider, $case, 1, $this->pdf());
        $case = $service->issueConcern($provider, $case->fresh(), 2, ['response_days' => 7, 'confirmed' => true]);
        if ($respond) {
            $case = $service->respondToConcern($applicant, $case, $case->revision, ['request_key' => (string) Str::uuid(), 'response_kind' => 'explanation', 'details' => 'The school record is available for independent review.', 'confirmed' => true]);
        }

        return [$provider, $applicant, $admin, $application->fresh(), $case];
    }

    private function body(RecipientAgreementCase $case): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $case->revision, 'details' => 'Please consider the accepted term, source proof, and explanation independently.', 'confirmed' => true];
    }

    private function outcome(RecipientAgreementCase $case): array
    {
        return ['finding' => 'confirmed', 'recommendation' => 'provider_decision_review', 'evidence_ids' => $case->evidence()->pluck('id')->all(), 'terms_reviewed' => true, 'responses_reviewed' => true];
    }

    private function act(User $actor, RecipientAgreementCase $case, string $action, array $extra = [], array $files = [])
    {
        $prefix = $actor->isAdmin() ? 'admin' : ($actor->isProvider() ? 'provider' : 'dashboard');
        $body = [...$this->body($case), ...$extra];
        if ($files) {
            $body['evidence'] = $files;
        }

        return $this->actingAs($actor)->post('/'.$prefix.'/recipient-cases/'.$case->id.'/'.$action, $body, ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'source.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
}
