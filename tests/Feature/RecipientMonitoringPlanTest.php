<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientMonitoringPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_create_activate_and_update_a_monitoring_plan(): void
    {
        [$provider, $scholarship] = $this->providerProgram();

        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-plan")
            ->assertOk()
            ->assertJsonPath('plan', null)
            ->assertJsonPath('can_edit', true)
            ->assertJsonFragment(['value' => 'academic_progress'])
            ->assertJsonFragment(['value' => 'enrollment']);

        $createResponse = $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}/monitoring-plan", [
                'frequency' => 'semester',
                'starts_on' => '2026-10-01',
                'ends_on' => '2027-06-30',
                'grace_period_days' => 7,
                'allow_exception_requests' => true,
                'instructions' => 'Submit readable records before each deadline.',
                'status' => 'active',
                'requirements' => [
                    [
                        'type' => 'academic_progress',
                        'title' => 'Semester grades',
                        'description' => 'Confirms continuing academic progress.',
                        'evidence_description' => 'Latest report card or official grade report.',
                        'required' => true,
                        'requires_file' => true,
                        'requires_original_verification' => true,
                        'minimum_grade' => 85,
                        'grading_scale' => 'percentage',
                    ],
                    [
                        'type' => 'enrollment',
                        'title' => 'Current enrollment',
                        'description' => 'Confirms that the recipient remains enrolled.',
                        'evidence_description' => 'Enrollment certificate or registration form.',
                        'required' => true,
                        'requires_file' => true,
                        'requires_original_verification' => false,
                        'minimum_grade' => null,
                        'grading_scale' => null,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('plan.status', 'active')
            ->assertJsonPath('plan.version', 1)
            ->assertJsonPath('plan.requirement_count', 2);

        $planId = $createResponse->json('plan.id');
        $academicRequirementId = $createResponse->json('plan.requirements.0.id');

        $this->assertDatabaseHas('recipient_monitoring_plans', [
            'id' => $planId,
            'scholarship_id' => $scholarship->id,
            'status' => 'active',
            'version' => 1,
        ]);
        $this->assertDatabaseCount('recipient_monitoring_requirements', 2);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}/monitoring-plan", [
                'frequency' => 'quarterly',
                'starts_on' => '2026-10-01',
                'ends_on' => '2027-06-30',
                'grace_period_days' => 10,
                'allow_exception_requests' => true,
                'instructions' => null,
                'status' => 'active',
                'requirements' => [[
                    'id' => $academicRequirementId,
                    'type' => 'academic_progress',
                    'title' => 'Quarterly grades',
                    'description' => null,
                    'evidence_description' => 'Latest official grade report.',
                    'required' => true,
                    'requires_file' => true,
                    'requires_original_verification' => false,
                    'minimum_grade' => 86,
                    'grading_scale' => 'percentage',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('plan.frequency', 'quarterly')
            ->assertJsonPath('plan.version', 2)
            ->assertJsonPath('plan.requirement_count', 1)
            ->assertJsonPath('plan.requirements.0.title', 'Quarterly grades');

        $this->assertDatabaseCount('recipient_monitoring_requirements', 1);
        $this->assertDatabaseHas('recipient_monitoring_requirements', [
            'id' => $academicRequirementId,
            'title' => 'Quarterly grades',
            'minimum_grade' => 86,
        ]);
    }

    public function test_active_plan_requires_a_requirement_and_valid_dates(): void
    {
        [$provider, $scholarship] = $this->providerProgram();

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}/monitoring-plan", [
                'frequency' => 'semester',
                'starts_on' => '2027-06-30',
                'ends_on' => '2026-10-01',
                'grace_period_days' => 7,
                'allow_exception_requests' => true,
                'status' => 'active',
                'requirements' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_on');

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}/monitoring-plan", [
                'frequency' => 'semester',
                'starts_on' => '2026-10-01',
                'ends_on' => '2027-06-30',
                'grace_period_days' => 7,
                'allow_exception_requests' => true,
                'status' => 'active',
                'requirements' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirements');
    }

    public function test_reviewer_can_view_but_cannot_change_the_monitoring_plan(): void
    {
        [$owner, $scholarship] = $this->providerProgram();
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
            'parent_account_id' => $owner->id,
            'permissions' => ['review_applications'],
            'assigned_program_ids' => [$scholarship->id],
        ]);

        $this->actingAs($reviewer)
            ->get("/provider/monitoring/{$scholarship->id}/plan")
            ->assertOk()
            ->assertViewIs('provider-monitoring-plan');

        $this->actingAs($reviewer)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-plan")
            ->assertOk()
            ->assertJsonPath('can_edit', false);

        $this->actingAs($reviewer)
            ->putJson("/provider/scholarships/{$scholarship->id}/monitoring-plan", $this->draftPayload())
            ->assertForbidden();
    }

    public function test_provider_cannot_open_another_organization_monitoring_plan(): void
    {
        [, $scholarship] = $this->providerProgram();
        $otherProvider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);

        $this->actingAs($otherProvider)
            ->get("/provider/monitoring/{$scholarship->id}/plan")
            ->assertForbidden();

        $this->actingAs($otherProvider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-plan")
            ->assertForbidden();
    }

    /** @return array{User, Scholarship} */
    private function providerProgram(): array
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Continuing Scholar Support',
            'description' => 'A continuing scholarship used to test recipient monitoring.',
            'status' => 'published',
        ]);

        return [$provider, $scholarship];
    }

    /** @return array<string, mixed> */
    private function draftPayload(): array
    {
        return [
            'frequency' => 'semester',
            'starts_on' => null,
            'ends_on' => null,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'instructions' => null,
            'status' => RecipientMonitoringPlan::STATUSES[0],
            'requirements' => [],
        ];
    }
}
