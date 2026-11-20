<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use App\Support\ProgramApplicationRules;
use App\Support\ProgramConfigurationRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramConfigurationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Manila'));
    }

    public static function conflictingConfigurations(): array
    {
        $conflicts = [
            'too few applicants' => [['slots_available' => 30, 'application_limit' => 20], 'application_limit'],
            'late opening' => [['application_opens_at' => '2027-06-02', 'deadline' => '2027-06-01'], 'application_opens_at'],
            'early results' => [['expected_results_at' => '2027-05-31', 'deadline' => '2027-06-01'], 'expected_results_at'],
            'early formal deadline' => [['handoff_deadline' => '2027-05-31', 'deadline' => '2027-06-01'], 'handoff_deadline'],
            'formal deadline on intake closing day' => [['handoff_deadline' => '2027-06-01', 'deadline' => '2027-06-01'], 'handoff_deadline'],
            'results before formal applications close' => [['expected_results_at' => '2027-06-02', 'handoff_deadline' => '2027-06-03', 'deadline' => '2027-06-01'], 'expected_results_at'],
            'results on the last application day' => [['expected_results_at' => '2027-06-01', 'deadline' => '2027-06-01'], 'expected_results_at'],
            'support configured before a deadline exists' => [['support_starts_at' => '2027-06-02', 'support_ends_at' => '2027-06-03'], 'deadline'],
            'formal deadline without intake deadline' => [['handoff_deadline' => '2027-06-03'], 'deadline'],
            'results without intake deadline' => [['expected_results_at' => '2027-06-03'], 'deadline'],
            'support starts on intake closing day' => [['support_starts_at' => '2027-06-01', 'deadline' => '2027-06-01'], 'support_starts_at'],
            'support starts on formal application closing day' => [['support_starts_at' => '2027-06-02', 'handoff_deadline' => '2027-06-02', 'deadline' => '2027-06-01'], 'support_starts_at'],
            'support starts before results' => [['support_starts_at' => '2027-06-02', 'expected_results_at' => '2027-06-03', 'deadline' => '2027-06-01'], 'support_starts_at'],
            'support end without start' => [['support_ends_at' => '2027-06-04', 'deadline' => '2027-06-01'], 'support_starts_at'],
            'backward support' => [['deadline' => '2027-06-01', 'support_starts_at' => '2027-09-01', 'support_ends_at' => '2027-08-31'], 'support_ends_at'],
        ];
        $cases = [];
        foreach (['draft', 'pending_review'] as $status) {
            foreach ($conflicts as $label => [$values, $field]) {
                $cases[$status.' '.$label] = [$values, $field, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('conflictingConfigurations')]
    public function test_inconsistent_configuration_is_rejected_even_when_saving_a_draft(array $values, string $field, string $status): void
    {
        $this->actingAs($this->provider())->postJson('/provider/scholarships', [
            'title' => 'Configuration validation program', 'description' => 'Draft validation.',
            'status' => $status, 'terms_accepted' => true, ...$values,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('scholarships', 0);
    }

    public static function partialConflicts(): array
    {
        return [
            'reduce applicant limit' => [['application_limit' => 19], 'application_limit'],
            'increase slots' => [['slots_available' => 101], 'application_limit'],
            'move opening later' => [['application_opens_at' => '2027-07-01'], 'application_opens_at'],
            'move deadline earlier' => [['deadline' => '2027-05-31'], 'application_opens_at'],
            'move results earlier' => [['expected_results_at' => '2027-06-29'], 'expected_results_at'],
            'move formal deadline earlier' => [['handoff_deadline' => '2027-06-29'], 'handoff_deadline'],
            'clear application deadline with later dates' => [['deadline' => null], 'deadline'],
            'move formal deadline beyond expected results' => [['handoff_deadline' => '2027-08-04'], 'expected_results_at'],
            'move results beyond recorded support start' => [['expected_results_at' => '2027-08-05'], 'support_starts_at'],
            'clear support start while an end is set' => [['support_starts_at' => null], 'support_starts_at'],
            'move support start later' => [['support_starts_at' => '2028-07-02'], 'support_ends_at'],
            'move support end earlier' => [['support_ends_at' => '2027-06-30'], 'support_ends_at'],
        ];
    }

    #[DataProvider('partialConflicts')]
    public function test_partial_edits_compare_against_saved_values_without_changing_the_record(array $values, string $field): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $before = $program->fresh()->getAttributes();
        $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
            'title' => $program->title, 'status' => 'draft', ...$values,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $program->fresh()->getAttributes());
    }

    public function test_valid_partial_support_end_edit_uses_the_saved_start_date(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
            'title' => $program->title, 'status' => 'draft', 'support_ends_at' => '2028-08-01',
        ])->assertOk()->assertJsonPath('scholarship.support_starts_at', '2027-08-03')
            ->assertJsonPath('scholarship.support_ends_at', '2028-08-01');
    }

    public function test_equal_capacity_same_day_dates_and_an_unlimited_application_intake_remain_valid(): void
    {
        $provider = $this->provider();
        foreach ([20, null] as $limit) {
            $this->actingAs($provider)->postJson('/provider/scholarships', [
                'title' => 'Valid boundary program', 'status' => 'draft',
                'slots_available' => 20, 'application_limit' => $limit,
                'application_opens_at' => '2027-06-01', 'deadline' => '2027-06-01',
                'expected_results_at' => '2027-06-03', 'handoff_deadline' => '2027-06-02',
                'support_starts_at' => '2027-06-03', 'support_ends_at' => '2027-06-03',
            ])->assertCreated();
        }
    }

    public function test_a_title_only_draft_and_explicitly_clearing_an_optional_limit_are_still_allowed(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider)->postJson('/provider/scholarships', ['title' => 'Initial draft', 'status' => 'draft'])->assertCreated();
        $program = $this->program($provider);
        $this->putJson('/provider/scholarships/'.$program->id, [
            'title' => $program->title, 'status' => 'draft', 'application_limit' => null,
        ])->assertOk()->assertJsonPath('scholarship.application_limit', null);
    }

    public function test_admin_cannot_publish_an_existing_program_with_conflicting_capacity_or_dates(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $program->update(['status' => 'pending_review', 'application_limit' => 10, 'expected_results_at' => '2027-06-29']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/admin/scholarships/'.$program->id.'/review', ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::program($program->id), 'status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors(['application_limit', 'expected_results_at']);
        $this->assertSame('pending_review', $program->fresh()->status);
    }

    public function test_unchanged_live_timeline_can_be_kept_but_cannot_be_made_invalid_or_republished(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $program->update(['status' => 'published', 'support_starts_at' => '2027-06-25']);

        $this->assertSame([], ProgramConfigurationRules::errors([], $program));
        $this->assertArrayHasKey('support_starts_at', ProgramConfigurationRules::errors([], $program, enforceFullTimeline: true));
        $this->assertArrayHasKey('support_starts_at', ProgramConfigurationRules::errors(['support_ends_at' => '2028-08-01'], $program));
        $this->assertSame([], ProgramConfigurationRules::errors(['support_starts_at' => '2027-08-03'], $program));

        $program->update(['status' => 'pending_review']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/admin/scholarships/'.$program->id.'/review', [
                'expected_review_token' => \Tests\Support\AdminReviewSnapshots::program($program->id), 'status' => 'published',
            ])->assertUnprocessable()->assertJsonValidationErrors('support_starts_at');
        $this->assertSame('2027-06-25', $program->fresh()->support_starts_at?->toDateString());
    }

    public function test_creation_update_and_publication_reject_conflicts_between_document_groups(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Duplicate pre-screening requirements', 'status' => 'draft',
            'application_mode' => 'online', 'requirements' => 'School ID', 'optional_requirements' => 'Recent school ID',
        ])->assertUnprocessable()->assertJsonValidationErrors('optional_requirements');

        $program = $this->program($provider);
        $program->update(['application_mode' => 'online', 'requirements' => 'School ID']);
        $this->putJson('/provider/scholarships/'.$program->id, [
            'title' => $program->title, 'status' => 'draft', 'optional_requirements' => 'Student ID',
        ])->assertUnprocessable()->assertJsonValidationErrors('optional_requirements');
        $this->assertNull($program->fresh()->optional_requirements);

        $program->update(['status' => 'pending_review', 'optional_requirements' => 'Current school ID']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patchJson('/admin/scholarships/'.$program->id.'/review', [
                'expected_review_token' => \Tests\Support\AdminReviewSnapshots::program($program->id), 'status' => 'published',
            ])->assertUnprocessable()->assertJsonValidationErrors('optional_requirements');
    }

    public function test_profile_only_keeps_old_file_lists_but_cannot_bypass_document_eligibility(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $program->update(['application_mode' => 'online', 'requirements' => 'School ID']);
        $payload = ['title' => $program->title, 'status' => 'draft', 'application_mode' => 'provider_review'];
        $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
            ...$payload, 'requirements' => 'Ignored input',
        ])->assertOk();
        $this->assertSame('School ID', $program->fresh()->requirements);
        $this->putJson('/provider/scholarships/'.$program->id, [
            ...$payload, 'eligibility_conditions' => json_encode([['key' => 'required_documents', 'statement' => 'Review the required documents.']]),
        ])->assertUnprocessable()->assertJsonValidationErrors('application_mode');
    }

    public function test_handoff_mode_clears_inactive_fields_from_storage_and_applicant_details(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $program->update(['handoff_mode' => 'onsite', 'handoff_location_name' => 'Main office', 'handoff_location_address' => 'Antipolo']);
        $providerForm = ['title' => $program->title, 'status' => 'draft'];
        $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
            ...$providerForm, 'handoff_mode' => 'online', 'handoff_url' => 'https://forms.example.test/scholarship',
        ])->assertOk()->assertJsonPath('scholarship.handoff_location_address', null);
        $program->refresh();
        $this->assertNull($program->handoff_location_name);
        $program->forceFill(['handoff_location_address' => 'Old address in a legacy record']);
        $channel = ProgramApplicationRules::handoffChannel($program);
        $this->assertSame('https://forms.example.test/scholarship', $channel['url']);
        $this->assertNull($channel['location_address']);
        $this->assertNull($channel['map_url']);
        $this->putJson('/provider/scholarships/'.$program->id, [
            ...$providerForm, 'handoff_mode' => 'provider_contact',
        ])->assertOk()->assertJsonPath('scholarship.handoff_url', null);
        $this->assertNull($program->fresh()->handoff_url);
    }

    public function test_new_support_dates_cannot_be_in_the_past_even_in_drafts(): void
    {
        $this->actingAs($this->provider());
        foreach (['draft', 'pending_review'] as $status) {
            foreach (['application_opens_at', 'deadline', 'expected_results_at', 'handoff_deadline', 'support_starts_at', 'support_ends_at'] as $field) {
                $this->postJson('/provider/scholarships', [
                    'title' => 'New support period', 'description' => 'Program date validation.', 'status' => $status, 'terms_accepted' => true,
                    $field => now()->subDay()->toDateString(),
                ])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseCount('scholarships', 0);
        $this->postJson('/provider/scholarships', [
            'title' => 'Same day support', 'status' => 'draft',
            'deadline' => now()->toDateString(),
            'support_starts_at' => now()->addDay()->toDateString(), 'support_ends_at' => now()->addDay()->toDateString(),
        ])->assertCreated();
    }

    public function test_edits_cannot_replace_support_dates_with_past_dates(): void
    {
        $provider = $this->provider();
        $program = $this->program($provider);
        $before = $program->fresh()->getAttributes();
        foreach (['support_starts_at', 'support_ends_at'] as $field) {
            $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
                'title' => $program->title, 'status' => 'draft', $field => now()->subDay()->toDateString(),
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $program->fresh()->getAttributes());
    }

    public function test_historical_published_dates_are_preserved_but_new_past_dates_and_stale_drafts_are_rejected(): void
    {
        $program = $this->program($this->provider());
        $program->update(['status' => 'published', 'support_starts_at' => '2026-09-01', 'support_ends_at' => '2026-10-31']);
        $this->assertSame([], ProgramConfigurationRules::errors([], $program));
        $this->assertSame([], ProgramConfigurationRules::errors(['support_starts_at' => '2026-09-01', 'support_ends_at' => '2026-10-31'], $program));
        $this->assertArrayHasKey('support_starts_at', ProgramConfigurationRules::errors(['support_starts_at' => '2026-09-02'], $program));
        $this->assertArrayHasKey('support_ends_at', ProgramConfigurationRules::errors(['support_ends_at' => '2026-11-01'], $program));
        $program->update(['status' => 'draft']);
        $this->assertArrayHasKey('support_starts_at', ProgramConfigurationRules::errors([], $program));
        $this->assertArrayHasKey('support_ends_at', ProgramConfigurationRules::errors([], $program));
        $program->update(['status' => 'pending_review']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/admin/scholarships/'.$program->id.'/review', [
            'expected_review_token' => \Tests\Support\AdminReviewSnapshots::program($program->id), 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors(['support_starts_at', 'support_ends_at']);
    }

    private function provider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        return $provider;
    }

    private function program(User $provider): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Saved configuration', 'description' => 'Existing draft.', 'status' => 'draft',
            'slots_available' => 20, 'application_limit' => 100,
            'application_opens_at' => '2027-06-01', 'deadline' => '2027-06-30',
            'expected_results_at' => '2027-08-02', 'handoff_deadline' => '2027-07-31',
            'support_starts_at' => '2027-08-03', 'support_ends_at' => '2028-07-01',
        ]);
    }
}
