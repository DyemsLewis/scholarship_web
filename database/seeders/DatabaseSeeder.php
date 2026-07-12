<?php

namespace Database\Seeders;

use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\User;
use App\Support\ReviewRubric;
use App\Support\Terms;
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
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

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
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);
        $provider->forceFill(['email_verified_at' => now()])->save();

        $provider->providerProfile()->updateOrCreate([
            'user_id' => $provider->id,
        ], [
            'first_name' => 'DOST',
            'last_name' => 'SEI',
            'middle_initial' => null,
            'contact_number' => '(02) 8330 8876',
            'provider_name' => 'Department of Science and Technology - Science Education Institute',
            'provider_type' => 'government',
            'provider_website' => 'https://www.sei.dost.gov.ph/',
            'provider_address' => 'DOST-SEI, DOST Compound, General Santos Avenue, Bicutan, Taguig City',
            'provider_description' => 'DOST-SEI administers science and technology scholarship programs for Filipino students pursuing priority S&T courses.',
            'verification_status' => 'approved',
            'verification_notes' => 'Seeded DOST-SEI provider profile for local scholarship testing.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $chedProvider = User::query()->updateOrCreate([
            'email' => env('CHED_PROVIDER_EMAIL', 'ched.provider@scholarship.test'),
        ], [
            'username' => env('CHED_PROVIDER_USERNAME', 'chedprovider'),
            'role' => 'provider',
            'password' => env('CHED_PROVIDER_PASSWORD', $password),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);
        $chedProvider->forceFill(['email_verified_at' => now()])->save();

        $chedProvider->providerProfile()->updateOrCreate([
            'user_id' => $chedProvider->id,
        ], [
            'first_name' => 'CHED',
            'last_name' => 'Central Office',
            'middle_initial' => null,
            'contact_number' => '(02) 8441 1220',
            'provider_name' => 'Commission on Higher Education (CHED)',
            'provider_type' => 'government',
            'provider_website' => 'https://ched.gov.ph/',
            'provider_address' => 'Higher Education Development Center Building, C.P. Garcia Avenue, UP Campus, Diliman, Quezon City',
            'provider_description' => 'CHED promotes equitable access to quality higher education and administers national and regional student financial assistance programs.',
            'verification_status' => 'approved',
            'verification_notes' => 'Seeded CHED provider profile for local scholarship testing.',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $student = User::query()->updateOrCreate([
            'email' => env('STUDENT_EMAIL', 'student@scholarship.test'),
        ], [
            'username' => env('STUDENT_USERNAME', 'student'),
            'role' => 'applicant',
            'password' => env('STUDENT_PASSWORD', $password),
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);
        $student->forceFill(['email_verified_at' => now()])->save();

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

        $legacyProgramTitles = [
            'Bright Future Student Grant' => 'DOST-SEI RA 7687 Undergraduate Scholarship',
            'Demo Program 1' => 'DOST-SEI RA 7687 Undergraduate Scholarship',
            'Demo Program 2' => 'DOST-SEI Merit Undergraduate Scholarship',
            'Demo Program 3' => 'DOST-SEI Junior Level Science Scholarship (JLSS)',
        ];

        foreach ($legacyProgramTitles as $oldTitle => $newTitle) {
            Scholarship::query()
                ->where('provider_id', $provider->id)
                ->where('title', $oldTitle)
                ->update(['title' => $newTitle]);
        }

        $dostContactEmail = 'dostsei@sei.dost.gov.ph';
        $dostContactNumber = '(02) 8330 8876';
        $priorityCourses = implode("\n", [
            'Science, Technology, Engineering, and Mathematics (STEM)',
            'Engineering',
            'Computer Science',
            'Information Technology',
            'Mathematics',
            'Statistics',
            'Biology',
            'Chemistry',
            'Physics',
            'Agriculture',
            'Fisheries',
            'Food Technology',
            'Geology',
            'Meteorology',
            'Science and Mathematics Teaching',
        ]);
        $undergraduateRequirements = implode("\n", [
            'DOST-SEI online application form',
            'Certificate of good moral character',
            'Certificate of good health',
            'Principal certification for STEM strand or upper 5% non-STEM ranking',
            'Parent or legal guardian certifications required by DOST-SEI',
            'Applicant certification of no post-secondary units',
            'Signed applicant and parent/legal guardian declaration',
            'DOST-SEI scholarship examination and award documents',
        ]);
        $undergraduateReturnService = 'Awardees and a parent/legal guardian sign the DOST-SEI Scholarship Agreement before availing benefits. The scholar must finish the approved priority S&T degree and render return service in the Philippines along the scholar\'s field for the period required by the signed agreement and current DOST-SEI policies.';
        $jlssReturnService = 'JLSS awardees sign a DOST-SEI scholarship agreement before availing benefits. Merit and RA 7687 JLSS scholars render return service in the Philippines under the signed agreement. RA 10612 scholars render teaching service in science, mathematics, or STEM in a secondary school as assigned or validated by the program. Exact duration and placement follow the signed agreement and DOST-SEI/DepEd rules.';
        $dostOtherContractTerms = 'Applicants and awardees should review the official DOST-SEI Scholarship Agreement and required forms before acceptance. Other commitments may include truthful declaration of application data, parent/legal guardian undertaking, consent for DOST-SEI to process personal data, background or socio-economic verification, approved course and school rules, grade and report submission, shifting or transfer approval, travel or clearance requirements, scholarship suspension or termination, and refund or repayment duties when required by the signed agreement.';
        $dostProgramDefaults = [
            'category' => 'DOST-SEI S&T Scholarship',
            'eligible_locations' => 'Philippines',
            'location_name' => 'DOST-SEI Main Office, Bicutan',
            'location_address' => 'DOST Compound, General Santos Avenue, Bicutan, Taguig City, Metro Manila',
            'latitude' => 14.4899500,
            'longitude' => 121.0442100,
            'application_mode' => 'online',
            'contact_email' => $dostContactEmail,
            'contact_number' => $dostContactNumber,
            'deadline' => null,
            'status' => 'published',
            'review_rubric' => ReviewRubric::DEFAULT,
            'views_count' => 0,
            'other_contract_terms' => $dostOtherContractTerms,
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
        ];
        $dostPrograms = [
            'DOST-SEI RA 7687 Undergraduate Scholarship' => [
                'image_path' => '/images/programs/dost-logo-card.jpg',
                'description' => 'A DOST-SEI undergraduate scholarship for poor, talented, and deserving Filipino students who will pursue priority science, mathematics, engineering, and other S&T bachelor degree programs.',
                'eligibility' => 'Natural-born Filipino citizen; Grade 12 STEM student or qualified upper 5% non-STEM/high school graduate; family socio-economic status within DOST-SEI RA 7687 indicators; resident of the municipality for at least four years when required; of good moral character and good health; no college or post-high-school vocational units; must qualify through the DOST-SEI Undergraduate Scholarship Examination.',
                'eligible_education_levels' => 'senior_high_school',
                'eligible_courses' => $priorityCourses,
                'eligible_school_types' => implode("\n", [
                    'public',
                    'private',
                    'state_university',
                    'local_college',
                ]),
                'eligible_year_levels' => 'Grade 12',
                'income_requirement' => 'Within RA 7687 socioeconomic indicators',
                'requirements' => implode("\n", [
                    $undergraduateRequirements,
                    'Certificate of residency',
                    'Household or socio-economic information required for RA 7687 screening',
                ]),
                'award_amount' => 40000,
                'minimum_gwa' => null,
                'minimum_grade_scale' => null,
                'slots_available' => null,
                'renewal_policy' => 'Continued assistance depends on the DOST-SEI scholarship agreement, enrollment in an approved priority S&T course, good academic standing, and submitted semester requirements.',
                'return_service_contract' => $undergraduateReturnService,
            ],
            'DOST-SEI Merit Undergraduate Scholarship' => [
                'image_path' => '/images/programs/dost-logo-card.jpg',
                'description' => 'A DOST-SEI undergraduate scholarship for students with high aptitude in science and mathematics who are willing to pursue careers in science and technology through priority S&T degree programs.',
                'eligibility' => 'Natural-born Filipino citizen; Grade 12 STEM student or qualified upper 5% non-STEM/high school graduate; of good moral character and good health; no college or post-high-school vocational units; must qualify through the DOST-SEI Undergraduate Scholarship Examination.',
                'eligible_education_levels' => 'senior_high_school',
                'eligible_courses' => $priorityCourses,
                'eligible_school_types' => implode("\n", [
                    'public',
                    'private',
                    'state_university',
                    'local_college',
                ]),
                'eligible_year_levels' => 'Grade 12',
                'income_requirement' => 'No income ceiling',
                'requirements' => $undergraduateRequirements,
                'award_amount' => 40000,
                'minimum_gwa' => null,
                'minimum_grade_scale' => null,
                'slots_available' => null,
                'renewal_policy' => 'Continued assistance depends on the DOST-SEI scholarship agreement, enrollment in an approved priority S&T course, good academic standing, and submitted semester requirements.',
                'return_service_contract' => $undergraduateReturnService,
            ],
            'DOST-SEI Junior Level Science Scholarship (JLSS)' => [
                'image_path' => '/images/programs/dost-logo-card.jpg',
                'description' => 'A DOST-SEI scholarship for regular college students entering the third year of a priority S&T course, including JLSS components under Merit, RA 7687, and RA 10612.',
                'eligibility' => 'Natural-born Filipino citizen; regular second-year college student and incoming third-year student in a DOST-SEI priority S&T course; general weighted average of at least 83% or equivalent; no conditional or failing grades from first year through the required evaluation period; of good moral character and good health; must qualify through the JLSS examination.',
                'eligible_education_levels' => 'college',
                'eligible_courses' => $priorityCourses,
                'eligible_school_types' => implode("\n", [
                    'state_university',
                    'local_college',
                    'private',
                ]),
                'eligible_year_levels' => implode("\n", [
                    '2nd year',
                    '3rd year',
                ]),
                'income_requirement' => 'Depends on JLSS component',
                'requirements' => implode("\n", [
                    'DOST-SEI JLSS online application form',
                    'Certificate of enrollment or regular second-year standing',
                    'Certified true copy of grades',
                    'Certificate of good moral character',
                    'Certificate of good health',
                    'Proof of citizenship or birth certificate',
                    'Parent or legal guardian certifications required by DOST-SEI',
                    'DOST-SEI JLSS examination and award documents',
                ]),
                'award_amount' => 40000,
                'minimum_gwa' => 83,
                'minimum_grade_scale' => 'percentage',
                'slots_available' => null,
                'renewal_policy' => 'Continued assistance depends on DOST-SEI academic standing rules, enrollment in a priority S&T course, and submitted semester requirements.',
                'return_service_contract' => $jlssReturnService,
            ],
        ];

        foreach ($dostPrograms as $title => $program) {
            Scholarship::query()->updateOrCreate([
                'provider_id' => $provider->id,
                'title' => $title,
            ], [
                ...$dostProgramDefaults,
                ...$program,
            ]);
        }

        $chedPriorityPrograms = implode("\n", [
            'Science and Mathematics',
            'Information Technology Education',
            'Engineering and Technology',
            'Architecture and related programs',
            'Health Profession Education',
            'Teacher Education',
            'Agriculture, Forestry, and Fisheries',
            'Business and Management',
            'Social Sciences',
        ]);
        $chedRequirements = implode("\n", [
            'Accomplished CHED online application form',
            'PSA-issued birth certificate',
            'Certified true copy of Grade 12 Form 138 or SF9 signed by the registrar or authorized school representative',
            'Latest income tax return of parents or guardian, BIR certificate of tax exemption/non-filer, OFW or seafarer proof of income, or CSWD/MSWD social case study report',
            'Proof for any claimed special equity group, when applicable',
            'Notarized certificate of guardianship, when applicable',
        ]);

        Scholarship::query()->updateOrCreate([
            'provider_id' => $chedProvider->id,
            'title' => 'CHED Merit Scholarship Program (CMSP)',
        ], [
            'image_path' => '/images/programs/ched-logo-card.jpg',
            'category' => 'CHED Merit Scholarship',
            'description' => 'A competitive CHED scholarship for academically talented and deserving Filipino students entering or currently in their first year of college in an identified priority undergraduate degree program.',
            'eligibility' => 'Filipino citizen; incoming or current first-year college student; graduate of a senior high school in the Philippines with a Grade 12 general weighted average of at least 93% or equivalent; combined annual gross income of parents or legal guardian not exceeding PHP 500,000; must enroll in an eligible CHED priority program and institution; selection is subject to ranking and available slots.',
            'eligible_education_levels' => implode("\n", [
                'senior_high_school',
                'college',
            ]),
            'eligible_courses' => $chedPriorityPrograms,
            'eligible_school_types' => implode("\n", [
                'state_university',
                'local_college',
                'private',
            ]),
            'eligible_year_levels' => implode("\n", [
                'Grade 12',
                '1st year',
            ]),
            'eligible_locations' => 'Philippines',
            'income_requirement' => 'Combined annual gross income of parents or legal guardian must not exceed PHP 500,000',
            'location_name' => 'CHED Central Office',
            'location_address' => 'Higher Education Development Center Building, C.P. Garcia Avenue, UP Campus, Diliman, Quezon City 1101',
            'latitude' => 14.6536208,
            'longitude' => 121.0581081,
            'requirements' => $chedRequirements,
            'review_rubric' => ReviewRubric::DEFAULT,
            'award_amount' => 120000,
            'minimum_gwa' => 93,
            'minimum_grade_scale' => 'percentage',
            'slots_available' => null,
            'application_mode' => 'online',
            'renewal_policy' => 'Continued assistance depends on the official CHED scholarship contract, enrollment in an approved priority degree program and institution, satisfactory academic standing, and timely submission of monitoring requirements.',
            'return_service_contract' => null,
            'other_contract_terms' => 'Awardees complete the official CHED Scholarship Contract through the responsible CHED Regional Office. Contract signing, verification, and any required supporting forms are handled directly by CHED outside this platform. Applicants must provide truthful information and comply with enrollment, academic monitoring, reporting, and scholarship termination rules in the signed contract.',
            'contact_email' => 'osds@ched.gov.ph',
            'contact_number' => '(02) 8441 1220',
            'deadline' => null,
            'status' => 'published',
            'views_count' => 0,
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
        ]);

        ProviderAssessment::query()->updateOrCreate([
            'provider_id' => $provider->id,
        ], [
            'title' => 'DOST-SEI Scholarship Qualifying Examination',
            'assessment_type' => 'qualifying_exam',
            'image_path' => '/images/programs/dost-logo-card.jpg',
            'description' => 'Provider-managed qualifying examination for screened DOST-SEI scholarship applicants.',
            'duration_minutes' => 120,
            'passing_score' => 75,
            'delivery_mode' => 'onsite',
            'venue' => 'Assigned DOST-SEI testing center',
            'instructions' => 'Only applicants who pass eligibility and document screening should be scheduled. Share the confirmed date, testing center, permitted materials, and identification requirements in the applicant review note.',
            'status' => 'active',
        ]);

        ProviderAssessment::query()->updateOrCreate([
            'provider_id' => $chedProvider->id,
        ], [
            'title' => 'CHED Merit Eligibility Screening Assessment',
            'assessment_type' => 'screening_assessment',
            'image_path' => '/images/programs/ched-logo-card.jpg',
            'description' => 'Optional provider-managed screening assessment for CHED Merit applicants. This is not presented as a national CHED examination.',
            'duration_minutes' => 60,
            'passing_score' => 70,
            'delivery_mode' => 'provider_managed',
            'venue' => 'CHED Regional Office or provider-approved online session',
            'instructions' => 'Use only when the responsible CHED office requires an additional screening activity. Applicants should first pass academic, income, program, and document checks before receiving a schedule.',
            'status' => 'active',
        ]);
    }
}
