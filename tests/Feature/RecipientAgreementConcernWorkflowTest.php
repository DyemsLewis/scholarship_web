<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvent;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicantApplicationDetailService;
use App\Services\RecipientAgreementCaseService;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RecipientAgreementConcernWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->freezeTime();
    }

    public function test_issuing_an_evidence_backed_notice_preserves_support_and_shares_only_the_final_notice(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $before = $application->getRawOriginal();
        $draft = $this->draft($provider, $application, ['details' => 'Private initial draft wording that must not be shared.']);
        $this->actingAs($applicant)->getJson($this->history($application, 'dashboard'))->assertOk()->assertJsonCount(0, 'cases.data');
        $this->actingAs($applicant)->getJson($this->read($draft, 'dashboard'))->assertForbidden();
        $this->actingAs($provider)->patchJson($this->read($draft), [
            'expected_revision' => 1, 'subject' => 'Enrollment record clarification', 'details' => 'Please explain the missing enrollment record for this semester.',
            'term_text' => 'A fabricated new term', 'finding' => 'confirmed',
        ])->assertOk();
        $draft->refresh();
        $this->upload($provider, $draft);
        $case = $this->issue($provider, $draft);
        $this->assertSame('awaiting_response', $case->status);
        $this->assertSame('not_assessed', $case->finding);
        $this->assertSame('not_shared', $case->disclosure_status);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), $case->response_due_at->toDateTimeString());
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $response = $this->actingAs($applicant)->getJson($this->read($case, 'dashboard'))->assertOk()
            ->assertJsonPath('case.can_respond', true)->assertJsonPath('case.can_edit', false)
            ->assertJsonPath('case.obligation.text', 'Submit proof of enrollment each semester.')
            ->assertJsonCount(1, 'case.evidence')->assertJsonCount(1, 'case.history');
        $this->assertStringNotContainsString('Private initial draft wording', $response->getContent());
        $this->assertStringNotContainsString('agreement_snapshot', $response->getContent());
        $this->assertStringNotContainsString('request_hash', $response->getContent());
        $this->assertStringNotContainsString('recipient-agreement-cases/', $response->getContent());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'recipient_agreement_concern']);
    }

    public function test_notice_requires_a_real_unmodified_file_and_an_explicit_valid_response_window(): void
    {
        [$provider, , $application] = $this->award();
        $case = $this->draft($provider, $application);
        $this->actingAs($provider)->postJson($this->read($case).'/issue', $this->issueBody($case))->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->upload($provider, $case);
        foreach ([0, 2, 31] as $days) {
            $this->actingAs($provider)->postJson($this->read($case).'/issue', [...$this->issueBody($case->fresh()), 'response_days' => $days])
                ->assertUnprocessable()->assertJsonValidationErrors('response_days');
        }
        $this->actingAs($provider)->postJson($this->read($case).'/issue', [...$this->issueBody($case->fresh()), 'confirmed' => false])->assertUnprocessable();
        $evidence = $case->evidence()->first();
        Storage::disk('local')->put($evidence->path, 'The stored proof was changed.');
        $this->actingAs($provider)->postJson($this->read($case).'/issue', $this->issueBody($case->fresh()))->assertUnprocessable()->assertJsonValidationErrors('evidence');
        Storage::disk('local')->delete($evidence->path);
        $this->actingAs($provider)->postJson($this->read($case).'/issue', $this->issueBody($case->fresh()))->assertUnprocessable();
        $this->assertSame('draft', $case->fresh()->status);
        $this->assertNull($case->fresh()->notice_sent_at);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_the_applicant_next_action_guide_prioritizes_an_issued_notice_but_not_a_private_draft(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $service = app(ApplicantApplicationDetailService::class);
        $case = $this->draft($provider, $application);
        $this->assertNotSame('agreement-concerns', $service->build($application, $applicant, 'outcome')['decision']['action']['target'] ?? null);
        $this->upload($provider, $case);
        $case = $this->issue($provider, $case);
        $detail = $service->build($application->fresh(), $applicant, 'summary');
        $this->assertSame('agreement-concerns', $detail['decision']['action']['target']);
        $this->assertStringEndsWith('#agreement-concerns', $detail['decision']['action']['href']);
        $this->actingAs($applicant)->postJson($this->read($case, 'dashboard').'/response', $this->responseBody($case))->assertOk();
        $this->assertNotSame('agreement-concerns', $service->build($application->fresh(), $applicant, 'outcome')['decision']['action']['target'] ?? null);
    }

    public function test_removed_draft_evidence_is_never_shared_or_downloadable_and_removal_is_versioned(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->draft($provider, $application);
        $this->upload($provider, $case, 'wrong-proof.pdf');
        $wrong = $case->evidence()->first();
        $this->actingAs($provider)->deleteJson($this->read($case).'/evidence/'.$wrong->id, ['expected_revision' => 2])->assertOk()->assertJsonCount(0, 'case.evidence');
        $this->actingAs($provider)->getJson('/provider/agreement-concern-evidence/'.$wrong->id)->assertForbidden();
        $this->upload($provider, $case->fresh(), 'correct-proof.pdf');
        $case = $this->issue($provider, $case);
        $this->actingAs($applicant)->getJson($this->read($case, 'dashboard'))->assertOk()->assertJsonCount(1, 'case.evidence')->assertJsonPath('case.evidence.0.name', 'correct-proof.pdf');
        $this->actingAs($applicant)->getJson('/dashboard/agreement-concern-evidence/'.$wrong->id)->assertForbidden();
        $this->actingAs($provider)->deleteJson($this->read($case).'/evidence/'.$case->evidence()->latest('id')->first()->id, ['expected_revision' => $case->revision])->assertConflict();
        $this->assertDatabaseCount('recipient_agreement_evidence_exclusions', 1);
    }

    public function test_draft_and_issue_retries_do_not_duplicate_cases_notices_or_active_obligations(): void
    {
        [$owner, , $application] = $this->award();
        $input = $this->draftBody();
        $first = $this->actingAs($owner)->postJson($this->history($application), $input)->assertCreated()->json('case.id');
        $this->actingAs($owner)->postJson($this->history($application), $input)->assertCreated()->assertJsonPath('case.id', $first);
        $case = RecipientAgreementCase::findOrFail($first);
        $this->upload($owner, $case);
        $case = $this->issue($owner, $case);
        $notifications = PortalNotification::count();
        $this->actingAs($owner)->postJson($this->read($case).'/issue', $this->issueBody($case))->assertConflict();
        $other = $this->officer($owner, ['manage_recipients'], [$application->scholarship_id]);
        $duplicate = $this->draft($other, $application);
        $this->upload($other, $duplicate);
        $this->actingAs($other)->postJson($this->read($duplicate).'/issue', $this->issueBody($duplicate->fresh()))->assertConflict();
        $this->assertSame($notifications, PortalNotification::count());
        $this->assertSame(1, RecipientAgreementCase::whereNotNull('open_obligation_key')->count());
    }

    #[DataProvider('responseKinds')]
    public function test_an_applicant_can_explain_correct_or_request_an_exception_without_a_finding(string $kind): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->ready($provider, $application);
        $before = $application->getRawOriginal();
        $this->actingAs($applicant)->postJson($this->read($case, 'dashboard').'/response', [
            ...$this->responseBody($case), 'response_kind' => $kind, 'status' => 'confirmed', 'finding' => 'confirmed',
        ])->assertOk()->assertJsonPath('case.status', 'under_review')->assertJsonPath('case.can_respond', false)
            ->assertJsonPath('case.responses.0.kind', $kind);
        $this->assertSame('not_assessed', $case->fresh()->finding);
        $this->assertSame($before, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $provider->id, 'type' => 'recipient_agreement_concern_response']);
    }

    public static function responseKinds(): array
    {
        return [['explanation'], ['correction_request'], ['exception_request']];
    }

    public function test_a_late_response_is_accepted_and_silence_does_not_create_a_finding(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->ready($provider, $application);
        $this->travel(8)->days();
        $this->actingAs($applicant)->getJson($this->read($case, 'dashboard'))->assertOk()->assertJsonPath('case.deadline_passed', true)->assertJsonPath('case.can_respond', true);
        $this->assertSame('not_assessed', $case->fresh()->finding);
        $this->actingAs($applicant)->postJson($this->read($case, 'dashboard').'/response', $this->responseBody($case))->assertOk();
        $this->assertTrue($case->events()->where('action', 'response_submitted')->first()->payload['late']);
        $this->assertSame('awarded', $application->fresh()->status);
    }

    public function test_a_response_retry_is_idempotent_and_a_second_or_stale_response_is_not_added(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->ready($provider, $application);
        $body = $this->responseBody($case);
        $url = $this->read($case, 'dashboard').'/response';
        $this->actingAs($applicant)->postJson($url, [...$body, 'expected_revision' => 999])->assertConflict();
        $this->actingAs($applicant)->postJson($url, $body)->assertOk();
        $notifications = PortalNotification::count();
        $events = $case->events()->count();
        $this->actingAs($applicant)->postJson($url, $body)->assertOk();
        $this->actingAs($applicant)->postJson($url, [...$body, 'details' => 'Changed text with the same request identifier.'])->assertConflict();
        $this->actingAs($applicant)->postJson($url, [...$body, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('recipient_agreement_case_responses', 1);
        $this->assertSame($notifications, PortalNotification::count());
        $this->assertSame($events, $case->events()->count());
    }

    public function test_response_files_are_optional_bounded_private_and_retried_without_duplicate_uploads(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->ready($provider, $application);
        $body = $this->responseBody($case);
        $url = $this->read($case, 'dashboard').'/response';
        $this->actingAs($applicant)->post($url, [...$body, 'evidence' => $this->pdf()], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($applicant)->post($url, [...$body, 'evidence' => [$this->pdf(), $this->pdf(), $this->pdf(), $this->pdf()]], ['Accept' => 'application/json'])->assertUnprocessable();
        $result = $this->actingAs($applicant)->post($url, [...$body, 'evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($applicant)->post($url, [...$body, 'evidence' => [$this->pdf()]], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('recipient_agreement_case_evidence', 2);
        $this->actingAs($provider)->get($result->json('case.responses.0.evidence.0.view_url'))->assertForbidden();
        $providerResult = $this->actingAs($provider)->getJson($this->read($case))->assertOk();
        $this->actingAs($provider)->get($providerResult->json('case.responses.0.evidence.0.view_url'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_access_is_scoped_by_role_program_organization_and_applicant_for_pages_and_mutations(): void
    {
        [$owner, $applicant, $application] = $this->award();
        $creator = $this->officer($owner, ['manage_recipients'], [$application->scholarship_id]);
        $case = $this->draft($creator, $application);
        $this->actingAs($owner)->getJson($this->read($case))->assertForbidden();
        $this->upload($creator, $case);
        $case = $this->issue($creator, $case);
        $this->actingAs($owner)->getJson($this->read($case))->assertOk();
        $this->actingAs($owner)->postJson($this->read($case).'/issue', $this->issueBody($case))->assertForbidden();
        foreach ([User::factory()->create(['role' => 'provider']), $this->officer($owner, ['manage_recipients'], []), $this->officer($owner, ['manage_benefit_releases'], [$application->scholarship_id]), User::factory()->create(['role' => 'admin'])] as $denied) {
            $this->actingAs($denied)->getJson($this->read($case))->assertForbidden();
            $queue = $this->actingAs($denied)->getJson('/provider/workspaces/recipients/concerns/data');
            if ($denied->isProvider() && $denied->hasPortalPermission('manage_recipients')) {
                $queue->assertOk()->assertJsonCount(0, 'cases.data');
            } else {
                $queue->assertForbidden();
            }
        }
        $foreign = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($foreign)->getJson($this->read($case, 'dashboard'))->assertForbidden();
        $this->actingAs($foreign)->postJson($this->read($case, 'dashboard').'/response', $this->responseBody($case))->assertForbidden();
        $applicant->update(['account_status' => 'suspended']);
        $this->actingAs($applicant)->postJson($this->read($case, 'dashboard').'/response', $this->responseBody($case))->assertForbidden();
    }

    public function test_queue_filters_and_pagination_do_not_include_someone_elses_draft_or_program(): void
    {
        [$owner, , $application] = $this->award();
        $officer = $this->officer($owner, ['manage_recipients'], [$application->scholarship_id]);
        $draft = $this->draft($officer, $application);
        $this->actingAs($owner)->getJson('/provider/workspaces/recipients/concerns/data')->assertOk()->assertJsonCount(0, 'cases.data');
        $this->actingAs($officer)->getJson('/provider/workspaces/recipients/concerns/data?status=draft')->assertOk()->assertJsonPath('cases.data.0.id', $draft->id);
        $this->upload($officer, $draft);
        $this->issue($officer, $draft);
        $this->actingAs($owner)->getJson('/provider/workspaces/recipients/concerns/data?status=awaiting_response&search=Enrollment')->assertOk()->assertJsonCount(1, 'cases.data');
        [, , $foreign] = $this->award();
        $this->actingAs($owner)->getJson('/provider/workspaces/recipients/concerns/data?program_id='.$foreign->scholarship_id)->assertForbidden();
        $this->withoutVite();
        $this->actingAs($officer)->get('/provider/workspaces/recipients/concerns')->assertOk()->assertSee('providerRecipientConcerns');
    }

    public function test_changed_accepted_terms_or_closed_support_prevent_issuing_a_saved_draft(): void
    {
        [$provider, , $application] = $this->award();
        $case = $this->draft($provider, $application);
        $this->upload($provider, $case);
        $application->update(['provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'Different accepted record.']]]);
        $this->actingAs($provider)->postJson($this->read($case).'/issue', $this->issueBody($case->fresh()))->assertConflict();
        $application->update(['status' => 'benefits_terminated']);
        $this->actingAs($provider)->postJson($this->read($case).'/issue', $this->issueBody($case->fresh()))->assertUnprocessable();
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_failed_audit_rolls_back_the_response_and_its_files_and_leaves_the_notice_open(): void
    {
        [$provider, $applicant, $application] = $this->award();
        $case = $this->ready($provider, $application);
        Event::listen('eloquent.creating: '.RecipientAgreementCaseEvent::class, function ($event): void {
            if ($event->action === 'response_submitted') {
                throw new RuntimeException('Response audit failed.');
            }
        });
        try {
            app(RecipientAgreementCaseService::class)->respondToConcern($applicant, $case, $case->revision, $this->responseBody($case), [$this->pdf()]);
            $this->fail('A failed audit must abort the response.');
        } catch (RuntimeException $error) {
            $this->assertSame('Response audit failed.', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.RecipientAgreementCaseEvent::class);
        }
        $this->assertDatabaseCount('recipient_agreement_case_responses', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame('awaiting_response', $case->fresh()->status);
        $this->assertNull($case->fresh()->response_received_at);
    }

    public function test_orphan_file_cleanup_has_a_safety_window_dry_run_and_narrow_private_scope(): void
    {
        [$provider, , $application] = $this->award();
        $case = $this->draft($provider, $application);
        $this->upload($provider, $case);
        $kept = $case->evidence()->first()->path;
        $old = 'recipient-agreement-cases/999/evidence/'.Str::uuid().'.pdf';
        $new = 'recipient-agreement-cases/998/evidence/'.Str::uuid().'.pdf';
        $other = 'applicant-verification/999/identity.pdf';
        foreach ([$old, $new, $other] as $path) {
            Storage::disk('local')->put($path, 'Private test fixture');
        }
        foreach ([$old, $kept, $other] as $path) {
            touch(Storage::disk('local')->path($path), now()->subDays(2)->getTimestamp());
        }
        $this->artisan('agreement-cases:prune-files', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('local')->assertExists($old);
        $this->artisan('agreement-cases:prune-files')->assertSuccessful();
        Storage::disk('local')->assertMissing($old);
        foreach ([$kept, $new, $other] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_a_failed_notice_notification_rolls_back_the_notice_and_open_obligation_key(): void
    {
        [$provider, , $application] = $this->award();
        $case = $this->draft($provider, $application);
        $this->upload($provider, $case);
        Event::listen('eloquent.creating: '.PortalNotification::class, fn () => throw new RuntimeException('Notice delivery record failed.'));
        try {
            app(RecipientAgreementCaseService::class)->issueConcern($provider, $case->fresh(), $case->fresh()->revision, $this->issueBody($case->fresh()));
            $this->fail('The notice must roll back if its portal notification cannot be created.');
        } catch (RuntimeException $error) {
            $this->assertSame('Notice delivery record failed.', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.PortalNotification::class);
        }
        $case->refresh();
        $this->assertSame('draft', $case->status);
        $this->assertNull($case->notice_sent_at);
        $this->assertNull($case->open_obligation_key);
        $this->assertSame(0, $case->events()->where('action', 'notice_issued')->count());
    }

    public function test_notice_control_migration_compiles_for_mysql_without_a_connection(): void
    {
        $connection = new MySqlConnection(static function (): never {
            throw new RuntimeException('Schema verification must not contact hosting.');
        }, 'schema_audit', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB', 'prefix_indexes' => true]);
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_02_000002_add_agreement_concern_notice_controls.php');
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

    private function award(): array
    {
        $owner = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
        $program = Scholarship::create(['provider_id' => $owner->id, 'title' => 'Concern Test Award', 'description' => 'Accepted scholarship.', 'status' => 'published']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded', 'final_outcome' => 'selected',
            'student_response_status' => 'accepted', 'submitted_at' => now()->subWeeks(2),
            'provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'Submit proof of enrollment each semester.']],
            'provider_contract_terms_version' => 'accepted-v1', 'student_response_terms_version' => 'accepted-v1',
            'provider_contract_terms_accepted_at' => now()->subWeek(),
        ]);

        return [$owner, $applicant, $application->refresh()];
    }

    private function officer(User $owner, array $permissions, array $programs): User
    {
        return User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => $permissions, 'assigned_program_ids' => $programs, 'account_status' => 'active']);
    }

    private function draftBody(): array
    {
        return ['request_key' => (string) Str::uuid(), 'term_key' => 'recipient_expectation.responsibilities', 'subject' => 'Enrollment record clarification', 'details' => 'Please explain the missing enrollment record for this semester.'];
    }

    private function draft(User $actor, ScholarshipApplication $application, array $extra = []): RecipientAgreementCase
    {
        $id = $this->actingAs($actor)->postJson($this->history($application), [...$this->draftBody(), ...$extra])->assertCreated()->json('case.id');

        return RecipientAgreementCase::findOrFail($id);
    }

    private function upload(User $actor, RecipientAgreementCase $case, string $name = 'evidence.pdf'): void
    {
        $this->actingAs($actor)->post($this->read($case).'/evidence', ['expected_revision' => $case->fresh()->revision, 'evidence' => $this->pdf($name)], ['Accept' => 'application/json'])->assertOk();
        $case->refresh();
    }

    private function ready(User $actor, ScholarshipApplication $application): RecipientAgreementCase
    {
        $case = $this->draft($actor, $application);
        $this->upload($actor, $case);

        return $this->issue($actor, $case);
    }

    private function issue(User $actor, RecipientAgreementCase $case): RecipientAgreementCase
    {
        $this->actingAs($actor)->postJson($this->read($case).'/issue', $this->issueBody($case->fresh()))->assertOk();

        return $case->fresh();
    }

    private function issueBody(RecipientAgreementCase $case): array
    {
        return ['expected_revision' => $case->revision, 'response_days' => 7, 'confirmed' => true];
    }

    private function responseBody(RecipientAgreementCase $case): array
    {
        return ['expected_revision' => $case->revision, 'request_key' => (string) Str::uuid(), 'response_kind' => 'explanation', 'details' => 'The school is still preparing my enrollment certificate.', 'confirmed' => true];
    }

    private function pdf(string $name = 'evidence.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }

    private function read(RecipientAgreementCase $case, string $prefix = 'provider'): string
    {
        return "/{$prefix}/agreement-concerns/{$case->id}";
    }

    private function history(ScholarshipApplication $application, string $prefix = 'provider'): string
    {
        return "/{$prefix}/applications/{$application->id}/agreement-concerns";
    }
}
