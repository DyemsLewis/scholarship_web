<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Http\Controllers\Controller;
use App\Queries\Admin\SuperAdminWorkspaceQuery;
use App\Support\AdminWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SuperAdminWorkspaceController extends Controller
{
    public function __construct(private readonly SuperAdminWorkspaceQuery $workspace) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($route = AdminWorkspace::preferredRouteName($request->user())) {
            return redirect()->route($route);
        }

        $this->authorizePrimaryAdmin($request);

        return view('admin-super-admin-workspace');
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorizePrimaryAdmin($request);

        return response()->json($this->workspace->overview());
    }

    public function team(Request $request): View
    {
        $this->authorizePrimaryAdmin($request);

        return view('admin-super-admin-area', ['area' => 'team', 'title' => 'Admin team']);
    }

    public function teamData(Request $request): JsonResponse
    {
        $this->authorizePrimaryAdmin($request);

        $filters = $request->validate(['role' => ['nullable', Rule::in(array_diff(array_keys(AdminRoleCatalog::definitions()), [AdminRoleCatalog::SUPER_ADMIN]))]]);

        return response()->json($this->workspace->team($filters['role'] ?? null));
    }

    public function platform(Request $request): View
    {
        $this->authorizePrimaryAdmin($request);

        return view('admin-super-admin-area', ['area' => 'platform', 'title' => 'Platform health']);
    }

    public function platformData(Request $request): JsonResponse
    {
        $this->authorizePrimaryAdmin($request);

        return response()->json($this->workspace->platform());
    }

    private function authorizePrimaryAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() && ! $request->user()->isManagedAccount(), 403);
    }
}
