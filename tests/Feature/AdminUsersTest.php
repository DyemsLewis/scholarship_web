<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_listing_is_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(12)->create(['role' => 'applicant']);
        User::factory()->count(2)->create(['role' => 'provider']);

        $this->actingAs($admin)
            ->getJson('/admin/users?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'users')
            ->assertJsonPath('stats.total_users', 15)
            ->assertJsonPath('stats.admins', 1)
            ->assertJsonPath('stats.applicants', 12)
            ->assertJsonPath('stats.providers', 2)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 15);
    }

    public function test_admin_user_listing_can_filter_by_role_and_profile_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'provider']);
        User::factory()->count(4)->create(['role' => 'applicant']);
        $provider = User::factory()->create([
            'role' => 'provider',
            'email' => 'bright-provider@example.com',
            'username' => 'bright-provider',
        ]);
        $provider->providerProfile()->update([
            'provider_name' => 'Bright Future Grants',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/users?role=provider&search=Future&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.email', 'bright-provider@example.com')
            ->assertJsonPath('users.0.provider_name', 'Bright Future Grants')
            ->assertJsonPath('pagination.total', 1);
    }
}
