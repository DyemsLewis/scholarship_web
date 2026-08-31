<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoPreparationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_preparation_refreshes_fictional_records_without_removing_regular_accounts(): void
    {
        $regularUser = User::factory()->create([
            'email' => 'regular-user@example.test',
            'role' => 'applicant',
        ]);

        $this->artisan('demo:prepare --force')
            ->expectsOutput('Demo data is ready. The fictional admin, providers, applicant, programs, completed application, and service examples have been refreshed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => $regularUser->email]);
        $this->assertDatabaseHas('users', ['email' => 'admin@scholarship.test', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'tulayaral@scholarship.test', 'role' => 'provider']);
        $this->assertDatabaseHas('users', ['email' => 'bukasfoundation@scholarship.test', 'role' => 'provider']);
        $this->assertDatabaseHas('users', ['email' => 'student@scholarship.test', 'role' => 'applicant']);
        $this->assertDatabaseHas('scholarships', ['title' => 'Tulay Aral Senior High Support Grant']);
    }
}
