<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipEligibilityParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_and_mobile_return_the_same_eligibility_and_document_readiness(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'education_level' => 'college',
            'school_type' => 'public',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 92,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);
        StudentDocument::create([
            'user_id' => $applicant->id,
            'document_name' => 'Latest report card',
            'original_name' => 'report-card.pdf',
            'path' => 'student-documents/report-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'uploaded_at' => now(),
        ]);
        Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Parity Test Scholarship',
            'description' => 'Verifies that web and mobile use the same matching rules.',
            'status' => 'published',
            'deadline' => now()->addWeek()->toDateString(),
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
            'eligible_education_levels' => 'college',
            'eligible_courses' => 'Any',
            'eligible_school_types' => 'public, private',
            'eligible_year_levels' => '1st year',
            'eligible_locations' => 'Philippines',
            'income_requirement' => 'No income requirement',
            'requirements' => "Latest report card\nCertificate of enrollment",
        ]);

        $plainToken = 'eligibility-parity-token';
        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $webScholarship = $this->actingAs($applicant)
            ->getJson('/dashboard/data')
            ->assertOk()
            ->json('scholarships.0');
        $mobileScholarship = $this->withToken($plainToken)
            ->getJson('/api/mobile/profile')
            ->assertOk()
            ->json('scholarships.0');

        $this->assertSame($webScholarship['eligibility_match'], $mobileScholarship['eligibility_match']);
        $this->assertSame($webScholarship['prepared_documents'], $mobileScholarship['prepared_documents']);
        $this->assertArrayHasKey('student_value', $mobileScholarship['eligibility_match']['criteria'][0]);
        $this->assertArrayNotHasKey('studentValue', $mobileScholarship['eligibility_match']['criteria'][0]);
    }
}
