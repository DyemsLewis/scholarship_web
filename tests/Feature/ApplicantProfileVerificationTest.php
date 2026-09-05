<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantProfileVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_scan_sets_the_academic_result_and_applicant_cannot_overwrite_it(): void
    {
        Storage::fake('local');
        $this->enableAcademicOcr();
        Http::fake([
            'https://api.ocr.space/parse/image' => Http::response([
                'ParsedResults' => [[
                    'ParsedText' => "Learner report card\nGWA:\n89\nSchool year 2026-2027",
                ]],
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
            ]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('report-card.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.gwa', '89.00')
            ->assertJsonPath('user.grading_scale', 'percentage')
            ->assertJsonPath('user.academic_result_source', 'ocr')
            ->assertJsonPath('verification_documents.0.ocr_status', 'succeeded')
            ->assertJsonPath('verification_documents.0.ocr_grade', '89.00');

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', [
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'contact_number' => $applicant->contact_number,
                'grading_scale' => 'percentage',
                'gwa' => 55,
            ])
            ->assertOk()
            ->assertJsonPath('user.gwa', '89.00');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $applicant->id,
            'gwa' => 89,
            'grading_scale' => 'percentage',
            'academic_result_source' => 'ocr',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()
            ->assertJsonPath('applicant.academic_scan_required', true)
            ->assertJsonPath('applicant.verification_documents.0.ocr_status', 'succeeded');

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('user.applicant_verification_status', 'approved');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.ocr.space/parse/image'
            && $request->hasHeader('apikey', 'test-key'));
        Http::assertSentCount(1);
    }

    public function test_reviewer_cannot_verify_when_cloud_scan_did_not_find_an_academic_result(): void
    {
        Storage::fake('local');
        $this->enableAcademicOcr();
        Http::fake([
            'https://api.ocr.space/parse/image' => Http::response([
                'ParsedResults' => [[
                    'ParsedText' => 'School year 2026-2027 subject grades only',
                ]],
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
            ]),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('unclear-record.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('verification_documents.0.ocr_status', 'needs_review');

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The academic record must have a successful scan before its result can be verified.');
    }

    public function test_applicant_can_submit_private_academic_record_for_admin_review(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('latest-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.applicant_verification_status', 'pending')
            ->assertJsonCount(1, 'verification_documents');

        $documentId = $response->json('verification_documents.0.id');

        $reviews = $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('stats.pending_applicants', 1)
            ->assertJsonPath('applicants.0.id', $applicant->id)
            ->assertJsonPath('applicants.0.applicant_verification_status', 'pending')
            ->assertJsonPath('applicants.0.verification_documents.0.id', $documentId);

        $this->assertArrayNotHasKey('applications', $reviews->json());

        $this->actingAs($admin)
            ->get($reviews->json('applicants.0.verification_documents.0.view_url'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($admin)
            ->getJson("/admin/users/{$applicant->id}")
            ->assertOk()
            ->assertJsonPath('verification_documents.0.id', $documentId);

        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->get("/dashboard/profile/verification-documents/{$documentId}/view")
            ->assertForbidden();
    }

    public function test_sensitive_identity_document_is_rejected_by_academic_verification_endpoint(): void
    {
        Storage::fake('local');

        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'birth_certificate',
                'document_file' => UploadedFile::fake()->image('birth-certificate.jpg'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_type');

        $this->assertDatabaseCount('applicant_verification_documents', 0);
    }

    public function test_optional_school_proof_is_reusable_without_resetting_academic_verification(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('latest-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertOk();

        $schoolProofResponse = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'school_record',
                'document_file' => UploadedFile::fake()->create('certificate-of-enrollment.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.applicant_verification_status', 'approved')
            ->assertJsonPath('user.is_profile_verified', true)
            ->assertJsonPath('prepared_document.document_name', 'Certificate of enrollment')
            ->assertJsonPath('prepared_documents_count', 2);

        $schoolProof = ApplicantVerificationDocument::query()
            ->where('applicant_id', $applicant->id)
            ->where('document_type', 'school_record')
            ->firstOrFail();

        $this->assertDatabaseHas('student_documents', [
            'user_id' => $applicant->id,
            'document_name' => 'Certificate of enrollment',
        ]);
        $this->assertSame('approved', $applicant->studentProfile()->firstOrFail()->verification_status);

        $this->actingAs($admin)
            ->getJson("/admin/users/{$applicant->id}")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $schoolProof->id,
                'document_type' => 'school_record',
            ]);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'School Proof Review Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests application-scoped school proof review.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $applicationResponse = $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $schoolProof->id,
                'document_type' => 'school_record',
            ]);

        $schoolProofPayload = collect($applicationResponse->json('application.applicant.profile_proofs'))
            ->firstWhere('document_type', 'school_record');

        $this->actingAs($provider)
            ->get($schoolProofPayload['view_url'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($otherProvider)
            ->get($schoolProofPayload['view_url'])
            ->assertForbidden();

        $this->actingAs($applicant)
            ->deleteJson("/dashboard/profile/verification-documents/{$schoolProof->id}")
            ->assertOk()
            ->assertJsonPath('user.applicant_verification_status', 'approved')
            ->assertJsonPath('prepared_documents_count', 2);

        $this->assertSame('approved', $applicant->studentProfile()->firstOrFail()->verification_status);
        $this->assertNotNull($schoolProofResponse->json('prepared_document.id'));
    }

    public function test_academic_verification_record_is_copied_to_documents_and_survives_verification_deletion(): void
    {
        Storage::fake('local');

        $applicant = User::factory()->create(['role' => 'applicant']);

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('latest-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('prepared_document.document_name', 'Latest report card or grades')
            ->assertJsonPath('prepared_documents_count', 1);

        $verificationDocument = ApplicantVerificationDocument::query()->firstOrFail();
        $preparedDocument = StudentDocument::query()->firstOrFail();

        $this->assertNotSame($verificationDocument->path, $preparedDocument->path);
        Storage::disk('local')->assertExists($verificationDocument->path);
        Storage::disk('local')->assertExists($preparedDocument->path);

        $this->actingAs($applicant)
            ->deleteJson("/dashboard/profile/verification-documents/{$verificationDocument->id}")
            ->assertOk()
            ->assertJsonPath('prepared_documents_count', 1);

        $this->assertDatabaseMissing('applicant_verification_documents', ['id' => $verificationDocument->id]);
        $this->assertDatabaseHas('student_documents', ['id' => $preparedDocument->id]);
        Storage::disk('local')->assertMissing($verificationDocument->path);
        Storage::disk('local')->assertExists($preparedDocument->path);

        $this->actingAs($applicant)
            ->getJson('/dashboard/documents/data')
            ->assertOk()
            ->assertJsonPath('prepared_documents.0.document_name', 'Latest report card or grades');
    }

    public function test_academic_proof_is_saved_as_a_reusable_grade_document(): void
    {
        Storage::fake('local');

        $applicant = User::factory()->create(['role' => 'applicant']);

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('latest-report-card.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('verification_documents.0.document_type', 'academic_record')
            ->assertJsonPath('prepared_document.document_name', 'Latest report card or grades')
            ->assertJsonPath('prepared_documents_count', 1);

        $verificationDocument = ApplicantVerificationDocument::query()->firstOrFail();
        $preparedDocument = StudentDocument::query()->firstOrFail();

        $this->assertSame('academic_record', $verificationDocument->document_type);
        $this->assertSame('Latest report card or grades', $preparedDocument->document_name);
        Storage::disk('local')->assertExists($verificationDocument->path);
        Storage::disk('local')->assertExists($preparedDocument->path);

        $this->actingAs($applicant)
            ->getJson('/dashboard/documents/data')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $response->json('prepared_document.id'),
                'document_name' => 'Latest report card or grades',
            ]);
    }

    public function test_admin_verification_is_visible_in_provider_list_without_exposing_proof_files(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('latest-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
                'verification_notes' => 'A stale rejection note should not remain after approval.',
            ])
            ->assertOk()
            ->assertJsonPath('user.is_profile_verified', true)
            ->assertJsonPath('user.applicant_verification_notes', null);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Verified Applicant Scholarship',
            'category' => 'Academic merit',
            'description' => 'Test scholarship for applicant verification visibility.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);

        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($provider)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->assertJsonPath('applications.0.applicant.profile_verification_status', 'approved');

        $this->assertArrayNotHasKey('verification_documents', $response->json('applications.0.applicant'));
        $this->assertArrayNotHasKey('profile_proofs', $response->json('applications.0.applicant'));
    }

    public function test_replacing_an_approved_proof_returns_the_applicant_to_pending_review(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('original-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('user.is_profile_verified', true);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'academic_record',
                'document_file' => UploadedFile::fake()->create('updated-grades.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('user.applicant_verification_status', 'pending')
            ->assertJsonPath('user.is_profile_verified', false)
            ->assertJsonPath('verification_documents.0.status', 'submitted');

        $profile = $applicant->studentProfile()->firstOrFail();

        $this->assertSame('pending', $profile->verification_status);
        $this->assertNull($profile->verified_at);
        $this->assertNull($profile->verified_by);
    }

    public function test_provider_can_review_profile_proof_only_through_an_owned_matching_application(): void
    {
        Storage::fake('local');

        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $otherApplicant = User::factory()->create(['role' => 'applicant']);

        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'first_name' => 'Demo',
            'last_name' => 'Learner',
            'contact_number' => '09171234567',
            'birthdate' => '2008-04-15',
            'gender' => 'female',
            'account_managed_by' => 'guardian',
            'education_level' => 'senior_high_school',
            'school' => 'Demo National High School',
            'school_type' => 'public',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'enrollment_status' => 'enrolled',
            'income_bracket' => 'Below PHP 250,000',
            'household_size' => 5,
            'support_needs' => "Books and supplies\nTransportation",
            'scholarship_goal' => 'Continue studying without interrupting enrollment.',
            'preferred_categories' => 'Financial assistance',
            'preferred_locations' => 'Near my home address',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'guardian_name' => 'Demo Guardian',
            'guardian_relationship' => 'Parent',
            'guardian_contact' => '09179876543',
            'guardian_email' => 'guardian@example.com',
            'guardian_is_account_owner' => true,
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        $proofPath = "applicant-verification/{$applicant->id}/academic-record.pdf";
        Storage::disk('local')->put($proofPath, 'demo academic record');
        $proof = ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'academic_record',
            'original_name' => 'academic-record.pdf',
            'path' => $proofPath,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'approved',
            'uploaded_at' => now(),
        ]);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Profile Review Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests application-scoped applicant profile review.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $otherApplication = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $otherApplicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $viewUrl = route('provider.applications.profile-proofs.view', [$application, $proof]);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.applicant.guardian_name', 'Demo Guardian')
            ->assertJsonPath('application.applicant.enrollment_status', 'enrolled')
            ->assertJsonPath('application.applicant.support_needs', "Books and supplies\nTransportation")
            ->assertJsonPath('application.applicant.scholarship_goal', 'Continue studying without interrupting enrollment.')
            ->assertJsonPath('application.applicant.profile_proofs.0.id', $proof->id)
            ->assertJsonPath('application.applicant.profile_proofs.0.view_url', $viewUrl);

        $this->actingAs($provider)
            ->get($viewUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($otherProvider)
            ->get($viewUrl)
            ->assertForbidden();

        $this->actingAs($provider)
            ->get(route('provider.applications.profile-proofs.view', [$otherApplication, $proof]))
            ->assertForbidden();
    }

    public function test_provider_can_verify_a_pending_academic_record_through_an_owned_application(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->updateOrCreate(['user_id' => $provider->id], [
            'provider_name' => 'Tulay Aral Community Foundation',
            'verification_status' => 'approved',
        ]);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'verification_status' => 'pending',
        ]);
        $proof = ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'academic_record',
            'original_name' => 'latest-grades.pdf',
            'path' => "applicant-verification/{$applicant->id}/latest-grades.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Provider Verified Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests provider verification through an owned application.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($otherProvider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertForbidden();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertOk()
            ->assertJsonPath('application.applicant.profile_verification_status', 'approved');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $applicant->id,
            'verification_status' => 'approved',
            'verified_by' => $provider->id,
        ]);
        $this->assertDatabaseHas('applicant_verification_documents', [
            'id' => $proof->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'applicant_profile_verification',
            'title' => 'Academic record verified',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()
            ->assertJsonPath('applicant.verification_oversight.source', 'provider')
            ->assertJsonPath('applicant.verification_oversight.source_label', 'Provider review')
            ->assertJsonPath('applicant.verification_oversight.provider_organization', 'Tulay Aral Community Foundation')
            ->assertJsonPath('applicant.verification_oversight.context.application_id', $application->id)
            ->assertJsonPath('applicant.verification_oversight.context.program_title', 'Provider Verified Scholarship')
            ->assertJsonPath('applicant.verification_oversight.context.is_current', true)
            ->assertJsonPath('applicant.verification_oversight.history.0.action', 'applicant_profile_verified_by_provider')
            ->assertJsonPath('applicant.verification_oversight.history.0.title', 'Verified by provider');

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('applicants.0.verification_oversight.source', 'provider')
            ->assertJsonPath('applicants.0.verification_oversight.provider_organization', 'Tulay Aral Community Foundation');
    }

    public function test_admin_can_reopen_provider_verification_with_a_reason_and_provider_cannot_override_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'verification_status' => 'pending',
        ]);
        $proof = ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'academic_record',
            'original_name' => 'latest-grades.pdf',
            'path' => "applicant-verification/{$applicant->id}/latest-grades.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Oversight Test Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests the admin oversight handoff after provider verification.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'pending',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_notes');

        $reason = 'The saved average does not clearly match the submitted report card.';

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'pending',
                'verification_notes' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('applicant.applicant_verification_status', 'pending')
            ->assertJsonPath('applicant.verification_oversight.source', 'unassigned')
            ->assertJsonPath('applicant.verification_oversight.review_note', $reason)
            ->assertJsonPath('applicant.verification_oversight.context.is_current', false)
            ->assertJsonPath('applicant.verification_oversight.history.0.title', 'Verification reopened')
            ->assertJsonPath('applicant.verification_oversight.history.0.reason', $reason)
            ->assertJsonPath('applicant.verification_oversight.history.1.title', 'Verified by provider');

        $profile = $applicant->studentProfile()->firstOrFail();
        $this->assertSame('pending', $profile->verification_status);
        $this->assertSame($reason, $profile->verification_notes);
        $this->assertNull($profile->verified_by);
        $this->assertNull($profile->verified_at);
        $this->assertDatabaseHas('applicant_verification_documents', [
            'id' => $proof->id,
            'status' => 'submitted',
            'review_notes' => $reason,
        ]);

        $reopenLog = ActivityLog::query()
            ->where('action', 'applicant_profile_verification_updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('approved', $reopenLog->metadata['previous_status']);
        $this->assertSame('pending', $reopenLog->metadata['verification_status']);
        $this->assertSame($reason, $reopenLog->metadata['verification_notes']);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification');
    }

    public function test_provider_cannot_verify_an_applicant_without_an_academic_record(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Academic Proof Required Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests the provider academic proof safeguard.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/profile-verification")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification');
    }

    public function test_admin_cannot_verify_applicant_without_a_submitted_proof(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable();
    }

    public function test_admin_cannot_use_a_legacy_identity_file_to_verify_academic_results(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'birth_certificate',
            'original_name' => 'older-birth-certificate.pdf',
            'path' => "applicant-verification/{$applicant->id}/older-birth-certificate.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The applicant must upload an academic record before the academic result can be verified.');
    }

    private function enableAcademicOcr(): void
    {
        config([
            'services.academic_ocr.enabled' => true,
            'services.academic_ocr.endpoint' => 'https://api.ocr.space/parse/image',
            'services.academic_ocr.key' => 'test-key',
            'services.academic_ocr.engine' => 2,
            'services.academic_ocr.language' => 'eng',
            'services.academic_ocr.max_file_size_kb' => 1024,
        ]);
    }
}
