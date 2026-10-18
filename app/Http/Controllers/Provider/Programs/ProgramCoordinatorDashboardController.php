<?php

namespace App\Http\Controllers\Provider\Programs;

use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\Programs\ProgramDashboardItemResource;
use App\Queries\Provider\Programs\ProgramCoordinatorDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramCoordinatorDashboardController extends Controller
{
    public function data(Request $request, ProgramCoordinatorDashboardQuery $dashboard): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->isProvider() && $actor->hasPortalPermission('manage_programs'), 403);

        $owner = $actor->providerOrganizationOwner()->loadMissing('providerProfile');
        $data = $dashboard->for($actor);

        return response()->json([
            'context' => [
                'organization_name' => $owner->provider_name ?: $owner->name,
                'access_scope' => $actor->hasLimitedProviderProgramAccess() ? 'assigned' : 'all',
            ],
            'summary' => $data['summary'],
            'priorities' => ProgramDashboardItemResource::collection($data['priorities'])->resolve($request),
            'deadlines' => ProgramDashboardItemResource::collection($data['deadlines'])->resolve($request),
            'recent' => ProgramDashboardItemResource::collection($data['recent'])->resolve($request),
        ]);
    }
}
