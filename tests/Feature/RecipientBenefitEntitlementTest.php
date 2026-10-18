<?php

namespace Tests\Feature;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\RecipientBenefitEntitlement;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBenefit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientBenefitEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_the_recipient_agreement_creates_immutable_benefit_entitlements(): void
    {
        [$provider, $applicant, $program, $application] = $this->selectedApplication();
        $oneTimeBenefit = $program->benefits()->create([
            'type' => 'device_support',
            'title' => 'Learning device',
            'amount' => 18000,
            'coverage' => 'fixed',
            'frequency' => 'one_time',
            'description' => 'One laptop for school work.',
            'sort_order' => 1,
        ]);
        $program->benefits()->create([
            'type' => 'allowance',
            'title' => 'Monthly learning allowance',
            'amount' => 2000,
            'coverage' => 'fixed',
            'frequency' => 'monthly',
            'duration' => '10 months',
            'sort_order' => 2,
        ]);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'accepted',
                'terms_accepted' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $oneTimeBenefit->id,
            'title' => 'Learning device',
            'amount' => 18000,
            'frequency' => 'one_time',
            'status' => RecipientSupportCatalog::ENTITLEMENT_READY,
        ]);
        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'title' => 'Monthly learning allowance',
            'frequency' => 'monthly',
            'status' => RecipientSupportCatalog::ENTITLEMENT_ACTIVE,
        ]);

        $oneTimeBenefit->update([
            'title' => 'Updated device package',
            'amount' => 25000,
        ]);

        $entitlement = RecipientBenefitEntitlement::query()
            ->where('scholarship_application_id', $application->id)
            ->where('scholarship_benefit_id', $oneTimeBenefit->id)
            ->sole();
        $this->assertSame('Learning device', $entitlement->title);
        $this->assertSame('18000.00', $entitlement->amount);
    }

    public function test_one_time_benefit_can_only_have_one_active_release_allocation(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(true);
        $benefit = $program->benefits()->create([
            'type' => 'school_supplies',
            'title' => 'School essentials package',
            'frequency' => 'one_time',
            'sort_order' => 1,
        ]);
        $payload = $this->releasePayload($application, $benefit, 'School essentials distribution');

        $response = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$program->id}/benefit-releases", $payload)
            ->assertCreated()
            ->assertJsonPath('release.benefits.0.benefit_id', $benefit->id)
            ->assertJsonPath('release.records.0.benefits.0.title', 'School essentials package');

        $this->assertDatabaseHas('recipient_benefit_release_allocations', [
            'recipient_benefit_release_record_id' => $response->json('release.records.0.id'),
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_SCHEDULED,
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$program->id}/benefit-releases", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_ids');

        $this->assertDatabaseCount('recipient_benefit_releases', 1);
        $this->assertDatabaseCount('recipient_benefit_release_allocations', 1);
    }

    public function test_recurring_benefit_can_be_allocated_to_multiple_release_schedules(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(true);
        $benefit = $program->benefits()->create([
            'type' => 'allowance',
            'title' => 'Monthly transport allowance',
            'amount' => 1500,
            'frequency' => 'monthly',
            'duration' => '10 months',
            'sort_order' => 1,
        ]);

        $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'November transport allowance'),
            )
            ->assertCreated();

        $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'December transport allowance', 32),
            )
            ->assertCreated();

        $this->assertDatabaseCount('recipient_benefit_releases', 2);
        $this->assertDatabaseCount('recipient_benefit_release_allocations', 2);
        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_ACTIVE,
        ]);
    }

    public function test_release_rejects_a_benefit_from_another_program(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(true);
        $otherProgram = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Separate Scholarship',
            'description' => 'A separate benefit package.',
            'status' => 'published',
        ]);
        $otherBenefit = $otherProgram->benefits()->create([
            'type' => 'allowance',
            'title' => 'Separate allowance',
            'frequency' => 'one_time',
            'sort_order' => 1,
        ]);

        $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $otherBenefit, 'Invalid release'),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('benefit_ids');

        $this->assertDatabaseCount('recipient_benefit_releases', 0);
    }

    public function test_one_time_benefit_is_fulfilled_only_after_recipient_confirmation(): void
    {
        [$provider, $applicant, $program, $application] = $this->selectedApplication(true);
        $benefit = $program->benefits()->create([
            'type' => 'device_support',
            'title' => 'Learning tablet',
            'frequency' => 'one_time',
            'sort_order' => 1,
        ]);
        $release = $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'Learning tablet release', 0),
            )
            ->assertCreated();
        $recordId = $release->json('release.records.0.id');

        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'released',
                'notes' => 'The sealed tablet was handed to the recipient.',
            ])
            ->assertOk()
            ->assertJsonPath('release.records.0.lifecycle_status', 'awaiting_confirmation')
            ->assertJsonPath('release.records.0.status_label', 'Waiting for confirmation');

        $this->assertDatabaseHas('recipient_benefit_release_allocations', [
            'recipient_benefit_release_record_id' => $recordId,
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_RELEASED,
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
                'response_type' => 'confirmed',
                'received_on' => now()->toDateString(),
                'acknowledged' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.lifecycle_status', 'fulfilled')
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.status_label', 'Receipt confirmed');

        $this->assertDatabaseHas('recipient_benefit_release_allocations', [
            'recipient_benefit_release_record_id' => $recordId,
            'status' => 'fulfilled',
        ]);
        $entitlement = RecipientBenefitEntitlement::query()
            ->where('scholarship_application_id', $application->id)
            ->where('scholarship_benefit_id', $benefit->id)
            ->sole();
        $this->assertSame(RecipientSupportCatalog::ENTITLEMENT_FULFILLED, $entitlement->status);
        $this->assertNotNull($entitlement->fulfilled_at);
    }

    public function test_missed_one_time_release_returns_the_benefit_to_ready_for_rescheduling(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(true);
        $benefit = $program->benefits()->create([
            'type' => 'school_supplies',
            'title' => 'School supply kit',
            'frequency' => 'one_time',
            'sort_order' => 1,
        ]);
        $firstRelease = $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'Initial supply pickup', 0),
            )
            ->assertCreated();
        $recordId = $firstRelease->json('release.records.0.id');

        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'missed',
                'notes' => 'The recipient could not attend the scheduled pickup.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_READY,
        ]);
        $this->assertDatabaseHas('recipient_benefit_release_allocations', [
            'recipient_benefit_release_record_id' => $recordId,
            'status' => 'missed',
        ]);

        $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'Rescheduled supply pickup'),
            )
            ->assertCreated();

        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'released',
                'notes' => 'Attempting to reuse the closed missed-release record.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('recipient_benefit_releases', 2);
        $this->assertDatabaseCount('recipient_benefit_release_allocations', 2);
    }

    public function test_open_issue_blocks_completion_until_provider_resolves_or_replaces_the_benefit(): void
    {
        [$provider, $applicant, $program, $application] = $this->selectedApplication(true);
        $benefit = $program->benefits()->create([
            'type' => 'device_support',
            'title' => 'Learning device package',
            'frequency' => 'one_time',
            'sort_order' => 1,
        ]);
        $release = $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'Device package release', 0),
            )
            ->assertCreated();
        $recordId = $release->json('release.records.0.id');
        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'released',
                'notes' => 'The device package was handed to the recipient.',
            ])
            ->assertOk();

        $issue = $this->actingAs($applicant)
            ->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
                'response_type' => 'issue',
                'issue_type' => 'damaged_item',
                'issue_details' => 'The device screen was damaged when the package was opened.',
                'acknowledged' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.lifecycle_status', 'issue_open');

        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_ISSUE_OPEN,
        ]);
        $responseId = $issue->json('application.recipient_monitoring.benefit_releases.0.receipt_response.id');

        $this->actingAs($provider)
            ->postJson("/provider/benefit-receipt-responses/{$responseId}/resolve", [
                'resolution_outcome' => 'replacement_scheduled',
                'resolution_notes' => 'The damaged unit was verified and a replacement must be scheduled.',
            ])
            ->assertOk()
            ->assertJsonPath('release.records.0.lifecycle_status', 'resolved');

        $this->assertDatabaseHas('recipient_benefit_release_allocations', [
            'recipient_benefit_release_record_id' => $recordId,
            'status' => 'replacement_required',
        ]);
        $this->assertDatabaseHas('recipient_benefit_entitlements', [
            'scholarship_application_id' => $application->id,
            'scholarship_benefit_id' => $benefit->id,
            'status' => RecipientSupportCatalog::ENTITLEMENT_READY,
        ]);

        $this->actingAs($provider)
            ->postJson(
                "/provider/scholarships/{$program->id}/benefit-releases",
                $this->releasePayload($application, $benefit, 'Replacement device release'),
            )
            ->assertCreated();
    }

    private function selectedApplication(bool $agreementAccepted = false): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Future Ready Education Grant',
            'description' => 'A scholarship used to verify benefit entitlement behavior.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'document_checklist' => [],
            'provider_contract_terms_snapshot' => [
                'responsibilities' => 'Confirm each scheduled benefit release.',
            ],
            'provider_contract_terms_version' => 'benefit-entitlement-v1',
            'student_response_status' => $agreementAccepted ? 'accepted' : null,
            'student_responded_at' => $agreementAccepted ? now() : null,
            'student_response_terms_accepted_at' => $agreementAccepted ? now() : null,
            'provider_contract_terms_accepted_at' => $agreementAccepted ? now() : null,
            'submitted_at' => now()->subMonth(),
        ]);

        return [$provider, $applicant, $program, $application];
    }

    private function releasePayload(
        ScholarshipApplication $application,
        ScholarshipBenefit $benefit,
        string $title,
        int $daysFromNow = 1,
    ): array {
        $releaseAt = $daysFromNow === 0
            ? now()->startOfDay()
            : now()->addDays($daysFromNow);

        return [
            'title' => $title,
            'release_at' => $releaseAt->format('Y-m-d H:i:s'),
            'benefit_description' => $benefit->title,
            'amount' => $benefit->amount,
            'release_method' => 'in_person',
            'location' => 'Program service desk',
            'instructions' => 'Bring a valid school ID.',
            'requires_original_verification' => false,
            'benefit_ids' => [$benefit->id],
            'recipient_ids' => [$application->id],
        ];
    }
}
