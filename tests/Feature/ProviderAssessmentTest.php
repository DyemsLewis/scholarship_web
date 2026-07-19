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

        $tulayAral = User::query()->where('email', 'tulayaral@scholarship.test')->firstOrFail();
        $bukasKinabukasan = User::query()->where('email', 'bukasfoundation@scholarship.test')->firstOrFail();

        $this->actingAs($tulayAral)
            ->getJson('/provider/exams/data')
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.title', 'Tulay Aral Applicant Screening')
            ->assertJsonPath('assessments.0.image_path', '/uploads/scholarship-default.jpg');

        $this->actingAs($bukasKinabukasan)
            ->getJson('/provider/exams/data')
            ->assertOk()
            ->assertJsonCount(1, 'assessments')
            ->assertJsonPath('assessments.0.title', 'Bukas Kinabukasan STEM Qualifying Activity')
            ->assertJsonPath('assessments.0.assessment_type', 'qualifying_exam')
            ->assertJsonPath('assessments.0.image_path', '/uploads/scholarship-default.jpg');
    }

    public function test_provider_can_update_its_assessment_but_not_another_providers_assessment(): void
    {
        $this->seed();

        $tulayAral = User::query()->where('email', 'tulayaral@scholarship.test')->firstOrFail();
        $bukasKinabukasan = User::query()->where('email', 'bukasfoundation@scholarship.test')->firstOrFail();
        $tulayAssessment = ProviderAssessment::query()->where('provider_id', $tulayAral->id)->firstOrFail();
        $bukasAssessment = ProviderAssessment::query()->where('provider_id', $bukasKinabukasan->id)->firstOrFail();
        $payload = [
            'title' => 'Updated Community Screening',
            'assessment_type' => 'qualifying_exam',
            'description' => 'Updated assessment details.',
            'duration_minutes' => 150,
            'passing_score' => 80,
            'delivery_mode' => 'onsite',
            'venue' => 'Assigned testing center',
            'instructions' => 'Bring a valid school identification card.',
            'status' => 'active',
        ];

        $this->actingAs($tulayAral)
            ->patchJson("/provider/exams/{$tulayAssessment->id}", $payload)
            ->assertOk()
            ->assertJsonPath('assessment.title', 'Updated Community Screening')
            ->assertJsonPath('assessment.duration_minutes', 150);

        $this->assertDatabaseHas('provider_assessments', [
            'id' => $tulayAssessment->id,
            'title' => 'Updated Community Screening',
            'passing_score' => 80,
        ]);

        $this->actingAs($tulayAral)
            ->patchJson("/provider/exams/{$bukasAssessment->id}", $payload)
            ->assertForbidden();
    }

    public function test_applicant_sees_the_provider_assessment_only_during_the_exam_workflow(): void
    {
        $this->seed();

        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()->where('title', 'Bukas Kinabukasan STEM Pathways Grant')->firstOrFail();
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
            ->assertJsonPath('application.exam.title', 'Bukas Kinabukasan STEM Qualifying Activity')
            ->assertJsonPath('application.exam.assessment_type', 'qualifying_exam')
            ->assertJsonPath('application.exam.duration_minutes', 60);
    }
}
