<?php

namespace Database\Seeders;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationStageSession;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringReview;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ProviderServiceLifecycleService;
use App\Services\RecipientBenefitEntitlementService;
use App\Services\ScholarshipEligibilityService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Carbon\Carbon;
use Database\Seeders\Support\WorkflowDemoCatalog as Catalog;
use Database\Seeders\Support\WorkflowDemoDocument as Document;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FreshWorkflowDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const REQUIREMENTS = ['Certificate of enrollment', 'Latest report card or grades', 'Recent school ID', 'Proof of household income'];
    private array $staff = [];
    private array $programs = [];
    private array $applications = [];

    public function run(): void
    {
        if (User::query()->exists()) {
            throw new RuntimeException('Fresh workflow demonstration requires an empty database. It never deletes existing records itself.');
        }
        if (app()->environment('production') && getenv('DEMO_SEED_CONFIRM') !== Catalog::CONFIRMATION) {
            throw new RuntimeException('The production demo rebuild requires explicit one-time confirmation.');
        }
        $mailer = config('mail.default');
        config()->set('mail.default', 'array');
        try {
            DB::transaction(fn () => $this->seedDemo());
        } finally {
            config()->set('mail.default', $mailer);
        }
        $this->command?->info('Fresh actionable workflow demonstration created. No failed outcomes were seeded.');
    }

    private function seedDemo(): void
    {
        $root = $this->account('superadmin', 'admin');
        $root->adminProfile()->create(['first_name' => 'Demo', 'last_name' => 'Administrator', 'display_name' => 'Demo Platform Administrator', 'contact_number' => '09178001000']);
        $this->staff['superadmin'] = $root;
        foreach (AdminRoleCatalog::definitions() as $key => $definition) {
            if ($key === AdminRoleCatalog::SUPER_ADMIN) continue;
            $suffix = ['account_manager' => 'accounts', 'review_officer' => 'reviews', 'support_officer' => 'support', 'billing_officer' => 'billing', 'finance_officer' => 'finance', 'records_officer' => 'records', 'portal_manager' => 'manager'][$key];
            $user = $this->account('platform.'.$suffix, 'admin', $root, $definition['title'], $definition['permissions']);
            $user->adminProfile()->create(['first_name' => 'Platform', 'last_name' => Str::headline($suffix), 'display_name' => $definition['title'], 'contact_number' => '09178001001']);
            $this->staff['platform.'.$suffix] = $user;
        }
        $primary = $this->provider('marilag', 'Marilag Learning Collective', 'MLC', $root);
        $partner = $this->provider('silayan', 'Silayan Education Circle', 'SEC', $root);
        $this->provider('dalisay', 'Dalisay Learning Association', 'DLA', $root, false);
        $this->programs['primary'] = $this->program($primary, 'Marilag Continuing Learners Scholarship', 'MCL', 1);
        $this->programs['one_time'] = $this->program($primary, 'Marilag School Start Award', 'MSS', 2, true);
        $this->programs['draft'] = $this->program($primary, 'Marilag Connected Classroom Grant', 'MCC', 3, status: 'draft');
        $this->programs['review'] = $this->program($primary, 'Marilag Science Discovery Scholarship', 'MSD', 4, status: 'pending_review');
        $this->programs['partner'] = $this->program($partner, 'Silayan Study Pathways Scholarship', 'SSP', 5);
        $this->programs['partner_one_time'] = $this->program($partner, 'Silayan Learning Tools Award', 'SLT', 6, true);
        $this->programs['partner_draft'] = $this->program($partner, 'Silayan Skills Exploration Grant', 'SSE', 7, status: 'draft');
        $this->team($primary, [$this->programs['primary']->id, $this->programs['one_time']->id], $this->programs['draft']->id);
        $this->team($partner, [$this->programs['partner']->id, $this->programs['partner_one_time']->id], $this->programs['partner_draft']->id);

        foreach (Catalog::applicants() as $index => $record) {
            $learner = $this->applicant($record, $index + 1, $this->staff['platform.reviews']);
            if (in_array($record['scenario'], ['profile', 'verification', 'apply'], true)) continue;
            $program = str_starts_with($record['scenario'], 'onetime.') ? $this->programs['one_time']
                : ($record['scenario'] === 'partner.screening' ? $this->programs['partner'] : $this->programs['primary']);
            $application = $this->application($learner, $program, $record['scenario']);
            $this->applications[$record['scenario']] = $application;
        }
        $this->monitoring($primary);
        foreach (['release.record', 'release.confirm', 'onetime.record', 'onetime.confirm'] as $scenario) {
            $this->release($this->applications[$scenario], str_ends_with($scenario, '.confirm'));
        }
        $this->support($primary);
        $this->services($primary);
        foreach ($this->applications as $application) {
            app(DecisionSupportService::class)->syncApplication($application->fresh(), 'fresh_workflow_demo_20261028');
            ActivityLog::record($application->assignedReviewer, 'application_demo_prepared',
                'Application prepared at '.$application->workflow_stage.' with the current outcome left to the tester.',
                metadata: ['application_id' => $application->id, 'program_id' => $application->scholarship_id, 'demo' => true]);
        }
        foreach (['marilag', 'silayan'] as $username) {
            ActivityLog::record($this->staff['platform.reviews'], 'provider_verified', 'Fictional organization verification completed for '.$this->staff[$username]->provider_name.'.',
                metadata: ['provider_id' => $this->staff[$username]->id, 'demo' => true]);
        }
        ActivityLog::record($root, 'demo_workflows_prepared', 'Fresh demonstration created with tester-controlled next actions.', metadata: ['catalog' => Catalog::DIRECTORY]);
        $this->guide();
        if ((int) $root->id !== 1) throw new RuntimeException('The fresh demonstration did not restart account IDs at 1.');
    }

    private function account(string $username, string $role, ?User $parent = null, ?string $title = null, ?array $permissions = null): User
    {
        $user = User::query()->create([
            'username' => $username, 'email' => $username.'@demo.findscholarship.test', 'role' => $role,
            'parent_account_id' => $parent?->id, 'account_title' => $title, 'permissions' => $permissions,
            'password' => getenv('WORKFLOW_DEMO_PASSWORD') ?: 'password123', 'account_status' => 'active',
            'must_reset_password' => false, 'terms_accepted_at' => now()->subDays(20),
            'privacy_accepted_at' => now()->subDays(20), 'terms_version' => Terms::VERSION,
            'created_at' => now()->subDays(60),
        ]);
        $user->forceFill(['email_verified_at' => now()->subDays(20)])->save();
        return $user;
    }

    private function provider(string $username, string $name, string $mark, User $admin, bool $approved = true): User
    {
        $user = $this->account($username, 'provider', title: 'Organization Representative', permissions: User::PROVIDER_PERMISSIONS);
        $user->providerProfile()->create([
            'first_name' => $username === 'marilag' ? 'Adriana' : ($username === 'silayan' ? 'Bernardo' : 'Celestine'),
            'last_name' => 'Demo Representative', 'contact_number' => '09178002000', 'representative_position' => 'Program Director',
            'provider_name' => $name, 'provider_type' => 'foundation', 'provider_website' => 'https://example.org/'.$username,
            'provider_address' => 'Barangay San Isidro, Antipolo City, Rizal', 'latitude' => 14.5864, 'longitude' => 121.1753,
            'provider_description' => 'A fictional organization prepared for hands-on scholarship workflow demonstrations. No real organization is represented.',
            'mission' => 'Make education support understandable, verifiable, and accountable.', 'year_established' => 2017,
            'service_area' => 'Rizal and Metro Manila', 'logo_path' => $this->logo('providers/'.$username, $mark, 0),
            'cover_image_source' => 'default', 'cover_image_key' => 'campus-community',
            'provider_contact_email' => $user->email, 'provider_contact_number' => '09178002000',
            'contact_department' => 'Scholarship Desk', 'office_hours' => 'Monday to Friday, 9 AM to 5 PM',
            'legal_name' => $name.' (Fictional Demo)', 'registration_authority' => 'DEMO registry',
            'registration_number' => 'DEMO-'.strtoupper($username).'-2026', 'registration_date' => '2017-06-01',
            'verification_status' => $approved ? 'approved' : 'pending', 'verified_by' => $approved ? $admin->id : null,
            'verified_at' => $approved ? now()->subDays(45) : null, 'created_at' => now()->subDays(50),
        ]);
        foreach (['organization_registration', 'representative_id', 'authority_letter'] as $type) {
            $file = Document::make(Catalog::DIRECTORY.'/providers/'.$username.'/'.$type.'.pdf', Str::headline($type), [$name, 'Representative: '.$user->fresh()->name, 'Reference: DEMO-'.strtoupper($username), 'For tester review only.']);
            $user->providerVerificationDocuments()->create([...$file, 'uploaded_by' => $user->id, 'document_type' => $type,
                'status' => $approved ? 'approved' : 'submitted', 'uploaded_at' => now()->subDays($approved ? 46 : 3),
                'terms_accepted_at' => now()->subDays(3), 'terms_version' => Terms::VERSION]);
        }
        $this->staff[$username] = $user->fresh('providerProfile');
        return $this->staff[$username];
    }

    private function team(User $owner, array $operationalIds, int $draftId): void
    {
        foreach (Catalog::providerRoles() as $suffix => [$title, $permission]) {
            $username = $owner->username.'.'.$suffix;
            $user = $this->account($username, 'provider', $owner, $title, $permission ? [$permission] : User::PROVIDER_PERMISSIONS);
            $user->update(['assigned_program_ids' => in_array($suffix, ['manager', 'profile', 'billing'], true) ? null
                : ($suffix === 'programs' ? [...$operationalIds, $draftId, ...($owner->username === 'marilag' ? [$this->programs['review']->id] : [])] : $operationalIds)]);
            $profile = $owner->providerProfile->only(['provider_name', 'provider_type', 'provider_address', 'latitude', 'longitude', 'logo_path', 'provider_contact_email', 'provider_contact_number', 'verification_status', 'verified_by', 'verified_at']);
            $user->providerProfile()->create([...$profile, 'first_name' => ucfirst($owner->username), 'last_name' => Str::headline($suffix), 'contact_number' => '09178002001']);
            $this->staff[$username] = $user;
        }
        $setup = $this->account($owner->username.'.newstaff', 'provider', $owner, 'Support Staff', ['manage_reports']);
        $setup->update(['must_reset_password' => true, 'assigned_program_ids' => $operationalIds]);
        $setup->providerProfile()->create(['first_name' => 'New', 'last_name' => 'Team Member', 'provider_name' => $owner->provider_name, 'verification_status' => 'approved']);
    }

    private function program(User $provider, string $title, string $mark, int $variant, bool $oneTime = false, string $status = 'published'): Scholarship
    {
        $program = Scholarship::query()->create([
            'provider_id' => $provider->id, 'image_path' => $this->logo('programs/'.Str::slug($title), $mark, $variant),
            'cover_image_source' => 'default', 'cover_image_key' => $oneTime ? 'student-workshop' : 'learning-together',
            'title' => $title, 'category' => 'Financial assistance', 'program_cycle' => 'School Year 2026-2027',
            'description' => $oneTime ? 'A one-time study-start award with agreement, distribution proof, and recipient receipt confirmation.'
                : 'Continuing support for senior high school learners through allowance, mentoring, and periodic academic check-ins.',
            'provider_objectives' => ['education_access', 'academic_progress'],
            'eligibility' => 'Verified senior high school profile, current enrollment, and at least an 85% average. STEM is preferred, not required.',
            'eligible_education_levels' => 'senior_high_school', 'eligible_courses' => 'STEM',
            'eligibility_rule_modes' => ['course' => 'preferred'],
            'eligibility_conditions' => [['key' => 'currently_enrolled', 'statement' => 'Currently enrolled in the stated academic year.', 'verification_type' => 'automatic']],
            'eligible_school_types' => 'public, private', 'eligible_year_levels' => 'Grade 11, Grade 12',
            'eligible_locations' => 'Rizal, Metro Manila', 'income_requirement' => 'PHP 10,000 - 20,000',
            'exclude_current_scholarship_recipients' => true, 'minimum_gwa' => 85, 'minimum_grade_scale' => 'percentage',
            'location_name' => $provider->provider_name.' Learning Center', 'location_address' => $provider->provider_address,
            'latitude' => 14.5864, 'longitude' => 121.1753, 'requirements' => implode("\n", self::REQUIREMENTS),
            'optional_requirements' => 'Achievement certificate', 'post_qualification_requirements' => 'Bring original enrollment and identity records.',
            'handoff_mode' => 'onsite', 'handoff_instructions' => 'Present your originals at the learning center. The provider records the verification outcome.',
            'handoff_deadline' => now()->addDays(70), 'handoff_location_name' => $provider->provider_name.' Learning Center',
            'handoff_location_address' => $provider->provider_address, 'handoff_latitude' => 14.5864, 'handoff_longitude' => 121.1753,
            'review_rubric' => ReviewRubric::DEFAULT,
            'application_questions' => [['id' => 'education_plan', 'prompt' => 'How will this support help your studies?', 'required' => true]],
            'award_amount' => $oneTime ? 5000 : 18000, 'slots_available' => 60, 'application_limit' => 150,
            'application_mode' => 'online', 'selection_stages' => $oneTime ? ['screening', 'formal_application', 'decision'] : ['screening', 'formal_application', 'exam', 'interview', 'decision'],
            'exam_duration_minutes' => $oneTime ? null : 90, 'exam_passing_score' => $oneTime ? null : 75,
            'support_mode' => $oneTime ? 'one_time' : 'mixed', 'monitoring_policy' => $oneTime ? 'none' : 'recurring',
            'renewal_policy' => $oneTime ? 'This award is distributed once; no recurring grade checks are required.' : 'The provider reviews due check-ins before deciding on renewal or completion.',
            'recipient_agreement' => ['commitment_type' => 'reporting_and_participation', 'responsibilities' => $oneTime ? 'Confirm identity and receipt of the award.' : 'Remain enrolled, submit check-ins, and confirm received benefits.',
                'required_evidence' => $oneTime ? 'Identity and award receipt.' : 'Current grades and enrollment certificate.',
                'release_conditions' => 'Accept the agreement before release. Due monitoring must be reviewed for later continuing-support releases.',
                'duration' => $oneTime ? 'One distribution' : 'Current school year'],
            'contact_email' => $provider->email, 'contact_number' => '09178002000', 'contact_person' => $provider->name,
            'contact_department' => 'Scholarship Desk', 'application_opens_at' => now()->subDays(30),
            'deadline' => now()->addDays(60), 'expected_results_at' => now()->addDays(75),
            'support_starts_at' => now()->subDays(15), 'support_ends_at' => now()->addMonths(9),
            'status' => $status, 'provider_terms_accepted_at' => now()->subDays(35), 'provider_terms_version' => Terms::VERSION,
            'created_at' => now()->subDays(35),
        ]);
        $program->benefits()->create(['type' => 'allowance', 'title' => $oneTime ? 'Study-start award' : 'Term learning allowance',
            'amount' => $oneTime ? 5000 : 9000, 'coverage' => 'fixed', 'frequency' => $oneTime ? 'one_time' : 'per_term',
            'duration' => $oneTime ? 'Once' : 'Two semesters', 'description' => 'Study expenses and learning materials.', 'sort_order' => 1]);
        if (! $oneTime) {
            $program->benefits()->create(['type' => 'mentorship', 'title' => 'College pathway mentoring', 'frequency' => 'per_term', 'description' => 'A scheduled provider-led mentoring session.', 'sort_order' => 2]);
        }
        return $program->fresh(['benefits', 'provider.providerProfile']);
    }

    private function applicant(array $record, int $number, User $reviewer): User
    {
        $user = $this->account($record['username'], 'applicant');
        $approved = ! in_array($record['scenario'], ['profile', 'verification'], true);
        $photo = Catalog::DIRECTORY.'/avatars/'.$user->id.'.jpg';
        $source = public_path('images/demo/default-applicant-avatar.jpg');
        if (! is_file($source)) throw new RuntimeException('The neutral demo avatar is missing.');
        Storage::disk('local')->put($photo, file_get_contents($source));
        $user->studentProfile()->create([
            'first_name' => $record['first_name'], 'last_name' => $record['last_name'], 'middle_initial' => null,
            'gender' => $number % 2 ? 'female' : 'male', 'contact_number' => sprintf('0917801%04d', $number),
            'account_managed_by' => 'learner', 'citizenship_status' => 'filipino', 'birthdate' => '2008-03-15',
            'education_level' => 'senior_high_school', 'school' => 'Marilag Demonstration Senior High School',
            'school_type' => 'public', 'learner_reference_number' => sprintf('20268000%04d', $number),
            'course_or_strand' => $record['scenario'] === 'apply' ? 'HUMSS' : 'STEM', 'year_level' => 'Grade 12',
            'enrollment_status' => 'Enrolled', 'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90 + ($number % 6), 'grading_scale' => 'percentage',
            'academic_result_source' => $approved ? 'verified_record' : 'self_declared',
            'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 4,
            'current_scholarship_status' => 'none', 'address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'barangay' => 'San Isidro', 'city' => 'Antipolo City', 'province' => 'Rizal', 'region' => 'CALABARZON',
            'latitude' => 14.5864, 'longitude' => 121.1753, 'guardian_name' => 'Alexis '.$record['last_name'],
            'guardian_relationship' => 'Parent', 'guardian_contact' => sprintf('0917802%04d', $number),
            'guardian_email' => 'guardian.'.$number.'@demo.findscholarship.test', 'guardian_is_account_owner' => false,
            'profile_photo_path' => $photo, 'profile_photo_original_name' => 'neutral-demo-avatar.jpg',
            'profile_photo_mime_type' => 'image/jpeg', 'profile_photo_size' => filesize($source),
            'profile_photo_updated_at' => now()->subDays(20), 'profile_photo_review_status' => $approved ? 'approved' : 'pending',
            'profile_photo_reviewed_by' => $approved ? $reviewer->id : null, 'profile_photo_reviewed_at' => $approved ? now()->subDays(15) : null,
            'verification_status' => $approved ? 'approved' : ($record['scenario'] === 'profile' ? 'unsubmitted' : 'pending'),
            'verified_by' => $approved ? $reviewer->id : null, 'verified_at' => $approved ? now()->subDays(15) : null,
            'scholarship_goal' => 'Continue senior high school and prepare for college.',
            'created_at' => now()->subDays(20),
        ]);
        $user = $user->fresh('studentProfile');
        if ($record['scenario'] !== 'profile') {
            foreach (ApplicantVerificationDocument::requiredTypesFor($user) as $type) {
                $file = $this->learnerDocument($user, $type, 'profile-evidence');
                $user->applicantVerificationDocuments()->create([...$file, 'uploaded_by' => $user->id, 'document_type' => $type,
                    'status' => $approved ? 'approved' : 'submitted', 'uploaded_at' => now()->subDays(16),
                    'terms_accepted_at' => now()->subDays(16), 'terms_version' => Terms::VERSION,
                    'ocr_status' => $type === 'academic_record' ? 'succeeded' : 'not_requested',
                    'ocr_provider' => $type === 'academic_record' ? 'demo_fixture' : null,
                    'ocr_grade' => $type === 'academic_record' ? $user->studentProfile->gwa : null,
                    'ocr_grading_scale' => $type === 'academic_record' ? 'percentage' : null,
                    'ocr_label' => $type === 'academic_record' ? 'General average' : null,
                    'ocr_processed_at' => $type === 'academic_record' ? now()->subDays(16) : null]);
            }
        }
        if ($approved) {
            foreach (self::REQUIREMENTS as $requirement) {
                $file = $this->learnerDocument($user, $requirement, 'document-library');
                $user->studentDocuments()->create([...$file, 'document_name' => $requirement, 'uploaded_at' => now()->subDays(15),
                    'terms_accepted_at' => now()->subDays(15), 'terms_version' => Terms::VERSION]);
            }
        }
        PortalNotification::create(['user_id' => $user->id, 'type' => 'demo_next_action', 'title' => 'Your demonstration starts here',
            'message' => $record['next_action'].'. Decision owner: '.$record['actor'].'.',
            'action_url' => in_array($record['scenario'], ['profile', 'verification'], true) ? '/dashboard/profile' : '/dashboard/applications',
            'deduplication_key' => 'fresh-demo-'.$record['scenario']]);
        return $user->fresh(['studentProfile', 'applicantVerificationDocuments']);
    }

    private function learnerDocument(User $user, string $type, string $area): array
    {
        $profile = $user->studentProfile;
        return Document::make(Catalog::DIRECTORY.'/'.$area.'/'.$user->id.'/'.Str::slug($type).'.pdf', Str::headline($type), [
            'Learner: '.$user->name, 'School: '.$profile->school,
            'Education: Senior High School / '.$profile->course_or_strand.' / Grade 12',
            'Academic year: 2026-2027 / First semester', 'General Average: '.$profile->gwa.'%',
            'Birthdate: '.$profile->birthdate->format('Y-m-d'), 'Enrollment: Enrolled',
            'Residence: '.$profile->address, 'Household income: '.$profile->income_bracket,
            'Parent or guardian: '.$profile->guardian_name, 'Prepared for workflow testing only.',
        ]);
    }

    private function application(User $learner, Scholarship $program, string $scenario): ScholarshipApplication
    {
        $prefix = $program->provider->username;
        $reviewer = $this->staff[$prefix.'.reviewer'];
        $actor = $this->staff[$prefix.'.selection'];
        $isScreening = in_array($scenario, ['screening', 'partner.screening'], true);
        $eligibility = app(ScholarshipEligibilityService::class)->evaluate($program, $learner);
        if (! $learner->applicantProfileReadiness()['verified'] || ! $eligibility['is_eligible']) {
            throw new RuntimeException('A demo application must satisfy current profile and eligibility safeguards.');
        }
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $learner->id,
            'status' => 'submitted', 'workflow_version' => 2, 'application_state' => 'submitted', 'workflow_stage' => 'screening',
            'document_checklist' => self::REQUIREMENTS, 'optional_document_checklist' => [],
            'eligibility_score' => $eligibility['score'], 'eligibility_breakdown' => $eligibility,
            'review_rubric_snapshot' => ReviewRubric::DEFAULT, 'assigned_reviewer_id' => $reviewer->id,
            'application_answers' => [['question_id' => 'education_plan', 'prompt' => 'How will this support help your studies?', 'answer' => 'It will cover school supplies, transport, and study expenses.']],
            'submitted_at' => now()->subDays(14), 'terms_accepted_at' => now()->subDays(14), 'terms_version' => Terms::VERSION,
            'created_at' => now()->subDays(14),
        ]);
        foreach (self::REQUIREMENTS as $requirement) {
            $file = $this->learnerDocument($learner, $requirement, 'applications/'.$application->id);
            $application->documents()->create([...$file, 'document_name' => $requirement, 'uploaded_by' => $learner->id,
                'uploaded_at' => now()->subDays(14), 'status' => $isScreening ? 'pending' : 'accepted',
                'reviewed_by' => $isScreening ? null : $reviewer->id, 'reviewed_at' => $isScreening ? null : now()->subDays(12),
                'terms_accepted_at' => now()->subDays(14), 'terms_version' => Terms::VERSION]);
        }
        ApplicationStatusHistory::create(['scholarship_application_id' => $application->id, 'changed_by' => $learner->id,
            'to_status' => 'submitted', 'review_notes' => 'Application submitted with complete demo evidence.', 'changed_at' => now()->subDays(14)]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $this->earlier(14, fn () => $workflow->start($application->fresh()));
        if ($isScreening) return $application;
        $application = $this->earlier(12, fn () => $workflow->recordStageResult($application, 'screening', 'passed', $reviewer, 'Earlier screening completed to prepare the next tester action.'));
        $scores = collect(ReviewRubric::DEFAULT)->mapWithKeys(fn ($criterion) => [$criterion['key'] => 92])->all();
        $application->update(['rubric_scores' => $scores, 'rubric_total_score' => ReviewRubric::result(ReviewRubric::DEFAULT, $scores)['total_score'],
            'rubric_scored_by' => $reviewer->id, 'rubric_scored_at' => now()->subDays(12)]);
        if ($scenario === 'formal') return $application->fresh();

        $application = $this->earlier(11, fn () => $workflow->recordStageResult($application->fresh(), 'formal_application', 'passed', $actor, 'Original demo school records verified.'));
        if (! str_starts_with($scenario, 'onetime.')) {
            if (str_starts_with($scenario, 'exam.')) {
                if ($scenario !== 'exam.assign') $this->session($application, 'exam', $scenario);
                return $application->fresh();
            }
            $this->earlier(10, fn () => $this->session($application, 'exam', 'exam.result'));
            $application = $this->earlier(10, fn () => $workflow->recordStageResult($application->fresh(), 'exam', 'passed', $actor, 'Earlier examination passed with a recorded demo score of 90.'));
            if (str_starts_with($scenario, 'interview.')) {
                if ($scenario !== 'interview.assign') $this->session($application, 'interview', $scenario);
                return $application->fresh();
            }
            $this->earlier(9, fn () => $this->session($application, 'interview', 'interview.result'));
            $application = $this->earlier(9, fn () => $workflow->recordStageResult($application->fresh(), 'interview', 'passed', $actor, 'Earlier interview completed successfully.'));
        }
        if ($scenario === 'decision') return $application->fresh();
        $application = $this->earlier(8, fn () => $workflow->recordFinalOutcome($application->fresh(), 'selected', $this->staff[$prefix.'.decisions'],
            'Prior award selection is the prerequisite for this recipient hand-off demonstration.', 'approved_for_award'));
        $application->update(['awarded_amount' => $program->award_amount]);
        if ($scenario === 'agreement') return $application->fresh();
        $application->update(['student_response_status' => 'accepted', 'student_responded_at' => now()->subDays(7),
            'student_response_terms_accepted_at' => now()->subDays(7), 'student_response_terms_version' => $application->provider_contract_terms_version,
            'provider_contract_terms_accepted_at' => now()->subDays(7), 'provider_contract_acceptance_ip' => '127.0.0.1',
            'provider_contract_acceptance_user_agent' => 'Fictional workflow demonstration']);
        app(RecipientBenefitEntitlementService::class)->activateForAcceptedApplication($application->fresh());
        return $application->fresh();
    }

    private function session(ScholarshipApplication $application, string $stage, string $scenario): void
    {
        $actor = $this->staff[$application->scholarship->provider->username.'.selection'];
        $completed = str_ends_with($scenario, '.result');
        $conducted = $completed || $scenario === 'exam.complete';
        $online = $stage === 'interview';
        $starts = $conducted ? now()->subHours($stage === 'exam' ? 4 : 2) : now()->addDays($stage === 'exam' ? 5 : 9)->setTime(9, 0);
        $session = ApplicationStageSession::create([
            'scholarship_id' => $application->scholarship_id, 'stage_key' => $stage,
            'title' => ucfirst($stage).' - '.$application->applicant->name.' demo group',
            'starts_at' => $starts, 'ends_at' => $starts->copy()->addMinutes($online ? 30 : 90), 'timezone' => 'Asia/Manila',
            'mode' => $online ? 'online' : 'onsite', 'venue' => $online ? null : 'Marilag Learning Center',
            'location_address' => $online ? null : 'Barangay San Isidro, Antipolo City, Rizal',
            'latitude' => $online ? null : 14.5864, 'longitude' => $online ? null : 121.1753,
            'meeting_url' => $online ? 'https://example.org/demo-interview-room' : null,
            'capacity' => 20, 'instructions' => 'Fictional demonstration schedule. Bring your school ID; do not visit a real location or join a real meeting.',
            'status' => $completed ? 'completed' : 'scheduled', 'proof_required' => true,
            'published_at' => now()->subDays(3), 'completed_at' => $completed ? now()->subHour() : null,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $assignment = $session->assignments()->create(['scholarship_application_id' => $application->id,
            'status' => $conducted ? 'acknowledged' : 'assigned', 'assigned_by' => $actor->id,
            'assigned_at' => now()->subDays(3), 'acknowledged_at' => $conducted ? now()->subDays(2) : null]);
        $assignment->attendance()->create(['status' => $conducted ? 'present' : 'pending',
            'recorded_by' => $conducted ? $actor->id : null, 'recorded_at' => $conducted ? now()->subHour() : null]);
        if ($conducted) {
            $file = Document::make(Catalog::DIRECTORY.'/sessions/'.$session->id.'/provider-proof.pdf', ucfirst($stage).' participation proof', [
                'Learner: '.$application->applicant->name, 'Program: '.$application->scholarship->title,
                'Attendance: present', 'Demo assessment record ready for the provider to choose an outcome.',
            ]);
            $session->evidence()->create([...$file, 'scholarship_id' => $application->scholarship_id, 'stage_key' => $stage,
                'scope' => 'session', 'category' => $online ? 'evaluation' : 'score_sheet', 'disk' => 'local',
                'event_date' => $starts, 'visibility' => 'provider_private', 'is_current' => true, 'uploaded_by' => $actor->id, 'uploaded_at' => now()->subHour()]);
        }
        $application->stageProgresses()->where('stage_key', $stage)->update([
            'operational_status' => $completed ? 'ready_for_review' : 'scheduled', 'proof_required' => true,
        ]);
        $session->auditEvents()->create(['scholarship_id' => $application->scholarship_id,
            'scholarship_application_id' => $application->id, 'application_stage_assignment_id' => $assignment->id,
            'stage_key' => $stage, 'event_type' => 'session_published', 'to_status' => $session->status,
            'actor_id' => $actor->id, 'occurred_at' => now()->subDays(3)]);
    }

    private function monitoring(User $provider): void
    {
        $actor = $this->staff[$provider->username.'.monitoring'];
        $plan = RecipientMonitoringPlan::create(['scholarship_id' => $this->programs['primary']->id,
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'frequency' => 'semester',
            'starts_on' => now()->subDays(15), 'ends_on' => now()->addMonths(9), 'grace_period_days' => 7,
            'allow_exception_requests' => true, 'instructions' => 'Submit current grades and enrollment proof. The provider records orientation participation.',
            'status' => 'active', 'version' => 1, 'activated_at' => now()->subDays(14)]);
        $definitions = [
            ['type' => 'academic_progress', 'title' => 'Current academic record', 'requires_file' => true, 'minimum_grade' => 85, 'grading_scale' => 'percentage'],
            ['type' => 'enrollment', 'title' => 'Current enrollment certificate', 'requires_file' => true],
            ['type' => 'program_participation', 'title' => 'Recipient orientation participation', 'requires_file' => false],
        ];
        foreach ($definitions as $index => $definition) $plan->requirements()->create([
            ...$definition, 'description' => 'Current evidence for the monitoring period.', 'evidence_description' => $definition['title'],
            'required' => true, 'requires_original_verification' => false, 'sort_order' => $index + 1, 'active' => true,
        ]);
        $cycle = RecipientMonitoringCycle::create(['scholarship_id' => $plan->scholarship_id, 'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 1, 'grace_period_days' => 7, 'allow_exception_requests' => true,
            'created_by' => $actor->id, 'title' => 'Marilag first semester check-in', 'period_type' => 'semester',
            'academic_period' => 'First semester', 'school_year' => '2026-2027', 'opens_at' => now()->subDays(7),
            'due_at' => now()->addDays(21), 'minimum_grade' => 85, 'grading_scale' => 'percentage',
            'instructions' => $plan->instructions, 'status' => 'open', 'published_at' => now()->subDays(7)]);
        foreach ($plan->requirements as $requirement) {
            $cycle->requirements()->create([...$requirement->only(['type', 'title', 'description', 'evidence_description', 'required',
                'requires_file', 'requires_original_verification', 'minimum_grade', 'grading_scale', 'sort_order']), 'source_requirement_id' => $requirement->id]);
        }
        foreach (['monitoring.upload', 'monitoring.review', 'monitoring.close', 'support.decision'] as $scenario) {
            $application = $this->applications[$scenario];
            $allMet = in_array($scenario, ['monitoring.close', 'support.decision'], true);
            $isClosed = $scenario === 'support.decision';
            $case = RecipientMonitoringCase::create(['recipient_monitoring_cycle_id' => $cycle->id,
                'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
                'status' => $isClosed ? 'closed' : ($allMet ? 'ready_to_close' : ($scenario === 'monitoring.review' ? 'under_review' : 'awaiting_submission')),
                'due_status' => $isClosed ? 'closed' : 'open', 'effective_due_at' => $cycle->due_at,
                'outcome' => $isClosed ? 'completed' : null, 'opened_at' => $cycle->published_at,
                'submitted_at' => $scenario === 'monitoring.upload' ? null : now()->subDays(2),
                'review_started_at' => $scenario === 'monitoring.upload' ? null : now()->subDay(),
                'ready_to_close_at' => $allMet ? now()->subHour() : null,
                'closed_by' => $isClosed ? $actor->id : null, 'closed_at' => $isClosed ? now()->subHour() : null,
                'next_action_role' => $isClosed ? null : ($scenario === 'monitoring.upload' ? 'applicant' : 'provider'),
                'next_action' => $isClosed ? null : ($allMet ? 'close_case' : ($scenario === 'monitoring.upload' ? 'submit_requirements' : 'review_requirements'))]);
            foreach ($cycle->requirements as $requirement) {
                $participation = ! $requirement->requires_file;
                if ($scenario === 'monitoring.upload' && ! $participation) continue;
                $met = $allMet || $participation;
                $file = $participation ? [] : $this->learnerDocument($application->applicant, $requirement->title, 'monitoring/'.$case->id);
                $submission = RecipientMonitoringSubmission::create([...$file,
                    'recipient_monitoring_cycle_id' => $cycle->id, 'recipient_monitoring_cycle_requirement_id' => $requirement->id,
                    'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
                    'applicant_note' => $participation ? null : 'Current demo school record submitted for provider review.',
                    'submission_source' => $participation ? 'provider_record' : 'applicant_upload',
                    'ocr_status' => $requirement->type === 'academic_progress' ? 'succeeded' : 'not_requested',
                    'ocr_grade' => $requirement->type === 'academic_progress' ? 93 : null,
                    'ocr_grading_scale' => $requirement->type === 'academic_progress' ? 'percentage' : null,
                    'reported_grade' => $requirement->type === 'academic_progress' ? 93 : null,
                    'reported_grading_scale' => $requirement->type === 'academic_progress' ? 'percentage' : null,
                    'grade_source' => $requirement->type === 'academic_progress' ? 'ocr' : null,
                    'submitted_at' => now()->subDays(2), 'review_status' => $met ? 'met' : 'pending',
                    'reviewed_by' => $met ? $actor->id : null, 'reviewed_at' => $met ? now()->subDay() : null]);
                if ($met) RecipientMonitoringReview::create(['recipient_monitoring_submission_id' => $submission->id,
                    'reviewed_by' => $actor->id, 'decision' => 'met', 'notes' => 'Prerequisite confirmed for this demonstration hand-off.', 'decided_at' => now()->subDay()]);
            }
        }
        // A non-cash benefit uses the same announcement and receipt workflow.
        $this->release($this->applications['monitoring.upload'], false, true);
    }

    private function release(ScholarshipApplication $application, bool $released, bool $mentoring = false): void
    {
        $program = $application->scholarship;
        $actor = $this->staff[$program->provider->username.'.releases'];
        $benefit = $mentoring ? $program->benefits->firstWhere('type', 'mentorship') : $program->benefits->firstWhere('type', 'allowance');
        $release = RecipientBenefitRelease::create(['scholarship_id' => $program->id, 'created_by' => $actor->id,
            'title' => $benefit->title.' - '.$application->applicant->name, 'release_at' => $mentoring ? now()->addDays(4)->setTime(10, 0) : now()->subHour(),
            'benefit_description' => $benefit->description, 'amount' => $benefit->amount, 'release_method' => $mentoring ? 'other' : 'in_person',
            'location' => 'Marilag Learning Center, Barangay San Isidro, Antipolo City, Rizal', 'latitude' => 14.5864, 'longitude' => 121.1753,
            'instructions' => 'Fictional demonstration only. Present the demo ID and confirm receipt after the provider records delivery.',
            'requires_original_verification' => false, 'status' => 'scheduled', 'published_at' => now()->subDay()]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id, 'status' => 'scheduled', 'originals_verified' => false]);
        $service = app(RecipientBenefitEntitlementService::class);
        $service->allocate($record, new \Illuminate\Database\Eloquent\Collection([$benefit]));
        if ($released) {
            $file = Document::make(Catalog::DIRECTORY.'/release-proofs/'.$record->id.'.pdf', 'Benefit distribution proof', [
                'Recipient: '.$application->applicant->name, 'Benefit: '.$benefit->title, 'Amount: PHP '.($benefit->amount ?? 0),
                'Provider distribution recorded. Recipient confirmation is still required.',
            ]);
            $record->update(['status' => 'released', 'originals_verified' => true, 'recorded_by' => $actor->id,
                'recorded_at' => now()->subMinutes(30), 'released_at' => now()->subMinutes(30),
                'receipt_original_name' => $file['original_name'], 'receipt_path' => $file['path'], 'receipt_mime_type' => $file['mime_type'], 'receipt_size' => $file['size']]);
            $service->syncReleaseResult($record->fresh());
        }
    }

    private function support(User $provider): void
    {
        $applicant = $this->applications['screening']->applicant;
        SupportReport::create(['applicant_id' => $applicant->id, 'provider_id' => $provider->id, 'scholarship_id' => $this->programs['primary']->id,
            'category' => 'program', 'subject' => 'Question about orientation materials',
            'description' => 'Where can I find the preparation checklist for the recipient orientation?',
            'assigned_role' => 'provider', 'status' => 'open', 'provider_status' => 'open', 'admin_status' => 'not_required']);
        SupportReport::create(['applicant_id' => $this->staff['marilag.support']->id, 'provider_id' => $provider->id, 'category' => 'data', 'subject' => 'Help choosing the right reporting export',
            'description' => 'Please explain the applicant and recipient export options for the new cycle.',
            'context' => json_encode(['reporter_id' => $this->staff['marilag.support']->id, 'source' => 'provider_workspace']),
            'assigned_role' => 'admin', 'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
        SupportReport::create(['applicant_id' => $this->applications['formal']->applicant_id, 'category' => 'privacy',
            'privacy_request_type' => 'access_copy', 'subject' => 'Request a copy of my demo profile',
            'description' => 'Please explain how I can obtain a copy of the information saved for this demonstration.',
            'assigned_role' => 'admin', 'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
    }

    private function services(User $provider): void
    {
        $billing = $this->staff['marilag.billing'];
        $officer = $this->staff['platform.billing'];
        ProviderServiceRequest::create(['provider_id' => $provider->id, 'created_by' => $billing->id,
            'scholarship_id' => $this->programs['draft']->id, 'plan_code' => 'assisted_setup', 'plan_name' => 'Assisted program setup',
            'amount' => config('billing.plans.assisted_setup.amount'), 'currency' => 'PHP', 'status' => 'ready_for_review',
            'answers' => ['program_stage' => 'review', 'main_blocker' => 'Please review the draft requirements.', 'requested_outcome' => 'A publishing-readiness checklist.'],
            'request_summary' => 'Review the new Marilag draft before submission.', 'requested_outcome' => 'A publishing-readiness checklist.', 'submitted_at' => now()->subDay()]);
        foreach (['payment' => 'queued', 'assignment' => 'ready', 'delivery' => 'in_progress', 'confirmation' => 'provider_review'] as $scenario => $state) {
            $paid = $scenario !== 'payment';
            $purchase = ProviderServicePurchase::create(['provider_id' => $provider->id, 'created_by' => $billing->id,
                'plan_code' => 'application_cycle_support', 'plan_name' => 'Application cycle support',
                'amount' => config('billing.plans.application_cycle_support.amount'), 'currency' => 'PHP',
                'status' => $paid ? 'paid' : 'pending', 'fulfillment_status' => $state,
                'reference_number' => 'DEMO-20261028-'.strtoupper($scenario), 'payment_method' => $paid ? 'demo' : null,
                'livemode' => false, 'gateway_metadata' => ['demo' => true, 'no_real_payment' => true],
                'service_terms_accepted_at' => now()->subDays(5), 'paid_at' => $paid ? now()->subDays(4) : null,
                'request_summary' => 'Demonstrate service '.$scenario.' for the new scholarship cycle.',
                'requested_outcome' => 'A clear operational checklist and a usable workflow guide.',
                'assigned_to' => in_array($state, ['in_progress', 'provider_review'], true) ? $officer->id : null,
                'target_due_at' => $paid ? now()->addDays(10) : null,
                'milestones' => [['id' => 'review', 'label' => 'Review the program workflow', 'completed' => $state === 'provider_review'],
                    ['id' => 'guide', 'label' => 'Deliver the operating guide', 'completed' => $state === 'provider_review']],
                'meeting_scheduled_for' => $state === 'in_progress' ? now()->addDays(3)->setTime(14, 0) : null,
                'meeting_mode' => $state === 'in_progress' ? 'online' : null,
                'meeting_purpose' => $state === 'in_progress' ? 'Confirm the operational hand-off.' : null,
                'meeting_status' => $state === 'in_progress' ? 'requested' : null,
                'fulfilled_at' => $state === 'provider_review' ? now()->subHour() : null,
                'fulfilled_by' => $state === 'provider_review' ? $officer->id : null,
            ]);
            app(ProviderServiceLifecycleService::class)->initialize($purchase);
            if ($paid) $purchase->lifecycleEvents()->create(['actor_id' => $billing->id, 'event_type' => 'demo_payment_confirmed',
                'payment_from' => 'pending', 'payment_to' => 'paid', 'delivery_to' => $state, 'action_owner' => 'platform',
                'metadata' => ['demo' => true, 'no_real_transaction' => true], 'created_at' => now()->subDays(4)]);
            if ($state === 'provider_review') {
                $file = Document::make(Catalog::DIRECTORY.'/services/'.$purchase->id.'/operating-guide.pdf', 'Demonstration operating guide', [
                    $provider->provider_name, 'Applicant review, scheduling, monitoring, and release hand-offs.',
                    'Delivery is ready for provider confirmation. The tester chooses whether to accept it.',
                ]);
                $purchase->files()->create([...$file, 'uploaded_by' => $officer->id, 'category' => 'deliverable', 'visible_to_provider' => true]);
            }
        }
    }

    private function guide(): void
    {
        $accounts = User::with(['parentAccount', 'adminProfile', 'providerProfile', 'studentProfile'])->orderBy('id')->get();
        $scenarios = [];
        foreach (Catalog::applicants() as $record) {
            $application = $this->applications[$record['scenario']] ?? null;
            $learner = $accounts->firstWhere('username', $record['username']);
            $scenarios[] = [...$record, 'user_id' => $learner->id, 'application_id' => $application?->id,
                'program_id' => $application?->scholarship_id, 'stage' => $application?->workflow_stage,
                'href' => $application ? '/dashboard/applications/'.$application->id
                    : ($record['scenario'] === 'apply' ? '/dashboard/scholarships/'.$this->programs['primary']->id : '/dashboard/profile')];
        }
        $manifest = ['catalog' => Catalog::DIRECTORY, 'users' => $accounts->count(), 'programs' => count($this->programs),
            'scenarios' => $scenarios, 'accounts' => $accounts->map(fn ($user) => [
                'username' => $user->username, 'role' => $user->role, 'title' => $user->account_title,
                'organization' => $user->isProvider() ? $user->provider_name : null,
                'owner' => $user->parentAccount?->username, 'must_reset_password' => $user->must_reset_password,
            ])->all()];
        Storage::disk('local')->put(Catalog::DIRECTORY.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $lines = ['# Fresh Workflow Demonstration', '', 'All organizations, people, documents, and payment records are fictional. Applicant photos are neutral silhouettes.',
            '', 'Demo password: `password123` (unless explicitly overridden during seeding). Change shared credentials after the demonstration.',
            '', '## Demonstration Order', '', 'Each account stops at a tester-controlled next action. Earlier successful decisions are prerequisites, not decisions made for the tester at the current stage.',
            '', '| Applicant Login | Name | Next Action | Acting Account |', '|---|---|---|---|'];
        foreach ($scenarios as $record) $lines[] = '| `'.$record['username'].'` | '.$record['first_name'].' '.$record['last_name'].' | '.$record['next_action'].' | `'.$record['actor'].'` |';
        $lines = [...$lines, '', '## Provider And Admin Accounts', '', '| Login | Role | Organization / Purpose |', '|---|---|---|'];
        foreach ($accounts->whereIn('role', ['admin', 'provider']) as $account) {
            $purpose = $account->isAdmin() ? 'Platform administration' : $account->provider_name;
            if ($account->username === 'dalisay') $purpose .= ' - organization evidence awaits admin approval';
            if ($account->must_reset_password) $purpose .= ' - first-login password setup';
            $lines[] = '| `'.$account->username.'` | '.($account->account_title ?: 'Super admin').' | '.$purpose.' |';
        }
        $lines = [...$lines, '', '## Additional Work Queues', '', '- `platform.reviews`: applicant profile, provider organization, and scholarship publication reviews.',
            '- `platform.accounts`: managed accounts, first-login setup, and account controls.',
            '- `marilag.programs`: a complete draft and a program awaiting publication review.',
            '- `marilag.selection`: unassigned activities, schedule acknowledgements, session completion, and result recording.',
            '- `marilag.monitoring`: pending uploads, submitted evidence, and a ready-to-close case.',
            '- `marilag.recipients`: agreement tracking and the support renewal/completion decision.',
            '- `marilag.releases`: first release setup, distribution recording, receipt confirmation, and a mentoring announcement.',
            '- `marilag.support` and `platform.support`: open questions and a privacy information request.',
            '- `marilag.billing` and `platform.billing`: service intake, unpaid checkout, assignment, delivery, meeting review, and provider acceptance.',
            '- `platform.finance`: clearly labelled simulated paid-service records; no real payments were made.',
            '- `platform.records`: fresh audit history and exports. `platform.manager` and `superadmin`: platform oversight.',
            '- `silayan.*`: separate organization roles and a separate application review queue.',
            '', 'One-time awards have monitoring policy None. Recurring support has a plan, checklist, and non-overdue check-in.',
            'No failed, rejected, withdrawn, terminated, or waitlisted scenario is seeded. Testers may create those outcomes themselves.',
            'New staff logins ending in `.newstaff` intentionally open first-login password setup.',
            '', 'Registration can also be demonstrated from scratch; no demo data is needed to register a new account.', ''];
        if (! app()->environment('testing')) {
            File::ensureDirectoryExists(base_path('docs'));
            File::put(base_path('docs/fresh-workflow-demo.md'), implode("\n", $lines));
        }
    }

    private function earlier(int $days, callable $callback): mixed
    {
        return Carbon::withTestNow(now()->subDays($days), $callback);
    }

    private function logo(string $key, string $mark, int $variant): string
    {
        $path = 'images/'.Catalog::DIRECTORY.'/'.$key.'.svg';
        File::ensureDirectoryExists(dirname(public_path($path)));
        $shapes = ['<path d="M26 65V35L50 21L74 35V65L50 79Z" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<circle cx="50" cy="50" r="30" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<path d="M20 50L50 20L80 50L50 80Z" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<path d="M24 76V24H76V76Z" fill="none" stroke="#fbbf24" stroke-width="5"/>'];
        File::put(public_path($path), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#0f172a"/>'.$shapes[$variant % 4].'<text x="50" y="56" text-anchor="middle" font-family="Georgia,serif" font-size="18" font-weight="bold" fill="white">'.$mark.'</text></svg>');
        return '/'.$path;
    }
}
