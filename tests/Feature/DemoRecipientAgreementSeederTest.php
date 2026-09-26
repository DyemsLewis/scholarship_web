<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Database\Seeders\DemoRecipientAgreementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRecipientAgreementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_agreement_demo_can_be_seeded_repeatedly(): void
    {
        $provider = User::factory()->create([
            'email' => 'tulayaral@scholarship.test',
            'role' => 'provider',
        ]);
        $provider->providerProfile()->update([
            'provider_name' => 'Tulay Aral Community Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        $this->seed(DemoRecipientAgreementSeeder::class);
        $this->seed(DemoRecipientAgreementSeeder::class);

        $applicant = User::query()->where('email', 'recipientdemo@scholarship.test')->sole();
        $scholarship = Scholarship::query()->where('title', 'Tulay Aral Continuing Scholar Grant')->sole();
        $application = ScholarshipApplication::query()
            ->where('applicant_id', $applicant->id)
            ->where('scholarship_id', $scholarship->id)
            ->sole();

        $this->assertSame('published', $scholarship->status);
        $this->assertSame('selected', $application->final_outcome);
        $this->assertSame('awarded', $application->status);
        $this->assertNull($application->student_response_status);
        $this->assertNotEmpty($application->provider_contract_terms_snapshot);
        $this->assertStringStartsWith('recipient-agreement-v2-', $application->provider_contract_terms_version);
    }
}
