<?php

namespace App\Http\Controllers\Provider\Programs;

use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\Programs\ProgramRecordResource;
use App\Models\Scholarship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramRecordController extends Controller
{
    public function index(Request $request, Scholarship $scholarship): View
    {
        $this->authorizeProgram($request, $scholarship);

        return view('provider-program-record', compact('scholarship'));
    }

    public function data(Request $request, Scholarship $scholarship): JsonResponse
    {
        $this->authorizeProgram($request, $scholarship);

        $scholarship->load('benefits')->loadCount('applications');

        return response()->json([
            'program' => (new ProgramRecordResource($scholarship))->resolve($request),
        ]);
    }

    private function authorizeProgram(Request $request, Scholarship $scholarship): void
    {
        $actor = $request->user();

        abort_unless($actor?->isProvider(), 403);
        abort_unless((int) $scholarship->provider_id === $actor->providerOrganizationId(), 403);
        abort_unless($actor->canAccessProviderProgram($scholarship), 403);
    }
}
