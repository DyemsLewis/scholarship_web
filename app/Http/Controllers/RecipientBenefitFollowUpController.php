<?php

namespace App\Http\Controllers;

use App\Models\RecipientBenefitReleaseRecord;
use App\Services\RecipientBenefitFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RecipientBenefitFollowUpController extends Controller
{
    public function __construct(private readonly RecipientBenefitFollowUpService $followUps) {}

    public function store(Request $request, RecipientBenefitReleaseRecord $record): JsonResponse
    {
        abort_unless($request->routeIs('dashboard.*') ? $request->user()->isApplicant() : $request->user()->isProvider(), 403);
        $this->followUps->authorize($request->user(), $record);
        $request->merge(['note' => is_string($request->input('note')) ? trim($request->input('note')) : $request->input('note')]);
        $input = $request->validate([
            'action' => ['required', Rule::in($request->user()->isApplicant() ? ['continue', 'decline'] : ['notice', 'remind', 'close_unclaimed', 'link_replacement'])],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
            'respond_by' => ['required_if:action,notice,remind', 'nullable', 'date_format:Y-m-d'],
            'replacement_record_id' => ['required_if:action,link_replacement', 'nullable', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'confirmed' => ['accepted'],
        ]);
        $this->followUps->act($request->user(), $record, $input);

        return response()->json([
            'message' => 'Benefit follow-up saved. The scholarship agreement and receipt records have not been changed.',
            'follow_up' => $this->followUps->payload($record->fresh(), $request->user()),
        ]);
    }
}
