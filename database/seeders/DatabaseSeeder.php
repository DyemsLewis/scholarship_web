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
            'first_name' => 'Provider',
            'last_name' => 'Account',
            'middle_initial' => 'P',
            'contact_number' => '09170000001',
            'provider_name' => 'Provider',
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
            'account_managed_by' => 'learner',
            'education_level' => 'senior_high_school',
            'school' => 'Demo National High School',
            'school_type' => 'public',
            'learner_reference_number' => '123456789012',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'enrollment_status' => 'Enrolled',
            'gwa' => 1.75,
            'grading_scale' => 'grade_point',
            'income_bracket' => 'PHP 10,000 - 20,000',
            'household_size' => 5,
            'preferred_categories' => "Academic merit\nFinancial assistance\nSTEM scholarship",
            'preferred_locations' => "Metro Manila\nOnline-friendly",
            'willing_to_relocate' => 'depends',
            'support_needs' => 'Tuition, school supplies, transportation, and internet support.',
            'scholarship_goal' => 'Find a scholarship that can support continued senior high school studies and future college preparation.',
            'address' => 'Quezon City, Metro Manila',
            'barangay' => 'Diliman',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'latitude' => 14.6760000,
            'longitude' => 121.0437000,
            'birthdate' => '2004-06-01',
            'guardian_name' => 'Demo Guardian',
            'guardian_relationship' => 'Parent / guardian',
            'guardian_contact' => '09170000003',
            'guardian_email' => 'guardian@scholarship.test',
            'guardian_is_account_owner' => false,
        ]);

        Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('title', 'Bright Future Student Grant')
            ->update(['title' => 'Demo Program 1']);

        $demoProgram = [
            'category' => 'Demo Scholarship',
            'description' => 'A generic demo scholarship program for testing the student finder, eligibility matching, and application workflow.',
            'eligibility' => 'Open to currently enrolled students who meet the listed academic, location, and document requirements.',
            'eligible_education_levels' => implode("\n", [
                'elementary',
                'junior_high_school',
                'senior_high_school',
                'college',
                'tvet',
                'als',
            ]),
            'eligible_courses' => 'Any track, strand, or course',
            'eligible_school_types' => implode("\n", [
                'public',
                'private',
                'state_university',
                'local_college',
                'tvet_center',
                'als_center',
            ]),
            'eligible_year_levels' => implode("\n", [
                'Grade 1',
                'Grade 2',
                'Grade 3',
                'Grade 4',
                'Grade 5',
                'Grade 6',
                'Grade 7',
                'Grade 8',
                'Grade 9',
                'Grade 10',
                'Grade 11',
                'Grade 12',
                '1st year',
                '2nd year',
                '3rd year',
                '4th year',
            ]),
            'eligible_locations' => 'Philippines',
            'income_requirement' => 'Any',
            'requirements' => implode("\n", [
                'Certificate of enrollment',
                'Latest report card or grades',
                'School ID',
                'Proof of income',
            ]),
            'award_amount' => 10000,
            'minimum_gwa' => 85,
            'slots_available' => 25,
            'application_mode' => 'hybrid',
            'renewal_policy' => 'Renewal depends on continued eligibility, submitted updated requirements, and available funding.',
            'contact_email' => 'provider@scholarship.test',
            'contact_number' => '09170000001',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
            'views_count' => 0,
        ];
        $demoLocations = [
            1 => [
                'location_name' => 'Demo Scholarship Office - Manila',
                'location_address' => 'Manila City Hall, Padre Burgos Avenue, Ermita, Manila, Metro Manila',
                'latitude' => 14.5896000,
                'longitude' => 120.9817000,
            ],
            2 => [
                'location_name' => 'Demo Scholarship Office - Cebu',
                'location_address' => 'Cebu City Hall, M.C. Briones Street, Cebu City, Cebu',
                'latitude' => 10.2929000,
                'longitude' => 123.9016000,
            ],
            3 => [
                'location_name' => 'Demo Scholarship Office - Davao',
                'location_address' => 'Davao City Hall, San Pedro Street, Davao City, Davao del Sur',
                'latitude' => 7.0644000,
                'longitude' => 125.6086000,
            ],
        ];

        foreach ([1, 2, 3] as $programNumber) {
            Scholarship::query()->updateOrCreate([
                'provider_id' => $provider->id,
                'title' => "Demo Program {$programNumber}",
            ], [
                ...$demoProgram,
                ...$demoLocations[$programNumber],
            ]);
        }
    }
}
