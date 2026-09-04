<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\User;
use App\Services\ScholarshipEventService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a compact, fictional demo dataset for local role testing.
     */
    public function run(): void
    {
        $password = env('DEMO_PASSWORD', 'password123');

        $admin = $this->seedUser(
            email: env('ADMIN_EMAIL', 'admin@scholarship.test'),
            username: env('ADMIN_USERNAME', 'admin'),
            role: 'admin',
            password: env('ADMIN_PASSWORD', $password),
        );
        $admin->adminProfile()->updateOrCreate([
            'user_id' => $admin->id,
        ], [
            'first_name' => 'Portal',
            'last_name' => 'Administrator',
            'middle_initial' => 'A',
            'contact_number' => '09170000000',
            'display_name' => 'Scholarship Administrator',
        ]);

        $tulayAral = $this->seedUser(
            email: env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'),
            username: env('TULAY_ARAL_USERNAME', 'tulayaral'),
            role: 'provider',
            password: env('TULAY_ARAL_PASSWORD', $password),
        );
        $tulayAral->providerProfile()->updateOrCreate([
            'user_id' => $tulayAral->id,
        ], [
            'first_name' => 'Mara',
            'last_name' => 'Reyes',
            'middle_initial' => 'L',
            'contact_number' => '09171234567',
            'provider_name' => 'Tulay Aral Community Foundation',
            'provider_type' => 'non_profit',
            'provider_website' => null,
            'provider_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'provider_description' => 'A small community foundation that provides practical education assistance to learners from nearby low-income households.',
            'provider_contact_email' => 'tulayaral@scholarship.test',
            'provider_contact_number' => '09171234567',
            'verification_status' => 'approved',
            'verification_notes' => 'Fictional community provider approved for local demonstration.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $bukasKinabukasan = $this->seedUser(
            email: env('BUKAS_FOUNDATION_EMAIL', 'bukasfoundation@scholarship.test'),
            username: env('BUKAS_FOUNDATION_USERNAME', 'bukasfoundation'),
            role: 'provider',
            password: env('BUKAS_FOUNDATION_PASSWORD', $password),
        );
        $bukasKinabukasan->providerProfile()->updateOrCreate([
            'user_id' => $bukasKinabukasan->id,
        ], [
            'first_name' => 'Paolo',
            'last_name' => 'Mendoza',
            'middle_initial' => 'C',
            'contact_number' => '09172345678',
            'provider_name' => 'Bukas Kinabukasan Education Foundation',
            'provider_type' => 'foundation',
            'provider_website' => null,
            'provider_address' => 'Barangay Nueva, San Pedro City, Laguna',
            'provider_description' => 'A local education foundation supporting school participation, learning materials, and STEM opportunities for young learners.',
            'provider_contact_email' => 'bukasfoundation@scholarship.test',
            'provider_contact_number' => '09172345678',
            'verification_status' => 'approved',
            'verification_notes' => 'Fictional community provider approved for local demonstration.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $student = $this->seedUser(
            email: env('STUDENT_EMAIL', 'student@scholarship.test'),
            username: env('STUDENT_USERNAME', 'student'),
            role: 'applicant',
            password: env('STUDENT_PASSWORD', $password),
        );
        $student->studentProfile()->updateOrCreate([
            'user_id' => $student->id,
        ], [
            'first_name' => 'Alex',
            'last_name' => 'Santos',
            'middle_initial' => 'M',
            'suffix' => null,
            'gender' => 'prefer_not_to_say',
            'contact_number' => '09173456789',
            'account_managed_by' => 'learner',
            'citizenship_status' => 'filipino',
            'education_level' => 'senior_high_school',
            'school' => 'Quezon City Community High School',
            'school_type' => 'public',
            'learner_reference_number' => '123456789012',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'enrollment_status' => 'Enrolled',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'PHP 10,000 - 20,000',
            'household_size' => 5,
            'preferred_categories' => "Financial assistance\nSTEM scholarship\nCommunity grant",
            'preferred_locations' => "Metro Manila\nRizal\nLaguna",
            'willing_to_relocate' => 'depends',
            'support_needs' => 'School supplies, transportation, internet access, and future college preparation.',
            'current_scholarship_status' => 'none',
            'current_scholarship_details' => null,
            'scholarship_goal' => 'Complete senior high school and prepare for a college program in technology.',
            'address' => 'Barangay Commonwealth, Quezon City, Metro Manila',
            'barangay' => 'Commonwealth',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'latitude' => 14.7009000,
            'longitude' => 121.0834000,
            'birthdate' => '2008-09-15',
            'guardian_name' => 'Andrea Santos',
            'guardian_relationship' => 'Parent',
            'guardian_contact' => '09174567890',
            'guardian_email' => 'guardian@scholarship.test',
            'guardian_is_account_owner' => false,
            'verification_status' => 'approved',
            'verification_notes' => 'Complete fictional learner profile for local demonstration.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $this->seedScholarships($tulayAral, $bukasKinabukasan);

        if (app()->environment('local')) {
            $this->call(DemoCompletedApplicationSeeder::class);
        }

        $this->call(DemoProviderServiceSeeder::class);
    }

    private function seedUser(string $email, string $username, string $role, string $password): User
    {
        $user = User::query()->updateOrCreate([
            'email' => $email,
        ], [
            'username' => $username,
            'role' => $role,
            'password' => $password,
            'account_status' => 'active',
            'must_reset_password' => false,
            'password_reset_required_at' => null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function seedScholarships(User $tulayAral, User $bukasKinabukasan): void
    {
        $deadline = now()->addDays(60)->startOfDay();
        $programCycle = 'School Year '.$deadline->year.'-'.($deadline->year + 1);
        $common = [
            'review_rubric' => ReviewRubric::DEFAULT,
            'application_mode' => 'online',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'program_cycle' => $programCycle,
            'application_opens_at' => now()->startOfDay()->toDateString(),
            'deadline' => $deadline->toDateString(),
            'handoff_mode' => 'onsite',
            'handoff_deadline' => $deadline->copy()->addDays(14)->toDateString(),
            'handoff_instructions' => 'If you pass portal pre-screening, bring the listed original documents to the provider. The provider will verify them and explain its formal application process. Portal qualification is not a final scholarship award.',
            'status' => 'published',
            'views_count' => 0,
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
        ];

        $programs = [
            [
                'provider' => $tulayAral,
                'title' => 'Tulay Aral Senior High Support Grant',
                'data' => [
                    ...$common,
                    'image_path' => '/images/programs/tulay-aral-logo.png',
                    'category' => 'Financial assistance',
                    'description' => 'A combined support package for Grade 11 and Grade 12 learners that includes a PHP 10,000 one-time cash grant and school supplies for transportation, learning materials, and connectivity.',
                    'eligibility' => 'Currently enrolled senior high school learner from Metro Manila or Rizal with a general average of at least 80% and a household income within the listed bracket.',
                    'eligible_education_levels' => 'senior_high_school',
                    'eligible_courses' => 'Any strand',
                    'eligible_school_types' => "public\nprivate",
                    'eligible_year_levels' => "Grade 11\nGrade 12",
                    'eligible_locations' => "Metro Manila\nRizal",
                    'income_requirement' => 'PHP 10,000 - 20,000',
                    'location_name' => 'Tulay Aral Community Desk',
                    'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                    'latitude' => 14.6255000,
                    'longitude' => 121.1245000,
                    'requirements' => implode("\n", [
                'Certificate of enrollment',
                        'Latest report card or grades',
                        'School ID',
                        'Proof of income',
                    ]),
                    'post_qualification_requirements' => implode("\n", [
                        'Original certificate of enrollment',
                        'Original latest report card',
                        'Valid school ID',
                        'Provider formal application form',
                    ]),
                    'handoff_location_name' => 'Tulay Aral Community Desk',
                    'handoff_location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                    'award_amount' => 10000,
                    'minimum_gwa' => 80,
                    'minimum_grade_scale' => 'percentage',
                    'slots_available' => 25,
                    'renewal_policy' => 'One school-year grant. A learner may reapply if still enrolled and the foundation has available funds.',
                    'return_service_contract' => null,
                    'other_contract_terms' => 'Recipients attend one orientation and submit a short end-of-term update on how the assistance was used.',
                    'expected_results_at' => $deadline->copy()->addDays(12)->toDateString(),
                    'contact_person' => 'Mara L. Reyes',
                    'contact_department' => 'Community Scholarship Desk',
                    'contact_email' => 'tulayaral@scholarship.test',
                    'contact_number' => '09171234567',
                ],
                'benefits' => [
                    [
                        'type' => 'cash_grant',
                        'title' => 'School support grant',
                        'amount' => 10000,
                        'frequency' => 'one_time',
                        'duration' => 'Current program cycle',
                        'description' => 'Flexible assistance for transportation, supplies, and connectivity during the school year.',
                    ],
                    [
                        'type' => 'school_supplies',
                        'title' => 'School supplies support',
                        'frequency' => 'one_time',
                        'duration' => 'Current program cycle',
                        'description' => 'Basic learning materials are provided during the release orientation, subject to availability.',
                    ],
                ],
                'events' => [],
            ],
            [
                'provider' => $tulayAral,
                'title' => 'Tulay Aral College Starter Grant',
                'data' => [
                    ...$common,
                    'application_mode' => 'onsite',
                    'selection_stages' => ['screening', 'formal_application', 'interview', 'decision'],
                    'image_path' => '/images/programs/tulay-aral-logo.png',
                    'category' => 'Community grant',
                    'description' => 'A college-entry support package that combines a PHP 15,000 one-time cash grant with transition mentoring for enrollment, books, school materials, and first-year preparation.',
                    'eligibility' => 'Incoming first-year college learner from Metro Manila or Rizal with proof of admission, a general average of at least 85%, and availability for a short finalist interview.',
                    'eligible_education_levels' => "senior_high_school\ncollege",
                    'eligible_courses' => 'Any course',
                    'eligible_school_types' => "state_university\nlocal_college\nprivate",
                    'eligible_year_levels' => "Grade 12\n1st year",
                    'eligible_locations' => "Metro Manila\nRizal",
                    'income_requirement' => 'PHP 10,000 - 20,000',
                    'location_name' => 'Tulay Aral Community Desk',
                    'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                    'latitude' => 14.6255000,
                    'longitude' => 121.1245000,
                    'requirements' => implode("\n", [
                        'Admission or acceptance letter',
                        'Latest report card or grades',
                        'Proof of income',
                        'Recommendation letter',
                    ]),
                    'post_qualification_requirements' => implode("\n", [
                        'Original proof of college admission or enrollment',
                        'Original latest report card',
                        'Valid school or government ID',
                        'Provider formal application form',
                    ]),
                    'handoff_location_name' => 'Tulay Aral Community Desk',
                    'handoff_location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                    'award_amount' => 15000,
                    'minimum_gwa' => 85,
                    'minimum_grade_scale' => 'percentage',
                    'slots_available' => 15,
                    'renewal_policy' => 'This is a one-time college entry grant and is not automatically renewable.',
                    'return_service_contract' => null,
                    'other_contract_terms' => 'Recipients submit proof of enrollment before release and a brief utilization update after the first semester.',
                    'expected_results_at' => $deadline->copy()->addDays(16)->toDateString(),
                    'contact_person' => 'Mara L. Reyes',
                    'contact_department' => 'Community Scholarship Desk',
                    'contact_email' => 'tulayaral@scholarship.test',
                    'contact_number' => '09171234567',
                ],
                'benefits' => [
                    [
                        'type' => 'cash_grant',
                        'title' => 'College starter grant',
                        'amount' => 15000,
                        'frequency' => 'one_time',
                        'duration' => 'College entry period',
                        'description' => 'One-time support for enrollment, books, and essential school materials.',
                    ],
                    [
                        'type' => 'mentorship',
                        'title' => 'College transition mentoring',
                        'frequency' => 'one_time',
                        'duration' => 'College entry period',
                        'description' => 'Recipients join a short orientation and college-transition mentoring session.',
                    ],
                ],
                'events' => [
                    [
                        'type' => 'interview',
                        'title' => 'College Starter Finalist Interview',
                        'scheduled_at' => $deadline->copy()->addDays(8)->setTime(9, 0),
                        'mode' => 'onsite',
                        'venue' => 'Tulay Aral Community Desk',
                        'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                        'latitude' => 14.6255000,
                        'longitude' => 121.1245000,
                        'instructions' => 'Shortlisted applicants should bring proof of admission and be ready to discuss their college plan and intended use of the support package.',
                    ],
                ],
            ],
            [
                'provider' => $bukasKinabukasan,
                'title' => 'Bukas Kinabukasan School Essentials Grant',
                'data' => [
                    ...$common,
                    'application_mode' => 'provider_review',
                    'image_path' => '/images/programs/bukas-kinabukasan-logo.png',
                    'category' => 'Community grant',
                    'description' => 'A non-cash support package that provides school supplies, learning materials, and shared learning-device access for elementary and junior high school learners from nearby communities.',
                    'eligibility' => 'Currently enrolled elementary or junior high school learner from Laguna or Metro Manila whose parent or guardian can complete the application.',
                    'eligible_education_levels' => "elementary\njunior_high_school",
                    'eligible_courses' => 'Any',
                    'eligible_school_types' => "public\nprivate",
                    'eligible_year_levels' => "Grade 1\nGrade 2\nGrade 3\nGrade 4\nGrade 5\nGrade 6\nGrade 7\nGrade 8\nGrade 9\nGrade 10",
                    'eligible_locations' => "Laguna\nMetro Manila",
                    'income_requirement' => 'Any',
                    'location_name' => 'Bukas Kinabukasan Learning Hub',
                    'location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                    'latitude' => 14.3595000,
                    'longitude' => 121.0473000,
                    'requirements' => null,
                    'optional_requirements' => null,
                    'post_qualification_requirements' => implode("\n", [
                        'Original learner enrollment record',
                        'Latest report card or grades',
                        'Learner school ID when available',
                        'Parent or guardian valid ID',
                        'Provider formal application form signed by the guardian',
                    ]),
                    'handoff_location_name' => 'Bukas Kinabukasan Learning Hub',
                    'handoff_location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                    'award_amount' => null,
                    'minimum_gwa' => null,
                    'minimum_grade_scale' => null,
                    'slots_available' => 40,
                    'renewal_policy' => 'The non-cash support package covers one school year. Families may apply again during the next intake.',
                    'return_service_contract' => null,
                    'other_contract_terms' => 'A parent or guardian attends the release orientation and confirms receipt of school materials.',
                    'expected_results_at' => $deadline->copy()->addDays(10)->toDateString(),
                    'contact_person' => 'Paolo C. Mendoza',
                    'contact_department' => 'Learner Support Office',
                    'contact_email' => 'bukasfoundation@scholarship.test',
                    'contact_number' => '09172345678',
                ],
                'benefits' => [
                    [
                        'type' => 'school_supplies',
                        'title' => 'School essentials package',
                        'frequency' => 'one_time',
                        'duration' => 'Current school year',
                        'description' => 'A provider-prepared package of school supplies and learning materials for the current school year.',
                    ],
                    [
                        'type' => 'device_support',
                        'title' => 'Shared learning-device access',
                        'frequency' => 'entire_program',
                        'duration' => 'Current school year',
                        'description' => 'Selected families may use learning devices at the community hub during scheduled hours.',
                    ],
                ],
                'events' => [],
            ],
            [
                'provider' => $bukasKinabukasan,
                'title' => 'Bukas Kinabukasan STEM Pathways Grant',
                'data' => [
                    ...$common,
                    'application_mode' => 'onsite',
                    'image_path' => '/images/programs/bukas-kinabukasan-logo.png',
                    'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'],
                    'exam_duration_minutes' => 60,
                    'exam_passing_score' => 75,
                    'category' => 'STEM scholarship',
                    'description' => 'A competitive STEM support package combining a PHP 12,000 learning grant, an enrichment session, and pathway mentoring for senior high school learners preparing for science, engineering, computing, or technology studies.',
                    'eligibility' => 'Grade 11 or Grade 12 STEM learner from Laguna or Metro Manila with at least an 85% general average and availability for a qualifying exam and finalist interview.',
                    'application_questions' => [
                        [
                            'id' => 'stem_support_use',
                            'prompt' => 'How would this support help your STEM studies this school year?',
                            'required' => true,
                        ],
                    ],
                    'eligible_education_levels' => 'senior_high_school',
                    'eligible_courses' => 'STEM',
                    'eligible_school_types' => "public\nprivate",
                    'eligible_year_levels' => "Grade 11\nGrade 12",
                    'eligible_locations' => "Laguna\nMetro Manila",
                    'income_requirement' => 'Any',
                    'location_name' => 'Bukas Kinabukasan Learning Hub',
                    'location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                    'latitude' => 14.3595000,
                    'longitude' => 121.0473000,
                    'requirements' => implode("\n", [
                'Certificate of enrollment',
                        'Latest report card or grades',
                        'School ID',
                        'Recommendation letter',
                    ]),
                    'post_qualification_requirements' => implode("\n", [
                        'Original certificate of enrollment',
                        'Original latest report card',
                        'Valid school ID',
                        'Provider formal application form',
                    ]),
                    'handoff_location_name' => 'Bukas Kinabukasan Learning Hub',
                    'handoff_location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                    'award_amount' => 12000,
                    'minimum_gwa' => 85,
                    'minimum_grade_scale' => 'percentage',
                    'slots_available' => 20,
                    'renewal_policy' => 'Renewal of the support package for the next school year depends on continued STEM enrollment, satisfactory progress, and available funding.',
                    'return_service_contract' => null,
                    'other_contract_terms' => 'Finalists complete the provider-managed qualifying exam and interview. Recipients join one community learning session during the award period.',
                    'expected_results_at' => $deadline->copy()->addDays(21)->toDateString(),
                    'contact_person' => 'Paolo C. Mendoza',
                    'contact_department' => 'STEM Programs Office',
                    'contact_email' => 'bukasfoundation@scholarship.test',
                    'contact_number' => '09172345678',
                ],
                'benefits' => [
                    [
                        'type' => 'cash_grant',
                        'title' => 'STEM learning grant',
                        'amount' => 12000,
                        'frequency' => 'one_time',
                        'duration' => 'Current program cycle',
                        'description' => 'Financial support for STEM learning materials, projects, and school-related expenses.',
                    ],
                    [
                        'type' => 'training',
                        'title' => 'STEM enrichment session',
                        'frequency' => 'one_time',
                        'duration' => 'Current program cycle',
                        'description' => 'Recipients join a provider-led STEM learning and career orientation session.',
                    ],
                    [
                        'type' => 'mentorship',
                        'title' => 'STEM pathway mentoring',
                        'frequency' => 'entire_program',
                        'duration' => 'Current school year',
                        'description' => 'Recipients receive guidance on STEM study pathways during the award period.',
                    ],
                ],
                'events' => [
                    [
                        'type' => 'exam',
                        'title' => 'STEM Pathways Qualifying Exam',
                        'scheduled_at' => $deadline->copy()->addDays(7)->setTime(9, 0),
                        'mode' => 'onsite',
                        'venue' => 'Bukas Kinabukasan Learning Hub',
                        'location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                        'latitude' => 14.3595000,
                        'longitude' => 121.0473000,
                        'instructions' => 'Qualified applicants should bring a school ID, pencil, and the exam schedule notice. Arrive at least 20 minutes before the exam.',
                    ],
                    [
                        'type' => 'interview',
                        'title' => 'STEM Pathways Finalist Interview',
                        'scheduled_at' => $deadline->copy()->addDays(14)->setTime(13, 0),
                        'mode' => 'onsite',
                        'venue' => 'Bukas Kinabukasan Learning Hub',
                        'location_address' => 'Barangay Nueva, San Pedro City, Laguna',
                        'latitude' => 14.3595000,
                        'longitude' => 121.0473000,
                        'instructions' => 'Applicants who pass the exam should be ready to discuss their STEM goals, school plans, and community interests.',
                    ],
                ],
            ],
        ];

        foreach ($programs as $program) {
            $scholarship = Scholarship::query()->updateOrCreate([
                'provider_id' => $program['provider']->id,
                'title' => $program['title'],
            ], $program['data']);

            $scholarship->benefits()->delete();
            $scholarship->benefits()->createMany(
                collect($program['benefits'] ?? [])->values()->map(
                    fn (array $benefit, int $index): array => [...$benefit, 'sort_order' => $index]
                )->all()
            );

            $eventTypes = collect($program['events'] ?? [])->pluck('type')->filter()->values();

            if ($eventTypes->isEmpty()) {
                $scholarship->events()->delete();
            } else {
                $scholarship->events()->whereNotIn('type', $eventTypes)->delete();
            }

            foreach ($program['events'] ?? [] as $eventData) {
                $event = $scholarship->events()->updateOrCreate([
                    'type' => $eventData['type'],
                ], [
                    ...$eventData,
                    'status' => 'scheduled',
                    'created_by' => $program['provider']->id,
                    'updated_by' => $program['provider']->id,
                ]);

                if ($event->wasRecentlyCreated || $event->wasChanged()) {
                    app(ScholarshipEventService::class)->syncEligibleApplications($event);
                }
            }
        }
    }

}
