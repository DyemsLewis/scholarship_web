<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipBenefitPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_create_and_duplicate_a_mixed_benefit_package(): void
    {
        $provider = $this->verifiedProvider();
        $benefits = [
            [
                'type' => 'cash_grant',
                'title' => 'Learning allowance',
                'amount' => 7500,
                'frequency' => 'per_term',
                'duration' => 'One school year',
                'description' => 'Flexible school-expense support.',
            ],
            [
                'type' => 'device_support',
                'title' => 'Laptop loan',
                'frequency' => 'entire_program',
                'description' => 'A learning device loaned for the award period.',
            ],
        ];

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Mixed Support Scholarship',
                'description' => 'A program that combines financial and non-cash support.',
                'benefits' => json_encode($benefits),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.award_amount', '7500.00')
            ->assertJsonPath('scholarship.benefits.0.type', 'cash_grant')
            ->assertJsonPath('scholarship.benefits.1.type', 'device_support');

        $scholarship = Scholarship::query()->findOrFail($response->json('scholarship.id'));

        $this->assertCount(2, $scholarship->benefits);
        $this->assertDatabaseHas('scholarship_benefits', [
            'scholarship_id' => $scholarship->id,
            'type' => 'device_support',
            'title' => 'Laptop loan',
            'amount' => null,
        ]);
        $this->assertSame('One school year', $scholarship->benefits->first()->duration);
        $this->assertSame('One school year', $response->json('scholarship.benefits.0.duration'));

        $duplicateResponse = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/duplicate")
            ->assertCreated()
            ->assertJsonCount(2, 'scholarship.benefits');

        $duplicate = Scholarship::query()->findOrFail($duplicateResponse->json('scholarship.id'));

        $this->assertSame('draft', $duplicate->status);
        $this->assertCount(2, $duplicate->benefits);
    }

    public function test_non_cash_only_package_does_not_create_a_fake_cash_award(): void
    {
        $provider = $this->verifiedProvider();

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'School Essentials Package',
                'description' => 'A non-cash scholarship support package.',
                'benefits' => json_encode([[
                    'type' => 'school_supplies',
                    'title' => 'School essentials kit',
                    'frequency' => 'annual',
                ]]),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.award_amount', null)
            ->assertJsonPath('scholarship.benefit_summary', 'School essentials kit (Annual)');

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'award_amount' => null,
        ]);
    }

    public function test_changing_published_program_benefits_returns_it_to_admin_review(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Published Support Program',
            'description' => 'An already approved scholarship program.',
            'status' => 'published',
        ]);
        $scholarship->benefits()->create([
            'type' => 'school_supplies',
            'title' => 'School supplies',
            'frequency' => 'one_time',
        ]);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'benefits' => json_encode([[
                    'type' => 'school_supplies',
                    'title' => 'Expanded school supplies package',
                    'frequency' => 'annual',
                ]]),
                'status' => 'published',
                'terms_accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'pending_review')
            ->assertJsonPath('scholarship.benefits.0.title', 'Expanded school supplies package');

        $this->assertSame('pending_review', $scholarship->fresh()->status);
    }

    private function verifiedProvider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        return $provider;
    }
}
