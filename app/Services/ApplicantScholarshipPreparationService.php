<?php

namespace App\Services;

use App\Models\ApplicantVerificationDocument;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ApplicantScholarshipPreparationService
{
    public function __construct(
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    public function withEvidenceReadiness(User $user, array $profile): array
    {
        $missing = ApplicantVerificationDocument::missingRequiredTypesFor($user);

        return [...$profile, 'missing_evidence' => collect(ApplicantVerificationDocument::requirementsFor($user))
            ->whereIn('value', $missing)
            ->map(fn (array $item): array => ['key' => $item['value'], 'label' => $item['label']])
            ->values()->all()];
    }

    public function documentReadiness(Scholarship $scholarship, User $user): array
    {
        $readiness = $this->eligibilityService->preparedDocumentReadiness($scholarship, $user);
        $required = array_values(array_unique($readiness['required_documents']));
        // Submission checks actual files, not just saved document names.
        $available = $required === [] ? collect() : $user->studentDocuments
            ->filter(fn ($document): bool => in_array($document->document_name, $required, true)
                && filled($document->path) && Storage::disk('local')->exists($document->path))
            ->pluck('document_name');
        $matched = array_values(array_filter($required, fn (string $name): bool => $available->contains($name)));

        return [
            'required' => count($required),
            'uploaded' => count($matched),
            'percent' => $required === [] ? 100 : (int) round(count($matched) / count($required) * 100),
            'required_documents' => $required,
            'matched' => $matched,
            'missing' => array_values(array_diff($required, $matched)),
        ];
    }

    public function build(
        Scholarship $scholarship,
        array $decision,
        array $profile,
        array $eligibility,
        array $documents,
        bool $emailVerified,
    ): array {
        $section = fn (string $name): string => route('dashboard.scholarships.show.section', [
            'scholarship' => $scholarship, 'section' => $name,
        ], false);
        $questions = collect($this->questions($scholarship));
        $requiredQuestions = $questions->filter(fn (array $question): bool => (bool) ($question['required'] ?? false))->count();
        $hasApplied = (bool) ($decision['has_applied'] ?? false);
        $canStart = (bool) ($decision['can_apply'] ?? false);
        $missingFiles = count($documents['missing'] ?? []);
        $requiredFiles = count($documents['required_documents'] ?? []);
        $steps = [
            $this->profileStep($profile),
            $this->eligibilityStep($eligibility, $section('eligibility')),
            [
                'key' => 'requirements',
                'title' => 'Prepare application requirements',
                'status' => $missingFiles > 0 ? 'needs_files' : ($requiredFiles > 0 ? 'saved' : 'not_required'),
                'status_label' => $missingFiles > 0 ? 'Files needed' : ($requiredFiles > 0 ? 'Files saved' : 'No initial files'),
                'tone' => $missingFiles > 0 ? 'attention' : 'neutral',
                'description' => $missingFiles > 0
                    ? 'Prepare the missing required files before submitting. You can also upload them in the application form.'
                    : ($requiredFiles > 0
                        ? 'Your saved files can be reused in the form. Saved does not mean attached or approved.'
                        : 'No files are needed for the initial application. Complete any provider questions in the form.'),
                'required_before' => 'submission',
                'blocks_start' => false,
                'action' => $this->link('View application requirements', $section('application'), 'application'),
                'details' => [
                    'required_file_count' => $requiredFiles,
                    'saved_file_count' => count($documents['matched'] ?? []),
                    'missing_file_count' => $missingFiles,
                    'missing_files' => $documents['missing'] ?? [],
                    'question_count' => $questions->count(),
                    'required_question_count' => $requiredQuestions,
                    'questions_completed_in' => 'application_form',
                    'manage_files_href' => '/dashboard/documents',
                ],
            ],
            [
                'key' => 'submit',
                'title' => 'Review and submit for pre-screening',
                'status' => $hasApplied ? 'submitted' : (! $emailVerified ? 'email_unverified' : 'in_application'),
                'status_label' => $hasApplied ? 'Submitted' : (! $emailVerified ? 'Verify email' : 'In application form'),
                'tone' => $hasApplied ? 'positive' : (! $emailVerified ? 'attention' : 'neutral'),
                'description' => $hasApplied
                    ? 'Open your application for its current stage and next action.'
                    : (! $emailVerified
                        ? 'Verify your email using the account reminder before submitting an application.'
                        : 'Confirm your files, answer required questions, and accept the application terms. Submission starts pre-screening; it is not a scholarship award.'),
                'required_before' => 'submission',
                'blocks_start' => false,
                'action' => $hasApplied
                    ? $decision['action']
                    : (! $emailVerified
                        ? $this->link('Review email verification', '/dashboard')
                        : ($canStart ? $decision['action'] : $this->disabled('Start pre-screening'))),
                'details' => ['email_verified' => $emailVerified, 'terms_acceptance_required' => true],
            ],
        ];

        $nextStep = match ($decision['state']) {
            'profile_incomplete', 'profile_unverified' => 'profile',
            'blocked' => 'eligibility',
            'eligible' => $missingFiles > 0 ? 'requirements' : 'submit',
            'applied' => 'submit',
            default => null,
        };
        $nextAction = $nextStep === null ? $decision['action'] : collect($steps)->firstWhere('key', $nextStep)['action'];

        return [
            'version' => 1,
            'mode' => 'live',
            'state' => $decision['state'],
            // This mirrors the existing start decision, not permission to submit a completed form.
            'can_start_application' => $canStart,
            'has_applied' => $hasApplied,
            'next_step' => $nextStep,
            'next_action' => $nextAction,
            'steps' => $steps,
            'submission' => [
                'revalidated_on_submit' => true,
                'email_verified' => $emailVerified,
                'required_question_count' => $requiredQuestions,
                'terms_acceptance_required' => true,
                'note' => 'Starting does not submit an application or reserve a place. Availability, verification, required eligibility, files, answers, and consent are checked again when you submit.',
            ],
        ];
    }

    public function preview(Scholarship $scholarship, array $decision, string $scenario): array
    {
        $sample = $scenario !== 'general';
        $documents = $this->eligibilityService->preparedDocumentReadiness($scholarship, null);
        if ($sample) {
            $documents['matched'] = $documents['required_documents'];
            $documents['missing'] = [];
        }
        $preparation = $this->build($scholarship, $decision, [
            'complete' => $sample, 'verified' => $sample,
            'verification_status' => $sample ? 'approved' : 'unsubmitted', 'missing' => [],
        ], [
            'is_eligible' => $scenario !== 'ineligible',
            'blocking_criteria' => $scenario === 'ineligible' ? [['label' => 'Sample required rule']] : [],
            'criteria' => [],
        ], $documents, $sample);
        $preparation['mode'] = 'preview';
        $preparation['can_start_application'] = false;
        $preparation['steps'] = array_map(function (array $step) use ($scholarship, $scenario, $sample): array {
            if (! $sample) {
                $step['status'] = 'not_compared';
                $step['status_label'] = 'Applicant-specific';
                $step['tone'] = 'neutral';
                $step['description'] = 'Applicants see their own progress and next action here.';
            }
            $step['action'] = isset($step['action']['section'])
                ? $this->link($step['action']['label'], route('provider.previews.programs.section', [
                    'scholarship' => $scholarship, 'section' => $step['action']['section'], 'scenario' => $scenario,
                ], false), $step['action']['section'])
                : $this->disabled($step['action']['label']);
            $step['blocks_start'] = false;
            if ($step['key'] === 'requirements') {
                $step['details']['manage_files_href'] = null;
                if (! $sample) {
                    $step['details']['saved_file_count'] = null;
                    $step['details']['missing_file_count'] = null;
                    $step['details']['missing_files'] = [];
                }
            }

            return $step;
        }, $preparation['steps']);
        $preparation['next_action'] = $this->disabled('Applicant action');
        $preparation['submission']['email_verified'] = $sample ? true : null;
        $preparation['submission']['note'] = 'Read-only preview. Sample progress is illustrative and does not use the provider account as an applicant.';

        return $preparation;
    }

    public function questions(Scholarship $scholarship): array
    {
        return collect($scholarship->application_questions ?? [])
            ->filter(fn ($question): bool => is_array($question) && filled($question['id'] ?? null) && filled($question['prompt'] ?? null))
            ->map(fn (array $question): array => [
                'id' => (string) $question['id'],
                'prompt' => (string) $question['prompt'],
                'required' => (bool) ($question['required'] ?? false),
            ])->values()->all();
    }

    private function profileStep(array $profile): array
    {
        $complete = (bool) ($profile['complete'] ?? false);
        $verified = (bool) ($profile['verified'] ?? false);
        $status = match (true) {
            ! $complete => 'incomplete',
            $verified => 'verified',
            ($profile['verification_status'] ?? null) === 'rejected' => 'changes_required',
            ($profile['missing_evidence'] ?? []) !== [] => 'evidence_missing',
            ($profile['verification_status'] ?? null) === 'pending' => 'pending',
            default => 'unverified',
        };
        [$label, $description] = match ($status) {
            'incomplete' => ['Details needed', 'Complete the required profile details, then submit supporting evidence for verification.'],
            'verified' => ['Verified', 'Your profile is complete and verified. The provider still reviews your application for this program.'],
            'pending' => ['Waiting for review', 'Your supporting evidence is awaiting review. You can prepare application requirements while you wait.'],
            'changes_required' => ['Changes requested', 'Read the reviewer feedback and replace the requested supporting evidence.'],
            'evidence_missing' => ['Evidence needed', 'Upload the missing required supporting records so your profile can be reviewed.'],
            default => ['Evidence needed', 'Upload the required supporting evidence and wait for an authorized reviewer to verify your profile.'],
        };

        return [
            'key' => 'profile', 'title' => 'Complete and verify your profile',
            'status' => $status, 'status_label' => $label,
            'tone' => $complete && $verified ? 'positive' : 'attention',
            'description' => $description, 'required_before' => 'start',
            'blocks_start' => ! $complete || ! $verified,
            'action' => $this->link($complete ? 'View supporting evidence' : 'Complete profile', $complete ? '/dashboard/profile?section=verification' : '/dashboard/profile'),
            'details' => [
                'missing_fields' => $profile['missing'] ?? [],
                'missing_evidence' => $profile['missing_evidence'] ?? [],
                'verification_status' => $profile['verification_status'] ?? 'unsubmitted',
            ],
        ];
    }

    private function eligibilityStep(array $eligibility, string $href): array
    {
        $blockers = $eligibility['blocking_criteria'] ?? [];
        $missing = collect($eligibility['criteria'] ?? [])->where('category', 'eligibility')->where('status', 'missing')->count();
        $review = count($eligibility['review_criteria'] ?? []);
        $preferred = count($eligibility['preferred_mismatches'] ?? []);
        [$status, $label, $description] = match (true) {
            $blockers !== [] => ['blocked', 'Required rules not met', 'Review the required rules that do not match. Only correct your profile if its saved information is inaccurate.'],
            $review > 0 => ['needs_review', 'Provider review needed', 'Some comparisons need provider review; they are not confirmed matches and do not block starting.'],
            $missing > 0 => ['incomplete', 'Comparison incomplete', 'Add missing information for a complete comparison. Profile verification is a separate requirement.'],
            $preferred > 0 => ['preferences_differ', 'Preferences differ', 'Some provider preferences differ. They do not block an application.'],
            default => ['ready', 'No required mismatches', 'No required rule mismatches were found. This is not a guarantee of selection.'],
        };

        return [
            'key' => 'eligibility', 'title' => 'Check program eligibility',
            'status' => $status, 'status_label' => $label,
            'tone' => $blockers !== [] ? 'blocked' : ($status === 'ready' ? 'positive' : 'attention'),
            'description' => $description, 'required_before' => 'start',
            'blocks_start' => ! ($eligibility['is_eligible'] ?? false),
            'action' => $this->link('View eligibility comparison', $href, 'eligibility'),
            'details' => [
                'required_mismatch_count' => count($blockers), 'preferred_difference_count' => $preferred,
                'missing_comparison_count' => $missing, 'provider_review_count' => $review,
            ],
        ];
    }

    private function link(string $label, string $href, ?string $section = null): array
    {
        return array_filter(['kind' => 'link', 'label' => $label, 'href' => $href, 'section' => $section, 'disabled' => false], fn ($value): bool => $value !== null);
    }

    private function disabled(string $label): array
    {
        return ['kind' => 'disabled', 'label' => $label, 'href' => null, 'disabled' => true];
    }
}
