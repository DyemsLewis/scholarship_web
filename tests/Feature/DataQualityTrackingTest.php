<?php

namespace Tests\Feature;

use App\Models\DssCalculationSnapshot;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Support\AcademicRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DataQualityTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_funnel_events_are_deduplicated_and_submission_creates_a_dss_snapshot(): void
    {
        Mail::fake();
        [$provider, $applicant, $scholarship] = $this->applicationScenario();

        $detailUrl = "/dashboard/scholarships/{$scholarship->id}/data";
        $startUrl = "/dashboard/scholarships/{$scholarship->id}/application-start";

        $this->actingAs($applicant)->getJson($detailUrl)->assertOk();
        $this->actingAs($applicant)->getJson($detailUrl)->assertOk();
        $this->actingAs($applicant)->postJson($startUrl)->assertOk();
        $this->actingAs($applicant)->postJson($startUrl)->assertOk();

        $this->assertSame(1, $scholarship->fresh()->views_count);
        $this->assertSame(1, ScholarshipFunnelEvent::query()->where('event_type', 'scholarship_viewed')->count());
        $this->assertSame(1, ScholarshipFunnelEvent::query()->where('event_type', 'application_started')->count());

        $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'document_checklist' => [],
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'submitted');

        $application = ScholarshipApplication::query()->sole();
        $snapshot = DssCalculationSnapshot::query()->where('scholarship_application_id', $application->id)->sole();

        $this->assertDatabaseHas('scholarship_funnel_events', [
            'scholarship_application_id' => $application->id,
            'event_type' => 'application_submitted',
            'source' => 'web',
        ]);
        $this->assertSame(DecisionSupportService::METHODOLOGY_VERSION, $snapshot->methodology_version);
        $this->assertSame('web_application_submitted', $snapshot->source);
        $this->assertSame('same_scale_numeric', $snapshot->academic_evaluation['comparison_mode']);
        $this->assertTrue($snapshot->academic_evaluation['is_comparable']);

        app(DecisionSupportService::class)->syncApplication($application, 'test_repeat');

        $this->assertSame(1, DssCalculationSnapshot::query()->where('scholarship_application_id', $application->id)->count());
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'application',
        ]);
    }

    public function test_provider_status_changes_create_auditable_outcome_events_and_new_snapshots(): void
    {
        Mail::fake();
        [$provider, $applicant, $scholarship] = $this->applicationScenario();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        app(DecisionSupportService::class)->syncApplication($application, 'test_initial');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'under_review',
                'review_notes' => 'Eligibility and documents are being reviewed.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'rejected',
                'decision_reason' => 'missing_documents',
                'review_notes' => 'A required document was not submitted.',
            ])
            ->assertOk();

        $outcomeEvent = ScholarshipFunnelEvent::query()
            ->where('scholarship_application_id', $application->id)
            ->where('event_type', 'application_status_rejected')
            ->sole();

        $this->assertSame($applicant->id, $outcomeEvent->user_id);
        $this->assertSame('provider', $outcomeEvent->source);
        $this->assertSame('under_review', $outcomeEvent->metadata['previous_status']);
        $this->assertSame('missing_documents', $outcomeEvent->metadata['canonical_decision_reason']);
        $this->assertSame(3, DssCalculationSnapshot::query()->where('scholarship_application_id', $application->id)->count());
        $this->assertSame('provider_status_updated', DssCalculationSnapshot::query()->latest('id')->value('source'));
    }

    public function test_incompatible_or_non_numeric_grading_scales_are_flagged_for_manual_review(): void
    {
        $scaleMismatch = AcademicRequirement::match(1.75, 'grade_point', 85, 'percentage');
        $passFail = AcademicRequirement::match(null, 'pass_fail', null, 'pass_fail');

        $this->assertSame('missing', $scaleMismatch['status']);
        $this->assertSame('scale_mismatch', $scaleMismatch['comparison_mode']);
        $this->assertFalse($scaleMismatch['is_comparable']);
        $this->assertSame('info', $passFail['status']);
        $this->assertSame('manual_review', $passFail['comparison_mode']);
        $this->assertFalse($passFail['counts']);
    }

    private function applicationScenario(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Sample University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Data Quality Scholarship',
            'description' => 'A test scholarship for event and DSS snapshot coverage.',
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
            'views_count' => 0,
        ]);

        return [$provider, $applicant->fresh(), $scholarship];
    }
}
