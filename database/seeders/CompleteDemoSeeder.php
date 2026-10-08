<?php

namespace Database\Seeders;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringAdjustmentRequest;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringIntervention;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringReview;
use App\Models\RecipientMonitoringSubmission;
use App\Models\RecipientSupportDecision;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CompleteDemoSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    private const REQUIREMENTS = [
        'Certificate of enrollment',
        'Latest report card or grades',
        'Recent school ID',
        'Proof of household income',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The complete demo seeder is restricted to local and testing environments.');
        }

        if (User::query()->exists()) {
            throw new RuntimeException('The complete demo seeder requires an empty database. Run migrate:fresh with this seeder.');
        }

        Storage::disk('local')->deleteDirectory('demo-showcase');

        $admin = $this->seedAdmin();
        $provider = $this->seedProvider($admin);
        $scholarship = $this->seedScholarship($provider);
        $programExamples = $this->seedProgramLifecycleExamples($scholarship);
        $applications = collect();

        foreach ($this->applicantRecords() as $index => $record) {
            $applicant = $this->seedApplicant($admin, $record, $index + 1);
            $applicationScholarship = $record['scenario'] === 'capacity_award'
                ? $programExamples->get('full_capacity')
                : $scholarship;
            $application = $this->seedApplication(
                provider: $provider,
                scholarship: $applicationScholarship,
                applicant: $applicant,
                scenario: $record['scenario'],
                applicantNumber: $index + 1,
            );
            $applications->put($record['scenario'], $application);
        }

        $this->seedRecipientMonitoring($provider, $scholarship, $applications);
        $this->seedSupportReports(
            $admin,
            $provider,
            $scholarship,
            $applications->get('correction'),
            $applications->get('completed_support'),
        );
        $this->seedProviderVerificationExamples($admin);
        $this->call(RoleTestAccountsSeeder::class);
        $this->call(DemoProviderServiceSeeder::class);

        if ((int) $admin->id !== 1 || (int) $provider->id !== 2 || (int) $scholarship->id !== 1) {
            throw new RuntimeException('Demo IDs did not restart at 1. Reset the database before running this seeder.');
        }

        $this->command?->info('Complete local demo created with role teams, program lifecycle examples, and full workflow branch coverage.');
    }

    private function seedAdmin(): User
    {
        $admin = User::query()->create([
            'email' => 'admin@findscholarship.test',
            'username' => 'superadmin',
            'role' => 'admin',
            'password' => env('DEMO_PASSWORD', self::PASSWORD),
            'account_status' => 'active',
            'must_reset_password' => false,
            'terms_accepted_at' => now()->subMonths(6),
            'privacy_accepted_at' => now()->subMonths(6),
            'terms_version' => Terms::VERSION,
        ]);
        $admin->forceFill(['email_verified_at' => now()->subMonths(6)])->save();
        $admin->adminProfile()->create([
            'first_name' => 'Elena',
            'last_name' => 'Castillo',
            'middle_initial' => 'R',
            'contact_number' => '09170000001',
            'display_name' => 'FindScholarship Super Admin',
        ]);

        return $admin->fresh('adminProfile');
    }

    private function seedProvider(User $admin): User
    {
        $provider = User::query()->create([
            'email' => 'programs@tulayaral.test',
            'username' => 'tulayaral',
            'role' => 'provider',
            'account_title' => 'Executive Program Director',
            'permissions' => User::PROVIDER_PERMISSIONS,
            'password' => env('DEMO_PASSWORD', self::PASSWORD),
            'account_status' => 'active',
            'must_reset_password' => false,
            'terms_accepted_at' => now()->subMonths(5),
            'privacy_accepted_at' => now()->subMonths(5),
            'terms_version' => Terms::VERSION,
        ]);
        $provider->forceFill(['email_verified_at' => now()->subMonths(5)])->save();
        $provider->providerProfile()->create([
            'first_name' => 'Mara',
            'last_name' => 'Reyes',
            'middle_initial' => 'L',
            'contact_number' => '09171234567',
            'representative_position' => 'Executive Program Director',
            'provider_name' => 'Tulay Aral Community Foundation',
            'provider_type' => 'non_profit',
            'provider_website' => 'https://findscholarship.online/providers/tulay-aral',
            'provider_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'provider_description' => 'A community education foundation helping qualified senior high school learners stay enrolled and prepare for college.',
            'mission' => 'Make practical education support easier to access, verify, and sustain for learners with limited resources.',
            'year_established' => 2018,
            'service_area' => 'Antipolo City and selected communities in Rizal',
            'logo_path' => '/images/programs/tulay-aral-logo.png',
            'provider_contact_email' => 'programs@tulayaral.test',
            'provider_contact_number' => '09171234567',
            'contact_department' => 'Scholarship Programs Office',
            'office_hours' => 'Monday to Friday, 8:30 AM to 5:00 PM',
            'legal_name' => 'Tulay Aral Community Foundation, Inc.',
            'registration_authority' => 'Securities and Exchange Commission',
            'registration_number' => 'DEMO-CN201812345',
            'registration_date' => '2018-06-18',
            'verification_status' => 'approved',
            'verification_notes' => 'Complete fictional organization record approved for local system demonstration.',
            'verified_at' => now()->subMonths(4),
            'verified_by' => $admin->id,
        ]);

        foreach ([
            'registration_certificate' => 'SEC registration certificate',
            'representative_authorization' => 'Representative authorization letter',
            'organization_profile' => 'Organization profile and program history',
        ] as $type => $label) {
            $contents = $this->demoDocument('Tulay Aral Community Foundation', $label, 'Provider verification record');
            $path = "demo-showcase/provider/{$type}.svg";
            Storage::disk('local')->put($path, $contents);
            $provider->providerVerificationDocuments()->create([
                'uploaded_by' => $provider->id,
                'document_type' => $type,
                'original_name' => Str::slug($label).'.svg',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'size' => strlen($contents),
                'status' => 'approved',
                'review_notes' => 'Verified for the local showcase dataset.',
                'uploaded_at' => now()->subMonths(4),
                'terms_accepted_at' => now()->subMonths(4),
                'terms_version' => Terms::VERSION,
            ]);
        }

        return $provider->fresh('providerProfile');
    }

    private function seedScholarship(User $provider): Scholarship
    {
        $scholarship = Scholarship::query()->create([
            'provider_id' => $provider->id,
            'image_path' => '/images/programs/tulay-aral-logo.png',
            'title' => 'Tulay Aral Complete Path Scholarship',
            'category' => 'Financial assistance',
            'program_cycle' => 'School Year 2026-2027',
            'description' => 'A year-long senior high school scholarship with education allowance, learning materials, mentoring, and structured recipient support.',
            'provider_objectives' => ['education_access', 'academic_progress', 'community_development'],
            'provider_objective_notes' => 'Support learners through selection, school-year monitoring, and transparent benefit releases.',
            'eligibility' => 'Grade 11 or Grade 12 learners with at least an 85% general average, current enrollment, and demonstrated financial need.',
            'eligibility_conditions' => [
                ['field' => 'education_level', 'operator' => 'in', 'value' => ['senior_high_school']],
                ['field' => 'gwa', 'operator' => 'gte', 'value' => 85],
            ],
            'eligible_education_levels' => 'senior_high_school',
            'eligible_courses' => 'Any strand',
            'eligible_school_types' => "public\nprivate",
            'eligible_year_levels' => "Grade 11\nGrade 12",
            'eligible_locations' => "Rizal\nMetro Manila",
            'income_requirement' => 'PHP 10,000 - 20,000',
            'exclude_current_scholarship_recipients' => true,
            'location_name' => 'Tulay Aral Community Learning Hub',
            'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'latitude' => 14.6255000,
            'longitude' => 121.1245000,
            'requirements' => implode("\n", self::REQUIREMENTS),
            'optional_requirements' => "Achievement certificate\nRecommendation letter",
            'post_qualification_requirements' => "Original certificate of enrollment\nOriginal latest report card\nRecent school ID",
            'handoff_mode' => 'onsite',
            'handoff_instructions' => 'Bring the original enrollment record, latest grades, and recent school ID for formal verification.',
            'handoff_deadline' => now()->addDays(20)->toDateString(),
            'handoff_location_name' => 'Tulay Aral Community Learning Hub',
            'handoff_location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'review_rubric' => ReviewRubric::DEFAULT,
            'application_questions' => [
                ['id' => 'study_goal', 'prompt' => 'How will the scholarship help you continue your studies?', 'required' => true],
                ['id' => 'community_goal', 'prompt' => 'What goal do you want to achieve this school year?', 'required' => true],
            ],
            'award_amount' => 18000,
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
            'slots_available' => 15,
            'application_limit' => 60,
            'application_mode' => 'online',
            'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'],
            'exam_duration_minutes' => 90,
            'exam_passing_score' => 75,
            'renewal_policy' => 'Support may be renewed after the school-year review when requirements are met and funding remains available.',
            'return_service_contract' => null,
            'other_contract_terms' => 'Recipients attend one orientation, keep contact details current, and report issues that may affect enrollment or participation.',
            'recipient_agreement' => [
                'commitment_type' => 'reporting_and_participation',
                'responsibilities' => 'Remain enrolled, attend recipient activities, submit each published check-in, and confirm received benefits.',
                'required_evidence' => 'Current grade record, proof of enrollment, and provider attendance records for required activities.',
                'release_conditions' => 'The initial allowance follows agreement acceptance and identity verification. Later releases require due monitoring items to be reviewed.',
                'duration' => 'From the first support release through the end of School Year 2026-2027.',
                'noncompliance_consequence' => 'The provider contacts the recipient and reviews the circumstances before delaying or ending unreleased support.',
                'exit_or_exception_process' => 'Recipients may request more time or an exception for illness, transfer, family circumstances, or another documented issue.',
            ],
            'contact_email' => 'programs@tulayaral.test',
            'contact_number' => '09171234567',
            'application_opens_at' => now()->subDays(30)->toDateString(),
            'deadline' => now()->addDays(45)->toDateString(),
            'expected_results_at' => now()->addDays(65)->toDateString(),
            'support_starts_at' => now()->subDays(20)->toDateString(),
            'support_ends_at' => now()->addMonths(8)->toDateString(),
            'official_program_url' => 'https://findscholarship.online/scholarships/tulay-aral-complete-path',
            'contact_person' => 'Mara L. Reyes',
            'contact_department' => 'Scholarship Programs Office',
            'status' => 'published',
            'views_count' => 148,
            'provider_terms_accepted_at' => now()->subMonths(2),
            'provider_terms_version' => Terms::VERSION,
        ]);

        $scholarship->benefits()->createMany([
            [
                'type' => 'allowance',
                'title' => 'Education allowance',
                'amount' => 18000,
                'coverage' => 'fixed',
                'frequency' => 'per_term',
                'duration' => 'School Year 2026-2027',
                'description' => 'Two scheduled releases for transportation, connectivity, and school expenses.',
                'sort_order' => 1,
            ],
            [
                'type' => 'school_supplies',
                'title' => 'Learning materials kit',
                'frequency' => 'one_time',
                'duration' => 'First semester',
                'description' => 'Core notebooks, printing supplies, and project materials.',
                'sort_order' => 2,
            ],
            [
                'type' => 'mentorship',
                'title' => 'College preparation mentoring',
                'frequency' => 'per_term',
                'duration' => 'Current school year',
                'description' => 'Short planning sessions for applications, study goals, and college readiness.',
                'sort_order' => 3,
            ],
        ]);

        $scholarship->events()->createMany([
            [
                'type' => 'exam',
                'title' => 'Complete Path Qualifying Exam',
                'scheduled_at' => now()->addDays(8)->setTime(9, 0),
                'mode' => 'onsite',
                'venue' => 'Tulay Aral Community Learning Hub',
                'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                'instructions' => 'Bring a recent school ID, two pencils, and the schedule notice.',
                'status' => 'scheduled',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
            [
                'type' => 'interview',
                'title' => 'Finalist Online Interview',
                'scheduled_at' => now()->addDays(15)->setTime(13, 30),
                'mode' => 'online',
                'online_url' => 'https://meet.google.com/demo-tulay-aral-room',
                'instructions' => 'Join ten minutes early and keep your school ID ready.',
                'status' => 'scheduled',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
        ]);

        $scholarship->announcements()->create([
            'audience' => 'all_applicants',
            'title' => 'Complete Path application cycle is active',
            'message' => 'Check your application record for the next required action and schedule.',
            'recipient_count' => count($this->applicantRecords()),
            'published_by' => $provider->id,
            'published_at' => now()->subDays(2),
        ]);

        return $scholarship->fresh(['benefits', 'events']);
    }

    /** @return Collection<string, Scholarship> */
    private function seedProgramLifecycleExamples(Scholarship $source): Collection
    {
        $definitions = [
            'draft' => [
                'title' => 'Tulay Aral Transportation Starter Grant',
                'description' => 'A draft transport-support program that the coordinator can finish and submit for review.',
                'status' => 'draft',
                'application_opens_at' => now()->addMonths(2)->toDateString(),
                'deadline' => now()->addMonths(3)->toDateString(),
                'views_count' => 0,
            ],
            'pending_review' => [
                'title' => 'Tulay Aral Community Technology Access Grant',
                'description' => 'A completed program submission waiting for administrator publication review.',
                'status' => 'pending_review',
                'application_opens_at' => now()->addMonth()->toDateString(),
                'deadline' => now()->addMonths(2)->toDateString(),
                'views_count' => 0,
            ],
            'closed' => [
                'title' => 'Tulay Aral School Essentials Grant 2025-2026',
                'program_cycle' => 'School Year 2025-2026',
                'description' => 'A completed prior-cycle program retained for records and reporting demonstrations.',
                'status' => 'closed',
                'application_opens_at' => now()->subYear()->toDateString(),
                'deadline' => now()->subMonths(10)->toDateString(),
                'support_starts_at' => now()->subMonths(9)->toDateString(),
                'support_ends_at' => now()->subMonth()->toDateString(),
                'views_count' => 224,
            ],
            'rejected' => [
                'title' => 'Tulay Aral Weekend Learning Hub Grant',
                'description' => 'A returned program example that requires clearer release conditions before resubmission.',
                'status' => 'rejected',
                'application_opens_at' => now()->addMonths(2)->toDateString(),
                'deadline' => now()->addMonths(4)->toDateString(),
                'views_count' => 0,
            ],
            'full_capacity' => [
                'title' => 'Tulay Aral One-Time College Readiness Award',
                'description' => 'A published one-slot award used to demonstrate a program that has reached recipient capacity.',
                'status' => 'published',
                'slots_available' => 1,
                'application_limit' => 10,
                'application_opens_at' => now()->subDays(30)->toDateString(),
                'deadline' => now()->addDays(20)->toDateString(),
                'support_starts_at' => now()->addMonth()->toDateString(),
                'support_ends_at' => now()->addMonths(2)->toDateString(),
                'views_count' => 46,
            ],
        ];

        return collect($definitions)->map(function (array $attributes) use ($source): Scholarship {
            $program = $source->replicate();
            $program->fill($attributes);
            $program->save();

            foreach ($source->benefits as $benefit) {
                $copy = $benefit->replicate();
                $copy->scholarship_id = $program->id;
                $copy->save();
            }

            return $program->fresh('benefits');
        });
    }

    private function seedProviderVerificationExamples(User $admin): void
    {
        $providers = [
            [
                'email' => 'verification.pending@demo.test',
                'username' => 'provider.pending',
                'first_name' => 'Leah',
                'last_name' => 'Mercado',
                'provider_name' => 'Lakbay Aral Learning Network',
                'status' => 'pending',
                'notes' => 'Organization registration and representative authority are waiting for administrator review.',
            ],
            [
                'email' => 'verification.revision@demo.test',
                'username' => 'provider.revision',
                'first_name' => 'Anton',
                'last_name' => 'Rivera',
                'provider_name' => 'Gabay Kabataan Education Initiative',
                'status' => 'rejected',
                'notes' => 'Replace the expired registration proof and upload a signed representative authorization.',
            ],
        ];

        foreach ($providers as $index => $record) {
            $provider = User::query()->create([
                'email' => $record['email'],
                'username' => $record['username'],
                'role' => 'provider',
                'account_title' => 'Organization Representative',
                'permissions' => User::PROVIDER_PERMISSIONS,
                'password' => env('DEMO_PASSWORD', self::PASSWORD),
                'account_status' => 'active',
                'must_reset_password' => false,
                'terms_accepted_at' => now()->subMonth(),
                'privacy_accepted_at' => now()->subMonth(),
                'terms_version' => Terms::VERSION,
            ]);
            $provider->forceFill(['email_verified_at' => now()->subMonth()])->save();
            $provider->providerProfile()->create([
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'contact_number' => sprintf('0917888000%d', $index + 1),
                'representative_position' => 'Organization Representative',
                'provider_name' => $record['provider_name'],
                'provider_type' => 'non_profit',
                'provider_address' => 'Antipolo City, Rizal',
                'provider_description' => 'Fictional provider record prepared for local verification workflow testing.',
                'mission' => 'Expand access to practical education support for local learners.',
                'year_established' => 2021 + $index,
                'service_area' => 'Rizal',
                'provider_contact_email' => $record['email'],
                'provider_contact_number' => sprintf('0917888000%d', $index + 1),
                'legal_name' => $record['provider_name'].', Inc.',
                'registration_authority' => 'Securities and Exchange Commission',
                'registration_number' => 'DEMO-VERIFY-'.($index + 1),
                'registration_date' => '2022-06-15',
                'verification_status' => $record['status'],
                'verification_notes' => $record['notes'],
                'verified_at' => $record['status'] === 'rejected' ? now()->subDays(3) : null,
                'verified_by' => $record['status'] === 'rejected' ? $admin->id : null,
            ]);

            foreach (['registration_certificate', 'representative_authorization'] as $documentType) {
                $label = Str::headline($documentType);
                $contents = $this->demoDocument($record['provider_name'], $label, 'Provider verification example');
                $path = "demo-showcase/provider-verification/{$provider->id}/{$documentType}.svg";
                Storage::disk('local')->put($path, $contents);
                $provider->providerVerificationDocuments()->create([
                    'uploaded_by' => $provider->id,
                    'document_type' => $documentType,
                    'original_name' => Str::slug($label).'.svg',
                    'path' => $path,
                    'mime_type' => 'image/svg+xml',
                    'size' => strlen($contents),
                    'status' => $record['status'] === 'rejected' ? 'rejected' : 'submitted',
                    'review_notes' => $record['status'] === 'rejected' ? $record['notes'] : null,
                    'uploaded_at' => now()->subWeek(),
                    'terms_accepted_at' => now()->subWeek(),
                    'terms_version' => Terms::VERSION,
                ]);
            }
        }
    }

    private function seedApplicant(User $admin, array $record, int $number): User
    {
        $email = Str::slug($record['first_name'].'.'.$record['last_name']).'@demo.test';
        $username = Str::slug($record['first_name'].$record['last_name'], '');
        $applicant = User::query()->create([
            'email' => $email,
            'username' => $username,
            'role' => 'applicant',
            'password' => env('DEMO_PASSWORD', self::PASSWORD),
            'account_status' => 'active',
            'must_reset_password' => false,
            'terms_accepted_at' => now()->subMonths(2),
            'privacy_accepted_at' => now()->subMonths(2),
            'terms_version' => Terms::VERSION,
        ]);
        $applicant->forceFill(['email_verified_at' => now()->subMonths(2)])->save();

        $photoSource = public_path('images/demo/default-applicant-avatar.jpg');
        if (! is_file($photoSource)) {
            throw new RuntimeException("Missing default demo applicant avatar: {$photoSource}");
        }
        $photoPath = sprintf('demo-showcase/profile-photos/%d/applicant-%02d.jpg', $applicant->id, $number);
        Storage::disk('local')->put($photoPath, file_get_contents($photoSource));
        $verificationPending = $record['scenario'] === 'screening_review';

        $applicant->studentProfile()->create([
            'first_name' => $record['first_name'],
            'last_name' => $record['last_name'],
            'middle_initial' => $record['middle_initial'],
            'gender' => $record['gender'],
            'contact_number' => sprintf('0917555%04d', $number),
            'account_managed_by' => 'learner',
            'citizenship_status' => 'filipino',
            'education_level' => 'senior_high_school',
            'school' => $record['school'],
            'school_type' => 'public',
            'learner_reference_number' => sprintf('20260000%04d', $number),
            'course_or_strand' => $record['strand'],
            'year_level' => $record['year_level'],
            'enrollment_status' => 'Enrolled',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => $record['gwa'],
            'grading_scale' => 'percentage',
            'academic_result_source' => 'verified_record',
            'academic_result_extracted_at' => now()->subMonths(2),
            'income_bracket' => 'PHP 10,000 - 20,000',
            'household_size' => $record['household_size'],
            'preferred_categories' => "Financial assistance\nAcademic support\nCommunity grant",
            'preferred_locations' => "Rizal\nMetro Manila",
            'willing_to_relocate' => 'depends',
            'support_needs' => $record['support_needs'],
            'current_scholarship_status' => 'none',
            'scholarship_goal' => $record['goal'],
            'achievements' => $record['achievements'],
            'activities_and_responsibilities' => $record['activities'],
            'address' => $record['address'],
            'barangay' => $record['barangay'],
            'city' => $record['city'],
            'province' => $record['province'],
            'region' => 'CALABARZON',
            'latitude' => $record['latitude'],
            'longitude' => $record['longitude'],
            'birthdate' => $record['birthdate'],
            'guardian_name' => $record['guardian'],
            'guardian_relationship' => 'Parent',
            'guardian_contact' => sprintf('0917666%04d', $number),
            'guardian_email' => 'guardian'.$number.'@demo.test',
            'guardian_is_account_owner' => false,
            'profile_photo_path' => $photoPath,
            'profile_photo_original_name' => 'default-applicant-avatar.jpg',
            'profile_photo_mime_type' => 'image/jpeg',
            'profile_photo_size' => filesize($photoSource),
            'profile_photo_updated_at' => now()->subMonths(2),
            'profile_photo_review_status' => $record['scenario'] === 'correction'
                ? 'needs_replacement'
                : ($verificationPending ? 'pending' : 'approved'),
            'profile_photo_review_note' => $record['scenario'] === 'correction'
                ? 'Upload a brighter image without glare before the review continues.'
                : null,
            'profile_photo_reviewed_by' => $verificationPending ? null : $admin->id,
            'profile_photo_reviewed_at' => $verificationPending ? null : now()->subMonth(),
            'verification_status' => $verificationPending ? 'pending' : 'approved',
            'verification_notes' => $verificationPending
                ? 'Academic evidence is ready for administrator review.'
                : 'Identity and academic profile verified for the local showcase.',
            'verified_at' => $verificationPending ? null : now()->subMonth(),
            'verified_by' => $verificationPending ? null : $admin->id,
        ]);

        foreach (['academic_record', 'recent_school_id', 'achievement_evidence'] as $documentType) {
            $label = Str::headline($documentType);
            $contents = $this->demoDocument($applicant->name, $label, 'Applicant profile evidence');
            $path = "demo-showcase/applicant-evidence/{$applicant->id}/{$documentType}.svg";
            Storage::disk('local')->put($path, $contents);
            ApplicantVerificationDocument::query()->create([
                'applicant_id' => $applicant->id,
                'uploaded_by' => $applicant->id,
                'document_type' => $documentType,
                'original_name' => Str::slug($label).'.svg',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'size' => strlen($contents),
                'status' => $verificationPending ? 'submitted' : 'approved',
                'review_notes' => $verificationPending ? null : 'Verified in the local showcase dataset.',
                'uploaded_at' => now()->subMonths(2),
                'terms_accepted_at' => now()->subMonths(2),
                'terms_version' => Terms::VERSION,
                'ocr_status' => $documentType === 'academic_record' ? 'completed' : 'not_requested',
                'ocr_provider' => $documentType === 'academic_record' ? 'demo_parser' : null,
                'ocr_grade' => $documentType === 'academic_record' ? $record['gwa'] : null,
                'ocr_grading_scale' => $documentType === 'academic_record' ? 'percentage' : null,
                'ocr_label' => $documentType === 'academic_record' ? 'General average' : null,
                'ocr_message' => $documentType === 'academic_record' ? 'Demo grade extracted successfully.' : null,
                'ocr_processed_at' => $documentType === 'academic_record' ? now()->subMonths(2) : null,
            ]);
        }

        return $applicant->fresh(['studentProfile', 'applicantVerificationDocuments']);
    }

    private function seedApplication(
        User $provider,
        Scholarship $scholarship,
        User $applicant,
        string $scenario,
        int $applicantNumber,
    ): ScholarshipApplication {
        $submittedAt = now()->subDays(25 - $applicantNumber);
        $eligibility = app(ScholarshipEligibilityService::class)->evaluate($scholarship, $applicant);
        $application = ScholarshipApplication::query()->create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'workflow_version' => 2,
            'application_state' => 'submitted',
            'workflow_stage' => 'screening',
            'document_checklist' => self::REQUIREMENTS,
            'optional_document_checklist' => ['Achievement certificate'],
            'eligibility_score' => $eligibility['score'],
            'eligibility_breakdown' => $eligibility,
            'review_rubric_snapshot' => ReviewRubric::DEFAULT,
            'application_answers' => [
                [
                    'question_id' => 'study_goal',
                    'prompt' => 'How will the scholarship help you continue your studies?',
                    'answer' => $applicant->studentProfile->scholarship_goal,
                ],
                [
                    'question_id' => 'community_goal',
                    'prompt' => 'What goal do you want to achieve this school year?',
                    'answer' => 'Complete the school year with strong attendance and prepare for college admission.',
                ],
            ],
            'notes' => 'Fictional application for the complete local system demonstration.',
            'submitted_at' => $submittedAt,
            'terms_accepted_at' => $submittedAt,
            'terms_version' => Terms::VERSION,
        ]);

        foreach (self::REQUIREMENTS as $requirement) {
            $contents = $this->demoDocument($applicant->name, $requirement, $scholarship->title);
            $path = 'demo-showcase/application-documents/'.$application->id.'/'.Str::slug($requirement).'.svg';
            Storage::disk('local')->put($path, $contents);
            $status = match (true) {
                $scenario === 'screening_review' => 'pending',
                $scenario === 'correction' && $requirement === 'Recent school ID' => 'needs_replacement',
                default => 'accepted',
            };
            $application->documents()->create([
                'uploaded_by' => $applicant->id,
                'document_name' => $requirement,
                'original_name' => Str::slug($requirement).'.svg',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'size' => strlen($contents),
                'status' => $status,
                'review_notes' => $status === 'accepted'
                    ? 'Readable and accepted for the local showcase.'
                    : ($status === 'needs_replacement' ? 'The uploaded school ID is no longer current.' : null),
                'reviewed_by' => $status === 'pending' ? null : $provider->id,
                'reviewed_at' => $status === 'pending' ? null : now()->subDays(8),
                'uploaded_at' => $submittedAt,
                'terms_accepted_at' => $submittedAt,
                'terms_version' => Terms::VERSION,
            ]);
        }

        ApplicationStatusHistory::query()->create([
            'scholarship_application_id' => $application->id,
            'changed_by' => $applicant->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'review_notes' => 'Application submitted by applicant.',
            'changed_at' => $submittedAt,
        ]);

        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application->fresh());

        if ($scenario === 'correction') {
            $application = $workflow->requestCorrection(
                $application,
                $provider,
                'Replace the recent school ID and upload a clearer profile photo before pre-screening continues.',
                ['profile', 'application_files'],
            );
        } elseif ($scenario !== 'screening_review') {
            $application = $this->advanceApplication($workflow, $application, $provider, $scenario);
        }

        if (! in_array($scenario, ['screening_review', 'correction'], true)) {
            $scores = collect(ReviewRubric::DEFAULT)->mapWithKeys(
                fn (array $criterion, int $index): array => [$criterion['key'] => 90 - $index],
            )->all();
            $result = ReviewRubric::result(ReviewRubric::DEFAULT, $scores);
            $application->update([
                'rubric_scores' => $scores,
                'rubric_total_score' => $result['total_score'],
                'rubric_scored_by' => $provider->id,
                'rubric_scored_at' => now()->subDays(6),
                'review_notes' => 'Eligibility, profile evidence, and required files were reviewed.',
                'reviewed_by' => $provider->id,
                'reviewed_at' => now()->subDays(6),
            ]);
        }

        $application = $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($application, 'complete_demo_seed');
        $this->seedApplicationNotification($application, $scenario);

        $photoStatus = $scenario === 'correction'
            ? 'needs_replacement'
            : ($scenario === 'screening_review' ? 'pending' : 'approved');
        $applicant->studentProfile->update([
            'profile_photo_review_status' => $photoStatus,
            'profile_photo_review_application_id' => $application->id,
            'profile_photo_reviewed_by' => $photoStatus === 'pending' ? null : $provider->id,
            'profile_photo_reviewed_at' => $photoStatus === 'pending' ? null : now()->subDays(6),
        ]);

        return $application->fresh(['applicant.studentProfile', 'scholarship', 'stageProgresses', 'schedules']);
    }

    private function advanceApplication(
        ApplicationWorkflowService $workflow,
        ScholarshipApplication $application,
        User $provider,
        string $scenario,
    ): ScholarshipApplication {
        if ($scenario === 'screening_rejected') {
            return $workflow->recordStageResult(
                $application,
                'screening',
                'not_passed',
                $provider,
                'The submitted profile does not meet the published residency requirement.',
                'location_not_eligible',
            );
        }

        $application = $workflow->recordStageResult(
            $application,
            'screening',
            'passed',
            $provider,
            'Published eligibility and submitted evidence were verified.',
        );
        if ($scenario === 'withdrawn') {
            return $workflow->withdraw(
                $application,
                $application->applicant,
                'The applicant chose another education-support opportunity before formal verification.',
            );
        }
        if ($scenario === 'formal_application') {
            return $application;
        }

        $application = $workflow->recordStageResult(
            $application,
            'formal_application',
            'passed',
            $provider,
            'Original records and formal provider requirements were verified.',
        );
        if ($scenario === 'exam_scheduled') {
            $this->seedSchedule($application, $provider, 'exam', false, false);

            return $application;
        }

        $this->seedSchedule($application, $provider, 'exam', true, false);
        if ($scenario === 'exam_failed') {
            return $workflow->recordStageResult(
                $application,
                'exam',
                'not_passed',
                $provider,
                'The recorded score was below the published passing score.',
                'exam_score_below_requirement',
            );
        }
        if ($scenario === 'exam_result') {
            return $application;
        }

        $application = $workflow->recordStageResult(
            $application,
            'exam',
            'passed',
            $provider,
            'The applicant passed the qualifying exam with a score above the published requirement.',
        );
        if ($scenario === 'interview_scheduled') {
            $this->seedSchedule($application, $provider, 'interview', false, true);

            return $application;
        }

        $this->seedSchedule($application, $provider, 'interview', true, true);
        if ($scenario === 'interview_failed') {
            return $workflow->recordStageResult(
                $application,
                'interview',
                'not_passed',
                $provider,
                'The interview evidence did not meet the published finalist standard.',
                'interview_standard_not_met',
            );
        }
        if ($scenario === 'interview_result') {
            return $application;
        }

        $application = $workflow->recordStageResult(
            $application,
            'interview',
            'passed',
            $provider,
            'The applicant completed the finalist interview and met the review standard.',
        );
        if ($scenario === 'final_decision') {
            return $application;
        }

        if ($scenario === 'not_selected') {
            return $workflow->recordFinalOutcome(
                $application,
                'not_selected',
                $provider,
                'The applicant qualified for final review but was not selected within the available award allocation.',
                'ranking_below_award_cutoff',
            );
        }

        if ($scenario === 'waitlisted') {
            return $workflow->recordFinalOutcome(
                $application,
                'waitlisted',
                $provider,
                'Qualified applicant placed on the waitlist while award slots are confirmed.',
                'funds_limited',
            );
        }

        $application = $workflow->recordFinalOutcome(
            $application,
            'selected',
            $provider,
            'Selected for the Complete Path Scholarship after the full provider review.',
            'approved_for_award',
        );
        $application->update(['awarded_amount' => 18000]);

        if ($scenario === 'agreement_declined') {
            $respondedAt = now()->subDays(12);
            $application->update([
                'student_response_status' => 'declined',
                'student_responded_at' => $respondedAt,
                'student_response_note' => 'I cannot commit to the required activities during this school year.',
                'student_response_terms_accepted_at' => null,
                'student_response_terms_version' => null,
            ]);
            ApplicationStatusHistory::query()->create([
                'scholarship_application_id' => $application->id,
                'changed_by' => $application->applicant_id,
                'from_status' => $application->status,
                'to_status' => 'agreement_declined',
                'review_notes' => 'Applicant declined the recipient agreement.',
                'changed_at' => $respondedAt,
            ]);

            return $application->fresh();
        }

        $acceptedScenarios = [
            'active_monitoring',
            'completed_support',
            'monitoring_adjustment',
            'monitoring_overdue',
            'benefit_issue',
            'support_terminated',
            'renewed_support',
        ];
        if (in_array($scenario, $acceptedScenarios, true)) {
            $acceptedAt = now()->subDays($scenario === 'completed_support' ? 35 : 28);
            $application->update([
                'student_response_status' => 'accepted',
                'student_responded_at' => $acceptedAt,
                'student_response_terms_accepted_at' => $acceptedAt,
                'student_response_terms_version' => $application->provider_contract_terms_version,
                'provider_contract_terms_accepted_at' => $acceptedAt,
                'provider_contract_acceptance_ip' => '127.0.0.1',
                'provider_contract_acceptance_user_agent' => 'Complete local demo seeder',
            ]);
            ApplicationStatusHistory::query()->create([
                'scholarship_application_id' => $application->id,
                'changed_by' => $application->applicant_id,
                'from_status' => $application->status,
                'to_status' => 'agreement_accepted',
                'review_notes' => 'Applicant accepted the recipient agreement.',
                'changed_at' => $acceptedAt,
            ]);
        }

        return $application->fresh();
    }

    private function seedSchedule(
        ScholarshipApplication $application,
        User $provider,
        string $type,
        bool $completed,
        bool $online,
    ): void {
        $scheduledAt = $completed
            ? now()->subDays($type === 'exam' ? 10 : 5)->setTime($type === 'exam' ? 9 : 14, 0)
            : now()->addDays($type === 'exam' ? 6 : 10)->setTime($type === 'exam' ? 9 : 14, 0);
        $application->schedules()->create([
            'type' => $type,
            'title' => $type === 'exam' ? 'Complete Path Qualifying Exam' : 'Finalist Online Interview',
            'scheduled_at' => $scheduledAt,
            'mode' => $online ? 'online' : 'onsite',
            'venue' => $online ? null : 'Tulay Aral Community Learning Hub',
            'location_address' => $online ? null : 'Barangay San Isidro, Antipolo City, Rizal',
            'online_url' => $online ? 'https://meet.google.com/demo-tulay-aral-room' : null,
            'instructions' => $type === 'exam'
                ? 'Bring a recent school ID and arrive twenty minutes early.'
                : 'Join ten minutes early and keep your school ID ready.',
            'status' => $completed ? 'completed' : 'scheduled',
            'attendance_status' => $completed ? 'attended' : 'pending',
            'attendance_notes' => $completed ? ucfirst($type).' attendance confirmed.' : null,
            'applicant_acknowledged_at' => $completed ? $scheduledAt->copy()->subDay() : null,
            'completed_at' => $completed ? $scheduledAt->copy()->addMinutes($type === 'exam' ? 90 : 30) : null,
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);
    }

    private function seedRecipientMonitoring(
        User $provider,
        Scholarship $scholarship,
        Collection $applications,
    ): void {
        $activeApplication = $applications->get('active_monitoring');
        $completedApplication = $applications->get('completed_support');
        $adjustmentApplication = $applications->get('monitoring_adjustment');
        $overdueApplication = $applications->get('monitoring_overdue');
        $benefitIssueApplication = $applications->get('benefit_issue');
        $terminatedApplication = $applications->get('support_terminated');
        $renewedApplication = $applications->get('renewed_support');

        $plan = RecipientMonitoringPlan::query()->create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
            'frequency' => 'semester',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(8)->toDateString(),
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'instructions' => 'Submit clear current records for each check-in. Contact the provider before the deadline if circumstances affect submission.',
            'status' => 'active',
            'version' => 1,
            'activated_at' => now()->subMonth(),
        ]);
        $requirements = collect([
            [
                'type' => 'academic_progress',
                'title' => 'Academic progress update',
                'description' => 'Confirm the current semester result.',
                'evidence_description' => 'Latest report card, grade report, or certified transcript.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => true,
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
                'sort_order' => 1,
                'active' => true,
            ],
            [
                'type' => 'enrollment',
                'title' => 'Proof of current enrollment',
                'description' => 'Confirm that the recipient remains enrolled.',
                'evidence_description' => 'Current enrollment certificate or registration form.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => false,
                'sort_order' => 2,
                'active' => true,
            ],
            [
                'type' => 'program_participation',
                'title' => 'Recipient orientation attendance',
                'description' => 'Provider-recorded attendance for the required orientation.',
                'evidence_description' => 'Provider attendance record.',
                'required' => true,
                'requires_file' => false,
                'requires_original_verification' => false,
                'sort_order' => 3,
                'active' => true,
            ],
        ])->map(fn (array $data) => $plan->requirements()->create($data));

        $cycle = RecipientMonitoringCycle::query()->create([
            'scholarship_id' => $scholarship->id,
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 1,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'created_by' => $provider->id,
            'title' => 'First semester progress check',
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'opens_at' => now()->subDays(14)->toDateString(),
            'due_at' => now()->addDays(14)->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'instructions' => $plan->instructions,
            'status' => 'open',
            'published_at' => now()->subDays(14),
        ]);
        $cycleRequirements = $requirements->map(fn ($requirement) => $cycle->requirements()->create([
            'source_requirement_id' => $requirement->id,
            'type' => $requirement->type,
            'title' => $requirement->title,
            'description' => $requirement->description,
            'evidence_description' => $requirement->evidence_description,
            'required' => $requirement->required,
            'requires_file' => $requirement->requires_file,
            'requires_original_verification' => $requirement->requires_original_verification,
            'minimum_grade' => $requirement->minimum_grade,
            'grading_scale' => $requirement->grading_scale,
            'sort_order' => $requirement->sort_order,
        ]));

        $activeAcademic = $this->seedMonitoringSubmission(
            $cycle,
            $cycleRequirements[0],
            $activeApplication,
            'pending',
            88.50,
            true,
            $provider,
        );
        $this->seedMonitoringSubmission(
            $cycle,
            $cycleRequirements[2],
            $activeApplication,
            'approved',
            null,
            false,
            $provider,
        );
        $activeAcademic->update(['review_notes' => 'Ready for provider review in the monitoring queue.']);

        foreach ($cycleRequirements as $requirement) {
            $grade = $requirement->type === 'academic_progress' ? 92.25 : null;
            $submission = $this->seedMonitoringSubmission(
                $cycle,
                $requirement,
                $completedApplication,
                'approved',
                $grade,
                (bool) $requirement->requires_file,
                $provider,
            );
            RecipientMonitoringReview::query()->create([
                'recipient_monitoring_submission_id' => $submission->id,
                'reviewed_by' => $provider->id,
                'decision' => 'approved',
                'notes' => 'Requirement confirmed for the completed-support demonstration.',
                'decided_at' => now()->subDays(4),
            ]);
        }

        $adjustmentAcademic = $this->seedMonitoringSubmission(
            $cycle,
            $cycleRequirements[0],
            $adjustmentApplication,
            'met',
            90.10,
            true,
            $provider,
        );
        $this->seedMonitoringReview($adjustmentAcademic, $provider, 'met', 'The current grade record meets the requirement.');
        $adjustmentParticipation = $this->seedMonitoringSubmission(
            $cycle,
            $cycleRequirements[2],
            $adjustmentApplication,
            'excused',
            null,
            false,
            $provider,
        );
        $this->seedMonitoringReview($adjustmentParticipation, $provider, 'excused', 'A documented family emergency was accepted for this activity.');
        RecipientMonitoringAdjustmentRequest::query()->create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $cycleRequirements[1]->id,
            'scholarship_application_id' => $adjustmentApplication->id,
            'applicant_id' => $adjustmentApplication->applicant_id,
            'request_type' => 'extension',
            'reason_category' => 'school_record_delay',
            'explanation' => 'The registrar needs additional processing time to issue the current enrollment certificate.',
            'requested_due_at' => now()->addDays(21)->toDateString(),
            'status' => 'pending',
        ]);
        RecipientMonitoringAdjustmentRequest::query()->create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $cycleRequirements[2]->id,
            'scholarship_application_id' => $adjustmentApplication->id,
            'applicant_id' => $adjustmentApplication->applicant_id,
            'request_type' => 'exception',
            'reason_category' => 'family_emergency',
            'explanation' => 'A documented family emergency prevented attendance at the recipient orientation.',
            'status' => 'approved',
            'decision_notes' => 'The exception is approved for this monitoring period.',
            'decided_by' => $provider->id,
            'decided_at' => now()->subDays(2),
        ]);

        foreach ([$benefitIssueApplication, $renewedApplication] as $application) {
            foreach ($cycleRequirements as $requirement) {
                $submission = $this->seedMonitoringSubmission(
                    $cycle,
                    $requirement,
                    $application,
                    'met',
                    $requirement->type === 'academic_progress' ? 91.40 : null,
                    (bool) $requirement->requires_file,
                    $provider,
                );
                $this->seedMonitoringReview($submission, $provider, 'met', 'Requirement confirmed for the recipient showcase.');
            }
        }

        foreach ($cycleRequirements as $requirement) {
            $decision = $requirement->type === 'academic_progress' ? 'not_met' : 'met';
            $submission = $this->seedMonitoringSubmission(
                $cycle,
                $requirement,
                $terminatedApplication,
                $decision,
                $requirement->type === 'academic_progress' ? 78.25 : null,
                (bool) $requirement->requires_file,
                $provider,
            );
            $this->seedMonitoringReview(
                $submission,
                $provider,
                $decision,
                $decision === 'not_met'
                    ? 'The reported average is below the continuing requirement.'
                    : 'Requirement confirmed.',
            );
        }

        $overdueCycle = RecipientMonitoringCycle::query()->create([
            'scholarship_id' => $scholarship->id,
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 1,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'created_by' => $provider->id,
            'title' => 'Overdue document follow-up',
            'period_type' => 'quarter',
            'academic_period' => 'First quarter',
            'school_year' => '2026-2027',
            'opens_at' => now()->subDays(45)->toDateString(),
            'due_at' => now()->subDays(10)->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'instructions' => 'Replace unclear records and complete each missing item.',
            'status' => 'open',
            'published_at' => now()->subDays(45),
        ]);
        $overdueRequirements = $requirements->map(fn ($requirement) => $overdueCycle->requirements()->create([
            'source_requirement_id' => $requirement->id,
            'type' => $requirement->type,
            'title' => $requirement->title,
            'description' => $requirement->description,
            'evidence_description' => $requirement->evidence_description,
            'required' => $requirement->required,
            'requires_file' => $requirement->requires_file,
            'requires_original_verification' => $requirement->requires_original_verification,
            'minimum_grade' => $requirement->minimum_grade,
            'grading_scale' => $requirement->grading_scale,
            'sort_order' => $requirement->sort_order,
        ]));
        $overdueAcademic = $this->seedMonitoringSubmission(
            $overdueCycle,
            $overdueRequirements[0],
            $overdueApplication,
            'needs_correction',
            82.10,
            true,
            $provider,
        );
        $this->seedMonitoringReview(
            $overdueAcademic,
            $provider,
            'needs_correction',
            'Upload a complete report card showing the school name and grading period.',
        );
        $overdueParticipation = $this->seedMonitoringSubmission(
            $overdueCycle,
            $overdueRequirements[2],
            $overdueApplication,
            'met',
            null,
            false,
            $provider,
        );
        $this->seedMonitoringReview($overdueParticipation, $provider, 'met', 'Attendance confirmed by the provider.');
        RecipientMonitoringIntervention::query()->create([
            'recipient_monitoring_cycle_id' => $overdueCycle->id,
            'recipient_monitoring_cycle_requirement_id' => $overdueRequirements[0]->id,
            'scholarship_application_id' => $overdueApplication->id,
            'applicant_id' => $overdueApplication->applicant_id,
            'created_by' => $provider->id,
            'type' => 'document_follow_up',
            'summary' => 'The grade record needs replacement and the enrollment record remains overdue.',
            'action_required' => 'Contact the recipient and agree on a final submission date.',
            'follow_up_on' => now()->addDays(3)->toDateString(),
            'status' => 'open',
        ]);

        $initialRelease = RecipientBenefitRelease::query()->create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester education allowance',
            'release_at' => now()->subDays(7)->setTime(10, 0),
            'benefit_description' => 'First education allowance and learning materials kit.',
            'amount' => 9000,
            'release_method' => 'bank_transfer',
            'instructions' => 'Confirm receipt in the portal after checking the amount received.',
            'requires_original_verification' => true,
            'status' => 'completed',
            'published_at' => now()->subDays(20),
        ]);
        foreach ([$activeApplication, $completedApplication, $benefitIssueApplication] as $application) {
            $receipt = $this->demoDocument($application->applicant->name, 'Benefit release acknowledgement', $scholarship->title);
            $receiptPath = "demo-showcase/benefit-receipts/{$application->id}/first-release.svg";
            Storage::disk('local')->put($receiptPath, $receipt);
            $record = $initialRelease->records()->create([
                'scholarship_application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'status' => 'released',
                'originals_verified' => true,
                'notes' => 'Transfer confirmed against the provider release register.',
                'receipt_original_name' => 'benefit-release-acknowledgement.svg',
                'receipt_path' => $receiptPath,
                'receipt_mime_type' => 'image/svg+xml',
                'receipt_size' => strlen($receipt),
                'recorded_by' => $provider->id,
                'recorded_at' => now()->subDays(7),
                'released_at' => now()->subDays(7),
            ]);
            if ($application->is($benefitIssueApplication)) {
                $evidence = $this->demoDocument($application->applicant->name, 'Incomplete benefit evidence', $initialRelease->title);
                $evidencePath = "demo-showcase/benefit-receipts/{$application->id}/issue-evidence.svg";
                Storage::disk('local')->put($evidencePath, $evidence);
                RecipientBenefitReceiptResponse::query()->create([
                    'recipient_benefit_release_record_id' => $record->id,
                    'scholarship_application_id' => $application->id,
                    'applicant_id' => $application->applicant_id,
                    'response_type' => 'issue',
                    'received_on' => now()->subDays(7)->toDateString(),
                    'recipient_note' => 'The transfer arrived, but the learning materials kit was incomplete.',
                    'issue_type' => 'incomplete_benefit',
                    'issue_details' => 'The listed reference books were not included in the released package.',
                    'evidence_original_name' => 'incomplete-benefit-evidence.svg',
                    'evidence_path' => $evidencePath,
                    'evidence_mime_type' => 'image/svg+xml',
                    'evidence_size' => strlen($evidence),
                    'status' => 'open',
                    'responded_at' => now()->subDays(6),
                ]);
            } else {
                RecipientBenefitReceiptResponse::query()->create([
                    'recipient_benefit_release_record_id' => $record->id,
                    'scholarship_application_id' => $application->id,
                    'applicant_id' => $application->applicant_id,
                    'response_type' => 'confirmed',
                    'received_on' => now()->subDays(7)->toDateString(),
                    'recipient_note' => 'I received the full education allowance.',
                    'status' => 'confirmed',
                    'responded_at' => now()->subDays(6),
                ]);
            }
        }

        $nextRelease = RecipientBenefitRelease::query()->create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'Second semester education allowance',
            'release_at' => now()->addDays(30)->setTime(10, 0),
            'benefit_description' => 'Second education allowance for the continuing recipient.',
            'amount' => 9000,
            'release_method' => 'bank_transfer',
            'instructions' => 'The provider will confirm eligibility after the current check-in is reviewed.',
            'requires_original_verification' => false,
            'status' => 'scheduled',
            'published_at' => now()->subDay(),
        ]);
        $nextRelease->records()->create([
            'scholarship_application_id' => $activeApplication->id,
            'applicant_id' => $activeApplication->applicant_id,
            'status' => 'scheduled',
            'originals_verified' => false,
        ]);

        $withheldRelease = RecipientBenefitRelease::query()->create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'Academic standing release review',
            'release_at' => now()->subDays(3)->setTime(10, 0),
            'benefit_description' => 'Continuing allowance subject to the published academic requirement.',
            'amount' => 4500,
            'release_method' => 'bank_transfer',
            'instructions' => 'The provider records a reason whenever a scheduled release is withheld.',
            'requires_original_verification' => false,
            'status' => 'completed',
            'published_at' => now()->subDays(12),
        ]);
        $withheldRelease->records()->create([
            'scholarship_application_id' => $terminatedApplication->id,
            'applicant_id' => $terminatedApplication->applicant_id,
            'status' => 'withheld',
            'originals_verified' => false,
            'notes' => 'Release withheld after the academic requirement remained unmet following documented follow-up.',
            'recorded_by' => $provider->id,
            'recorded_at' => now()->subDays(3),
        ]);

        RecipientSupportDecision::query()->create([
            'scholarship_application_id' => $completedApplication->id,
            'applicant_id' => $completedApplication->applicant_id,
            'decision' => 'completed',
            'reason_category' => 'program_completed',
            'effective_on' => now()->subDay()->toDateString(),
            'reason' => 'The recipient completed the agreed support period, submitted all required records, and received all scheduled benefits.',
            'decided_by' => $provider->id,
            'decided_at' => now()->subDay(),
        ]);

        $renewedAt = now()->subDays(2);
        RecipientSupportDecision::query()->create([
            'scholarship_application_id' => $renewedApplication->id,
            'applicant_id' => $renewedApplication->applicant_id,
            'decision' => 'renewed',
            'reason_category' => 'requirements_met',
            'effective_on' => $renewedAt->toDateString(),
            'support_ends_on' => now()->addMonths(8)->toDateString(),
            'next_review_on' => now()->addMonths(3)->toDateString(),
            'next_period_terms' => 'Continue enrollment and submit the next published academic progress check.',
            'decided_by' => $provider->id,
            'decided_at' => $renewedAt,
        ]);
        $renewedPreviousStatus = $renewedApplication->status;
        $renewedApplication->update([
            'status' => 'renewed',
            'reviewed_by' => $provider->id,
            'reviewed_at' => $renewedAt,
        ]);
        ApplicationStatusHistory::query()->create([
            'scholarship_application_id' => $renewedApplication->id,
            'changed_by' => $provider->id,
            'from_status' => $renewedPreviousStatus,
            'to_status' => 'renewed',
            'decision_reason' => 'requirements_met',
            'review_notes' => 'Recipient support renewed after all current monitoring requirements were confirmed.',
            'changed_at' => $renewedAt,
        ]);

        $terminationDocument = $this->demoDocument(
            $terminatedApplication->applicant->name,
            'Early support ending notice',
            $scholarship->title,
        );
        $terminationPath = "demo-showcase/support-decisions/{$terminatedApplication->id}/termination-notice.svg";
        Storage::disk('local')->put($terminationPath, $terminationDocument);
        $terminatedAt = now()->subDays(2);
        RecipientSupportDecision::query()->create([
            'scholarship_application_id' => $terminatedApplication->id,
            'applicant_id' => $terminatedApplication->applicant_id,
            'decision' => 'terminated',
            'reason_category' => 'requirement_not_met',
            'effective_on' => $terminatedAt->toDateString(),
            'notice_given_on' => now()->subDays(5)->toDateString(),
            'reason' => 'Support ended early after the academic requirement remained unmet and follow-up was documented.',
            'decision_document_original_name' => 'early-support-ending-notice.svg',
            'decision_document_path' => $terminationPath,
            'decision_document_mime_type' => 'image/svg+xml',
            'decision_document_size' => strlen($terminationDocument),
            'decided_by' => $provider->id,
            'decided_at' => $terminatedAt,
            'applicant_response_type' => 'reconsideration_requested',
            'applicant_response_message' => 'Please review my updated school record and current circumstances before the decision is finalized.',
            'applicant_responded_at' => now()->subDay(),
            'response_status' => 'open',
        ]);
        $terminatedPreviousStatus = $terminatedApplication->status;
        $terminatedApplication->update([
            'status' => 'benefits_terminated',
            'outcome_notes' => 'Support ended early with an open reconsideration request.',
            'outcome_at' => $terminatedAt,
            'reviewed_by' => $provider->id,
            'reviewed_at' => $terminatedAt,
        ]);
        ApplicationStatusHistory::query()->create([
            'scholarship_application_id' => $terminatedApplication->id,
            'changed_by' => $provider->id,
            'from_status' => $terminatedPreviousStatus,
            'to_status' => 'benefits_terminated',
            'decision_reason' => 'requirement_not_met',
            'review_notes' => 'Support ended early after documented monitoring follow-up.',
            'changed_at' => $terminatedAt,
        ]);
        RecipientMonitoringIntervention::query()->create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $cycleRequirements[0]->id,
            'scholarship_application_id' => $terminatedApplication->id,
            'applicant_id' => $terminatedApplication->applicant_id,
            'created_by' => $provider->id,
            'type' => 'academic_support',
            'summary' => 'Academic standing remained below the continuing requirement after follow-up.',
            'action_required' => 'Review the recipient reconsideration evidence and record a resolution.',
            'follow_up_on' => now()->addDays(2)->toDateString(),
            'status' => 'open',
        ]);

        foreach ([
            $activeApplication,
            $completedApplication,
            $adjustmentApplication,
            $overdueApplication,
            $benefitIssueApplication,
            $terminatedApplication,
            $renewedApplication,
        ] as $application) {
            PortalNotification::query()->create([
                'user_id' => $application->applicant_id,
                'type' => 'recipient_monitoring_request',
                'title' => 'First semester progress check',
                'message' => 'Open recipient monitoring to review the current check-in and benefit records.',
                'action_url' => route('dashboard.monitoring.show', $application, false),
                'deduplication_key' => "complete-demo-monitoring-{$application->id}",
                'read_at' => $application->is($completedApplication) ? now()->subDays(3) : null,
            ]);
        }
    }

    private function seedMonitoringSubmission(
        RecipientMonitoringCycle $cycle,
        $requirement,
        ScholarshipApplication $application,
        string $status,
        ?float $grade,
        bool $withFile,
        User $provider,
    ): RecipientMonitoringSubmission {
        $reviewed = $status !== 'pending';
        $contents = null;
        $path = null;
        if ($withFile) {
            $contents = $this->demoDocument(
                $application->applicant->name,
                $requirement->title,
                $cycle->title,
            );
            $path = "demo-showcase/monitoring/{$cycle->id}/{$application->id}/{$requirement->id}.svg";
            Storage::disk('local')->put($path, $contents);
        }

        return RecipientMonitoringSubmission::query()->create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $requirement->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'applicant_note' => $requirement->type === 'academic_progress'
                ? 'This is my current first semester grade record.'
                : null,
            'submission_source' => $withFile ? 'applicant_upload' : 'provider_record',
            'original_name' => $withFile ? Str::slug($requirement->title).'.svg' : null,
            'path' => $path,
            'mime_type' => $withFile ? 'image/svg+xml' : null,
            'size' => $contents === null ? 0 : strlen($contents),
            'ocr_status' => $grade === null ? 'not_requested' : 'completed',
            'ocr_provider' => $grade === null ? null : 'demo_parser',
            'ocr_grade' => $grade,
            'ocr_grading_scale' => $grade === null ? null : 'percentage',
            'ocr_label' => $grade === null ? null : 'General average',
            'ocr_message' => $grade === null ? null : 'Demo grade extracted successfully.',
            'ocr_processed_at' => $grade === null ? null : now()->subDays(5),
            'reported_grade' => $grade,
            'reported_grading_scale' => $grade === null ? null : 'percentage',
            'grade_source' => $grade === null ? null : 'ocr_confirmed',
            'submitted_at' => now()->subDays(5),
            'review_status' => $status,
            'review_notes' => match ($status) {
                'approved', 'met' => 'Requirement confirmed.',
                'excused' => 'Requirement completed through an approved exception.',
                'not_met' => 'The submitted record does not meet the continuing requirement.',
                'needs_correction' => 'A replacement record is required.',
                default => null,
            },
            'reviewed_by' => $reviewed ? $provider->id : null,
            'reviewed_at' => $reviewed ? now()->subDays(4) : null,
        ]);
    }

    private function seedMonitoringReview(
        RecipientMonitoringSubmission $submission,
        User $provider,
        string $decision,
        string $notes,
    ): void {
        RecipientMonitoringReview::query()->create([
            'recipient_monitoring_submission_id' => $submission->id,
            'reviewed_by' => $provider->id,
            'decision' => $decision,
            'notes' => $notes,
            'decided_at' => $submission->reviewed_at ?? now()->subDays(4),
        ]);
        $submission->update([
            'review_notes' => $notes,
            'reviewed_by' => $provider->id,
            'reviewed_at' => $submission->reviewed_at ?? now()->subDays(4),
        ]);
    }

    private function seedSupportReports(
        User $admin,
        User $provider,
        Scholarship $scholarship,
        ScholarshipApplication $correctionApplication,
        ScholarshipApplication $completedApplication,
    ): void {
        SupportReport::query()->create([
            'applicant_id' => $correctionApplication->applicant_id,
            'scholarship_id' => $scholarship->id,
            'provider_id' => $provider->id,
            'assigned_role' => 'provider',
            'category' => 'program',
            'subject' => 'Question about replacing my school ID',
            'description' => 'I requested a new school ID and want to confirm whether the temporary enrollment slip is acceptable.',
            'context' => 'Application correction request',
            'status' => 'open',
            'provider_status' => 'open',
            'admin_status' => 'open',
        ]);
        SupportReport::query()->create([
            'applicant_id' => $completedApplication->applicant_id,
            'scholarship_id' => $scholarship->id,
            'provider_id' => $provider->id,
            'assigned_role' => 'admin',
            'category' => 'technical',
            'subject' => 'Benefit receipt page did not refresh',
            'description' => 'The confirmation was saved but the page needed one refresh before the new status appeared.',
            'context' => 'Recipient monitoring benefit receipt',
            'status' => 'resolved',
            'provider_status' => 'not_required',
            'admin_status' => 'resolved',
            'admin_resolved_by' => $admin->id,
            'admin_resolved_at' => now()->subDays(2),
            'resolved_by' => $admin->id,
            'resolved_at' => now()->subDays(2),
        ]);
    }

    private function seedApplicationNotification(ScholarshipApplication $application, string $scenario): void
    {
        [$title, $message] = match ($scenario) {
            'screening_review' => ['Application received', 'Your application is waiting for provider pre-screening.'],
            'correction' => ['Application update required', 'Replace the requested file and profile photo to continue.'],
            'screening_rejected' => ['Application did not pass pre-screening', 'Review the provider reason recorded in your application.'],
            'withdrawn' => ['Application withdrawn', 'This application was closed after your withdrawal request.'],
            'formal_application' => ['Continue with formal verification', 'Review the provider handoff instructions and required originals.'],
            'exam_scheduled' => ['Qualifying exam scheduled', 'Open your application schedule for the date and venue.'],
            'exam_result' => ['Exam completed', 'Your exam is complete and the provider is recording the result.'],
            'exam_failed' => ['Exam result recorded', 'Review the recorded qualifying-exam outcome.'],
            'interview_scheduled' => ['Finalist interview scheduled', 'Open your online interview schedule and meeting link.'],
            'interview_result' => ['Interview completed', 'Your interview is complete and the provider is recording the result.'],
            'interview_failed' => ['Interview result recorded', 'Review the recorded finalist-interview outcome.'],
            'final_decision' => ['Final review in progress', 'All selection activities are complete. Wait for the final provider decision.'],
            'not_selected' => ['Final decision recorded', 'Open your application to review the provider decision.'],
            'waitlisted' => ['Application waitlisted', 'You remain qualified while the provider confirms available slots.'],
            'selected_agreement' => ['You were selected', 'Review and respond to the recipient agreement before support begins.'],
            'agreement_declined' => ['Recipient agreement declined', 'Your response is recorded for provider follow-up.'],
            'active_monitoring' => ['Recipient support active', 'Open monitoring to review your current check-in and benefit schedule.'],
            'completed_support' => ['Support period completed', 'Your scholarship support history and completed records remain available.'],
            'monitoring_adjustment' => ['Monitoring request submitted', 'Your extension request is waiting for provider review.'],
            'monitoring_overdue' => ['Monitoring action required', 'Replace the requested record and complete the overdue item.'],
            'benefit_issue' => ['Benefit issue reported', 'Your benefit receipt issue is waiting for provider resolution.'],
            'support_terminated' => ['Support ended early', 'Review the decision and your open reconsideration request.'],
            'renewed_support' => ['Scholarship support renewed', 'Review the next period and upcoming monitoring terms.'],
            'capacity_award' => ['You were selected', 'This award has now reached its available recipient capacity.'],
            default => ['Application updated', 'Open your application to review the latest status.'],
        };
        PortalNotification::query()->create([
            'user_id' => $application->applicant_id,
            'type' => str_contains($scenario, 'selected') || str_contains($scenario, 'support')
                ? 'application_outcome'
                : 'application_status',
            'title' => $title,
            'message' => $message,
            'action_url' => route('dashboard.applications.show', $application, false),
            'deduplication_key' => "complete-demo-application-{$application->id}",
            'read_at' => in_array($scenario, ['screening_review', 'correction', 'exam_scheduled', 'interview_scheduled', 'selected_agreement'], true)
                ? null
                : now()->subDay(),
        ]);
    }

    private function applicantRecords(): array
    {
        $shared = [
            'province' => 'Rizal',
            'latitude' => 14.6255000,
            'longitude' => 121.1245000,
            'household_size' => 5,
            'support_needs' => 'Transportation, school materials, connectivity, and college preparation support.',
        ];

        return [
            [...$shared, 'scenario' => 'screening_review', 'first_name' => 'Alyssa Mae', 'last_name' => 'Reyes', 'middle_initial' => 'C', 'gender' => 'female', 'school' => 'Antipolo City Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 91.20, 'birthdate' => '2008-03-14', 'barangay' => 'San Isidro', 'city' => 'Antipolo City', 'address' => 'Barangay San Isidro, Antipolo City, Rizal', 'guardian' => 'Lorna Reyes', 'goal' => 'Complete senior high school and prepare for an engineering degree.', 'achievements' => 'Honor student and regional science quiz participant.', 'activities' => 'Science club member and weekend family store assistant.'],
            [...$shared, 'scenario' => 'correction', 'first_name' => 'Joshua Miguel', 'last_name' => 'Santos', 'middle_initial' => 'P', 'gender' => 'male', 'school' => 'Sumulong Memorial Senior High School', 'strand' => 'ABM', 'year_level' => 'Grade 12', 'gwa' => 88.75, 'birthdate' => '2008-05-22', 'barangay' => 'Mayamot', 'city' => 'Antipolo City', 'address' => 'Barangay Mayamot, Antipolo City, Rizal', 'guardian' => 'Rogelio Santos', 'goal' => 'Finish Grade 12 and pursue accountancy.', 'achievements' => 'Class treasurer and school business-plan finalist.', 'activities' => 'Student council volunteer and helps manage household expenses.'],
            [...$shared, 'scenario' => 'formal_application', 'first_name' => 'Bianca Louise', 'last_name' => 'Navarro', 'middle_initial' => 'D', 'gender' => 'female', 'school' => 'Antipolo National High School', 'strand' => 'HUMSS', 'year_level' => 'Grade 12', 'gwa' => 93.10, 'birthdate' => '2008-07-09', 'barangay' => 'Dela Paz', 'city' => 'Antipolo City', 'address' => 'Barangay Dela Paz, Antipolo City, Rizal', 'guardian' => 'Elena Navarro', 'goal' => 'Prepare for a college program in communication.', 'achievements' => 'Campus journalism awardee and consistent honor student.', 'activities' => 'School paper writer and youth reading volunteer.'],
            [...$shared, 'scenario' => 'exam_scheduled', 'first_name' => 'Mark Angelo', 'last_name' => 'Dela Cruz', 'middle_initial' => 'R', 'gender' => 'male', 'school' => 'Mambugan Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 11', 'gwa' => 89.40, 'birthdate' => '2009-01-18', 'barangay' => 'Mambugan', 'city' => 'Antipolo City', 'address' => 'Barangay Mambugan, Antipolo City, Rizal', 'guardian' => 'Liza Dela Cruz', 'goal' => 'Build a strong foundation for computer science.', 'achievements' => 'Robotics team member and mathematics contest qualifier.', 'activities' => 'Computer club member and assists younger siblings with schoolwork.'],
            [...$shared, 'scenario' => 'exam_result', 'first_name' => 'Carlo Javier', 'last_name' => 'Mendoza', 'middle_initial' => 'S', 'gender' => 'male', 'school' => 'Antipolo City Senior High School', 'strand' => 'TVL-ICT', 'year_level' => 'Grade 12', 'gwa' => 87.90, 'birthdate' => '2008-10-02', 'barangay' => 'Cupang', 'city' => 'Antipolo City', 'address' => 'Barangay Cupang, Antipolo City, Rizal', 'guardian' => 'Marites Mendoza', 'goal' => 'Earn an information technology certification and enter college.', 'achievements' => 'School web-design finalist and ICT merit awardee.', 'activities' => 'Peer computer tutor and community event volunteer.'],
            [...$shared, 'scenario' => 'interview_scheduled', 'first_name' => 'Sofia Marie', 'last_name' => 'Villanueva', 'middle_initial' => 'A', 'gender' => 'female', 'school' => 'San Roque Senior High School', 'strand' => 'GAS', 'year_level' => 'Grade 12', 'gwa' => 90.60, 'birthdate' => '2008-02-27', 'barangay' => 'San Roque', 'city' => 'Antipolo City', 'address' => 'Barangay San Roque, Antipolo City, Rizal', 'guardian' => 'Noemi Villanueva', 'goal' => 'Continue to college and study education.', 'achievements' => 'Reading program volunteer and honor student.', 'activities' => 'Youth choir member and primary-school reading tutor.'],
            [...$shared, 'scenario' => 'interview_result', 'first_name' => 'Gabriel Luis', 'last_name' => 'Ramos', 'middle_initial' => 'T', 'gender' => 'male', 'school' => 'Cogeo Village Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 92.35, 'birthdate' => '2008-06-11', 'barangay' => 'Bagong Nayon', 'city' => 'Antipolo City', 'address' => 'Barangay Bagong Nayon, Antipolo City, Rizal', 'guardian' => 'Rosario Ramos', 'goal' => 'Qualify for an electronics engineering program.', 'achievements' => 'Research fair finalist and physics quiz team member.', 'activities' => 'Research club officer and neighborhood tutorial volunteer.'],
            [...$shared, 'scenario' => 'final_decision', 'first_name' => 'Trisha Anne', 'last_name' => 'Garcia', 'middle_initial' => 'M', 'gender' => 'female', 'school' => 'Antipolo National High School', 'strand' => 'ABM', 'year_level' => 'Grade 12', 'gwa' => 89.95, 'birthdate' => '2008-08-30', 'barangay' => 'Dalig', 'city' => 'Antipolo City', 'address' => 'Barangay Dalig, Antipolo City, Rizal', 'guardian' => 'Carmela Garcia', 'goal' => 'Enter college and develop community business skills.', 'achievements' => 'Entrepreneurship pitch winner and honor student.', 'activities' => 'School cooperative volunteer and household caregiver.'],
            [...$shared, 'scenario' => 'waitlisted', 'first_name' => 'Camille Rose', 'last_name' => 'Flores', 'middle_initial' => 'L', 'gender' => 'female', 'school' => 'Sumulong Memorial Senior High School', 'strand' => 'HUMSS', 'year_level' => 'Grade 12', 'gwa' => 88.20, 'birthdate' => '2008-12-06', 'barangay' => 'Muntingdilaw', 'city' => 'Antipolo City', 'address' => 'Barangay Muntingdilaw, Antipolo City, Rizal', 'guardian' => 'Teresa Flores', 'goal' => 'Study social work and support community youth programs.', 'achievements' => 'Debate finalist and community essay awardee.', 'activities' => 'Youth council volunteer and peer support facilitator.'],
            [...$shared, 'scenario' => 'selected_agreement', 'first_name' => 'Daniel Paolo', 'last_name' => 'Aquino', 'middle_initial' => 'V', 'gender' => 'male', 'school' => 'Mambugan Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 94.00, 'birthdate' => '2008-04-25', 'barangay' => 'Mambugan', 'city' => 'Antipolo City', 'address' => 'Barangay Mambugan, Antipolo City, Rizal', 'guardian' => 'Arlene Aquino', 'goal' => 'Study civil engineering and contribute to safer communities.', 'achievements' => 'Division mathematics medalist and research team leader.', 'activities' => 'Math club tutor and barangay clean-up volunteer.'],
            [...$shared, 'scenario' => 'active_monitoring', 'first_name' => 'Nicole Andrea', 'last_name' => 'Bautista', 'middle_initial' => 'E', 'gender' => 'female', 'school' => 'San Roque Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 91.80, 'birthdate' => '2008-09-17', 'barangay' => 'San Roque', 'city' => 'Antipolo City', 'address' => 'Barangay San Roque, Antipolo City, Rizal', 'guardian' => 'Melanie Bautista', 'goal' => 'Maintain strong grades and prepare for nursing school.', 'achievements' => 'Biology quiz finalist and consistent honor student.', 'activities' => 'Health club volunteer and helps care for younger siblings.'],
            [...$shared, 'scenario' => 'completed_support', 'first_name' => 'Vincent Rafael', 'last_name' => 'Torres', 'middle_initial' => 'G', 'gender' => 'male', 'school' => 'Cogeo Village Senior High School', 'strand' => 'TVL-ICT', 'year_level' => 'Grade 12', 'gwa' => 92.25, 'birthdate' => '2008-11-03', 'barangay' => 'Bagong Nayon', 'city' => 'Antipolo City', 'address' => 'Barangay Bagong Nayon, Antipolo City, Rizal', 'guardian' => 'Grace Torres', 'goal' => 'Complete senior high school and continue to an information systems degree.', 'achievements' => 'ICT skills competition medalist and honor student.', 'activities' => 'Computer laboratory assistant and community digital-literacy volunteer.'],
            [...$shared, 'scenario' => 'screening_rejected', 'first_name' => 'Janine Mae', 'last_name' => 'Lopez', 'middle_initial' => 'A', 'gender' => 'female', 'school' => 'Marikina Senior High School', 'strand' => 'HUMSS', 'year_level' => 'Grade 12', 'gwa' => 89.10, 'birthdate' => '2008-01-12', 'barangay' => 'Concepcion Uno', 'city' => 'Marikina City', 'address' => 'Barangay Concepcion Uno, Marikina City, Metro Manila', 'guardian' => 'Rosalinda Lopez', 'goal' => 'Prepare for a college degree in public administration.', 'achievements' => 'Essay contest finalist and honor student.', 'activities' => 'Student publication contributor and community volunteer.'],
            [...$shared, 'scenario' => 'exam_failed', 'first_name' => 'Ethan Cole', 'last_name' => 'Martinez', 'middle_initial' => 'B', 'gender' => 'male', 'school' => 'Antipolo National High School', 'strand' => 'STEM', 'year_level' => 'Grade 11', 'gwa' => 86.40, 'birthdate' => '2009-02-08', 'barangay' => 'Dela Paz', 'city' => 'Antipolo City', 'address' => 'Barangay Dela Paz, Antipolo City, Rizal', 'guardian' => 'Mila Martinez', 'goal' => 'Improve my mathematics foundation before college.', 'achievements' => 'School science exhibit participant.', 'activities' => 'Math club member and family store assistant.'],
            [...$shared, 'scenario' => 'interview_failed', 'first_name' => 'Patricia Joy', 'last_name' => 'Castillo', 'middle_initial' => 'C', 'gender' => 'female', 'school' => 'Sumulong Memorial Senior High School', 'strand' => 'ABM', 'year_level' => 'Grade 12', 'gwa' => 87.60, 'birthdate' => '2008-04-16', 'barangay' => 'Mayamot', 'city' => 'Antipolo City', 'address' => 'Barangay Mayamot, Antipolo City, Rizal', 'guardian' => 'Lourdes Castillo', 'goal' => 'Study entrepreneurship and help grow our family livelihood.', 'achievements' => 'Business-plan team member.', 'activities' => 'Class officer and family livelihood assistant.'],
            [...$shared, 'scenario' => 'not_selected', 'first_name' => 'Luis Gabriel', 'last_name' => 'Herrera', 'middle_initial' => 'D', 'gender' => 'male', 'school' => 'San Roque Senior High School', 'strand' => 'GAS', 'year_level' => 'Grade 12', 'gwa' => 88.35, 'birthdate' => '2008-05-19', 'barangay' => 'San Roque', 'city' => 'Antipolo City', 'address' => 'Barangay San Roque, Antipolo City, Rizal', 'guardian' => 'Maribel Herrera', 'goal' => 'Continue to college and pursue community development.', 'achievements' => 'Community research presenter.', 'activities' => 'Youth volunteer and school event assistant.'],
            [...$shared, 'scenario' => 'withdrawn', 'first_name' => 'Andrea Faith', 'last_name' => 'Domingo', 'middle_initial' => 'E', 'gender' => 'female', 'school' => 'Mambugan Senior High School', 'strand' => 'HUMSS', 'year_level' => 'Grade 12', 'gwa' => 90.25, 'birthdate' => '2008-06-23', 'barangay' => 'Mambugan', 'city' => 'Antipolo City', 'address' => 'Barangay Mambugan, Antipolo City, Rizal', 'guardian' => 'Cecilia Domingo', 'goal' => 'Study communication and work in community media.', 'achievements' => 'School writing awardee.', 'activities' => 'Campus writer and church youth volunteer.'],
            [...$shared, 'scenario' => 'agreement_declined', 'first_name' => 'Miguel Andre', 'last_name' => 'Salazar', 'middle_initial' => 'F', 'gender' => 'male', 'school' => 'Cogeo Village Senior High School', 'strand' => 'TVL-ICT', 'year_level' => 'Grade 12', 'gwa' => 91.05, 'birthdate' => '2008-07-28', 'barangay' => 'Bagong Nayon', 'city' => 'Antipolo City', 'address' => 'Barangay Bagong Nayon, Antipolo City, Rizal', 'guardian' => 'Ramon Salazar', 'goal' => 'Complete an information technology degree.', 'achievements' => 'ICT troubleshooting finalist.', 'activities' => 'Computer club member and household caregiver.'],
            [...$shared, 'scenario' => 'monitoring_adjustment', 'first_name' => 'Hannah Grace', 'last_name' => 'Valdez', 'middle_initial' => 'G', 'gender' => 'female', 'school' => 'Antipolo City Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 90.10, 'birthdate' => '2008-08-15', 'barangay' => 'San Isidro', 'city' => 'Antipolo City', 'address' => 'Barangay San Isidro, Antipolo City, Rizal', 'guardian' => 'Anita Valdez', 'goal' => 'Prepare for a nursing program and remain enrolled.', 'achievements' => 'Health science quiz participant.', 'activities' => 'Health club member and family caregiver.'],
            [...$shared, 'scenario' => 'monitoring_overdue', 'first_name' => 'John Carlo', 'last_name' => 'Manalo', 'middle_initial' => 'H', 'gender' => 'male', 'school' => 'Antipolo National High School', 'strand' => 'ABM', 'year_level' => 'Grade 12', 'gwa' => 86.90, 'birthdate' => '2008-09-21', 'barangay' => 'Dalig', 'city' => 'Antipolo City', 'address' => 'Barangay Dalig, Antipolo City, Rizal', 'guardian' => 'Susan Manalo', 'goal' => 'Complete senior high school and study accountancy.', 'achievements' => 'Financial-literacy seminar participant.', 'activities' => 'Class treasurer and family store assistant.'],
            [...$shared, 'scenario' => 'benefit_issue', 'first_name' => 'Maria Isabel', 'last_name' => 'Ortega', 'middle_initial' => 'I', 'gender' => 'female', 'school' => 'Sumulong Memorial Senior High School', 'strand' => 'HUMSS', 'year_level' => 'Grade 12', 'gwa' => 91.40, 'birthdate' => '2008-10-24', 'barangay' => 'Muntingdilaw', 'city' => 'Antipolo City', 'address' => 'Barangay Muntingdilaw, Antipolo City, Rizal', 'guardian' => 'Dolores Ortega', 'goal' => 'Study education and support local literacy programs.', 'achievements' => 'Reading advocacy awardee.', 'activities' => 'Peer reading tutor and youth volunteer.'],
            [...$shared, 'scenario' => 'support_terminated', 'first_name' => 'Paolo Nathan', 'last_name' => 'Cruz', 'middle_initial' => 'J', 'gender' => 'male', 'school' => 'San Roque Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 12', 'gwa' => 78.25, 'birthdate' => '2008-11-26', 'barangay' => 'San Roque', 'city' => 'Antipolo City', 'address' => 'Barangay San Roque, Antipolo City, Rizal', 'guardian' => 'Estela Cruz', 'goal' => 'Recover academically and continue toward an engineering program.', 'achievements' => 'Robotics workshop participant.', 'activities' => 'Science club member and sibling caregiver.'],
            [...$shared, 'scenario' => 'renewed_support', 'first_name' => 'Clarisse Mae', 'last_name' => 'Evangelista', 'middle_initial' => 'K', 'gender' => 'female', 'school' => 'Mambugan Senior High School', 'strand' => 'STEM', 'year_level' => 'Grade 11', 'gwa' => 93.20, 'birthdate' => '2009-01-30', 'barangay' => 'Mambugan', 'city' => 'Antipolo City', 'address' => 'Barangay Mambugan, Antipolo City, Rizal', 'guardian' => 'Luz Evangelista', 'goal' => 'Maintain strong grades and prepare for environmental science.', 'achievements' => 'Regional ecology quiz participant.', 'activities' => 'Environment club officer and tutorial volunteer.'],
            [...$shared, 'scenario' => 'capacity_award', 'first_name' => 'Adrian James', 'last_name' => 'Pascual', 'middle_initial' => 'L', 'gender' => 'male', 'school' => 'Cogeo Village Senior High School', 'strand' => 'GAS', 'year_level' => 'Grade 12', 'gwa' => 92.00, 'birthdate' => '2008-12-18', 'barangay' => 'Bagong Nayon', 'city' => 'Antipolo City', 'address' => 'Barangay Bagong Nayon, Antipolo City, Rizal', 'guardian' => 'Nora Pascual', 'goal' => 'Prepare for college admission and complete application expenses.', 'achievements' => 'College-readiness workshop awardee.', 'activities' => 'Peer mentor and community event volunteer.'],
        ];
    }

    private function demoDocument(string $owner, string $documentName, string $context): string
    {
        $owner = htmlspecialchars($owner, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $documentName = htmlspecialchars($documentName, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $context = htmlspecialchars($context, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700" viewBox="0 0 1000 700">
  <rect width="1000" height="700" fill="#f8fafc"/>
  <rect x="55" y="55" width="890" height="590" rx="12" fill="#ffffff" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="55" y="55" width="890" height="90" rx="12" fill="#020617"/>
  <text x="95" y="112" fill="#fbbf24" font-family="Arial, sans-serif" font-size="25" font-weight="700">FINDSCHOLARSHIP DEMO RECORD</text>
  <text x="95" y="220" fill="#0f172a" font-family="Arial, sans-serif" font-size="36" font-weight="700">{$documentName}</text>
  <text x="95" y="285" fill="#334155" font-family="Arial, sans-serif" font-size="24">Record for: {$owner}</text>
  <text x="95" y="335" fill="#334155" font-family="Arial, sans-serif" font-size="22">Context: {$context}</text>
  <line x1="95" y1="385" x2="905" y2="385" stroke="#e2e8f0" stroke-width="3"/>
  <text x="95" y="450" fill="#475569" font-family="Arial, sans-serif" font-size="22">Fictional readable file prepared for local workflow testing.</text>
  <text x="95" y="585" fill="#b45309" font-family="Arial, sans-serif" font-size="18" font-weight="700">DEMO ONLY - NOT AN OFFICIAL RECORD</text>
</svg>
SVG;
    }
}
