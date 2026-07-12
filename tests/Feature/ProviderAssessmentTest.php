<?php

namespace Tests\Feature;

use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_seeded_provider_sees_only_its_own_assessment_and_logo(): void
    {
        $this->seed();

        $dost = User::query()->where('email', 'provider@scholarship.test')->firstOrFail();
        $ched = User::query()->where('email', 'ched.provider@scholarship.test')->firstOrFail();

        $this->actingAs($dost)
            ->getJson('/provider/exams/data')
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.title', 'DOST-SEI Scholarship Qualifying Examination')
            ->assertJsonPath('assessments.0.image_path', '/images/programs/dost-logo-card.jpg');

        $this->actingAs($ched)
            ->getJson('/provider/exams/data')
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.title', 'CHED Merit Eligibility Screening Assessment')
            ->assertJsonPath('assessments.0.assessment_type', 'screening_assessment')
            ->assertJsonPath('assessments.0.image_path', '/images/programs/ched-logo-card.jpg');
    }

    public function test_provider_can_update_its_assessment_but_not_another_providers_assessment(): void
    {
        $this->seed();

        $dost = User::query()->where('email', 'provider@scholarship.test')->firstOrFail();
        $ched = User::query()->where('email', 'ched.provider@scholarship.test')->firstOrFail();
        $dostAssessment = ProviderAssessment::query()->where('provider_id', $dost->id)->firstOrFail();
        $chedAssessment = ProviderAssessment::query()->where('provider_id', $ched->id)->firstOrFail();
        $payload = [
            'title' => 'Updated DOST Qualifying Examination',
            'assessment_type' => 'qualifying_exam',
            'description' => 'Updated assessment details.',
            'duration_minutes' => 150,
            'passing_score' => 80,
            'delivery_mode' => 'onsite',
            'venue' => 'Assigned testing center',
            'instructions' => 'Bring a valid school identification card.',
            'status' => 'active',
        ];

        $this->actingAs($dost)
            ->patchJson("/provider/exams/{$dostAssessment->id}", $payload)
            ->assertOk()
            ->assertJsonPath('assessment.title', 'Updated DOST Qualifying Examination')
            ->assertJsonPath('assessment.duration_minutes', 150);

        $this->assertDatabaseHas('provider_assessments', [
            'id' => $dostAssessment->id,
            'title' => 'Updated DOST Qualifying Examination',
            'passing_score' => 80,
        ]);

        $this->actingAs($dost)
            ->patchJson("/provider/exams/{$chedAssessment->id}", $payload)
            ->assertForbidden();
    }

    public function test_applicant_sees_the_provider_assessment_only_during_the_exam_workflow(): void
    {
        $this->seed();

        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()->where('title', 'DOST-SEI Merit Undergraduate Scholarship')->firstOrFail();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.exam', null);

        $application->update(['status' => 'exam_qualified']);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.exam.title', 'DOST-SEI Scholarship Qualifying Examination')
            ->assertJsonPath('application.exam.assessment_type', 'qualifying_exam')
            ->assertJsonPath('application.exam.duration_minutes', 120);
    }
}
