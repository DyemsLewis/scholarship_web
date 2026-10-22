<?php

use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientSupportDecision;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$root = rtrim((string) (getenv('BROWSER_QA_APP_ROOT') ?: dirname(__DIR__, 2)), '/\\');

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scenario = function (string $username): array {
    $user = User::query()->where('username', $username)->firstOrFail();
    $application = ScholarshipApplication::query()
        ->with('scholarship.monitoringCycles.requirements')
        ->where('applicant_id', $user->id)
        ->latest('id')
        ->firstOrFail();
    $checkIn = $application->scholarship->monitoringCycles
        ->first(fn ($cycle): bool => $cycle->requirements->isNotEmpty());
    $releaseRecord = RecipientBenefitReleaseRecord::query()
        ->with('release')
        ->where('scholarship_application_id', $application->id)
        ->orderByDesc('released_at')
        ->orderByDesc('id')
        ->first();
    $outcome = RecipientSupportDecision::query()
        ->where('scholarship_application_id', $application->id)
        ->orderByDesc('decided_at')
        ->orderByDesc('id')
        ->first();

    return [
        'username' => $username,
        'application_id' => $application->id,
        'program_title' => $application->scholarship->title,
        'check_in' => $checkIn ? [
            'record_key' => 'check-in-'.$checkIn->id,
            'title' => $checkIn->title,
        ] : null,
        'release' => $releaseRecord ? [
            'record_key' => 'release-'.$releaseRecord->id,
            'title' => $releaseRecord->release?->title ?? 'Benefit release',
        ] : null,
        'outcome' => $outcome ? [
            'record_key' => 'outcome-'.$outcome->id,
            'title' => match ($outcome->decision) {
                'renewed' => 'Support renewed',
                'completed' => 'Program completed',
                'terminated' => 'Support ended early',
                default => str($outcome->decision)->headline()->toString(),
            },
        ] : null,
    ];
};

echo json_encode([
    'active' => $scenario('nicoleandreabautista'),
    'completed' => $scenario('vincentrafaeltorres'),
], JSON_THROW_ON_ERROR);
