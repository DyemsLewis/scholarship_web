<?php

namespace App\Http\Controllers\Provider\Programs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\Programs\ProgramDirectoryRequest;
use App\Http\Resources\Provider\Programs\ProgramDirectoryItemResource;
use App\Queries\Provider\Programs\ProgramDirectoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramDirectoryController extends Controller
{
    public function index(ProgramDirectoryRequest $request): View
    {
        return view('provider-program-directory');
    }

    public function data(ProgramDirectoryRequest $request, ProgramDirectoryQuery $programs): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();
        $filter = $request->filter();
        $search = trim((string) ($validated['search'] ?? ''));
        $sort = $validated['sort'] ?? 'updated';
        $paginator = $programs->paginate($actor, $filter, $search, $sort);
        $owner = $actor->providerOrganizationOwner()->loadMissing('providerProfile');

        return response()->json([
            'context' => [
                'organization_name' => $owner->provider_name ?: $owner->name,
                'access_scope' => $actor->hasLimitedProviderProgramAccess() ? 'assigned' : 'all',
            ],
            'summary' => $programs->summary($actor),
            'filter' => $filter,
            'sort' => $sort,
            'programs' => ProgramDirectoryItemResource::collection(collect($paginator->items()))->resolve($request),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function redirectLegacy(Request $request): RedirectResponse
    {
        $routeName = match ($request->route('programFilter')) {
            'drafts' => 'provider.programs.drafts',
            'review' => 'provider.programs.review',
            'published' => 'provider.programs.published',
            'closed' => 'provider.programs.closed',
            default => 'provider.programs',
        };

        return redirect()->route($routeName);
    }
}
