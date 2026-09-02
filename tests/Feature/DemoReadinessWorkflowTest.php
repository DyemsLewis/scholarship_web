<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\ApplicationDocument;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoReadinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_admin_provider_and_applicant_can_complete_the_core_workflow(): void
    {
        Mail::fake();
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = $this->registerAndVerifyUser('provider');
        $applicant = $this->completeApplicant($this->registerAndVerifyUser('applicant'));

        $this->actingAs($provider)
            ->patchJson('/provider/profile', [
                'profile_section' => 'organization',
                'provider_name' => 'Fresh Start Scholarship Foundation',
                'provider_type' => 'foundation',
                'provider_website' => 'https://example.test/fresh-start',
                'provider_address' => 'Quezon City, Metro Manila',
                'provider_description' => 'A community provider used to verify the clean-account workflow.',
                'provider_contact_email' => 'programs@example.test',
                'provider_contact_number' => '09179876543',
            ])
            ->assertOk()
            ->assertJsonPath('user.provider_name', 'Fresh Start Scholarship Foundation');

        $this->actingAs($provider)
            ->post('/provider/verification-documents', [
                'document_type' => 'organization_registration',
                'document_file' => UploadedFile::fake()->create('registration.pdf', 100, 'application/pdf'),
                'terms_accepted' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('document.document_type', 'organization_registration');

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", [
                'verification_status' => 'approved',
                'verification_notes' => 'Organization details and proof were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.verification_status', 'approved');

        // Production requests reload the authenticated provider after admin approval.
        $provider = $provider->fresh(['providerProfile']);

        $applicationOpensAt = now()->subDay()->toDateString();
        $deadline = now()->addMonth()->toDateString();
        $expectedResultsAt = now()->addMonth()->addWeeks(2)->toDateString();
        $applicationOpensAtLabel = Carbon::parse($applicationOpensAt)->format('M d, Y');
        $expectedResultsAtLabel = Carbon::parse($expectedResultsAt)->format('M d, Y');

        $scholarshipResponse = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Core Workflow Scholarship',
                'category' => 'Financial assistance',
                'program_cycle' => 'School Year 2026-2027',
                'description' => 'A scholarship used to verify the complete role workflow.',
                'eligibility' => 'Open to enrolled college students who meet the listed document requirements.',
                'eligible_education_levels' => 'college',
                'eligible_locations' => 'Metro Manila',
                'requirements' => "Certificate of enrollment\nLatest report card or grades",
                'post_qualification_requirements' => "Original certificate of enrollment\nProvider formal application form",
                'benefits' => json_encode([[
                    'type' => 'cash_grant',
                    'title' => 'Education allowance',
                    'amount' => 10000,
                    'frequency' => 'one_time',
                    'duration' => 'Current program cycle',
                ]]),
                'application_mode' => 'online',
                'handoff_mode' => 'onsite',
                'handoff_instructions' => 'Bring the original records to the provider office to continue the formal application.',
                'handoff_location_name' => 'Community Scholarship Office',
                'handoff_location_address' => 'Quezon City, Metro Manila',
                'location_name' => 'Community Scholarship Office',
                'location_address' => 'Quezon City, Metro Manila',
                'latitude' => 14.6760,
                'longitude' => 121.0437,
                'contact_email' => 'scholarships@example.test',
                'contact_person' => 'Program Coordinator',
                'contact_department' => 'Scholarship Office',
                'official_program_url' => 'https://example.test/scholarships/core-workflow',
                'return_service_contract' => 'Provider will handle any return service agreement after awarding.',
                'other_contract_terms' => 'Provider may require separate contract signing after final selection.',
                'application_opens_at' => $applicationOpensAt,
                'deadline' => $deadline,
                'expected_results_at' => $expectedResultsAt,
                'status' => 'pending_review',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.status', 'pending_review')
            ->assertJsonPath('scholarship.program_cycle', 'School Year 2026-2027')
            ->assertJsonPath('scholarship.benefits.0.duration', 'Current program cycle');

        $scholarshipId = $scholarshipResponse->json('scholarship.id');

        $this->actingAs($admin)
            ->getJson("/admin/scholarships/{$scholarshipId}/review/data")
            ->assertOk()
            ->assertJsonPath('scholarship.program_cycle', 'School Year 2026-2027')
            ->assertJsonPath('scholarship.application_opens_at', $applicationOpensAtLabel)
            ->assertJsonPath('scholarship.expected_results_at', $expectedResultsAtLabel)
            ->assertJsonPath('scholarship.contact_department', 'Scholarship Office')
            ->assertJsonPath('scholarship.benefits.0.duration', 'Current program cycle');

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarshipId}/review", [
                'status' => 'published',
                'review_notes' => 'Program details are ready for applicants.',
            ])
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'published');

        $this->actingAs($admin)
            ->getJson('/admin/dashboard/data')
            ->assertOk()
            ->assertJsonPath('recent_scholarships.0.id', $scholarshipId);

        $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarshipId}/data")
            ->assertOk()
            ->assertJsonPath('scholarship.program_cycle', 'School Year 2026-2027')
            ->assertJsonPath('scholarship.application_opens_at', $applicationOpensAtLabel)
            ->assertJsonPath('scholarship.expected_results_at', $expectedResultsAtLabel)
            ->assertJsonPath('scholarship.contact_person', 'Program Coordinator')
            ->assertJsonPath('scholarship.benefits.0.duration', 'Current program cycle');

        foreach (['Certificate of enrollment', 'Latest report card or grades'] as $documentName) {
            $path = "student-documents/{$applicant->id}/".str()->slug($documentName).'.pdf';
            Storage::disk('local')->put($path, 'Demo document content');
            StudentDocument::create([
                'user_id' => $applicant->id,
                'document_name' => $documentName,
                'original_name' => str()->slug($documentName).'.pdf',
                'path' => $path,
                'mime_type' => 'application/pdf',
                'size' => 21,
                'uploaded_at' => now(),
            ]);
        }

        $applicationResponse = $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarshipId,
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'submitted');

        $applicationId = $applicationResponse->json('application.id');
        $applicationPayload = $applicationResponse->json('application');

        $this->assertArrayNotHasKey('provider_contract_terms_accepted_at', $applicationPayload);
        $this->assertArrayNotHasKey('provider_contract_terms_snapshot', $applicationPayload);
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $applicationId,
            'provider_contract_terms_accepted_at' => null,
            'provider_contract_terms_snapshot' => null,
            'provider_contract_terms_version' => null,
            'provider_contract_acceptance_ip' => null,
        ]);
        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $applicationId,
            'to_status' => 'submitted',
            'review_notes' => 'Application submitted by applicant.',
        ]);

        $this->actingAs($provider)
            ->getJson('/provider/dashboard/data')
            ->assertOk()
            ->assertJsonPath('review_queue.0.id', $applicationId);

        ApplicationDocument::query()
            ->where('scholarship_application_id', $applicationId)
            ->pluck('id')
            ->each(fn (int $documentId) => $this->actingAs($provider)
                ->patchJson("/provider/documents/{$documentId}/status", ['status' => 'accepted'])
                ->assertOk());

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/correction", [
                'action' => 'request',
                'message' => 'Confirm that the uploaded grade record reflects the current school year.',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'requested');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$applicationId}/correction-response", [
                'response' => 'Confirmed. The uploaded record is for the current school year.',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'submitted');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/correction", [
                'action' => 'resolve',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'resolved');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/decision", [
                'decision' => 'approve',
                'review_notes' => 'The applicant meets the published criteria.',
                'rubric_scores' => [
                    'eligibility_fit' => 95,
                    'academic_merit' => 90,
                    'financial_need' => 90,
                    'document_quality' => 95,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'formal_application');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/stages/formal_application/result", [
                'result' => 'passed',
                'notes' => 'The applicant completed the provider formal application.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'decision');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/final-outcome", [
                'outcome' => 'selected',
                'notes' => 'The applicant was selected after the provider review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.final_outcome', 'selected');

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $provider->id,
            'provider_name' => 'Fresh Start Scholarship Foundation',
            'verification_status' => 'approved',
        ]);
        $this->assertDatabaseHas('provider_verification_documents', [
            'provider_id' => $provider->id,
            'document_type' => 'organization_registration',
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('scholarships', [
            'id' => $scholarshipId,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $applicationId,
            'status' => 'awarded',
            'application_state' => 'closed',
            'final_outcome' => 'selected',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
        ]);
    }

    public function test_negative_decisions_require_feedback_and_include_it_in_notifications(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Feedback Test Scholarship',
            'description' => 'Used to verify clear negative decision feedback.',
            'status' => 'pending_review',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $document = ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Report Card',
            'original_name' => 'report-card.pdf',
            'path' => 'test/report-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.documents.0.mime_type', 'application/pdf')
            ->assertJsonPath('application.documents.0.view_url', route('documents.view', $document));

        $this->actingAs($provider)
            ->getJson('/provider/insights/data')
            ->assertOk()
            ->assertJsonPath('document_review_queue.total', 1)
            ->assertJsonPath('document_review_queue.data.0.application_id', $application->id)
            ->assertJsonPath('document_review_queue.data.0.documents.0.view_url', route('documents.view', $document));

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", ['verification_status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_notes');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_reason');

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", ['status' => 'needs_replacement'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", [
                'status' => 'rejected',
                'review_notes' => 'Clarify the eligibility criteria before resubmitting.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", [
                'status' => 'needs_replacement',
                'review_notes' => 'Upload a complete and readable report card.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'reject',
                'decision_reason' => 'incomplete_requirements',
                'review_notes' => 'The required supporting records were incomplete.',
            ])
            ->assertOk();

        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('message', 'like', '%Clarify the eligibility criteria%')
            ->exists());
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('message', 'like', '%complete and readable report card%')
            ->exists());
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('message', 'like', '%Incomplete Requirements%')
            ->exists());
    }

    private function registerAndVerifyUser(string $role): User
    {
        $email = "fresh.{$role}@example.test";
        $registration = $this->postJson('/register', [
            'first_name' => 'Fresh',
            'last_name' => ucfirst($role),
            'middle_initial' => 'D',
            'email' => $email,
            'username' => "fresh_{$role}",
            'number' => $role === 'provider' ? '09179876543' : '09171234567',
            'role' => $role,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $registration->assertStatus(202);
        $this->assertDatabaseMissing('users', ['email' => $email]);

        $verificationMail = null;
        Mail::assertSent(
            RegistrationVerificationCodeMail::class,
            function (RegistrationVerificationCodeMail $mail) use ($email, &$verificationMail): bool {
                if (! $mail->hasTo($email)) {
                    return false;
                }

                $verificationMail = $mail;

                return true;
            },
        );

        $this->assertInstanceOf(RegistrationVerificationCodeMail::class, $verificationMail);
        $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $verificationMail->verificationCode,
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame($role, $user->role);
        $this->assertTrue($user->hasVerifiedEmail());

        return $user;
    }

    private function completeApplicant(User $applicant): User
    {
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Demo University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);

        return $applicant->fresh();
    }
}
