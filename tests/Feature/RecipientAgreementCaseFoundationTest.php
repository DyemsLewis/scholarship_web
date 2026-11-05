<?php

namespace Tests\Feature;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvent;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\RecipientAgreementCaseResponse;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientAgreementCaseAccess;
use App\Services\RecipientAgreementCaseService;
use Closure;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RecipientAgreementCaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_preserves_the_exact_accepted_obligation_without_imposing_a_finding(): void
    {
        [$provider, , $program, $application] = $this->award();
        $before = $application->getRawOriginal();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $this->assertSame('draft', $case->status);
        $this->assertSame('not_assessed', $case->finding);
        $this->assertSame('not_shared', $case->disclosure_status);
        $this->assertNull($case->outcome);
        $this->assertNull($case->support_decision_id);
        $this->assertNull($case->notice_sent_at);
        $this->assertNull($case->response_due_at);
        $this->assertSame('accepted-v1', $case->agreement_version);
        $this->assertSame('Submit an enrollment record each semester.', $case->term_text);
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $program->update(['recipient_agreement' => ['responsibilities' => 'A new requirement not accepted by this recipient.']]);
        $this->assertSame('Submit an enrollment record each semester.', $case->fresh()->term_text);
        $this->assertSame($application->provider_contract_terms_snapshot, $case->fresh()->agreement_snapshot);
        $this->assertSame(1, $application->recipientAgreementCases()->count());
        $this->assertSame('draft_created', $case->events()->first()->action);
        $this->assertSame($provider->name, $case->events()->first()->actor_name);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_client_fields_cannot_forge_findings_deadlines_identity_or_disclosure(): void
    {
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, [
            ...$this->concern(), 'opened_by' => 9000, 'scholarship_application_id' => 9000,
            'status' => 'closed', 'finding' => 'confirmed', 'outcome' => 'future_support_ended',
            'agreement_snapshot' => ['forged' => true], 'term_text' => 'Invented obligation',
            'disclosure_status' => 'approved', 'response_due_at' => now()->subDay()->toIso8601String(),
            'reviewed_by' => $provider->id, 'support_decision_id' => 99,
        ]);
        $this->assertSame($provider->id, $case->opened_by);
        $this->assertSame($application->id, $case->scholarship_application_id);
        $this->assertSame('draft', $case->status);
        $this->assertSame('not_assessed', $case->finding);
        $this->assertSame('not_shared', $case->disclosure_status);
        $this->assertNull($case->response_due_at);
        $this->assertNull($case->reviewed_by);
        $this->assertNull($case->support_decision_id);
        $this->assertSame('Submit an enrollment record each semester.', $case->term_text);
        $this->assertFalse($case->canShareWithOtherProviders());
        $this->assertArrayNotHasKey('agreement_snapshot', $case->toArray());
        $this->assertArrayNotHasKey('request_hash', $case->toArray());
    }

    #[DataProvider('unacceptedAwards')]
    public function test_a_concern_cannot_be_based_on_an_unaccepted_or_mismatched_agreement(array $changes): void
    {
        [$provider, , , $application] = $this->award();
        $application->forceFill($changes)->save();
        $this->assertValidation('agreement', fn () => $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern()));
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
    }

    public static function unacceptedAwards(): array
    {
        return [
            'not selected' => [['final_outcome' => null]],
            'pending' => [['student_response_status' => null]],
            'declined' => [['student_response_status' => 'declined']],
            'no saved terms' => [['provider_contract_terms_snapshot' => null]],
            'empty saved terms' => [['provider_contract_terms_snapshot' => []]],
            'no acceptance date' => [['provider_contract_terms_accepted_at' => null]],
            'no version' => [['student_response_terms_version' => null, 'provider_contract_terms_version' => null]],
            'version changed after acceptance' => [['provider_contract_terms_version' => 'not-accepted-v2']],
            'support ended' => [['status' => 'benefits_terminated']],
        ];
    }

    public function test_the_legacy_saved_version_is_used_if_the_separate_student_version_is_missing(): void
    {
        [$provider, , , $application] = $this->award();
        $application->update(['student_response_terms_version' => null]);
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $this->assertSame('accepted-v1', $case->agreement_version);
    }

    public function test_closed_support_cannot_be_reported_as_a_new_active_recipient_obligation(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $application->supportDecisions()->create([
            'applicant_id' => $applicant->id, 'decision' => 'completed', 'effective_on' => now(),
            'reason' => 'The support period ended.', 'decided_by' => $provider->id, 'decided_at' => now(),
        ]);
        $this->assertValidation('agreement', fn () => $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern()));
    }

    public function test_missing_obligations_and_non_obligation_clauses_cannot_be_invented(): void
    {
        [$provider, , , $application] = $this->award();
        foreach (['recipient_expectation.required_evidence', 'recipient_expectation.noncompliance_consequence', 'provider_contact.email'] as $key) {
            $this->assertValidation('term_key', fn () => $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, [
                ...$this->concern(), 'term_key' => $key,
            ]));
        }
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
    }

    public function test_retrying_the_same_request_is_idempotent_but_reusing_it_for_different_content_is_not(): void
    {
        [$provider, , , $application] = $this->award();
        $data = $this->concern();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $data);
        $retry = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, array_reverse($data, true));
        $this->assertSame($case->id, $retry->id);
        $this->assertHttp(409, fn () => $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, [
            ...$data, 'details' => 'A different allegation with the same request identifier.',
        ]));
        $this->assertDatabaseCount('recipient_agreement_cases', 1);
        $this->assertDatabaseCount('recipient_agreement_case_events', 1);
    }

    public function test_only_an_approved_assigned_recipient_officer_can_draft_an_agreement_concern(): void
    {
        [$owner, $applicant, $program, $application] = $this->award();
        $allowed = $this->officer($owner, ['manage_recipients'], [$program->id]);
        $this->service()->createDraft($allowed, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        foreach ([
            $applicant,
            $this->officer($owner, ['manage_benefit_releases'], [$program->id]),
            $this->officer($owner, ['manage_recipients'], []),
            User::factory()->create(['role' => 'provider']),
            User::factory()->create(['role' => 'admin']),
        ] as $denied) {
            $this->assertHttp(403, fn () => $this->service()->createDraft($denied, $application, Catalog::AGREEMENT_CONCERN, $this->concern()));
        }
        $owner->providerProfile()->update(['verification_status' => 'pending']);
        $this->assertHttp(403, fn () => $this->service()->createDraft($allowed, $application, Catalog::AGREEMENT_CONCERN, $this->concern()));
        $this->assertDatabaseCount('recipient_agreement_cases', 1);
    }

    public function test_revoked_permissions_and_suspended_accounts_are_rechecked_from_the_database(): void
    {
        [$owner, , $program, $application] = $this->award();
        $officer = $this->officer($owner, ['manage_recipients'], [$program->id]);
        $case = $this->service()->createDraft($officer, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        User::query()->whereKey($officer->id)->update(['permissions' => json_encode([])]);
        $this->assertHttp(403, fn () => $this->service()->cancelDraft($officer, $case, 1));
        $owner->update(['account_status' => 'suspended']);
        $this->assertHttp(403, fn () => $this->service()->createDraft($owner, $application, Catalog::AGREEMENT_CONCERN, $this->concern()));
        $this->assertSame('draft', $case->fresh()->status);
    }

    public function test_a_benefit_draft_can_exist_before_a_release_is_scheduled_or_marked_delivered(): void
    {
        [, $applicant, , $application, $benefit] = $this->award();
        $case = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit));
        $this->assertSame($benefit->id, $case->benefit_entitlement_id);
        $this->assertNull($case->benefit_release_record_id);
        $this->assertSame('Learning allowance', $case->benefit_snapshot['entitlement']['title']);
        $this->assertSame('one_time', $case->benefit_snapshot['entitlement']['frequency']);
        $this->assertSame('ready', $benefit->fresh()->status);
        $this->assertSame('draft', $case->status);
        $this->assertDatabaseCount('recipient_benefit_releases', 0);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
    }

    public function test_ended_support_does_not_remove_the_applicants_ability_to_record_an_undelivered_prior_benefit(): void
    {
        [$provider, $applicant, , $application, $benefit] = $this->award();
        $application->update(['status' => 'benefits_terminated']);
        $provider->update(['account_status' => 'suspended']);
        $case = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit));
        $this->assertSame('draft', $case->status);
        $this->assertSame('benefits_terminated', $application->fresh()->status);
    }

    public function test_benefit_sources_are_restricted_to_the_same_award_and_applicant(): void
    {
        [$provider, $applicant, $program, $application, $benefit] = $this->award();
        [, , , , $foreign] = $this->award();
        $this->assertValidation('benefit', fn () => $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($foreign)));
        $other = User::factory()->create(['role' => 'applicant']);
        $this->assertHttp(403, fn () => $this->service()->createDraft($other, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit)));
        $this->assertHttp(403, fn () => $this->service()->createDraft($provider, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit)));
        $benefit->update(['applicant_id' => $other->id]);
        $this->assertValidation('benefit', fn () => $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit)));
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
    }

    public function test_a_release_only_case_does_not_need_a_delivered_status_or_a_receipt_response(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $record = $this->releaseRecord($provider, $program, $application);
        $case = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, [
            ...$this->baseInput(), 'benefit_release_record_id' => $record->id,
        ]);
        $this->assertSame($record->id, $case->benefit_release_record_id);
        $this->assertSame('scheduled', $record->fresh()->status);
        $this->assertNotNull($case->benefit_snapshot['release']['scheduled_at']);
        $this->assertNull($case->benefit_entitlement_id);
        $record->release->update(['title' => 'Changed release']);
        $this->assertSame('Initial allowance', $case->fresh()->benefit_snapshot['release']['title']);
    }

    public function test_combined_release_and_benefit_must_share_a_real_allocation(): void
    {
        [$provider, $applicant, $program, $application, $benefit] = $this->award();
        $record = $this->releaseRecord($provider, $program, $application);
        $input = [...$this->delivery($benefit), 'benefit_release_record_id' => $record->id];
        $this->assertValidation('benefit', fn () => $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $input));
        $record->allocations()->create(['recipient_benefit_entitlement_id' => $benefit->id, 'status' => 'scheduled']);
        $case = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $input);
        $this->assertSame($benefit->id, $case->benefit_entitlement_id);
        $this->assertSame($record->id, $case->benefit_release_record_id);
    }

    public function test_private_unpublished_or_foreign_release_sources_cannot_be_disclosed_through_a_draft(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $record = $this->releaseRecord($provider, $program, $application);
        $record->release->update(['published_at' => null]);
        $input = [...$this->baseInput(), 'benefit_release_record_id' => $record->id];
        $this->assertValidation('benefit', fn () => $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $input));
        [$foreignProvider, , $foreignProgram, $foreignApplication] = $this->award();
        $foreignRecord = $this->releaseRecord($foreignProvider, $foreignProgram, $foreignApplication);
        $foreignRecord->update(['scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id]);
        $this->assertValidation('benefit', fn () => $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, [
            ...$this->baseInput(), 'benefit_release_record_id' => $foreignRecord->id,
        ]));
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
    }

    public function test_drafts_are_private_to_their_author_until_notice_is_issued(): void
    {
        [$owner, $applicant, $program, $application, $benefit] = $this->award();
        $officer = $this->officer($owner, ['manage_recipients', 'manage_benefit_releases'], [$program->id]);
        $concern = $this->service()->createDraft($officer, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $delivery = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame([$concern->id], $this->visibleIds($officer));
        $this->assertSame([$delivery->id], $this->visibleIds($applicant));
        $this->assertSame([], $this->visibleIds($owner));
        $this->assertSame([], $this->visibleIds($admin));
        $this->service()->cancelDraft($officer, $concern, 1);
        $this->assertSame([$delivery->id], $this->visibleIds($applicant));
        $this->assertSame([], $this->visibleIds($owner));
        $this->assertSame([], $this->visibleIds($admin));
    }

    public function test_published_case_access_is_role_program_and_organization_scoped_even_when_disclosure_is_forged(): void
    {
        [$owner, $applicant, $program, $application, $benefit] = $this->award();
        $concern = $this->service()->createDraft($owner, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $delivery = $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit));
        RecipientAgreementCase::query()->update([
            'status' => 'closed', 'notice_sent_at' => now(), 'finding' => 'confirmed', 'disclosure_status' => 'approved',
        ]);
        $recipients = $this->officer($owner, ['manage_recipients'], [$program->id]);
        $releases = $this->officer($owner, ['manage_benefit_releases'], [$program->id]);
        $this->assertSame([$concern->id], $this->visibleIds($recipients));
        $this->assertSame([$delivery->id], $this->visibleIds($releases));
        $this->assertSame([$concern->id, $delivery->id], $this->visibleIds($applicant));
        $this->assertSame([$concern->id, $delivery->id], $this->visibleIds($owner));
        $this->assertSame([], $this->visibleIds($this->officer($owner, ['manage_recipients', 'manage_benefit_releases'], [])));
        $this->assertSame([], $this->visibleIds(User::factory()->create(['role' => 'provider'])));
        $this->assertSame([], $this->visibleIds(User::factory()->create(['role' => 'applicant'])));
        $adminOwner = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $adminOwner->id, 'permissions' => ['manage_reviews']]);
        $support = User::factory()->create(['role' => 'admin', 'parent_account_id' => $adminOwner->id, 'permissions' => ['manage_reports']]);
        $this->assertSame([$concern->id, $delivery->id], $this->visibleIds($reviewer));
        $this->assertSame([], $this->visibleIds($support));
        $this->assertFalse($concern->fresh()->canShareWithOtherProviders());
    }

    public function test_draft_edits_are_versioned_and_cannot_change_the_accepted_source_or_finding(): void
    {
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $changed = $this->service()->reviseDraft($provider, $case, 1, [
            'subject' => '  Updated explanation  ', 'details' => '  Updated facts for the same accepted obligation.  ',
            'finding' => 'confirmed', 'term_text' => 'Substituted terms', 'notice_sent_at' => now(),
        ]);
        $this->assertSame(2, $changed->revision);
        $this->assertSame('Updated explanation', $changed->subject);
        $this->assertSame('not_assessed', $changed->finding);
        $this->assertSame('Submit an enrollment record each semester.', $changed->term_text);
        $this->assertNull($changed->notice_sent_at);
        $this->assertSame('Updated facts for the same accepted obligation.', $changed->events()->first()->payload['details']);
        $this->assertHttp(409, fn () => $this->service()->cancelDraft($provider, $case, 1));
        $this->assertDatabaseCount('recipient_agreement_case_events', 2);
        $unchanged = $this->service()->reviseDraft($provider, $changed, 2, $changed->only(['subject', 'details']));
        $this->assertSame(2, $unchanged->revision);
    }

    public function test_only_the_author_can_edit_or_cancel_and_cancelled_drafts_cannot_be_reopened(): void
    {
        [$owner, $applicant, $program, $application] = $this->award();
        $officer = $this->officer($owner, ['manage_recipients'], [$program->id]);
        $case = $this->service()->createDraft($officer, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        foreach ([$owner, $applicant, User::factory()->create(['role' => 'admin'])] as $other) {
            $this->assertHttp(403, fn () => $this->service()->cancelDraft($other, $case, 1));
        }
        $cancelled = $this->service()->cancelDraft($officer, $case, 1);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('not_assessed', $cancelled->finding);
        $this->assertSame('not_shared', $cancelled->disclosure_status);
        $this->assertNotNull($cancelled->closed_at);
        $this->assertHttp(409, fn () => $this->service()->cancelDraft($officer, $case, 2));
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_an_overdue_deadline_is_only_a_read_only_signal_not_a_finding_or_support_decision(): void
    {
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $case->forceFill(['response_due_at' => now()->subDay()])->save();
        $this->assertFalse($case->responseDeadlinePassed());
        $case->forceFill(['status' => 'awaiting_response', 'notice_sent_at' => now()->subDays(8)])->save();
        $case->refresh();
        $before = $case->getRawOriginal();
        $this->assertTrue($case->responseDeadlinePassed());
        $this->assertSame($before, $case->fresh()->getRawOriginal());
        $this->assertSame('not_assessed', $case->finding);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $case->forceFill(['response_received_at' => now()->subDays(2)])->save();
        $this->assertFalse($case->responseDeadlinePassed());
    }

    public function test_evidence_is_private_checked_hashed_and_not_serialized_with_a_storage_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$provider, $applicant, , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('evidence.pdf', $contents);
        $evidence = $this->service()->attachDraftEvidence($provider, $case, 1, $file);
        Storage::disk('local')->assertExists($evidence->path);
        Storage::disk('public')->assertMissing($evidence->path);
        $this->assertSame(hash('sha256', $contents), $evidence->sha256);
        $this->assertSame('application/pdf', $evidence->mime_type);
        $this->assertSame(2, $case->fresh()->revision);
        $this->assertArrayNotHasKey('path', $evidence->toArray());
        $this->assertArrayNotHasKey('sha256', $evidence->toArray());
        $response = $this->service()->evidenceResponse($provider, $evidence);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertHttp(403, fn () => $this->service()->evidenceResponse($applicant, $evidence));
        $this->assertHttp(403, fn () => $this->service()->evidenceResponse(User::factory()->create(['role' => 'provider']), $evidence));
        Storage::disk('local')->delete($evidence->path);
        $this->assertHttp(404, fn () => $this->service()->evidenceResponse($provider, $evidence));
    }

    public function test_invalid_and_stale_evidence_submissions_do_not_create_files_or_history(): void
    {
        Storage::fake('local');
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $this->assertValidation('evidence', fn () => $this->service()->attachDraftEvidence($provider, $case, 1,
            UploadedFile::fake()->createWithContent('not-evidence.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')));
        $this->assertValidation('evidence', fn () => $this->service()->attachDraftEvidence($provider, $case, 1,
            UploadedFile::fake()->createWithContent('large.pdf', "%PDF-1.4\n".str_repeat('x', 5121 * 1024))));
        $this->assertHttp(409, fn () => $this->service()->attachDraftEvidence($provider, $case, 9, $this->pdf()));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('recipient_agreement_case_evidence', 0);
        $this->assertDatabaseCount('recipient_agreement_case_events', 1);
    }

    public function test_evidence_count_is_bounded_and_a_failed_transaction_removes_the_new_private_file(): void
    {
        Storage::fake('local');
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        Event::listen('eloquent.creating: '.RecipientAgreementCaseEvidence::class, fn () => throw new RuntimeException('Simulated database failure'));
        try {
            $this->service()->attachDraftEvidence($provider, $case, 1, $this->pdf());
            $this->fail('The simulated database error must be raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated database failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RecipientAgreementCaseEvidence::class);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(1, $case->fresh()->revision);
        for ($revision = 1; $revision <= 5; $revision++) {
            $this->service()->attachDraftEvidence($provider, $case, $revision, $this->pdf());
        }
        $this->assertValidation('evidence', fn () => $this->service()->attachDraftEvidence($provider, $case, 6, $this->pdf()));
        $this->assertCount(5, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('recipient_agreement_case_events', 6);
    }

    public function test_case_changes_are_rolled_back_if_the_audit_event_cannot_be_written(): void
    {
        [$provider, , , $application] = $this->award();
        Event::listen('eloquent.creating: '.RecipientAgreementCaseEvent::class, fn () => throw new RuntimeException('Simulated audit failure'));
        try {
            $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
            $this->fail('The simulated audit error must be raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RecipientAgreementCaseEvent::class);
        }
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
        $this->assertDatabaseCount('recipient_agreement_case_events', 0);
    }

    public function test_an_audit_failure_after_storing_evidence_rolls_back_its_row_file_and_case_revision(): void
    {
        Storage::fake('local');
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        Event::listen('eloquent.creating: '.RecipientAgreementCaseEvent::class, fn () => throw new RuntimeException('Evidence audit failed'));
        try {
            $this->service()->attachDraftEvidence($provider, $case, 1, $this->pdf());
            $this->fail('The evidence audit failure must abort the transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Evidence audit failed', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RecipientAgreementCaseEvent::class);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('recipient_agreement_case_evidence', 0);
        $this->assertDatabaseCount('recipient_agreement_case_events', 1);
        $this->assertSame(1, $case->fresh()->revision);
    }

    public function test_a_failed_repeat_of_the_same_upload_object_cannot_delete_earlier_valid_evidence(): void
    {
        Storage::fake('local');
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $file = $this->pdf();
        $evidence = $this->service()->attachDraftEvidence($provider, $case, 1, $file);
        Event::listen('eloquent.creating: '.RecipientAgreementCaseEvent::class, fn () => throw new RuntimeException('Repeated upload failed'));
        try {
            $this->service()->attachDraftEvidence($provider, $case, 2, $file);
            $this->fail('The repeated upload must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Repeated upload failed', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RecipientAgreementCaseEvent::class);
        }
        Storage::disk('local')->assertExists($evidence->path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(2, $case->fresh()->revision);
        $this->assertDatabaseCount('recipient_agreement_case_evidence', 1);
        $this->assertDatabaseCount('recipient_agreement_case_events', 2);
    }

    public function test_case_history_responses_and_evidence_reject_normal_updates_and_deletes(): void
    {
        Storage::fake('local');
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $evidence = $this->service()->attachDraftEvidence($provider, $case, 1, $this->pdf());
        $response = new RecipientAgreementCaseResponse;
        $response->forceFill([
            'recipient_agreement_case_id' => $case->id, 'case_revision' => 3, 'notice_revision' => 2,
            'submitted_by' => $provider->id, 'author_name' => $provider->name, 'author_role' => $provider->role,
            'body' => 'An append-only response fixture.', 'created_at' => now(),
        ])->save();
        foreach ([$case->events()->first(), $response, $evidence] as $record) {
            foreach (['update', 'delete'] as $action) {
                try {
                    if ($action === 'update') {
                        $record->forceFill(['created_at' => now()->addDay()])->save();
                    } else {
                        $record->delete();
                    }
                    $this->fail('History records must be append-only.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('append-only', $error->getMessage());
                }
            }
        }
        $this->assertDatabaseCount('recipient_agreement_case_events', 2);
        $this->assertDatabaseCount('recipient_agreement_case_responses', 1);
        $this->assertDatabaseCount('recipient_agreement_case_evidence', 1);
    }

    public function test_application_relationships_do_not_implicitly_append_cases_or_change_other_awards(): void
    {
        [$provider, $applicant, , $application, $benefit] = $this->award();
        [, , , $other] = $this->award();
        $other->update(['applicant_id' => $applicant->id]);
        $originalOther = $other->getRawOriginal();
        $originalUser = $applicant->fresh()->getRawOriginal();
        $originalBenefit = $benefit->fresh()->getRawOriginal();
        $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $this->service()->createDraft($applicant, $application, Catalog::BENEFIT_DELIVERY, $this->delivery($benefit));
        $this->assertArrayNotHasKey('recipient_agreement_cases', $application->fresh()->toArray());
        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());
        $this->assertSame($originalUser, $applicant->fresh()->getRawOriginal());
        $this->assertSame($originalBenefit, $benefit->fresh()->getRawOriginal());
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_duplicate_audit_revision_is_rejected_by_the_database(): void
    {
        [$provider, , , $application] = $this->award();
        $case = $this->service()->createDraft($provider, $application, Catalog::AGREEMENT_CONCERN, $this->concern());
        $event = $case->events()->first()->getRawOriginal();
        unset($event['id']);
        $this->expectException(QueryException::class);
        DB::table('recipient_agreement_case_events')->insert($event);
    }

    public function test_security_sensitive_models_do_not_allow_unrestricted_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);
        RecipientAgreementCase::create(['status' => 'closed', 'finding' => 'confirmed', 'disclosure_status' => 'approved']);
    }

    public function test_part_one_has_no_public_case_endpoints_or_automatic_jobs(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('agreement-cases', $route->uri());
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_mysql_schema_and_rollback_compile_without_connecting_to_hosting(): void
    {
        $connection = new MySqlConnection(static function (): never {
            throw new RuntimeException('Schema compilation must never connect to a database.');
        }, 'schema_audit', '', [
            'driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'engine' => 'InnoDB', 'prefix_indexes' => true,
        ]);
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_02_000001_create_recipient_agreement_cases.php');
            $queries = $connection->pretend(fn () => $migration->up());
            $creates = 0;
            foreach ($queries as $query) {
                $creates += str_starts_with($query['query'], 'create table ') ? 1 : 0;
                preg_match_all('/(?:constraint|index|key|table) `([^`]+)`/i', $query['query'], $names);
                foreach ($names[1] as $name) {
                    $this->assertLessThanOrEqual(64, strlen($name), $name);
                }
            }
            $this->assertSame(4, $creates);
            $drops = $connection->pretend(fn () => $migration->down());
            $this->assertCount(4, $drops);
            $this->assertStringContainsString('recipient_agreement_cases', $drops[3]['query']);
        } finally {
            Schema::swap($original);
        }
    }

    private function award(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Accountability Test Award',
            'description' => 'Scholarship agreement and benefit test fixture.', 'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected', 'workflow_stage' => 'complete',
            'application_state' => 'closed', 'submitted_at' => now()->subWeeks(2),
            'student_response_status' => 'accepted', 'student_responded_at' => now()->subWeek(),
            'provider_contract_terms_snapshot' => ['recipient_expectation' => [
                'responsibilities' => 'Submit an enrollment record each semester.',
                'noncompliance_consequence' => 'A provider review is required.',
            ]],
            'provider_contract_terms_version' => 'accepted-v1', 'student_response_terms_version' => 'accepted-v1',
            'provider_contract_terms_accepted_at' => now()->subWeek(),
        ]);
        $benefit = RecipientBenefitEntitlement::create([
            'scholarship_id' => $program->id, 'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id, 'benefit_type' => 'cash_grant', 'title' => 'Learning allowance',
            'amount' => 5000, 'frequency' => 'one_time', 'status' => 'ready', 'assigned_at' => now()->subWeek(),
        ]);

        return [$provider, $applicant, $program, $application->refresh(), $benefit];
    }

    private function officer(User $owner, array $permissions, array $programs): User
    {
        return User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => $permissions,
            'assigned_program_ids' => $programs, 'account_status' => 'active',
        ]);
    }

    private function releaseRecord(User $provider, Scholarship $program, ScholarshipApplication $application): RecipientBenefitReleaseRecord
    {
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $program->id, 'created_by' => $provider->id, 'title' => 'Initial allowance',
            'benefit_description' => 'The scheduled learning allowance.',
            'release_at' => now()->subDay(), 'release_method' => 'in_person', 'status' => 'scheduled', 'published_at' => now()->subDays(2),
        ]);

        return $release->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
            'status' => 'scheduled',
        ]);
    }

    private function baseInput(): array
    {
        return ['request_key' => (string) Str::uuid(), 'subject' => 'A specific agreement question', 'details' => 'A factual explanation requiring a response and review.'];
    }

    private function concern(): array
    {
        return [...$this->baseInput(), 'term_key' => 'recipient_expectation.responsibilities'];
    }

    private function delivery(RecipientBenefitEntitlement $benefit): array
    {
        return [...$this->baseInput(), 'benefit_entitlement_id' => $benefit->id];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    private function service(): RecipientAgreementCaseService
    {
        return app(RecipientAgreementCaseService::class);
    }

    private function visibleIds(User $actor): array
    {
        return app(RecipientAgreementCaseAccess::class)->visibleTo($actor)->orderBy('id')->pluck('id')->all();
    }

    private function assertHttp(int $status, Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function assertValidation(string $field, Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error for {$field}.");
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }
}
