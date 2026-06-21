<?php

namespace Database\Seeders;

use App\Models\Scholarship;
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
        $password = env('DEMO_PASSWORD', 'password123');

        $admin = User::query()->updateOrCreate([
            'email' => env('ADMIN_EMAIL', 'admin@scholarship.test'),
        ], [
            'username' => env('ADMIN_USERNAME', 'admin'),
            'role' => 'admin',
            'password' => env('ADMIN_PASSWORD', $password),
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

        $provider = User::query()->updateOrCreate([
            'email' => env('PROVIDER_EMAIL', 'provider@scholarship.test'),
        ], [
            'username' => env('PROVIDER_USERNAME', 'provider'),
            'role' => 'provider',
            'password' => env('PROVIDER_PASSWORD', $password),
        ]);

        $provider->providerProfile()->updateOrCreate([
            'user_id' => $provider->id,
        ], [
            'first_name' => 'NCR',
            'last_name' => 'Grants',
            'middle_initial' => 'P',
            'contact_number' => '09170000001',
            'provider_name' => 'NCR Scholarship Foundation',
            'provider_type' => 'foundation',
            'provider_website' => 'https://example.com',
            'provider_address' => 'Manila City Hall, Padre Burgos Avenue, Manila',
            'provider_description' => 'A demo provider account for publishing scholarship programs.',
            'verification_status' => 'approved',
            'verification_notes' => 'Demo provider approved for local testing.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $student = User::query()->updateOrCreate([
            'email' => env('STUDENT_EMAIL', 'student@scholarship.test'),
        ], [
            'username' => env('STUDENT_USERNAME', 'student'),
            'role' => 'applicant',
            'password' => env('STUDENT_PASSWORD', $password),
        ]);

        $student->studentProfile()->updateOrCreate([
            'user_id' => $student->id,
        ], [
            'first_name' => 'Demo',
            'last_name' => 'Student',
            'middle_initial' => 'S',
            'contact_number' => '09170000002',
            'school' => 'Quezon City University',
            'course_or_strand' => 'BSIT',
            'year_level' => '2nd year',
            'enrollment_status' => 'Enrolled',
            'gwa' => 1.75,
            'grading_scale' => 'grade_point',
            'income_bracket' => 'PHP 10,000 - 20,000',
            'address' => 'Quezon City, Metro Manila',
            'barangay' => 'Diliman',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'latitude' => 14.6760000,
            'longitude' => 121.0437000,
            'birthdate' => '2004-06-01',
            'guardian_name' => 'Demo Guardian',
            'guardian_contact' => '09170000003',
        ]);

        Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('title', 'Bright Future NCR College Grant')
            ->update(['title' => 'Demo Program 1']);

        $demoProgram = [
            'category' => 'Demo Scholarship',
            'description' => 'A generic demo scholarship program for testing the student finder, eligibility matching, and application workflow.',
            'eligibility' => 'Open to currently enrolled students who meet the listed academic, location, and document requirements.',
            'eligible_courses' => 'Any course or strand',
            'eligible_year_levels' => 'Any year level',
            'eligible_locations' => 'Philippines',
            'income_requirement' => 'Any',
            'location_name' => 'Demo Scholarship Office',
            'location_address' => 'Quezon City, Metro Manila',
            'latitude' => 14.6760000,
            'longitude' => 121.0437000,
            'requirements' => implode("\n", [
                'Certificate of enrollment',
                'Latest report card or grades',
                'School ID',
                'Proof of income',
            ]),
            'award_amount' => 10000,
            'minimum_gwa' => 85,
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
            'views_count' => 0,
        ];

        foreach ([1, 2, 3] as $programNumber) {
            Scholarship::query()->updateOrCreate([
                'provider_id' => $provider->id,
                'title' => "Demo Program {$programNumber}",
            ], $demoProgram);
        }
    }
}
