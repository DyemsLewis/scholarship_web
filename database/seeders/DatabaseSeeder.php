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
        User::query()->updateOrCreate([
            'email' => env('ADMIN_EMAIL', 'admin@scholarship.test'),
        ], [
            'name' => 'Scholarship Admin',
            'first_name' => 'Scholarship',
            'last_name' => 'Admin',
            'middle_initial' => 'A',
            'username' => env('ADMIN_USERNAME', 'admin'),
            'contact_number' => '09170000000',
            'is_admin' => true,
            'role' => 'admin',
            'password' => env('ADMIN_PASSWORD', 'password123'),
        ]);
    }
}
