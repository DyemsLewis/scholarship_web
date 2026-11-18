<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipApplication;
use App\Services\RecipientOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecipientOnboardingController extends Controller
{
    public function __construct(private readonly RecipientOnboardingService $onboarding) {}

    public function show(Request $request, ScholarshipApplication $application): JsonResponse
    {
        return response()->json(['onboarding' => $this->onboarding->providerPayload($application, $request->user())]);
    }

    public function instructions(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->onboarding->authorizeView($application, $request->user());
        abort_unless($request->user()->hasPortalPermission('manage_recipients'), 403);
        validator(['submission' => $request->except('_token')], [
            'submission' => ['required', 'array:instructions,expected_revision,confirmed'],
        ])->validate();
        $input = $request->validate([
            'instructions' => ['required', 'string', 'max:3000'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $this->onboarding->publishInstructions($application, $request->user(), $input['instructions'], $input['expected_revision']);

        return response()->json([
            'message' => 'Provider instructions published. The disclosed requirement and benefit records are unchanged.',
            'onboarding' => $this->onboarding->providerPayload($application, $request->user()),
        ]);
    }

    public function complete(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $this->onboarding->authorizeView($application, $request->user());
        abort_unless($request->user()->hasPortalPermission('manage_recipients'), 403);
        validator(['submission' => $request->except('_token')], [
            'submission' => ['required', 'array:completed_on,confirmed,note,expected_revision'],
        ])->validate();
        $input = $request->validate([
            'completed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'confirmed' => ['required', 'accepted'],
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        $this->onboarding->confirmCompletion($application, $request->user(), [
            'completed_on' => $input['completed_on'], 'confirmed' => $input['confirmed'], 'note' => $input['note'] ?? null,
        ], $input['expected_revision']);

        return response()->json([
            'message' => 'Provider onboarding completion recorded. This does not release any benefit.',
            'onboarding' => $this->onboarding->providerPayload($application, $request->user()),
        ]);
    }
}
