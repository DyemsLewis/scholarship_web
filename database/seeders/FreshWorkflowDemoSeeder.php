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
use App\Services\RecipientBenefitPayoutService;
use App\Services\RecipientMonitoringCaseService;
use App\Services\ScholarshipEligibilityService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\WorkflowDemoCatalog as Catalog;
use Database\Seeders\Support\WorkflowDemoDocument as Document;
use Database\Seeders\Support\WorkflowDemoTimeline as Timeline;
use Database\Seeders\Support\WorkflowDemoTimelineAudit;
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
    protected Timeline $timeline;
    protected array $staff = [];
    protected array $programs = [];
    protected array $applications = [];
    private array $sessions = [];
    private int $releaseSlot = 0;

    public function run(): void
    {
        if (User::query()->exists()) {
            throw new RuntimeException('Fresh workflow demonstration requires an empty database. It never deletes existing records itself.');
        }
        if (app()->environment('production') && getenv('DEMO_SEED_CONFIRM') !== Catalog::CONFIRMATION) {
            throw new RuntimeException('The production demo rebuild requires explicit one-time confirmation.');
        }
        $this->timeline = new Timeline;
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
        $root = $this->account(Catalog::ROOT, 'admin');
        $root->adminProfile()->create(['first_name' => 'Demo', 'last_name' => 'Administrator', 'display_name' => 'Demo Platform Administrator', 'contact_number' => '09000001000']);
        $this->staff[Catalog::ROOT] = $root;
        foreach (AdminRoleCatalog::definitions() as $key => $definition) {
            if ($key === AdminRoleCatalog::SUPER_ADMIN) continue;
            $suffix = ['account_manager' => 'accounts', 'review_officer' => 'reviews', 'support_officer' => 'support', 'billing_officer' => 'billing', 'finance_officer' => 'finance', 'records_officer' => 'records', 'portal_manager' => 'manager'][$key];
            $username = Catalog::ADMIN.'.'.$suffix;
            $user = $this->account($username, 'admin', $root, $definition['title'], $definition['permissions']);
            $user->adminProfile()->create(['first_name' => 'Platform', 'last_name' => Str::headline($suffix), 'display_name' => $definition['title'], 'contact_number' => '09000001001']);
            $this->staff[$username] = $user;
        }
        $primary = $this->provider(Catalog::PRIMARY, 'Talunaw Learning Trust', 'TLT', $root);
        $partner = $this->provider(Catalog::PARTNER, 'Sidlakbay Education Guild', 'SEG', $root);
        $this->provider(Catalog::PENDING, 'Hirayalon Study Foundation', 'HSF', $root, false);
        $rounds = [
            'intake' => ['Talunaw Campus Pathways Grant', 'TCP'],
            'screening' => ['Talunaw Classroom Bridge Grant', 'TCB'],
            'exam' => ['Talunaw Applied Science Scholarship', 'TAS'],
            'interview' => ['Talunaw Community Leadership Scholarship', 'TCL'],
            'decision' => ['Talunaw Senior Scholars Award', 'TSS'],
            'support' => ['Talunaw Continuing Studies Scholarship', 'TCS'],
            'one_time' => ['Talunaw Learning Kit Award', 'TLK'],
            'draft' => ['Talunaw Skills Lab Grant', 'TSL', 'draft'],
            'review' => ['Talunaw Digital Horizons Award', 'TDH', 'pending_review'],
            'partner_intake' => ['Sidlakbay Study Access Grant', 'SSA'],
            'partner_screening' => ['Sidlakbay School Essentials Award', 'SSE'],
            'partner_draft' => ['Sidlakbay Learning Routes Grant', 'SLR', 'draft'],
        ];
        foreach ($rounds as $round => [$title, $mark]) {
            $provider = str_starts_with($round, 'partner_') ? $partner : $primary;
            $this->programs[$round] = $this->program($provider, $round, $title, $mark, count($this->programs), $rounds[$round][2] ?? 'published');
        }
        $this->team($primary, $this->programs['draft']->id);
        $this->team($partner, $this->programs['partner_draft']->id);

        foreach (Catalog::applicants() as $index => $record) {
            $scenario = $record['scenario'];
            $learner = $this->applicant($record, $index + 1, $this->staff[Catalog::ADMIN.'.reviews']);
            if ($round = Catalog::roundFor($scenario)) {
                $this->applications[$scenario] = $this->application($learner, $round, $scenario);
            }
        }
        $this->monitoring($primary);
        foreach (['release.record', 'release.confirm', 'onetime.record', 'onetime.confirm'] as $scenario) {
            $this->release($this->applications[$scenario], str_ends_with($scenario, '.confirm'));
        }
        $this->release($this->applications['payout.details'], false, RecipientBenefitPayoutService::BANK);
        $this->release($this->applications['payout.review'], false, RecipientBenefitPayoutService::WALLET, 'submitted');
        $this->release($this->applications['payout.transfer'], false, RecipientBenefitPayoutService::BANK, 'approved');
        $this->support($primary);
        $this->services($primary);
        foreach ($this->applications as $application) {
            app(DecisionSupportService::class)->syncApplication($application->fresh(), Catalog::DIRECTORY);
            ActivityLog::record($application->assignedReviewer, 'application_demo_prepared',
                'Application prepared at '.$application->workflow_stage.' with the current outcome left to the tester.',
                metadata: ['application_id' => $application->id, 'program_id' => $application->scholarship_id, 'demo' => true]);
        }
        foreach ([Catalog::PRIMARY, Catalog::PARTNER] as $username) {
            ActivityLog::record($this->staff[Catalog::ADMIN.'.reviews'], 'provider_verified', 'Organization verification completed for '.$this->staff[$username]->provider_name.'.',
                metadata: ['provider_id' => $this->staff[$username]->id, 'demo' => true]);
        }
        ActivityLog::record($root, 'demo_workflows_prepared', 'Fresh demonstration created with tester-controlled next actions.', metadata: ['catalog' => Catalog::DIRECTORY]);
        WorkflowDemoTimelineAudit::assertValid();
        $this->manifest();
        if ((int) $root->id !== 1) throw new RuntimeException('The fresh demonstration did not restart account IDs at 1.');
    }

    protected function account(string $username, string $role, ?User $parent = null, ?string $title = null, ?array $permissions = null): User
    {
        $user = User::query()->create([
            'username' => $username, 'email' => $username.'@portal-demo.test', 'role' => $role,
            'parent_account_id' => $parent?->id, 'account_title' => $title, 'permissions' => $permissions,
            'password' => getenv('WORKFLOW_DEMO_PASSWORD') ?: 'password123', 'account_status' => 'active',
            'must_reset_password' => false, 'terms_accepted_at' => $this->timeline->at(-100),
            'privacy_accepted_at' => $this->timeline->at(-100), 'terms_version' => Terms::VERSION,
            'created_at' => $this->timeline->at(-100),
        ]);
        $user->forceFill(['email_verified_at' => $this->timeline->at(-100, 10)])->save();
        return $user;
    }

    private function provider(string $username, string $name, string $mark, User $admin, bool $approved = true): User
    {
        $user = $this->account($username, 'provider', title: 'Organization Representative', permissions: User::PROVIDER_PERMISSIONS);
        $user->providerProfile()->create([
            'first_name' => match ($username) { Catalog::PRIMARY => 'Taren', Catalog::PARTNER => 'Silaren', Catalog::PENDING => 'Hiravel' },
            'last_name' => 'Demo Representative', 'contact_number' => '09000002000', 'representative_position' => 'Program Director',
            'provider_name' => $name, 'provider_type' => 'foundation', 'provider_website' => 'https://example.org/'.$username,
            'provider_address' => 'Antipolo City, Rizal (demo venue)', 'latitude' => 14.5864, 'longitude' => 121.1753,
            'provider_description' => 'A fictional organization prepared for hands-on scholarship workflow demonstrations. No real organization is represented.',
            'mission' => 'Make education support understandable, verifiable, and accountable.', 'year_established' => 2017,
            'service_area' => 'Rizal and Metro Manila', 'logo_path' => $this->logo('providers/'.$username, $mark, 0),
            'cover_image_source' => 'default', 'cover_image_key' => 'campus-community',
            'provider_contact_email' => $user->email, 'provider_contact_number' => '09000002000',
            'contact_department' => 'Scholarship Desk', 'office_hours' => 'Monday to Friday, 9 AM to 5 PM',
            'legal_name' => $name.' (Fictional Demo)', 'registration_authority' => 'DEMO registry',
            'registration_number' => 'DEMO-'.strtoupper($username), 'registration_date' => '2017-06-01',
            'verification_status' => $approved ? 'approved' : 'pending', 'verified_by' => $approved ? $admin->id : null,
            'verified_at' => $approved ? $this->timeline->at(-90) : null, 'created_at' => $this->timeline->at(-99),
        ]);
        foreach (['organization_registration', 'representative_id', 'authority_letter'] as $type) {
            $file = Document::make(Catalog::DIRECTORY.'/providers/'.$username.'/'.$type.'.pdf', Str::headline($type), [$name, 'Representative: '.$user->fresh()->name, 'Reference: DEMO-'.strtoupper($username), 'For tester review only.']);
            $uploaded = $this->timeline->at($approved ? -91 : -3);
            $user->providerVerificationDocuments()->create([...$file, 'uploaded_by' => $user->id, 'document_type' => $type,
                'status' => $approved ? 'approved' : 'submitted', 'uploaded_at' => $uploaded,
                'reviewed_by' => $approved ? $admin->id : null, 'reviewed_at' => $approved ? $this->timeline->at(-90) : null,
                'terms_accepted_at' => $uploaded, 'terms_version' => Terms::VERSION]);
        }
        $this->staff[$username] = $user->fresh('providerProfile');
        return $this->staff[$username];
    }

    private function team(User $owner, int $draftId): void
    {
        $operationalIds = collect($this->programs)->where('provider_id', $owner->id)->where('status', 'published')->pluck('id')->all();
        $coordinatorIds = [...$operationalIds, $draftId, ...($owner->username === Catalog::PRIMARY ? [$this->programs['review']->id] : [])];
        foreach (Catalog::providerRoles() as $suffix => [$title, $permission]) {
            $username = $owner->username.'.'.$suffix;
            $user = $this->account($username, 'provider', $owner, $title, $permission ? [$permission] : User::PROVIDER_PERMISSIONS);
            $user->update(['assigned_program_ids' => in_array($suffix, ['manager', 'profile', 'billing'], true) ? null
                : ($suffix === 'programs' ? $coordinatorIds : $operationalIds)]);
            $profile = $owner->providerProfile->only(['provider_name', 'provider_type', 'provider_address', 'latitude', 'longitude', 'logo_path', 'provider_contact_email', 'provider_contact_number', 'verification_status', 'verified_by', 'verified_at']);
            $user->providerProfile()->create([...$profile, 'first_name' => ucfirst($owner->username), 'last_name' => Str::headline($suffix), 'contact_number' => '09000002001']);
            $this->staff[$username] = $user;
        }
        $setup = $this->account($owner->username.'.newstaff', 'provider', $owner, 'Support Staff', ['manage_reports']);
        $setup->update(['must_reset_password' => true, 'assigned_program_ids' => $operationalIds]);
        $setup->providerProfile()->create(['first_name' => 'New', 'last_name' => 'Team Member', 'provider_name' => $owner->provider_name, 'verification_status' => 'approved']);
    }

    protected function program(User $provider, string $round, string $title, string $mark, int $variant, string $status): Scholarship
    {
        $oneTime = $round === 'one_time';
        $dates = $this->timeline->intake($round);
        $program = Scholarship::query()->create([
            'provider_id' => $provider->id, 'image_path' => $this->logo('programs/'.Str::slug($title), $mark, $variant),
            'cover_image_source' => 'default', 'cover_image_key' => $oneTime ? 'student-workshop' : 'learning-together',
            'title' => $title, 'category' => 'Financial assistance', 'program_cycle' => 'School Year '.$this->schoolYear(),
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
            'handoff_location_name' => $provider->provider_name.' Learning Center',
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
            'contact_email' => $provider->email, 'contact_number' => '09000002000', 'contact_person' => $provider->name,
            'contact_department' => 'Scholarship Desk', ...$dates,
            'status' => $status, 'provider_terms_accepted_at' => $dates['created_at'], 'provider_terms_version' => Terms::VERSION,
        ]);
        $program->benefits()->create(['type' => 'allowance', 'title' => $oneTime ? 'Study-start award' : 'Term learning allowance',
            'amount' => $oneTime ? 5000 : 9000, 'coverage' => 'fixed', 'frequency' => $oneTime ? 'one_time' : 'per_term',
            'duration' => $oneTime ? 'Once' : 'Two semesters', 'description' => 'Study expenses and learning materials.', 'sort_order' => 1]);
        if (! $oneTime) {
            $program->benefits()->create(['type' => 'mentorship', 'title' => 'College pathway mentoring', 'frequency' => 'per_term', 'description' => 'A scheduled provider-led mentoring session.', 'sort_order' => 2]);
        }
        return $program->fresh(['benefits', 'provider.providerProfile']);
    }

    protected function applicant(array $record, int $number, User $reviewer): User
    {
        $user = $this->account($record['username'], 'applicant');
        $approved = ! in_array($record['scenario'], ['profile', 'verification'], true);
        $photo = Catalog::DIRECTORY.'/avatars/'.$user->id.'.jpg';
        $source = public_path('images/demo/default-applicant-avatar.jpg');
        if (! is_file($source)) throw new RuntimeException('The neutral demo avatar is missing.');
        Storage::disk('local')->put($photo, file_get_contents($source));
        $user->studentProfile()->create([
            'first_name' => $record['first_name'], 'last_name' => $record['last_name'], 'middle_initial' => null,
            'gender' => $number % 2 ? 'female' : 'male', 'contact_number' => sprintf('0900003%04d', $number),
            'account_managed_by' => 'learner', 'citizenship_status' => 'filipino', 'birthdate' => $this->timeline->anchor->subYears(19)->toDateString(),
            'education_level' => 'senior_high_school', 'school' => 'Luntag River Senior High School',
            'school_type' => 'public', 'learner_reference_number' => sprintf('00007000%04d', $number),
            'course_or_strand' => $record['scenario'] === 'apply' ? 'HUMSS' : 'STEM', 'year_level' => 'Grade 12',
            'enrollment_status' => 'Enrolled', 'academic_year' => $this->schoolYear(), 'academic_term' => 'first_semester',
            'gwa' => 90 + ($number % 6), 'grading_scale' => 'percentage',
            'academic_result_source' => $approved ? 'verified_record' : 'self_declared',
            'income_bracket' => 'PHP 10,000 - 20,000', 'household_size' => 4,
            'current_scholarship_status' => 'none', 'address' => 'Antipolo City, Rizal (city-level demo location)',
            'city' => 'Antipolo City', 'province' => 'Rizal', 'region' => 'CALABARZON',
            'latitude' => 14.5864, 'longitude' => 121.1753, 'guardian_name' => 'Alexis '.$record['last_name'],
            'guardian_relationship' => 'Parent', 'guardian_contact' => sprintf('0900004%04d', $number),
            'guardian_email' => 'guardian.'.$number.'@portal-demo.test', 'guardian_is_account_owner' => false,
            'profile_photo_path' => $photo, 'profile_photo_original_name' => 'neutral-demo-avatar.jpg',
            'profile_photo_mime_type' => 'image/jpeg', 'profile_photo_size' => filesize($source),
            'profile_photo_updated_at' => $this->timeline->at(-86), 'profile_photo_review_status' => $approved ? 'approved' : 'pending',
            'profile_photo_reviewed_by' => $approved ? $reviewer->id : null, 'profile_photo_reviewed_at' => $approved ? $this->timeline->at(-82) : null,
            'verification_status' => $approved ? 'approved' : ($record['scenario'] === 'profile' ? 'unsubmitted' : 'pending'),
            'verified_by' => $approved ? $reviewer->id : null, 'verified_at' => $approved ? $this->timeline->at(-82) : null,
            'scholarship_goal' => 'Continue senior high school and prepare for college.',
            'created_at' => $this->timeline->at(-86),
        ]);
        $user = $user->fresh('studentProfile');
        if ($record['scenario'] !== 'profile') {
            foreach (ApplicantVerificationDocument::requiredTypesFor($user) as $type) {
                $file = $this->learnerDocument($user, $type, 'profile-evidence');
                $user->applicantVerificationDocuments()->create([...$file, 'uploaded_by' => $user->id, 'document_type' => $type,
                    'status' => $approved ? 'approved' : 'submitted', 'uploaded_at' => $this->timeline->at(-84),
                    'terms_accepted_at' => $this->timeline->at(-84), 'terms_version' => Terms::VERSION,
                    'ocr_status' => $type === 'academic_record' ? 'succeeded' : 'not_requested',
                    'ocr_provider' => $type === 'academic_record' ? 'demo_fixture' : null,
                    'ocr_grade' => $type === 'academic_record' ? $user->studentProfile->gwa : null,
                    'ocr_grading_scale' => $type === 'academic_record' ? 'percentage' : null,
                    'ocr_label' => $type === 'academic_record' ? 'General average' : null,
                    'ocr_processed_at' => $type === 'academic_record' ? $this->timeline->at(-84, 10) : null]);
            }
        }
        if ($approved) {
            foreach (self::REQUIREMENTS as $requirement) {
                $file = $this->learnerDocument($user, $requirement, 'document-library');
                $user->studentDocuments()->create([...$file, 'document_name' => $requirement, 'uploaded_at' => $this->timeline->at(-81),
                    'terms_accepted_at' => $this->timeline->at(-81), 'terms_version' => Terms::VERSION]);
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
            'Academic year: '.$this->schoolYear().' / First semester', 'General Average: '.$profile->gwa.'%',
            'Birthdate: '.$profile->birthdate->format('Y-m-d'), 'Enrollment: Enrolled',
            'Residence: '.$profile->address, 'Household income: '.$profile->income_bracket,
            'Parent or guardian: '.$profile->guardian_name, 'Prepared for workflow testing only.',
        ]);
    }

    protected function application(User $learner, string $round, string $scenario): ScholarshipApplication
    {
        $program = $this->programs[$round];
        [$submitted, $screening, $formal, $decision, $accepted] = $this->timeline->progression($round);
        $submittedAt = $this->timeline->at($submitted);
        $screeningAt = $this->timeline->at($screening);
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
            'submitted_at' => $submittedAt, 'terms_accepted_at' => $submittedAt, 'terms_version' => Terms::VERSION,
            'created_at' => $submittedAt,
        ]);
        foreach (self::REQUIREMENTS as $requirement) {
            $file = $this->learnerDocument($learner, $requirement, 'applications/'.$application->id);
            $application->documents()->create([...$file, 'document_name' => $requirement, 'uploaded_by' => $learner->id,
                'uploaded_at' => $submittedAt, 'status' => $isScreening ? 'pending' : 'accepted',
                'reviewed_by' => $isScreening ? null : $reviewer->id, 'reviewed_at' => $isScreening ? null : $screeningAt,
                'terms_accepted_at' => $submittedAt, 'terms_version' => Terms::VERSION]);
        }
        ApplicationStatusHistory::create(['scholarship_application_id' => $application->id, 'changed_by' => $learner->id,
            'to_status' => 'submitted', 'review_notes' => 'Application submitted with complete evidence.', 'changed_at' => $submittedAt]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = Carbon::withTestNow($submittedAt, fn () => $workflow->start($application->fresh()));
        if ($isScreening) return $application;
        $scores = collect(ReviewRubric::DEFAULT)->mapWithKeys(fn ($criterion) => [$criterion['key'] => 92])->all();
        $application->update(['rubric_scores' => $scores, 'rubric_total_score' => ReviewRubric::result(ReviewRubric::DEFAULT, $scores)['total_score'],
            'rubric_scored_by' => $reviewer->id, 'rubric_scored_at' => $screeningAt]);
        $application = Carbon::withTestNow($screeningAt, fn () => $workflow->recordStageResult($application, 'screening', 'passed', $reviewer, 'Earlier screening completed.'));
        if ($scenario === 'formal') return $application->fresh();

        $application = Carbon::withTestNow($this->timeline->at($formal), fn () => $workflow->recordStageResult($application->fresh(), 'formal_application', 'passed', $actor, 'Original school records reviewed.'));
        if ($round !== 'one_time') {
            foreach (['exam', 'interview'] as $stage) {
                if ($round === $stage) {
                    if ($scenario !== $stage.'.assign') $this->session($application, $round, $stage, $scenario);
                    return $application->fresh();
                }
                $session = $this->session($application, $round, $stage, 'prior');
                $application = Carbon::withTestNow($session->completed_at->copy()->addMinutes(30), fn () => $workflow->recordStageResult(
                    $application->fresh(), $stage, 'passed', $actor, ucfirst($stage).' completed successfully.',
                ));
            }
        }
        if ($scenario === 'decision') return $application->fresh();
        $application = Carbon::withTestNow($this->timeline->at($decision), fn () => $workflow->recordFinalOutcome($application->fresh(), 'selected', $this->staff[$prefix.'.decisions'],
            'Prior award selection is the prerequisite for this recipient hand-off demonstration.', 'approved_for_award'));
        $application->update(['awarded_amount' => $program->award_amount]);
        if ($scenario === 'agreement') return $application->fresh();
        $acceptedAt = $this->timeline->at($accepted);
        $application->update(['student_response_status' => 'accepted', 'student_responded_at' => $acceptedAt,
            'student_response_terms_accepted_at' => $acceptedAt, 'student_response_terms_version' => $application->provider_contract_terms_version,
            'provider_contract_terms_accepted_at' => $acceptedAt, 'provider_contract_acceptance_ip' => '127.0.0.1',
            'provider_contract_acceptance_user_agent' => 'Fictional workflow demonstration']);
        Carbon::withTestNow($acceptedAt, fn () => app(RecipientBenefitEntitlementService::class)->activateForAcceptedApplication($application->fresh()));
        return $application->fresh();
    }

    protected function session(ScholarshipApplication $application, string $round, string $stage, string $scenario): ApplicationStageSession
    {
        $program = $application->scholarship;
        $actor = $this->staff[$program->provider->username.'.selection'];
        $completed = $scenario === 'prior' || str_ends_with($scenario, '.result');
        $conducted = $completed || $scenario === 'exam.complete';
        $online = $stage === 'interview';
        $day = $this->timeline->sessionDay($round, $stage, $scenario);
        $starts = $this->timeline->at($day, $online ? 10 : 9);
        $ends = $starts->addMinutes($online ? 30 : 90);
        $published = $this->timeline->at(min(-2, $day - 2), 11);
        $recorded = $ends->addMinutes(15);
        $key = $program->id.'.'.$round.'.'.$stage.'.'.$scenario;
        $session = $this->sessions[$key] ??= ApplicationStageSession::create([
            'scholarship_id' => $application->scholarship_id, 'stage_key' => $stage,
            'title' => ucfirst($stage).' - '.($scenario === 'prior' ? $program->title.' group' : $application->applicant->name),
            'starts_at' => $starts, 'ends_at' => $ends, 'timezone' => Timeline::TIMEZONE,
            'mode' => $online ? 'online' : 'onsite', 'venue' => $online ? null : $program->location_name,
            'location_address' => $online ? null : $program->location_address,
            'latitude' => $online ? null : 14.5864, 'longitude' => $online ? null : 121.1753,
            'meeting_url' => $online ? 'https://example.org/demo-interview-room' : null,
            'capacity' => 20, 'instructions' => 'Fictional demonstration schedule. Bring your school ID; do not visit a real location or join a real meeting.',
            'status' => $completed ? 'completed' : 'scheduled', 'proof_required' => true,
            'published_at' => $published, 'completed_at' => $completed ? $recorded->addMinutes(15) : null,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $assignment = $session->assignments()->create(['scholarship_application_id' => $application->id,
            'status' => $conducted ? 'acknowledged' : 'assigned', 'assigned_by' => $actor->id,
            'assigned_at' => $published, 'acknowledged_at' => $conducted ? $published->addDay() : null]);
        $assignment->attendance()->create(['status' => $conducted ? 'present' : 'pending',
            'recorded_by' => $conducted ? $actor->id : null, 'recorded_at' => $conducted ? $recorded : null]);
        if ($conducted && ! $session->evidence()->exists()) {
            $file = Document::make(Catalog::DIRECTORY.'/sessions/'.$session->id.'/provider-proof.pdf', ucfirst($stage).' participation proof', [
                'Session: '.$session->title, 'Program: '.$program->title,
                'Attendance: present', 'Demo assessment record ready for the provider to choose an outcome.',
            ]);
            $session->evidence()->create([...$file, 'scholarship_id' => $application->scholarship_id, 'stage_key' => $stage,
                'scope' => 'session', 'category' => $online ? 'evaluation' : 'score_sheet', 'disk' => 'local',
                'event_date' => $starts, 'visibility' => 'provider_private', 'is_current' => true, 'uploaded_by' => $actor->id, 'uploaded_at' => $recorded]);
        }
        $application->stageProgresses()->where('stage_key', $stage)->update([
            'operational_status' => $completed ? 'ready_for_review' : 'scheduled', 'proof_required' => true,
        ]);
        $session->auditEvents()->create(['scholarship_id' => $application->scholarship_id,
            'scholarship_application_id' => $application->id, 'application_stage_assignment_id' => $assignment->id,
            'stage_key' => $stage, 'event_type' => 'session_published', 'to_status' => $session->status,
            'actor_id' => $actor->id, 'occurred_at' => $published]);

        return $session;
    }

    private function monitoring(User $provider): void
    {
        $actor = $this->staff[$provider->username.'.monitoring'];
        $program = $this->programs['support'];
        $plan = RecipientMonitoringPlan::create(['scholarship_id' => $program->id,
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'frequency' => 'semester',
            'starts_on' => $program->support_starts_at, 'ends_on' => $program->support_ends_at, 'grace_period_days' => 7,
            'allow_exception_requests' => true, 'instructions' => 'Bring your current grades and enrollment proof to the appointment.',
            'status' => 'active', 'version' => 1, 'activated_at' => $this->timeline->at(-28)]);
        $definitions = [
            ['type' => 'academic_progress', 'title' => 'Current academic record', 'minimum_grade' => 85, 'grading_scale' => 'percentage'],
            ['type' => 'enrollment', 'title' => 'Current enrollment certificate'],
        ];
        foreach ($definitions as $index => $definition) $plan->requirements()->create([
            ...$definition, 'description' => 'Current evidence for the monitoring period.', 'evidence_description' => $definition['title'],
            'required' => true, 'requires_file' => false, 'requires_original_verification' => true, 'sort_order' => $index + 1, 'active' => true,
        ]);
        $this->checkIn($plan, $actor, 5, ['monitoring.appointment']);
        $this->checkIn($plan, $actor, -3, ['monitoring.review', 'monitoring.close', 'support.decision']);
        $this->release($this->applications['monitoring.appointment'], false, 'other');
    }

    protected function checkIn(RecipientMonitoringPlan $plan, User $actor, int $day, array $scenarios): void
    {
        $appointment = $this->timeline->at($day, 14);
        $published = $this->timeline->at(min(-1, $day - 3));
        $reviewed = $appointment->addHour();
        $cycle = RecipientMonitoringCycle::create(['scholarship_id' => $plan->scholarship_id, 'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 1, 'grace_period_days' => 7, 'allow_exception_requests' => true,
            'created_by' => $actor->id, 'title' => $day > 0 ? 'First semester document handoff - upcoming group' : 'First semester document handoff - attended group',
            'period_type' => 'semester', 'academic_period' => 'First semester', 'school_year' => $this->schoolYear(),
            'opens_at' => $appointment->toDateString(), 'due_at' => $appointment->toDateString(),
            'handoff_at' => $appointment, 'handoff_location' => $plan->scholarship->location_name.', Antipolo City, Rizal',
            'address_details' => $this->supportVenueAddress($plan->scholarship),
            'minimum_grade' => 85, 'grading_scale' => 'percentage',
            'instructions' => $plan->instructions, 'status' => 'open', 'published_at' => $published]);
        foreach ($plan->requirements as $requirement) {
            $cycle->requirements()->create([...$requirement->only(['type', 'title', 'description', 'evidence_description', 'required',
                'requires_file', 'requires_original_verification', 'minimum_grade', 'grading_scale', 'sort_order']), 'source_requirement_id' => $requirement->id]);
        }
        foreach ($scenarios as $scenario) {
            $application = $this->applications[$scenario];
            $allMet = in_array($scenario, ['monitoring.close', 'support.decision'], true);
            $isClosed = $scenario === 'support.decision';
            RecipientMonitoringCase::create(['recipient_monitoring_cycle_id' => $cycle->id,
                'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
                'attendance_status' => $day < 0 ? 'attended' : null,
                'status' => $isClosed ? 'closed' : ($allMet ? 'ready_to_close' : ($scenario === 'monitoring.review' ? 'under_review' : 'awaiting_submission')),
                'due_status' => $isClosed ? 'closed' : 'open', 'effective_due_at' => $cycle->due_at,
                'outcome' => $isClosed ? 'completed' : null, 'opened_at' => $cycle->published_at,
                'submitted_at' => $allMet ? $reviewed : null,
                'review_started_at' => $allMet ? $reviewed : null,
                'ready_to_close_at' => $allMet ? $reviewed->addMinutes(15) : null,
                'closed_by' => $isClosed ? $actor->id : null, 'closed_at' => $isClosed ? $reviewed->addMinutes(30) : null,
                'next_action_role' => $isClosed ? null : ($day > 0 ? 'applicant' : 'provider'),
                'next_action' => $isClosed ? null : ($allMet ? 'close_case' : ($day > 0 ? 'attend_handoff' : 'review_requirements'))]);
            foreach ($allMet ? $cycle->requirements : [] as $requirement) {
                $academic = $requirement->type === 'academic_progress';
                $submission = RecipientMonitoringSubmission::create([
                    'recipient_monitoring_cycle_id' => $cycle->id, 'recipient_monitoring_cycle_requirement_id' => $requirement->id,
                    'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
                    'submission_source' => 'provider_record', 'ocr_status' => 'not_requested',
                    'reported_grade' => $academic ? 93 : null, 'reported_grading_scale' => $academic ? 'percentage' : null,
                    'grade_source' => $academic ? 'provider_verified' : null,
                    'submitted_at' => $reviewed, 'review_status' => 'met', 'reviewed_by' => $actor->id, 'reviewed_at' => $reviewed]);
                RecipientMonitoringReview::create(['recipient_monitoring_submission_id' => $submission->id,
                    'reviewed_by' => $actor->id, 'decision' => 'met', 'notes' => 'Original document checked at the appointment.', 'decided_at' => $reviewed]);
            }
            app(RecipientMonitoringCaseService::class)->sync($cycle, $application);
        }
    }

    protected function supportVenueAddress(Scholarship $program): array
    {
        return ['venue' => $program->location_name, 'region' => 'Region IV-A', 'province' => 'Rizal',
            'city' => 'City of Antipolo', 'barangay' => '', 'street' => '',
            'latitude' => (float) $program->latitude, 'longitude' => (float) $program->longitude];
    }

    protected function release(ScholarshipApplication $application, bool $released, string $method = 'in_person', ?string $payoutStatus = null): void
    {
        $program = $application->scholarship;
        $actor = $this->staff[$program->provider->username.'.releases'];
        $mentoring = $method === 'other';
        $digital = app(RecipientBenefitPayoutService::class)->isDigital($method);
        $releaseAt = $mentoring ? $this->timeline->at(8, 14) : $this->timeline->at(-1, 9)->addMinutes(45 * $this->releaseSlot++);
        $publishedAt = $this->timeline->at(-6, 10);
        $benefit = $mentoring ? $program->benefits->firstWhere('type', 'mentorship') : $program->benefits->firstWhere('type', 'allowance');
        $release = RecipientBenefitRelease::create(['scholarship_id' => $program->id, 'created_by' => $actor->id,
            'title' => $benefit->title.' - '.$application->applicant->name, 'release_at' => $releaseAt,
            'benefit_description' => $benefit->description, 'amount' => $benefit->amount, 'release_method' => $mentoring ? 'in_person' : $method,
            'location' => $digital ? null : $program->location_name.', Antipolo City, Rizal', 'latitude' => $digital ? null : 14.5864, 'longitude' => $digital ? null : 121.1753,
            'address_details' => $digital ? null : $this->supportVenueAddress($program),
            'instructions' => $mentoring
                ? 'Attend the mentoring session at the scheduled date and time. Open View map for the venue. Bring your school ID and questions about your college plans. Confirm receipt only after attending the session.'
                : ($digital
                    ? 'Open this release and enter your payout details when requested. The provider verifies the details and sends the benefit outside the portal. Confirm receipt only after checking your account; report a problem if the benefit has not arrived.'
                    : 'Report to the listed venue at the scheduled date and time. Open View map for directions and bring your school ID. Confirm receipt only after receiving the listed benefit.'),
            'requires_original_verification' => false, 'status' => 'scheduled', 'published_at' => $publishedAt]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id, 'status' => 'scheduled', 'originals_verified' => false]);
        $service = app(RecipientBenefitEntitlementService::class);
        Carbon::withTestNow($publishedAt, fn () => $service->allocate($record, new \Illuminate\Database\Eloquent\Collection([$benefit])));
        if ($payoutStatus) {
            $record->payoutDetails()->create([
                'method' => $method,
                'destination' => ['institution' => $method === RecipientBenefitPayoutService::WALLET ? 'Example Wallet (demo)' : 'Example Bank (demo)',
                    'account_holder' => $application->applicant->name,
                    'account_number' => $method === RecipientBenefitPayoutService::WALLET ? '09000000000' : '0000000000'],
                'submitted_at' => $this->timeline->at(-5), 'review_status' => $payoutStatus, 'revision' => 1,
                'reviewed_by' => $payoutStatus === 'approved' ? $actor->id : null,
                'reviewed_at' => $payoutStatus === 'approved' ? $this->timeline->at(-4) : null,
            ]);
        }
        if ($released) {
            $file = Document::make(Catalog::DIRECTORY.'/release-proofs/'.$record->id.'.pdf', 'Benefit distribution proof', [
                'Recipient: '.$application->applicant->name, 'Benefit: '.$benefit->title, 'Amount: PHP '.($benefit->amount ?? 0),
                'Provider distribution recorded. Recipient confirmation is still required.',
            ]);
            $record->update(['status' => 'released', 'originals_verified' => true, 'recorded_by' => $actor->id,
                'recorded_at' => $releaseAt->addMinutes(30), 'released_at' => $releaseAt->addMinutes(30),
                'receipt_original_name' => $file['original_name'], 'receipt_path' => $file['path'], 'receipt_mime_type' => $file['mime_type'], 'receipt_size' => $file['size']]);
            Carbon::withTestNow($releaseAt->addMinutes(30), fn () => $service->syncReleaseResult($record->fresh()));
        }
    }

    private function support(User $provider): void
    {
        $applicant = $this->applications['screening']->applicant;
        SupportReport::create(['applicant_id' => $applicant->id, 'provider_id' => $provider->id, 'scholarship_id' => $this->programs['screening']->id,
            'category' => 'program', 'subject' => 'Question about orientation materials',
            'description' => 'Where can I find the preparation checklist for the recipient orientation?',
            'assigned_role' => 'provider', 'status' => 'open', 'provider_status' => 'open', 'admin_status' => 'not_required']);
        SupportReport::create(['applicant_id' => $this->staff[Catalog::PRIMARY.'.support']->id, 'provider_id' => $provider->id, 'category' => 'data', 'subject' => 'Help choosing the right reporting export',
            'description' => 'Please explain the applicant and recipient export options for the new cycle.',
            'context' => json_encode(['reporter_id' => $this->staff[Catalog::PRIMARY.'.support']->id, 'source' => 'provider_workspace']),
            'assigned_role' => 'admin', 'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
        SupportReport::create(['applicant_id' => $this->applications['formal']->applicant_id, 'category' => 'privacy',
            'privacy_request_type' => 'access_copy', 'subject' => 'Request a copy of my demo profile',
            'description' => 'Please explain how I can obtain a copy of the information saved for this demonstration.',
            'assigned_role' => 'admin', 'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
    }

    private function services(User $provider): void
    {
        $billing = $this->staff[Catalog::PRIMARY.'.billing'];
        $officer = $this->staff[Catalog::ADMIN.'.billing'];
        ProviderServiceRequest::create(['provider_id' => $provider->id, 'created_by' => $billing->id,
            'scholarship_id' => $this->programs['draft']->id, 'plan_code' => 'assisted_setup', 'plan_name' => 'Assisted program setup',
            'amount' => config('billing.plans.assisted_setup.amount'), 'currency' => 'PHP', 'status' => 'ready_for_review',
            'answers' => ['program_stage' => 'review', 'main_blocker' => 'Please review the draft requirements.', 'requested_outcome' => 'A publishing-readiness checklist.'],
            'request_summary' => 'Review the Skills Lab draft before submission.', 'requested_outcome' => 'A publishing-readiness checklist.', 'submitted_at' => $this->timeline->at(-1)]);
        foreach (['payment' => 'queued', 'assignment' => 'ready', 'delivery' => 'in_progress', 'confirmation' => 'provider_review'] as $scenario => $state) {
            $paid = $scenario !== 'payment';
            $purchase = ProviderServicePurchase::create(['provider_id' => $provider->id, 'created_by' => $billing->id,
                'plan_code' => 'application_cycle_support', 'plan_name' => 'Application cycle support',
                'amount' => config('billing.plans.application_cycle_support.amount'), 'currency' => 'PHP',
                'status' => $paid ? 'paid' : 'pending', 'fulfillment_status' => $state,
                'reference_number' => 'DEMO-20261110-'.strtoupper($scenario), 'payment_method' => $paid ? 'demo' : null,
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

    private function manifest(): void
    {
        $accounts = User::with(['parentAccount', 'adminProfile', 'providerProfile', 'studentProfile'])->orderBy('id')->get();
        $scenarios = [];
        foreach (Catalog::applicants() as $record) {
            $application = $this->applications[$record['scenario']] ?? null;
            $learner = $accounts->firstWhere('username', $record['username']);
            $scenarios[] = [...$record, 'user_id' => $learner->id, 'application_id' => $application?->id,
                'program_id' => $application?->scholarship_id ?? ($record['scenario'] === 'apply' ? $this->programs['intake']->id : null),
                'round' => Catalog::roundFor($record['scenario']) ?? ($record['scenario'] === 'apply' ? 'intake' : null),
                'stage' => $application?->workflow_stage,
                'href' => $application ? '/dashboard/applications/'.$application->id
                    : ($record['scenario'] === 'apply' ? '/dashboard/scholarships/'.$this->programs['intake']->id : '/dashboard/profile')];
        }
        $manifest = ['catalog' => Catalog::DIRECTORY, 'anchor_date' => $this->timeline->anchor->toDateString(), 'timezone' => Timeline::TIMEZONE,
            'users' => $accounts->count(), 'programs' => count($this->programs),
            'rounds' => collect($this->programs)->map(fn (Scholarship $program) => [
                'id' => $program->id, 'title' => $program->title, 'status' => $program->status,
                'opens_on' => $program->application_opens_at->toDateString(), 'closes_on' => $program->deadline->toDateString(),
            ])->all(),
            'scenarios' => $scenarios, 'accounts' => $accounts->map(fn ($user) => [
                'username' => $user->username, 'role' => $user->role, 'title' => $user->account_title,
                'organization' => $user->isProvider() ? $user->provider_name : null,
                'owner' => $user->parentAccount?->username, 'must_reset_password' => $user->must_reset_password,
            ])->all()];
        Storage::disk('local')->put(Catalog::DIRECTORY.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function schoolYear(): string
    {
        $start = $this->timeline->anchor->subMonths(5)->year;

        return $start.'-'.($start + 1);
    }

    private function logo(string $key, string $mark, int $variant): string
    {
        $path = 'images/'.Catalog::DIRECTORY.'/'.$key.'.svg';
        $shapes = ['<path d="M26 65V35L50 21L74 35V65L50 79Z" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<circle cx="50" cy="50" r="30" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<path d="M20 50L50 20L80 50L50 80Z" fill="none" stroke="#fbbf24" stroke-width="5"/>', '<path d="M24 76V24H76V76Z" fill="none" stroke="#fbbf24" stroke-width="5"/>'];
        $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#0f172a"/>'.$shapes[$variant % 4].'<text x="50" y="56" text-anchor="middle" font-family="Georgia,serif" font-size="18" font-weight="bold" fill="white">'.$mark.'</text></svg>';
        if (app()->environment('testing')) {
            Storage::disk('local')->put(Catalog::DIRECTORY.'/logos/'.$key.'.svg', $logo);
        } else {
            File::ensureDirectoryExists(dirname(public_path($path)));
            File::put(public_path($path), $logo);
        }
        return '/'.$path;
    }
}
