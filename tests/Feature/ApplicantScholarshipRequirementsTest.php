<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use App\Services\ApplicantScholarshipDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicantScholarshipRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requirements_separate_initial_optional_and_later_files_and_expose_only_public_question_fields(): void
    {
        $user = User::factory()->create();
        $program = $this->program([
            'requirements' => 'School ID', 'optional_requirements' => 'Award certificate',
            'post_qualification_requirements' => 'Original transcript',
            'application_questions' => [
                ['id' => 'reason', 'prompt' => 'Why are you applying?', 'required' => true, 'answer' => 'Private answer', 'internal_note' => 'Not public'],
                ['id' => 'other', 'prompt' => 'Other information', 'required' => false],
                ['id' => 'invalid', 'prompt' => '', 'required' => true],
            ],
        ]);
        $response = $this->actingAs($user)->getJson("/dashboard/scholarships/{$program->id}/data?section=application")
            ->assertOk()->assertJsonCount(1, 'detail.content.requirements.readiness.items')
            ->assertJsonPath('detail.content.requirements.readiness.items.0.name', 'School ID')
            ->assertJsonPath('detail.content.requirements.optional_documents', ['Award certificate'])
            ->assertJsonPath('detail.content.requirements.post_qualification_documents', ['Original transcript'])
            ->assertJsonCount(2, 'detail.content.requirements.questions')
            ->assertJsonPath('detail.content.requirements.questions.0', ['id' => 'reason', 'prompt' => 'Why are you applying?', 'required' => true])
            ->assertJsonPath('detail.content.requirements.questions.1.required', false)
            ->assertJsonPath('detail.content.requirements.upload_policy.max_size_mb', 5);

        $this->assertStringNotContainsString('Private answer', $response->getContent());
        $this->assertStringNotContainsString('Not public', $response->getContent());
        $this->assertSame(1, $response->json('detail.preparation.steps.2.details.required_question_count'));
    }

    public function test_profile_review_still_includes_questions_but_not_initial_or_optional_file_requirements(): void
    {
        $detail = app(ApplicantScholarshipDetailService::class)->build($this->program([
            'application_mode' => 'provider_review', 'requirements' => 'School ID', 'optional_requirements' => 'Award certificate',
            'post_qualification_requirements' => 'Original transcript',
            'application_questions' => [['id' => 'reason', 'prompt' => 'Why are you applying?', 'required' => true]],
        ]), User::factory()->create(), 'application');

        $this->assertSame([], $detail['content']['requirements']['readiness']['items']);
        $this->assertSame([], $detail['content']['requirements']['optional_documents']);
        $this->assertCount(1, $detail['content']['requirements']['questions']);
        $this->assertSame(['Original transcript'], $detail['content']['requirements']['post_qualification_documents']);
    }

    public function test_a_reusable_file_is_called_saved_not_approved_and_the_storage_path_is_not_exposed(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::disk('local')->put('student-documents/private-school-id.pdf', 'Test file');
        $user->studentDocuments()->create(['document_name' => 'School ID', 'original_name' => 'id.pdf',
            'path' => 'student-documents/private-school-id.pdf', 'mime_type' => 'application/pdf', 'size' => 9]);
        $program = $this->program(['requirements' => 'School ID']);
        $response = $this->actingAs($user)->getJson("/dashboard/scholarships/{$program->id}/data?section=application")
            ->assertOk()->assertJsonPath('detail.content.requirements.readiness.items.0.status_label', 'Saved file');

        $this->assertStringNotContainsString('private-school-id.pdf', $response->getContent());
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_preview_requirements_do_not_reuse_the_provider_accounts_saved_files(): void
    {
        Storage::fake('local');
        $program = $this->program(['requirements' => 'School ID']);
        $owner = $program->provider;
        Storage::disk('local')->put('student-documents/provider-id.pdf', 'Private file');
        $owner->studentDocuments()->create(['document_name' => 'School ID', 'original_name' => 'provider-id.pdf',
            'path' => 'student-documents/provider-id.pdf', 'mime_type' => 'application/pdf', 'size' => 12]);

        foreach (['general', 'eligible', 'ineligible'] as $scenario) {
            $this->actingAs($owner)->getJson("/provider/previews/programs/{$program->id}/data?section=application&scenario={$scenario}")
                ->assertOk()->assertJsonPath('detail.content.requirements.readiness.state', 'preview')
                ->assertJsonPath('detail.content.requirements.readiness.ready_count', null)
                ->assertJsonPath('detail.content.requirements.readiness.missing_count', null)
                ->assertJsonPath('detail.content.requirements.readiness.items.0.status', 'not_compared')
                ->assertJsonPath('detail.content.requirements.manage_files_href', null)
                ->assertJsonPath('detail.preparation.can_start_application', false);
        }
    }

    public function test_multiple_program_sessions_are_not_collapsed_to_one_assigned_appointment(): void
    {
        $program = $this->program(['selection_stages' => ['screening', 'exam', 'interview', 'decision']]);
        foreach ([1, 2] as $week) {
            $program->stageSessions()->create(['stage_key' => 'exam', 'title' => "Exam group {$week}",
                'starts_at' => now()->addWeeks($week)->setTime(9, 0), 'mode' => 'onsite', 'venue' => "Private room {$week}",
                'status' => 'scheduled', 'published_at' => now(), 'meeting_url' => 'https://example.test/private-meeting']);
        }
        $program->events()->create(['type' => 'interview', 'title' => 'Interview',
            'scheduled_at' => now()->addWeeks(3), 'mode' => 'online', 'status' => 'scheduled']);
        $detail = app(ApplicantScholarshipDetailService::class)->build($program, User::factory()->create(), 'application');
        $exam = collect($detail['content']['process']['stages'])->firstWhere('key', 'exam');
        $interview = collect($detail['content']['process']['stages'])->firstWhere('key', 'interview');

        $this->assertSame('multiple', $exam['timing']['state']);
        $this->assertSame(2, $exam['session_count']);
        $this->assertFalse($exam['is_assigned_appointment']);
        $this->assertSame('scheduled', $interview['timing']['state']);
        $this->assertSame(1, $interview['session_count']);
        $this->assertFalse($interview['is_assigned_appointment']);
        $this->assertStringContainsString('not your assigned appointment', $detail['content']['process']['schedule_note']);
        $this->assertStringNotContainsString('private-meeting', json_encode($detail));
        $this->assertStringNotContainsString('Private room', json_encode($detail));
    }

    public function test_draft_cancelled_and_completed_sessions_do_not_become_public_upcoming_dates(): void
    {
        $program = $this->program(['selection_stages' => ['screening', 'exam', 'decision']]);
        foreach (['draft', 'cancelled', 'completed'] as $status) {
            $program->stageSessions()->create(['stage_key' => 'exam', 'title' => "Private {$status}",
                'starts_at' => now()->addWeek(), 'mode' => 'online', 'status' => $status,
                'published_at' => $status === 'draft' ? null : now()]);
        }
        $detail = app(ApplicantScholarshipDetailService::class)->build($program, User::factory()->create(), 'application');
        $exam = collect($detail['content']['process']['stages'])->firstWhere('key', 'exam');
        $this->assertSame(0, $exam['session_count']);
        $this->assertSame('conditional', $exam['timing']['state']);
        $this->assertStringNotContainsString('Private', json_encode($detail));
    }

    public function test_profile_and_document_preparation_context_returns_to_the_same_public_scholarship_section(): void
    {
        $user = User::factory()->create();
        $program = $this->program();
        foreach (['profile', 'documents'] as $page) {
            foreach (['summary', 'eligibility', 'application'] as $section) {
                $this->actingAs($user)->getJson("/dashboard/{$page}/data?preparing_scholarship={$program->id}&return_section={$section}")
                    ->assertOk()->assertJsonPath('preparation_context.scholarship_id', $program->id)
                    ->assertJsonPath('preparation_context.scholarship_title', $program->title)
                    ->assertJsonPath('preparation_context.return_section', $section)
                    ->assertJsonPath('preparation_context.return_href', "/dashboard/scholarships/{$program->id}".($section === 'summary' ? '' : "/{$section}"));
            }
        }
        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public static function invalidContextIds(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'decimal' => ['1.5'], 'scientific' => ['1e3'],
            'leading zero' => ['01'], 'overflow' => ['9007199254740992'], 'array' => [['1']],
            'external address' => ['https://example.test'], 'path' => ['../admin']];
    }

    #[DataProvider('invalidContextIds')]
    public function test_malformed_preparation_context_is_ignored_without_breaking_profile_or_documents(mixed $id): void
    {
        $user = User::factory()->create();
        foreach (['profile', 'documents'] as $page) {
            $this->actingAs($user)->getJson("/dashboard/{$page}/data?".http_build_query(['preparing_scholarship' => $id]))
                ->assertOk()->assertJsonPath('preparation_context', null);
        }
    }

    public function test_unrelated_visits_have_no_banner_and_untrusted_return_urls_are_never_used(): void
    {
        $user = User::factory()->create();
        $program = $this->program();
        foreach (['profile', 'documents'] as $page) {
            $this->actingAs($user)->getJson("/dashboard/{$page}/data")->assertOk()->assertJsonPath('preparation_context', null);
            $this->getJson("/dashboard/{$page}/data?".http_build_query(['preparing_scholarship' => $program->id,
                'return_section' => 'https://example.test', 'return_to' => '//example.test']))
                ->assertOk()->assertJsonPath('preparation_context.return_href', "/dashboard/scholarships/{$program->id}/application");
        }
    }

    public function test_draft_or_unverified_organizations_cannot_be_exposed_through_a_preparation_banner(): void
    {
        $user = User::factory()->create();
        $program = $this->program(['status' => 'draft', 'title' => 'Private Program']);
        foreach (['profile', 'documents'] as $page) {
            $response = $this->actingAs($user)->getJson("/dashboard/{$page}/data?preparing_scholarship={$program->id}")
                ->assertOk()->assertJsonPath('preparation_context', null);
            $this->assertStringNotContainsString('Private Program', $response->getContent());
        }
        $program->update(['status' => 'published']);
        $program->provider->providerProfile()->update(['verification_status' => 'pending']);
        $this->getJson("/dashboard/profile/data?preparing_scholarship={$program->id}")
            ->assertOk()->assertJsonPath('preparation_context', null);
    }

    public function test_a_return_context_is_not_permission_to_apply_to_a_closed_program(): void
    {
        $user = User::factory()->create();
        $program = $this->program(['deadline' => now()->subDay()->toDateString()]);
        $this->actingAs($user)->getJson("/dashboard/documents/data?preparing_scholarship={$program->id}")
            ->assertOk()->assertJsonPath('preparation_context.return_href', "/dashboard/scholarships/{$program->id}/application");
        $this->getJson("/dashboard/scholarships/{$program->id}/data?section=application")
            ->assertOk()->assertJsonPath('detail.decision.state', 'closed')
            ->assertJsonPath('detail.preparation.can_start_application', false);
    }

    private function program(array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => User::factory()->create(['role' => 'provider'])->id,
            'title' => 'Preparation Handoff Program', 'description' => 'Public test program.',
            'status' => 'published', 'application_mode' => 'online', 'deadline' => now()->addMonth()->toDateString(),
            'selection_stages' => ['screening', 'formal_application', 'decision'], ...$attributes,
        ]);
    }
}
