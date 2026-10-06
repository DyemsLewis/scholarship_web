<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesProviderApplicationQueries;
use App\Http\Controllers\Concerns\HandlesProviderApplicationWorkflow;
use App\Http\Controllers\Concerns\HandlesProviderGovernance;
use App\Http\Controllers\Concerns\HandlesProviderProfileManagement;
use App\Http\Controllers\Concerns\HandlesProviderProgramManagement;
use App\Http\Controllers\Concerns\HandlesProviderRecipientLifecycle;
use App\Http\Controllers\Concerns\HandlesProviderReporting;
use App\Http\Controllers\Concerns\HandlesProviderRoleWorkspaceData;
use App\Http\Controllers\Concerns\HandlesProviderTeamAdministration;
use App\Services\AcademicRecordOcrService;
use App\Services\ApplicationWorkflowService;
use App\Services\ScholarshipEligibilityService;

class ProviderController extends Controller
{
    use HandlesProviderApplicationQueries;
    use HandlesProviderApplicationWorkflow;
    use HandlesProviderGovernance;
    use HandlesProviderProfileManagement;
    use HandlesProviderProgramManagement;
    use HandlesProviderRecipientLifecycle;
    use HandlesProviderReporting;
    use HandlesProviderRoleWorkspaceData;
    use HandlesProviderTeamAdministration;

    public function __construct(
        private readonly ApplicationWorkflowService $workflowService,
        private readonly AcademicRecordOcrService $academicRecordOcrService,
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    private const PROVIDER_TEAM_ROLES = [
        'manager' => 'Manager',
        'program_coordinator' => 'Program coordinator',
        'application_reviewer' => 'Application reviewer',
        'selection_officer' => 'Selection officer',
        'decision_officer' => 'Decision officer',
        'recipient_officer' => 'Recipient officer',
        'monitoring_officer' => 'Monitoring officer',
        'benefit_release_officer' => 'Benefit release officer',
        'organization_profile_manager' => 'Organization profile manager',
        'team_administrator' => 'Team administrator',
        'support_staff' => 'Support staff',
        'billing_staff' => 'Billing staff',
        'custom' => 'Custom role',
    ];

    private const PROVIDER_TEAM_ROLE_PERMISSION_PRESETS = [
        'manager' => [
            'manage_programs',
            'verify_applications',
            'manage_selection_activities',
            'record_final_decisions',
            'manage_recipients',
            'manage_monitoring',
            'manage_benefit_releases',
            'manage_reports',
            'manage_profile',
            'manage_team',
            'manage_billing',
        ],
        'program_coordinator' => ['manage_programs'],
        'application_reviewer' => ['verify_applications'],
        'selection_officer' => ['manage_selection_activities'],
        'decision_officer' => ['record_final_decisions'],
        'recipient_officer' => ['manage_recipients'],
        'monitoring_officer' => ['manage_monitoring'],
        'benefit_release_officer' => ['manage_benefit_releases'],
        'organization_profile_manager' => ['manage_profile'],
        'team_administrator' => ['manage_team'],
        'support_staff' => ['manage_reports'],
        'billing_staff' => ['manage_billing'],
    ];

    private const PROVIDER_PROGRAM_WORKFLOW_PERMISSIONS = [
        'verify_applications',
        'manage_selection_activities',
        'record_final_decisions',
        'manage_recipients',
        'manage_monitoring',
        'manage_benefit_releases',
    ];

    private const PROVIDER_GOVERNANCE_RESPONSIBILITIES = [
        'manage_programs' => [
            'label' => 'Program coordination',
            'description' => 'Builds and maintains scholarship programs.',
            'role' => 'Program coordinator',
        ],
        'verify_applications' => [
            'label' => 'Application verification',
            'description' => 'Checks profiles, eligibility, and evidence.',
            'role' => 'Application reviewer',
        ],
        'manage_selection_activities' => [
            'label' => 'Selection activities',
            'description' => 'Runs formal applications, exams, and interviews.',
            'role' => 'Selection officer',
        ],
        'record_final_decisions' => [
            'label' => 'Final decisions',
            'description' => 'Records selection and waitlist outcomes.',
            'role' => 'Decision officer',
        ],
        'manage_recipients' => [
            'label' => 'Recipient onboarding',
            'description' => 'Handles agreements and recipient support records.',
            'role' => 'Recipient officer',
        ],
        'manage_monitoring' => [
            'label' => 'Recipient monitoring',
            'description' => 'Reviews check-ins, requests, and interventions.',
            'role' => 'Monitoring officer',
        ],
        'manage_benefit_releases' => [
            'label' => 'Benefit releases',
            'description' => 'Records distribution and verifies release proof.',
            'role' => 'Benefit release officer',
        ],
        'manage_reports' => [
            'label' => 'Applicant support',
            'description' => 'Responds to reports and program concerns.',
            'role' => 'Support staff',
        ],
    ];

    private const AWARD_SLOT_STATUSES = [
        'awarded',
        'distribution_scheduled',
        'disbursed',
        'renewed',
    ];

    private const PROVIDER_OBJECTIVES = [
        'education_access',
        'priority_skills',
        'future_talent',
        'community_development',
        'equity_inclusion',
        'academic_excellence',
        'education_partnerships',
        'other',
    ];

    private const RECIPIENT_COMMITMENT_TYPES = [
        'provider_briefing',
        'none',
        'renewal',
        'service',
        'activities',
        'reporting',
        'custom',
    ];

    private const REVIEW_DECISION_STATUSES = [
        'submitted',
        'under_review',
        'qualified',
        'shortlisted',
        'exam_taken',
        'exam_passed',
        'interview',
    ];
}
