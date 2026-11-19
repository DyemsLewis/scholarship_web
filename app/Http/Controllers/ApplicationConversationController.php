<?php

namespace App\Http\Controllers;

use App\Models\ApplicationConversation;
use App\Models\ApplicationMessage;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Support\OpaqueRecordUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplicationConversationController extends Controller
{
    private const PAGE_SIZE = 30;
    private const ALERT_LIMIT = 8;
    private const INBOX_FILTERS = ['all', 'unread', 'awaiting_reply'];

    public function providerInbox(Request $request): Response
    {
        $this->authorizeProviderMessaging($request);

        return response()->view('provider-application-messages-inbox', [
            'inboxUrl' => route('provider.application-conversations.inbox', [], false),
            'dataUrl' => route('provider.application-conversations.inbox-data', [], false),
            'inboxContext' => $this->inboxContext($request),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function providerInboxData(Request $request, ProviderWorkspaceScope $scope, OpaqueRecordUrl $opaqueUrls): JsonResponse
    {
        $actor = $this->authorizeProviderMessaging($request);
        $context = $this->inboxContext($request);
        $applications = $scope->applications($actor)->whereNotNull('submitted_at')->select('scholarship_applications.id');
        $unread = ApplicationConversation::unreadMessageFilter(ApplicationMessage::PROVIDER);
        $latestSender = ApplicationMessage::query()->select('sender_side')
            ->whereColumn('application_conversation_id', 'application_conversations.id')->orderByDesc('id')->limit(1);

        $page = ApplicationConversation::query()
            ->whereIn('scholarship_application_id', $applications)
            ->with([
                'application:id,applicant_id,scholarship_id',
                'application.applicant:id',
                'application.applicant.studentProfile:user_id,first_name,middle_initial,last_name,suffix',
                'application.scholarship:id,title',
            ])
            ->addSelect(['latest_sender' => $latestSender])
            ->withCount(['messages as unread_count' => $unread])
            ->when($context['filter'] === 'unread', fn ($query) => $query->whereHas('messages', $unread))
            ->when($context['filter'] === 'awaiting_reply', fn ($query) => $query->where($latestSender, '=', ApplicationMessage::APPLICANT))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $context['page']);

        return $this->respond([
            'conversations' => $page->getCollection()->map(function (ApplicationConversation $conversation) use ($context, $opaqueUrls): array {
                $application = $conversation->application;
                $thread = route('provider.application-conversations.thread', $application, false);
                $threadPath = $opaqueUrls->encode($thread)['path'] ?? $thread;

                return [
                    'key' => $conversation->id,
                    'applicant_name' => $this->applicantDisplayName($application),
                    'program_title' => $application->scholarship->title,
                    'unread_count' => (int) $conversation->unread_count,
                    'awaiting_reply' => $conversation->latest_sender === ApplicationMessage::APPLICANT,
                    'last_message_at' => $conversation->last_message_at->toISOString(),
                    'href' => $threadPath.'?'.http_build_query($context),
                ];
            }),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function unreadAlerts(Request $request, ProviderWorkspaceScope $scope, OpaqueRecordUrl $opaqueUrls): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->isActive(), 403);

        $isProvider = $actor->isProvider();
        if ($isProvider && $actor->hasPortalPermission(User::PROVIDER_MESSAGING_PERMISSION)
            && $this->organizationAvailable($actor->providerOrganizationOwner())) {
            $applications = $scope->applications($actor);
            $side = ApplicationMessage::PROVIDER;
        } elseif ($actor->isApplicant()) {
            $applications = ScholarshipApplication::query()->where('applicant_id', $actor->id);
            $side = ApplicationMessage::APPLICANT;
        } else {
            return $this->respond(['conversations' => [], 'unread_count' => 0]);
        }

        $unread = ApplicationConversation::unreadMessageFilter($side);
        $query = ApplicationConversation::query()
            ->whereIn('scholarship_application_id', $applications->whereNotNull('submitted_at')->select('scholarship_applications.id'))
            ->whereHas('messages', $unread);

        $count = $query->count();
        $latest = $count === 0 ? collect() : $query
            ->with([
                'application:id,applicant_id,scholarship_id', 'application.scholarship:id,title',
                ...($isProvider ? ['application.applicant:id', 'application.applicant.studentProfile:user_id,first_name,middle_initial,last_name,suffix'] : []),
            ])
            ->withCount(['messages as unread_count' => $unread])
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::ALERT_LIMIT)->get();

        return $this->respond([
            'unread_count' => $count,
            'conversations' => $latest->map(function (ApplicationConversation $conversation) use ($isProvider, $opaqueUrls): array {
                $application = $conversation->application;
                $thread = route($isProvider ? 'provider.application-conversations.thread' : 'dashboard.application-conversations.page', $application, false);

                return [
                    'key' => $conversation->id,
                    'program_title' => $application->scholarship->title,
                    ...($isProvider ? ['applicant_name' => $this->applicantDisplayName($application)] : []),
                    'unread_count' => (int) $conversation->unread_count,
                    'href' => $opaqueUrls->encode($thread)['path'] ?? $thread,
                ];
            }),
        ]);
    }

    public function providerThread(Request $request, ScholarshipApplication $application): Response
    {
        $this->authorizeApplication($request, $application);

        return response()->view('provider-application-message-thread', [
            'conversationUrl' => route('provider.application-conversations.show', $application, false),
            'backUrl' => route('provider.application-conversations.inbox', $this->inboxContext($request), false),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function page(Request $request, ScholarshipApplication $application): Response
    {
        $this->authorizeApplication($request, $application);
        $fromSupport = $request->query('from') === 'support';

        return response()->view('dashboard-application-messages', [
            'conversationUrl' => route('dashboard.application-conversations.show', $application, false),
            'backUrl' => route($fromSupport ? 'dashboard.monitoring.show' : 'dashboard.applications.show', $application, false),
            'backLabel' => $fromSupport ? 'Scholarship Support' : 'application details',
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function show(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $side = $this->authorizeApplication($request, $application);
        $data = $request->validate([
            'before_id' => ['sometimes', 'integer', 'min:1', 'prohibits:after_id'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
        ]);
        $conversation = $application->conversation()->first();
        $after = isset($data['after_id']);
        $messages = $conversation?->messages()
            ->when(isset($data['before_id']), fn ($query) => $query->where('id', '<', $data['before_id']))
            ->when($after, fn ($query) => $query->where('id', '>', $data['after_id']))
            ->orderBy('id', $after ? 'asc' : 'desc')
            ->limit(self::PAGE_SIZE + 1)->get() ?? collect();
        $hasMore = $messages->count() > self::PAGE_SIZE;
        $page = $messages->take(self::PAGE_SIZE)->sortBy('id')->values();
        $owner = $application->scholarship->provider;

        return $this->respond([
            'program_title' => $application->scholarship->title,
            'provider_name' => $owner?->provider_name ?: 'Scholarship provider',
            ...($side === ApplicationMessage::PROVIDER ? ['applicant_name' => $this->applicantDisplayName($application)] : []),
            'started' => $conversation !== null,
            'can_send' => $this->organizationAvailable($owner),
            ...$this->readState($conversation, $side),
            'messages' => $page->map($this->messagePayload(...)),
            'has_more' => $hasMore,
            'first_message_id' => $page->first()?->id,
            'last_message_id' => $page->last()?->id,
        ]);
    }

    public function store(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $side = $this->authorizeApplication($request, $application);
        if ($request->allFiles() !== []) {
            throw ValidationException::withMessages(['body' => 'Only text messages are supported.']);
        }
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'request_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'],
        ]);

        $message = DB::transaction(function () use ($request, $application, $side, $data): ApplicationMessage {
            // The parent lock serializes the first send and preserves message order within this application.
            $locked = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->authorizeApplication($request, $locked);
            abort_unless($this->organizationAvailable($locked->scholarship->provider), 403, 'The provider is currently unavailable for messages.');

            $conversation = $locked->conversation()->firstOrCreate([], ['last_message_at' => now()]);
            $key = ['sender_id' => $request->user()->id, 'request_key' => strtolower($data['request_key'])];
            $body = trim($data['body']);
            $previous = $conversation->messages()->where($key)->first();
            if ($previous) {
                abort_if($previous->body !== $body, 409, 'This request was already used for a different message.');

                return $previous;
            }

            $message = $conversation->messages()->create([...$key, 'sender_side' => $side, 'body' => $body]);
            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        }, 3);

        return $this->respond([
            'message' => 'Message sent.',
            'chat_message' => $this->messagePayload($message),
        ], $message->wasRecentlyCreated ? 201 : 200);
    }

    public function markRead(Request $request, ScholarshipApplication $application): JsonResponse
    {
        $side = $this->authorizeApplication($request, $application);
        $messageId = (int) $request->validate(['through_message_id' => ['required', 'integer', 'min:1']])['through_message_id'];

        $state = DB::transaction(function () use ($application, $side, $messageId): array {
            $conversation = $application->conversation()->lockForUpdate()->first();
            if (! $conversation || ! $conversation->messages()->whereKey($messageId)->exists()) {
                throw ValidationException::withMessages(['through_message_id' => 'Choose a message in this conversation.']);
            }
            $conversation->advanceReadCursor($side, $messageId);

            return $this->readState($conversation, $side);
        }, 3);

        return $this->respond($state);
    }

    private function authorizeApplication(Request $request, ScholarshipApplication $application): string
    {
        $actor = $request->user();
        abort_unless($actor?->isActive(), 403);

        if ($request->routeIs('provider.application-conversations.*')) {
            $application->loadMissing('scholarship');
            $this->authorizeProviderMessaging($request);
            abort_unless($actor->canAccessProviderProgram($application->scholarship), 403);
            $side = ApplicationMessage::PROVIDER;
        } else {
            abort_unless($actor->isApplicant() && (int) $application->applicant_id === (int) $actor->id, 403);
            $side = ApplicationMessage::APPLICANT;
        }

        abort_if($application->submitted_at === null, 404, 'Messaging is available after submitting an application.');

        return $side;
    }

    private function authorizeProviderMessaging(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor?->isActive() && $actor->isProvider()
            && $actor->hasPortalPermission(User::PROVIDER_MESSAGING_PERMISSION), 403);

        return $actor;
    }

    private function inboxContext(Request $request): array
    {
        $filter = $request->query('filter');
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);

        return ['filter' => in_array($filter, self::INBOX_FILTERS, true) ? $filter : 'all', 'page' => $page ?: 1];
    }

    private function applicantDisplayName(ScholarshipApplication $application): string
    {
        $profile = $application->applicant?->studentProfile;
        $initial = $profile?->middle_initial;

        return implode(' ', array_filter([$profile?->first_name, $initial ? rtrim($initial, '.').'.' : null, $profile?->last_name, $profile?->suffix])) ?: 'Applicant';
    }

    private function organizationAvailable(?User $owner): bool
    {
        return $owner?->isProvider() && $owner->isActive() && $owner->hasVerifiedEmail() && $owner->providerProfile?->isVerified();
    }

    private function readState(?ApplicationConversation $conversation, string $side): array
    {
        return [
            'read_through_message_id' => $conversation?->readCursor($side) ?: null,
            'unread_count' => $conversation?->unreadMessages($side)->count() ?? 0,
        ];
    }

    private function messagePayload(ApplicationMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender_side' => $message->sender_side,
            'body' => $message->body,
            'sent_at' => $message->created_at->toISOString(),
        ];
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'no-store, private']);
    }
}
