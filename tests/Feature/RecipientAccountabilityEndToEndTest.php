<?php

namespace Tests\Feature;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientBenefitRelease;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientBenefitEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipientAccountabilityEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->freezeTime();
    }

    public function test_notice_response_review_privacy_and_independent_appeal_form_one_safe_workflow(): void
    {
        [$owner, $applicant, $application] = $this->award();
        $officer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => ['manage_recipients'], 'assigned_program_ids' => [$application->scholarship_id]]);
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $appealAdmin = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $original = $application->fresh()->getRawOriginal();
        $id = $this->actingAs($officer)->postJson('/provider/applications/'.$application->id.'/agreement-concerns', ['request_key' => (string) Str::uuid(), 'subject' => 'Enrollment clarification', 'details' => 'The school enrollment record needs clarification.', 'term_key' => 'recipient_expectation.responsibilities'])->assertCreated()->json('case.id');
        $url = '/provider/agreement-concerns/'.$id;
        $this->actingAs($applicant)->getJson('/dashboard/agreement-concerns/'.$id)->assertForbidden();
        $this->actingAs($officer)->post($url.'/evidence', ['expected_revision' => 1, 'evidence' => $this->pdf('enrollment.pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($url.'/issue', ['expected_revision' => 2, 'response_days' => 7, 'confirmed' => true])->assertOk();
        $case = RecipientAgreementCase::findOrFail($id);
        $proof = $case->evidence()->sole();
        $this->actingAs($applicant)->get('/dashboard/agreement-concern-evidence/'.$proof->id)->assertOk();
        $this->postJson('/dashboard/agreement-concerns/'.$id.'/response', $this->command($case) + ['response_kind' => 'explanation'])->assertOk();
        $this->actingAs($officer)->postJson('/provider/recipient-cases/'.$id.'/request', $this->command($case->fresh()))->assertOk();
        $outcome = ['finding' => 'confirmed', 'recommendation' => 'provider_decision_review', 'evidence_ids' => [$proof->id], 'terms_reviewed' => true, 'responses_reviewed' => true];
        $this->actingAs($admin)->postJson('/admin/recipient-cases/'.$id.'/outcome', $this->command($case->fresh()) + $outcome)->assertOk();
        $privacy = ['request_key' => (string) Str::uuid(), 'expected_revision' => $case->fresh()->revision, 'assessment' => 'blocked', 'audience' => 'originating_award', 'purpose' => 'Review the necessity of disclosure.', 'rationale' => 'No external disclosure is authorized; preserve the appeal opportunity and access metadata.', 'recheck_on' => now()->addMonth()->toDateString(), 'audit_retention_hold' => true, 'confirmed' => true];
        $this->postJson('/admin/recipient-cases/'.$id.'/privacy', $privacy)->assertOk()->assertJsonPath('privacy.sharing_enabled', false);
        $this->actingAs($officer)->postJson('/provider/applications/'.$application->id.'/support-decision', ['decision' => 'terminated', 'reason_category' => 'document_noncompliance', 'agreement_case_review_id' => $case->fresh()->latest_review_id, 'effective_on' => now()->toDateString(), 'notice_given_on' => now()->toDateString(), 'reason' => 'A separate support decision is not allowed before the appeal opportunity ends.', 'confirmed' => true])->assertUnprocessable();
        $this->actingAs($applicant)->postJson('/dashboard/recipient-cases/'.$id.'/appeal', $this->command($case->fresh()))->assertOk();
        $this->actingAs($admin)->postJson('/admin/recipient-cases/'.$id.'/outcome', $this->command($case->fresh()) + $outcome)->assertUnprocessable();
        $this->actingAs($appealAdmin)->postJson('/admin/recipient-cases/'.$id.'/outcome', [...$this->command($case->fresh()), ...$outcome, 'finding' => 'excused', 'recommendation' => 'no_support_change'])->assertOk();
        $this->getJson('/admin/recipient-cases/'.$id.'/privacy')->assertOk()->assertJsonPath('privacy.assessment_state', 'superseded')->assertJsonPath('privacy.audit_retention_hold', true)->assertJsonPath('privacy.sharing_enabled', false);
        $other = User::factory()->create(['role' => 'provider']);
        $this->actingAs($other)->getJson('/provider/recipient-cases/'.$id)->assertForbidden();
        $this->get('/provider/recipient-case-evidence/'.$proof->id)->assertForbidden();
        $this->assertSame($original, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('recipient_case_reviews', 2);
        $this->assertDatabaseCount('recipient_case_privacy_reviews', 1);
        $this->assertFalse($case->fresh()->canShareWithOtherProviders());
    }

    public function test_missing_benefit_review_and_proven_delivery_still_require_the_applicants_receipt_confirmation(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => ['manage_reviews']]);
        $benefit = $application->scholarship->benefits()->create(['type' => 'allowance', 'title' => 'Learning allowance', 'amount' => 2000, 'frequency' => 'one_time']);
        $entitlements = app(RecipientBenefitEntitlementService::class);
        $entitlements->ensureForApplication($application);
        $entitlement = $application->benefitEntitlements()->sole();
        $release = RecipientBenefitRelease::create(['scholarship_id' => $application->scholarship_id, 'created_by' => $provider->id, 'title' => 'First learning allowance', 'benefit_description' => 'Agreed first allowance', 'release_at' => now()->subDay(), 'release_method' => 'bank_transfer', 'status' => 'published', 'published_at' => now()->subDays(2)]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'released', 'recorded_by' => $provider->id, 'released_at' => now()]);
        $entitlements->allocate($record, $application->scholarship->benefits()->whereKey($benefit->id)->get());
        $entitlements->syncReleaseResult($record->fresh());
        $id = $this->actingAs($applicant)->postJson('/dashboard/applications/'.$application->id.'/delivery-reports', ['request_key' => (string) Str::uuid(), 'source_type' => 'release', 'source_id' => $record->id, 'issue_kind' => 'not_received', 'details' => 'The learning allowance has not reached my account.', 'confirmed' => true])->assertCreated()->json('report.id');
        $case = RecipientAgreementCase::findOrFail($id);
        $this->postJson('/dashboard/recipient-cases/'.$id.'/request', $this->command($case))->assertOk();
        $this->actingAs($admin)->post('/admin/recipient-cases/'.$id.'/outcome', $this->command($case->fresh()) + ['finding' => 'confirmed', 'recommendation' => 'delivery_follow_up', 'terms_reviewed' => true, 'responses_reviewed' => true, 'evidence' => [$this->pdf('delivery-review.pdf')]], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($record->receiptResponse()->sole()->recipient_resolution_confirmed_at);
        $this->assertNotSame('fulfilled', $entitlement->fresh()->status);
        $this->actingAs($provider)->post('/provider/delivery-reports/'.$id.'/proposal', $this->command($case->fresh()) + ['response_kind' => 'delivery_proposed', 'delivery_record_id' => $record->id, 'delivered_on' => now()->toDateString(), 'evidence' => [$this->pdf('transfer-proof.pdf')]], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($record->receiptResponse()->sole()->recipient_resolution_confirmed_at);
        $this->actingAs($applicant)->postJson('/dashboard/delivery-reports/'.$id.'/response', $this->command($case->fresh()) + ['response_kind' => 'receipt_confirmed', 'received_on' => now()->toDateString()])->assertOk()->assertJsonPath('report.status', 'closed');
        $this->assertNotNull($record->receiptResponse()->sole()->recipient_resolution_confirmed_at);
        $this->assertSame('fulfilled', $entitlement->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertFalse($case->fresh()->canShareWithOtherProviders());
        $this->assertSame('awarded', $application->fresh()->status);
    }

    private function award(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Integrated workflow test award', 'description' => 'Isolated test data.', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected', 'application_state' => 'closed', 'workflow_stage' => 'complete', 'student_response_status' => 'accepted', 'submitted_at' => now()->subMonth(), 'provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'Submit enrollment records.', 'noncompliance_consequence' => 'Support may end after notice, response, and review.']], 'provider_contract_terms_version' => 'integrated-v1', 'student_response_terms_version' => 'integrated-v1', 'provider_contract_terms_accepted_at' => now()->subWeeks(2)]);

        return [$provider, $applicant, $application];
    }

    private function command(RecipientAgreementCase $case): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => $case->revision, 'details' => 'Consider the actual accepted terms, source documents, and applicant explanation before deciding.', 'confirmed' => true];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
}
