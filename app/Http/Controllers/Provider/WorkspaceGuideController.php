<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderWorkspaceGuideProgress;
use App\Support\ProviderWorkspaceGuides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceGuideController extends Controller
{
    public function show(Request $request, string $workspace): JsonResponse
    {
        $guide = ProviderWorkspaceGuides::for($request->user(), $workspace);
        $progress = ProviderWorkspaceGuideProgress::query()->where($this->identity($request, $guide))->first();
        $hasPreviousProgress = $progress || ProviderWorkspaceGuideProgress::query()
            ->where('user_id', $request->user()->id)->where('workspace_key', $workspace)->exists();

        return response()->json([
            'guide' => $guide,
            'status' => $progress?->status,
            'auto_offer' => ! $hasPreviousProgress && ProviderWorkspaceGuides::shouldOffer($request->user(), $workspace),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $workspace): JsonResponse
    {
        $guide = ProviderWorkspaceGuides::for($request->user(), $workspace);
        $input = $request->validate([
            'version' => ['required', 'integer', Rule::in([$guide['version']])],
            'status' => ['required', Rule::in(['seen', 'dismissed', 'completed'])],
            'user_id' => ['prohibited'],
        ]);

        $status = DB::transaction(function () use ($request, $guide, $input): string {
            $identity = $this->identity($request, $guide);
            ProviderWorkspaceGuideProgress::query()->insertOrIgnore([
                ...$identity, 'status' => $input['status'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $progress = ProviderWorkspaceGuideProgress::query()->where($identity)->lockForUpdate()->firstOrFail();
            $rank = ['seen' => 1, 'dismissed' => 2, 'completed' => 3];
            // Reopening or a late network response must not undo completion.
            if ($rank[$input['status']] > $rank[$progress->status]) {
                $progress->update(['status' => $input['status']]);
            }

            return $progress->status;
        }, 3);

        return response()->json(['status' => $status])->header('Cache-Control', 'private, no-store');
    }

    private function identity(Request $request, array $guide): array
    {
        return ['user_id' => $request->user()->id, 'workspace_key' => $guide['key'], 'guide_version' => $guide['version']];
    }
}
