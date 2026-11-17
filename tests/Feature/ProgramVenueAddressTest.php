<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProgramVenueAddressTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): User
    {
        Mail::fake();
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);

        return $provider;
    }

    private function address(array $changes = []): array
    {
        return [...[
            'venue' => 'Scholarship office', 'region' => 'Region IV-A', 'province' => 'Rizal',
            'city' => 'Antipolo City', 'barangay' => 'San Isidro', 'street' => 'Learning Road',
            'latitude' => 14.5861, 'longitude' => 121.1756,
        ], ...$changes];
    }

    public function test_program_creation_editing_and_reload_preserve_structured_addresses_and_pins(): void
    {
        $provider = $this->provider();
        $address = $this->address();
        $handoff = $this->address(['venue' => 'Records desk']);
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Structured venue program', 'status' => 'draft', 'handoff_mode' => 'onsite',
            'location_address_details' => json_encode($address), 'handoff_address_details' => $handoff,
            'location_address' => 'Untrusted legacy text', 'latitude' => 14.5, 'longitude' => 120.8,
        ])->assertCreated()->assertJsonPath('scholarship.location_name', 'Scholarship office')
            ->assertJsonPath('scholarship.location_address', 'Learning Road, San Isidro, Antipolo City, Rizal, Region IV-A')
            ->assertJsonPath('scholarship.location_address_details.province', 'Rizal')
            ->assertJsonPath('scholarship.handoff_address_details.venue', 'Records desk');
        $id = $response->json('scholarship.id');
        $program = Scholarship::findOrFail($id);
        $this->assertEquals(14.5861, $program->latitude);
        $this->assertEquals(121.1756, $program->handoff_longitude);

        $edited = $this->address(['street' => 'Updated Road', 'latitude' => 14.676, 'longitude' => 121.0437]);
        $this->putJson('/provider/scholarships/'.$id, [
            'title' => $program->title, 'status' => 'draft', 'location_address_details' => $edited,
        ])->assertOk()->assertJsonPath('scholarship.location_address_details.street', 'Updated Road');
        $this->getJson('/provider/scholarships/'.$id)->assertOk()
            ->assertJsonPath('scholarship.location_address_details.city', 'Antipolo City')
            ->assertJsonPath('scholarship.handoff_address_details.venue', 'Records desk');
        $this->assertEquals(14.676, $program->fresh()->latitude);
    }

    public function test_legacy_addresses_remain_unchanged_when_unrelated_program_fields_are_edited(): void
    {
        $provider = $this->provider();
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Existing venue', 'description' => '', 'status' => 'draft',
            'location_name' => 'Existing office', 'location_address' => 'Existing address',
            'latitude' => 14.5861, 'longitude' => 121.1756,
        ]);
        $this->actingAs($provider)->putJson('/provider/scholarships/'.$program->id, [
            'title' => 'Renamed program', 'status' => 'draft',
        ])->assertOk();
        $program->refresh();
        $this->assertSame('Existing address', $program->location_address);
        $this->assertEquals(14.5861, $program->latitude);
        $this->assertNull($program->location_address_details);
    }

    public function test_partial_address_drafts_are_allowed_but_submission_requires_complete_fields(): void
    {
        $this->actingAs($this->provider())->postJson('/provider/scholarships', [
            'title' => 'Partial venue draft', 'status' => 'draft',
            'location_address_details' => ['venue' => 'New office'],
        ])->assertCreated()->assertJsonPath('scholarship.location_address_details.venue', 'New office');
        $this->postJson('/provider/scholarships', [
            'title' => 'Incomplete submission', 'description' => 'Address must be completed.', 'status' => 'pending_review',
            'terms_accepted' => true, 'location_address_details' => ['venue' => 'New office'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'location_address_details.region', 'location_address_details.province', 'location_address_details.city',
        ]);
    }

    public function test_water_foreign_and_incomplete_structured_pins_are_rejected(): void
    {
        $this->actingAs($this->provider());
        foreach ([[14.5, 120.8], [6.1, 116.5], [14.5861, null]] as [$latitude, $longitude]) {
            $this->postJson('/provider/scholarships', [
                'title' => 'Invalid pin', 'status' => 'draft',
                'location_address_details' => $this->address(compact('latitude', 'longitude')),
            ])->assertUnprocessable()->assertJsonValidationErrors('location_address_details.latitude');
        }
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_online_handoffs_discard_physical_venue_metadata_and_pins(): void
    {
        $this->actingAs($this->provider())->postJson('/provider/scholarships', [
            'title' => 'Online handoff', 'status' => 'draft', 'handoff_mode' => 'online',
            'handoff_url' => 'https://example.org/apply', 'handoff_address_details' => $this->address(),
        ])->assertCreated()->assertJsonPath('scholarship.handoff_address_details', null)
            ->assertJsonPath('scholarship.handoff_latitude', null);
    }
}
