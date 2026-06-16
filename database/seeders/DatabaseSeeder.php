<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate([
            'email' => env('ADMIN_EMAIL', 'admin@scholarship.test'),
        ], [
            'username' => env('ADMIN_USERNAME', 'admin'),
            'role' => 'admin',
            'password' => env('ADMIN_PASSWORD', 'password123'),
        ]);

        $admin->adminProfile()->updateOrCreate([
            'user_id' => $admin->id,
        ], [
            'first_name' => 'Scholarship',
            'last_name' => 'Admin',
            'middle_initial' => 'A',
            'contact_number' => '09170000000',
            'display_name' => 'Scholarship Admin',
        ]);
    }
}
