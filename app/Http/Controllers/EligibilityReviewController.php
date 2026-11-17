<?php

namespace App\Http\Controllers;

use App\Models\EligibilityReviewRequest;
use App\Models\Scholarship;
use App\Services\EligibilityReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EligibilityReviewController extends Controller
{
    public function __construct(private readonly EligibilityReviewService $reviews) {}

    public function applicantContext(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless(in_array($scholarship->status, ['published', 'closed'], true), 404);
        return response()->json($this->reviews->context($scholarship, $request->user()));
    }

    public function store(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($scholarship->status === 'published', 404);
        $input = $request->validate([
            'context_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'explanation' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $this->reviews->submit($scholarship, $request->user(), $input, $request->file('evidence'));
        return response()->json(['message' => 'Your eligibility review request was sent. This is not an application or a reserved slot.', 'context' => $this->reviews->context($scholarship->fresh(), $request->user()->fresh())], 201);
    }

    public function index(Request $request): View
    {
        $this->authorizeQueue($request);
        return view('provider-eligibility-reviews');
    }

    private function authorizeQueue(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor?->isProvider() && $actor->isActive() && $actor->hasPortalPermission('verify_applications'), 403);
        abort_unless($actor->providerOrganizationOwner()->isActive() && $actor->providerOrganizationOwner()->hasVerifiedEmail() && $actor->providerOrganizationOwner()->providerProfile?->isVerified(), 403);
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorizeQueue($request);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'declined', 'all'])], 'search' => ['nullable', 'string', 'max:100']]);
        $actor = $request->user();
        $query = EligibilityReviewRequest::query()->with(['applicant.studentProfile', 'scholarship'])
            ->whereHas('scholarship', fn ($q) => $q->where('provider_id', $actor->providerOrganizationId())
                ->when($actor->hasLimitedProviderProgramAccess(), fn ($q) => $q->whereIn('id', $actor->assignedProviderProgramIds())));
        $status = $filters['status'] ?? 'pending';
        if ($status !== 'all') $query->where('status', $status);
        if ($search = trim($filters['search'] ?? '')) $query->where(fn ($q) => $q->whereHas('applicant', fn ($a) => $a->where('name', 'like', "%{$search}%"))->orWhereHas('scholarship', fn ($s) => $s->where('title', 'like', "%{$search}%")));
        $rows = $query->oldest('created_at')->paginate(15)->withQueryString();
        return response()->json(['requests' => $rows->through(fn ($record) => [
            'id' => $record->id, 'applicant' => $record->applicant->name, 'program' => $record->scholarship->title,
            'photo_url' => $record->applicant->studentProfile?->profile_photo_path ? "/provider/eligibility-reviews/{$record->id}/applicant-photo" : null, 'status' => $record->status,
            'submitted_at' => $record->created_at->format('M d, Y h:i A'), 'rules' => collect($record->criteria_snapshot)->pluck('label')->all(),
        ])]);
    }

    public function show(Request $request, EligibilityReviewRequest $eligibilityReview): JsonResponse
    {
        $this->reviews->authorizeProvider($request->user(), $eligibilityReview->scholarship);
        $data = $this->reviews->payload($eligibilityReview, $request->user());
        return response()->json(['request' => [...$data, 'applicant' => $eligibilityReview->applicant->name,
            'program' => $eligibilityReview->scholarship->title,
            'can_approve' => $eligibilityReview->status === 'pending' && $data['context_matches'] && $this->reviews->applicantGate($eligibilityReview->scholarship, $eligibilityReview->applicant) === null,
        ]]);
    }

    public function decide(Request $request, EligibilityReviewRequest $eligibilityReview): JsonResponse
    {
        $this->reviews->authorizeProvider($request->user(), $eligibilityReview->scholarship);
        $input = $request->validate([
            'context_hash' => ['required', 'string', 'size:64'], 'decision' => ['required', Rule::in(['approved', 'declined'])],
            'decision_note' => ['required', 'string', 'min:10', 'max:1500'], 'confirmed' => ['required', 'accepted'],
            'evidence_reviewed' => [$eligibilityReview->evidence_path !== null ? 'required' : 'sometimes', 'accepted'],
        ]);
        $this->reviews->decide($eligibilityReview, $request->user(), $input);
        return $this->show($request, $eligibilityReview->fresh());
    }

    public function evidence(Request $request, EligibilityReviewRequest $eligibilityReview): BinaryFileResponse
    {
        $viewer = $request->user();
        if ($viewer?->isApplicant()) abort_unless($viewer->isActive() && $viewer->id === $eligibilityReview->applicant_id, 403);
        else $this->reviews->authorizeProvider($viewer, $eligibilityReview->scholarship);
        abort_unless($eligibilityReview->evidence_path && Storage::disk('local')->exists($eligibilityReview->evidence_path), 404);
        return response()->file(Storage::disk('local')->path($eligibilityReview->evidence_path), [
            'Content-Type' => $eligibilityReview->evidence_mime, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }

    public function photo(Request $request, EligibilityReviewRequest $eligibilityReview): BinaryFileResponse
    {
        $this->reviews->authorizeProvider($request->user(), $eligibilityReview->scholarship);
        $path = $eligibilityReview->applicant->studentProfile?->profile_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
