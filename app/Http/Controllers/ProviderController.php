<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Scholarship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider');
    }

    public function programs(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-programs');
    }

    public function applications(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-applications');
    }

    public function profile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'user' => $request->user()->only([
                'id',
                'name',
                'first_name',
                'last_name',
                'middle_initial',
                'email',
                'username',
                'contact_number',
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
                'role',
            ]),
            'stats' => [
                'scholarships' => $scholarships->count(),
                'applications' => 0,
                'drafts' => $scholarships->where('status', 'draft')->count(),
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function scholarships(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $this->validateScholarship($request);

        $scholarship = Scholarship::create([
            ...$validated,
            'provider_id' => $request->user()->id,
        ]);

        ActivityLog::record(
            $request->user(),
            'scholarship_created',
            "{$request->user()->name} created scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => 'Scholarship created successfully.',
            'scholarship' => $this->scholarshipPayload($scholarship),
        ], 201);
    }

    public function updateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->id, 403);

        $validated = $this->validateScholarship($request);

        $scholarship->update($validated);

        ActivityLog::record(
            $request->user(),
            'scholarship_updated',
            "{$request->user()->name} updated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => 'Scholarship updated successfully.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()),
        ]);
    }

    private function validateScholarship(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
        ]);
    }

    private function scholarshipPayload(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id,
            'title' => $scholarship->title,
            'description' => $scholarship->description,
            'eligibility' => $scholarship->eligibility,
            'requirements' => $scholarship->requirements,
            'award_amount' => $scholarship->award_amount,
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => $scholarship->status,
            'created_at' => $scholarship->created_at?->format('M d, Y'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y'),
        ];
    }
}
