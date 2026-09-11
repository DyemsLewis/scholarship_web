<?php

namespace App\Support;

use App\Models\ApplicationDocument;
use App\Models\ScholarshipApplication;

class PreScreeningHandoffRecord
{
    public static function make(
        ScholarshipApplication $application,
        array $workflow,
        array $documentReadiness,
        array $decisionSupport,
    ): ?array {
        $screeningStep = collect($workflow['steps'] ?? [])->firstWhere('key', 'screening');

        if (($screeningStep['status'] ?? null) !== 'passed') {
            return null;
        }

        $application->loadMissing(['applicant.studentProfile', 'documents', 'scholarship']);
        $snapshot = data_get($application->submission_snapshot, 'current.applicant');
        $profile = is_array($snapshot) && $snapshot !== []
            ? $snapshot
            : self::currentProfile($application);
        $requiredFiles = collect($application->document_checklist ?? [])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => self::normalize($name))
            ->values();
        $acceptedFileNames = $application->documents
            ->filter(fn (ApplicationDocument $document): bool => $document->status === 'accepted')
            ->pluck('document_name')
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => self::normalize($name))
            ->values();
        $storedDecisionSupport = is_array($application->dss_breakdown)
            ? $application->dss_breakdown
            : $decisionSupport;
        $verificationStatus = $application->applicant?->applicantAcademicVerificationStatus() ?? 'unsubmitted';
        $nextStep = self::nextStep($application, $workflow);

        return [
            'record_id' => 'PS-'.str_pad((string) $application->id, 6, '0', STR_PAD_LEFT),
            'snapshot_version' => (int) data_get($application->submission_snapshot, 'version', 1),
            'profile_source' => is_array($snapshot) && $snapshot !== []
                ? 'Submitted application snapshot'
                : 'Applicant profile at review',
            'passed_at' => $screeningStep['completed_at'] ?? $application->reviewed_at?->format('M d, Y h:i A'),
            'applicant' => [
                'name' => $profile['name'] ?? $application->applicant?->name,
                'email' => $profile['email'] ?? $application->applicant?->email,
                'contact_number' => $profile['contact_number'] ?? $application->applicant?->contact_number,
                'education' => self::join([
                    self::label($profile['education_level'] ?? null),
                    $profile['course_or_strand'] ?? null,
                    self::label($profile['year_level'] ?? null),
                ]),
                'school' => $profile['school'] ?? null,
                'academic_result' => AcademicRequirement::studentLabel(
                    $profile['gwa'] ?? null,
                    $profile['grading_scale'] ?? null,
                ),
                'location' => $profile['location'] ?? null,
            ],
            'review' => [
                'eligibility_score' => $application->eligibility_score !== null
                    ? (int) round((float) $application->eligibility_score)
                    : null,
                'dss_score' => isset($storedDecisionSupport['score'])
                    ? (int) round((float) $storedDecisionSupport['score'])
                    : null,
                'dss_label' => $storedDecisionSupport['label'] ?? self::label($application->dss_recommendation),
                'required_files' => (int) ($documentReadiness['required'] ?? $requiredFiles->count()),
                'accepted_files' => (int) ($documentReadiness['accepted'] ?? $acceptedFileNames->count()),
                'accepted_file_names' => $acceptedFileNames->all(),
                'academic_verification_status' => $verificationStatus,
                'academic_verification_label' => match ($verificationStatus) {
                    'approved' => 'Academic record verified',
                    'pending' => 'Academic record under review',
                    'rejected' => 'Academic record needs replacement',
                    default => 'Academic record not verified',
                },
            ],
            'decision' => [
                'label' => 'Passed pre-screening',
                'note' => $screeningStep['notes'] ?? 'The provider confirmed that the applicant may continue to the next configured stage.',
            ],
            'next_step' => $nextStep,
            'notice' => 'This record confirms portal pre-screening only. The scholarship provider still controls formal verification and the final scholarship decision.',
        ];
    }

    private static function currentProfile(ScholarshipApplication $application): array
    {
        $profile = $application->applicant?->studentProfile;

        return [
            'name' => $application->applicant?->name,
            'email' => $application->applicant?->email,
            'contact_number' => $application->applicant?->contact_number,
            'education_level' => $profile?->education_level,
            'school' => $profile?->school,
            'course_or_strand' => $profile?->course_or_strand,
            'year_level' => $profile?->year_level,
            'gwa' => $profile?->gwa,
            'grading_scale' => $profile?->grading_scale,
            'location' => self::join([
                $profile?->barangay,
                $profile?->city,
                $profile?->province,
                $profile?->region,
            ]),
        ];
    }

    private static function nextStep(ScholarshipApplication $application, array $workflow): array
    {
        $steps = collect($workflow['steps'] ?? [])->values();
        $screeningIndex = $steps->search(fn (array $step): bool => ($step['key'] ?? null) === 'screening');
        $next = $screeningIndex === false ? null : $steps->get($screeningIndex + 1);
        $stage = $next['key'] ?? 'formal_application';
        $scholarship = $application->scholarship;

        if ($stage === 'formal_application') {
            $mode = $scholarship?->handoff_mode ?: 'provider_contact';

            return [
                'stage' => $stage,
                'label' => 'Continue with the provider',
                'mode' => $mode,
                'mode_label' => match ($mode) {
                    'onsite' => 'On-site formal application',
                    'online' => 'Online formal application',
                    default => 'Provider will make contact',
                },
                'instructions' => $scholarship?->handoff_instructions
                    ?: 'Follow the provider instructions for formal verification and application.',
                'deadline' => $scholarship?->handoff_deadline?->format('M d, Y'),
                'location' => self::join([
                    $scholarship?->handoff_location_name,
                    $scholarship?->handoff_location_address,
                ]),
                'url' => $scholarship?->handoff_url,
            ];
        }

        return [
            'stage' => $stage,
            'label' => $next['label'] ?? self::label($stage),
            'mode' => null,
            'mode_label' => null,
            'instructions' => match ($stage) {
                'exam' => 'Wait for the provider to publish the exam schedule and instructions.',
                'interview' => 'Wait for the provider to publish the interview schedule and instructions.',
                'decision' => 'Wait for the provider to record the final scholarship decision.',
                default => 'Follow the next instructions posted by the scholarship provider.',
            },
            'deadline' => null,
            'location' => null,
            'url' => null,
        ];
    }

    private static function normalize(string $value): string
    {
        return str($value)->lower()->squish()->toString();
    }

    private static function label(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return str($value)->replace('_', ' ')->title()->toString();
    }

    private static function join(array $values): ?string
    {
        $joined = collect($values)->filter(fn (mixed $value): bool => filled($value))->implode(' - ');

        return $joined !== '' ? $joined : null;
    }
}
