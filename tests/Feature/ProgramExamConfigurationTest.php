<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramExamConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_exams_page_redirects_to_programs(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->get('/provider/exams')
            ->assertRedirect('/provider/programs');
    }

    public function test_provider_can_update_exam_details_for_its_program_only(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $otherProvider->providerProfile()->update(['verification_status' => 'approved']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Provider Exam Scholarship',
            'description' => 'A scholarship with a provider-managed qualifying exam.',
            'selection_stages' => ['screening', 'exam', 'distribution'],
            'status' => 'draft',
        ]);
        $payload = [
            'title' => $scholarship->title,
            'description' => $scholarship->description,
            'selection_stages' => json_encode(['exam']),
            'exam_duration_minutes' => 90,
            'exam_passing_score' => 80,
            'status' => 'draft',
            'terms_accepted' => true,
        ];

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", $payload)
            ->assertOk()
            ->assertJsonPath('scholarship.exam_duration_minutes', 90)
            ->assertJsonPath('scholarship.exam_passing_score', '80.00');

        $this->assertDatabaseHas('scholarships', [
            'id' => $scholarship->id,
            'exam_duration_minutes' => 90,
            'exam_passing_score' => 80,
        ]);

        $this->actingAs($otherProvider)
            ->putJson("/provider/scholarships/{$scholarship->id}", $payload)
            ->assertForbidden();
    }

    public function test_applicant_sees_program_exam_details_only_during_the_exam_workflow(): void
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
            ->assertJsonPath('application.exam', null)
            ->assertJsonPath('application.scholarship.exam_duration_minutes', 60)
            ->assertJsonPath('application.scholarship.exam_passing_score', '75.00');

        $application->update(['status' => 'exam_qualified']);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.exam.title', 'STEM Pathways Qualifying Exam')
            ->assertJsonPath('application.exam.duration_minutes', 60)
            ->assertJsonPath('application.exam.passing_score', '75.00')
            ->assertJsonPath('application.exam.delivery_mode', 'onsite');
    }
}
