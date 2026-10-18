<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountDirectoryRequest;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Admin\AccountDirectoryQuery;
use App\Queries\Admin\Dashboards\AccountManagerDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AccountManagerWorkspaceController extends Controller
{
    public function __construct(
        private readonly AccountDirectoryQuery $accountDirectory,
        private readonly AccountManagerDashboardQuery $dashboard,
    ) {}

    public function dashboard(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-account-manager-dashboard');
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $this->authorizeWorkspace($request);

        return response()->json($this->dashboard->get($request->user()));
    }

    public function directory(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-account-directory');
    }

    public function attention(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-account-attention');
    }

    public function create(Request $request): View
    {
        $this->authorizeWorkspace($request);

        return view('admin-account-create');
    }

    public function show(Request $request, User $user): View
    {
        $this->authorizeTarget($request, $user);

        return view('admin-account-record', ['account' => $user]);
    }

    public function data(AccountDirectoryRequest $request): JsonResponse
    {
        return response()->json(
            $this->accountDirectory->payload($request->user(), $request->validated()),
        );
    }

    public function showData(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile']);

        return response()->json([
            'user' => [
                ...$user->publicPayload(),
                'avatar_url' => $this->avatarUrl($user),
                'created_at' => $user->created_at?->format('M d, Y h:i A'),
                'updated_at' => $user->updated_at?->format('M d, Y h:i A'),
            ],
            'role_context' => $this->roleContext($user),
        ]);
    }

    public function photo(Request $request, User $user)
    {
        $this->authorizeTarget($request, $user);
        abort_unless($user->isApplicant(), 404);

        $profile = $user->studentProfile;
        abort_unless($profile?->profile_photo_path, 404);
        abort_unless(Storage::disk('local')->exists($profile->profile_photo_path), 404);

        return Storage::disk('local')->response(
            $profile->profile_photo_path,
            $profile->profile_photo_original_name ?: 'applicant-photo',
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless(
            $request->user()?->isAdmin()
                && $request->user()->hasPortalPermission('manage_accounts'),
            403,
        );
    }

    private function authorizeTarget(Request $request, User $target): void
    {
        $this->authorizeWorkspace($request);
        abort_if(
            $request->user()->isManagedAccount() && $target->isAdmin(),
            403,
            'Delegated administrators cannot manage administrator accounts.',
        );
    }

    private function roleContext(User $user): array
    {
        return match ($user->role) {
            'applicant' => [
                'label' => 'Applications',
                'count' => ScholarshipApplication::query()->where('applicant_id', $user->id)->count(),
            ],
            'provider' => [
                'label' => 'Programs',
                'count' => Scholarship::query()->where('provider_id', $user->id)->count(),
                'secondary_label' => 'Team accounts',
                'secondary_count' => $user->managedAccounts()->count(),
            ],
            default => [
                'label' => 'Managed accounts',
                'count' => $user->managedAccounts()->count(),
            ],
        };
    }

    private function avatarUrl(User $user): ?string
    {
        if ($user->isApplicant() && filled($user->studentProfile?->profile_photo_path)) {
            return "/admin/workspaces/accounts/{$user->id}/photo";
        }

        return $user->isProvider() ? $user->publicPayload()['provider_logo_url'] : null;
    }
}
