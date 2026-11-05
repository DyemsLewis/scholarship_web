<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\PhilippineLandPin;
use App\Support\PhilippineLand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PhilippineLandPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_land_and_water_fixtures_match_backend_policy(): void
    {
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/philippine-location-pins.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $pin) {
            $this->assertSame($pin['allowed'], app(PhilippineLand::class)->contains($pin['latitude'], $pin['longitude']), $pin['name']);
        }
    }

    public function test_rule_handles_nested_event_and_prefixed_coordinate_fields(): void
    {
        $validator = Validator::make([
            'provider_latitude' => 14.5, 'provider_longitude' => 120.8,
            'handoff_latitude' => 14.676, 'handoff_longitude' => 121.0437,
            'program_events' => [
                ['latitude' => 14.35, 'longitude' => 121.32],
                ['latitude' => 14.676, 'longitude' => 121.0437],
            ],
        ], [
            'provider_latitude' => [new PhilippineLandPin],
            'handoff_longitude' => [new PhilippineLandPin],
            'program_events.*.latitude' => [new PhilippineLandPin],
        ]);
        $this->assertSame(['provider_latitude', 'program_events.0.latitude'], $validator->errors()->keys());
    }

    public function test_applicant_cannot_save_water_or_foreign_coordinates(): void
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $before = $applicant->studentProfile()->first()->only(['latitude', 'longitude']);
        foreach ([[14.5, 120.8], [14.35, 121.32], [6.1, 116.5]] as [$latitude, $longitude]) {
            $this->actingAs($applicant)->patchJson('/dashboard/profile', [
                'latitude' => $latitude, 'longitude' => $longitude,
            ])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        }
        $this->assertSame($before, $applicant->studentProfile()->first()->only(['latitude', 'longitude']));
    }

    public function test_optional_and_incomplete_pins_are_handled_separately(): void
    {
        $rules = ['latitude' => ['nullable', new PhilippineLandPin], 'longitude' => ['nullable', new PhilippineLandPin]];
        $this->assertTrue(Validator::make(['latitude' => null, 'longitude' => null], $rules)->passes());
        $this->assertFalse(Validator::make(['latitude' => 14.676, 'longitude' => null], $rules)->passes());
        $this->assertFalse(Validator::make(['latitude' => null, 'longitude' => 121.0437], $rules)->passes());
        $this->assertTrue(Validator::make(['latitude' => '14.676', 'longitude' => '121.0437'], $rules)->passes());
    }
}
