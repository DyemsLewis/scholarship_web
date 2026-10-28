<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
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
    }

    public static function conflictingConfigurations(): array
    {
        $conflicts = [
            'too few applicants' => [['slots_available' => 30, 'application_limit' => 20], 'application_limit'],
            'late opening' => [['application_opens_at' => '2027-06-02', 'deadline' => '2027-06-01'], 'application_opens_at'],
            'early results' => [['expected_results_at' => '2027-05-31', 'deadline' => '2027-06-01'], 'expected_results_at'],
            'early formal deadline' => [['handoff_deadline' => '2027-05-31', 'deadline' => '2027-06-01'], 'handoff_deadline'],
            'backward support' => [['support_starts_at' => '2027-09-01', 'support_ends_at' => '2027-08-31'], 'support_ends_at'],
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
        ])->assertOk()->assertJsonPath('scholarship.support_starts_at', '2027-07-01')
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
                'expected_results_at' => '2027-06-01', 'handoff_deadline' => '2027-06-01',
                'support_starts_at' => '2027-07-01', 'support_ends_at' => '2027-07-01',
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
        $this->actingAs($admin)->patchJson('/admin/scholarships/'.$program->id.'/review', ['status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors(['application_limit', 'expected_results_at']);
        $this->assertSame('pending_review', $program->fresh()->status);
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
            'expected_results_at' => '2027-07-15', 'handoff_deadline' => '2027-07-31',
            'support_starts_at' => '2027-07-01', 'support_ends_at' => '2028-07-01',
        ]);
    }
}
