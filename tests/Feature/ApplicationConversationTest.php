<?php

namespace Tests\Feature;

use App\Models\ApplicationMessage;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\OpaqueRecordUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_message_page_has_safe_return_links_and_requires_the_submitted_application_owner(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $url = route('dashboard.application-conversations.page', $application, false);
        $opaqueUrl = app(OpaqueRecordUrl::class)->encode($url)['path'];
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($applicant)->get($url, ['Sec-Fetch-Dest' => 'document'])->assertRedirectContains('/dashboard/r/');
        $this->get($opaqueUrl)->assertOk()->assertViewIs('dashboard-application-messages')
            ->assertViewHas('backUrl', route('dashboard.applications.show', $application, false))
            ->assertViewHas('conversationUrl', $this->url($application))
            ->assertSee('data-page="dashboardApplicationMessages"', false);
        $this->get($url.'?from=support')->assertOk()->assertViewHas('backUrl', route('dashboard.monitoring.show', $application, false));
        $this->get($url.'?from=https://elsewhere.example')->assertOk()
            ->assertViewHas('backUrl', route('dashboard.applications.show', $application, false));
        $this->actingAs(User::factory()->create())->get($opaqueUrl)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertForbidden();
        $application->update(['submitted_at' => null]);
        $this->actingAs($applicant)->get($url)->assertNotFound();
        $this->assertDatabaseCount('application_conversations', 0);
    }

    public function test_reading_an_empty_conversation_does_not_create_records_and_retried_sends_are_idempotent(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $url = $this->url($application);
        $this->actingAs($applicant)->getJson($url)->assertOk()
            ->assertJsonPath('started', false)->assertJsonPath('unread_count', 0)
            ->assertJsonPath('messages', [])->assertJsonPath('can_send', true);
        $this->assertDatabaseCount('application_conversations', 0);

        $message = $this->message("Is the schedule confirmed?\nThank you.");
        $first = $this->postJson($url.'/messages', $message)->assertCreated()
            ->assertJsonPath('chat_message.sender_side', 'applicant')
            ->assertJsonPath('chat_message.body', $message['body'])
            ->assertJsonMissingPath('chat_message.sender_id')->assertJsonMissingPath('chat_message.request_key');
        $this->postJson($url.'/messages', $message)->assertOk()
            ->assertJsonPath('chat_message.id', $first->json('chat_message.id'));
        $this->postJson($url.'/messages', [...$message, 'body' => 'Different text'])->assertConflict();
        $this->assertDatabaseCount('application_conversations', 1);
        $this->assertDatabaseCount('application_messages', 1);
        $this->assertStringNotContainsString('schedule confirmed', DB::table('application_messages')->value('body'));

        $this->actingAs($owner)->getJson($this->url($application, 'provider'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('started', true)->assertJsonPath('unread_count', 1)
            ->assertJsonPath('messages.0.body', $message['body']);
        $this->actingAs($applicant)->getJson($url)->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_conversations_are_separate_per_application_and_remain_accessible_after_selection(): void
    {
        [$owner, $applicant, $first] = $this->submittedApplication();
        [, , $second] = $this->submittedApplication($owner, $applicant);
        $this->actingAs($applicant)->postJson($this->url($first).'/messages', $this->message('First scholarship'))->assertCreated();
        $this->postJson($this->url($second).'/messages', $this->message('Second scholarship'))->assertCreated();
        $first->update(['status' => 'awarded', 'final_outcome' => 'selected']);

        $this->getJson($this->url($first))->assertOk()->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'First scholarship');
        $this->getJson($this->url($second))->assertOk()->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Second scholarship');
        $this->actingAs($owner)->postJson($this->url($first, 'provider').'/messages', $this->message('Onboarding is next.'))->assertCreated();
        $this->assertDatabaseCount('application_conversations', 2);
        $this->assertSame('selected', $first->fresh()->final_outcome);
    }

    public function test_read_markers_are_scoped_monotonic_and_shared_by_authorized_provider_staff(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        [, , $other] = $this->submittedApplication($owner, $applicant);
        $staff = $this->messagingStaff($owner, [$application->scholarship_id]);
        $applicantUrl = $this->url($application);
        $providerUrl = $this->url($application, 'provider');
        $this->actingAs($applicant);
        $first = $this->postJson($applicantUrl.'/messages', $this->message('First incoming'))->assertCreated()->json('chat_message.id');
        $second = $this->postJson($applicantUrl.'/messages', $this->message('Second incoming'))->assertCreated()->json('chat_message.id');
        $foreign = $this->postJson($this->url($other).'/messages', $this->message('Different conversation'))->assertCreated()->json('chat_message.id');

        $this->actingAs($staff)->getJson($providerUrl)->assertOk()->assertJsonPath('unread_count', 2);
        $this->patchJson($providerUrl.'/read', ['through_message_id' => $foreign])->assertUnprocessable();
        $this->patchJson($providerUrl.'/read', ['through_message_id' => $first])->assertOk()->assertJsonPath('unread_count', 1);
        $reply = $this->postJson($providerUrl.'/messages', $this->message('Provider reply'))->assertCreated()
            ->assertJsonPath('chat_message.sender_side', 'provider')->json('chat_message.id');

        $this->actingAs($owner)->getJson($providerUrl)->assertOk()->assertJsonPath('unread_count', 1);
        $this->patchJson($providerUrl.'/read', ['through_message_id' => $second])->assertOk()->assertJsonPath('unread_count', 0);
        $this->patchJson($providerUrl.'/read', ['through_message_id' => $first])->assertOk()->assertJsonPath('read_through_message_id', $second);
        $this->actingAs($applicant)->getJson($applicantUrl)->assertOk()->assertJsonPath('unread_count', 1);
        $this->patchJson($applicantUrl.'/read', ['through_message_id' => $reply])->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_guests_admins_other_applicants_and_unassigned_providers_cannot_read_or_mutate_a_conversation(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $applicantUrl = $this->url($application);
        $providerUrl = $this->url($application, 'provider');
        $this->getJson($applicantUrl)->assertUnauthorized();
        $this->actingAs($applicant)->postJson($applicantUrl.'/messages', $this->message('Private message'))->assertCreated();
        $opaqueProviderUrl = app(OpaqueRecordUrl::class)->encode($providerUrl)['path'];

        foreach ([User::factory()->create(), User::factory()->create(['role' => 'admin']), $owner] as $unrelated) {
            $this->actingAs($unrelated)->getJson($applicantUrl)->assertForbidden();
            $this->postJson($applicantUrl.'/messages', $this->message())->assertForbidden();
            $this->patchJson($applicantUrl.'/read', ['through_message_id' => 1])->assertForbidden();
        }

        $unapproved = User::factory()->create(['role' => 'provider']);
        $unapproved->providerProfile()->update(['verification_status' => 'pending']);
        foreach ([
            User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'provider']),
            $unapproved, $this->messagingStaff($owner, []),
            $this->messagingStaff($owner, null, ['manage_reports']),
        ] as $unrelated) {
            $this->actingAs($unrelated)->getJson($opaqueProviderUrl)->assertForbidden();
            $this->postJson($providerUrl.'/messages', $this->message())->assertForbidden();
            $this->patchJson($providerUrl.'/read', ['through_message_id' => 1])->assertForbidden();
        }

        $staff = $this->messagingStaff($owner, [$application->scholarship_id]);
        $this->actingAs($staff)->getJson($opaqueProviderUrl)->assertOk();
        $staff->update(['assigned_program_ids' => []]);
        $this->actingAs($staff->fresh())->getJson($opaqueProviderUrl)->assertForbidden();
        $this->assertDatabaseCount('application_messages', 1);
    }

    public function test_submission_and_provider_approval_gate_new_messages_without_hiding_applicant_history(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $url = $this->url($application);
        $application->update(['submitted_at' => null]);
        $this->actingAs($applicant)->getJson($url)->assertNotFound();
        $this->postJson($url.'/messages', $this->message())->assertNotFound();
        $this->actingAs($owner)->getJson($this->url($application, 'provider'))->assertNotFound();
        $this->assertDatabaseCount('application_conversations', 0);

        $application->update(['submitted_at' => now()]);
        $this->actingAs($applicant)->postJson($url.'/messages', $this->message())->assertCreated();
        $owner->providerProfile()->update(['verification_status' => 'pending']);
        $this->getJson($url)->assertOk()->assertJsonPath('can_send', false)->assertJsonCount(1, 'messages');
        $this->postJson($url.'/messages', $this->message())->assertForbidden();
        $this->actingAs($owner->fresh())->getJson($this->url($application, 'provider'))->assertForbidden();
        $this->assertDatabaseCount('application_messages', 1);
    }

    public function test_sending_requires_confirmation_valid_text_and_is_rate_limited_per_account(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        [, , $other] = $this->submittedApplication($owner, $applicant);
        $url = $this->url($application).'/messages';
        $this->actingAs($applicant);
        $this->postJson($url, [...$this->message(), 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, [...$this->message(), 'body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url, [...$this->message(), 'body' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url, [...$this->message(), 'request_key' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->post($url, [...$this->message(), 'attachment' => UploadedFile::fake()->create('notes.pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('application_messages', 0);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson($url, $this->message('Message '.$i))->assertCreated();
        }
        $this->postJson($this->url($other).'/messages', $this->message())->assertTooManyRequests();
        $this->getJson($this->url($application))->assertOk();
        $this->actingAs($owner)->postJson($this->url($application, 'provider').'/messages', $this->message())->assertCreated();
    }

    public function test_history_supports_older_pages_and_polling_for_new_messages_in_display_order(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $url = $this->url($application);
        $this->actingAs($applicant)->postJson($url.'/messages', $this->message('Message 1'))->assertCreated();
        $conversation = $application->conversation()->firstOrFail();
        for ($i = 2; $i <= 35; $i++) {
            $conversation->messages()->create([
                'sender_id' => $owner->id, 'sender_side' => ApplicationMessage::PROVIDER,
                'request_key' => (string) Str::uuid(), 'body' => 'Message '.$i,
            ]);
        }
        $first = $conversation->messages()->oldest('id')->firstOrFail()->id;
        $last = $conversation->messages()->latest('id')->firstOrFail()->id;
        $page = $this->getJson($url)->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(30, 'messages')
            ->assertJsonPath('messages.0.body', 'Message 6')->assertJsonPath('messages.29.id', $last);
        $this->getJson($url.'?before_id='.$page->json('first_message_id'))->assertOk()->assertJsonPath('has_more', false)
            ->assertJsonCount(5, 'messages')->assertJsonPath('messages.0.id', $first);
        $this->getJson($url.'?after_id='.$first)->assertOk()->assertJsonPath('has_more', true)->assertJsonCount(30, 'messages')
            ->assertJsonPath('messages.0.body', 'Message 2')->assertJsonPath('messages.29.body', 'Message 31');
        $this->getJson($url.'?after_id='.$last)->assertOk()->assertJsonPath('messages', []);
        $this->getJson($url.'?before_id='.$last.'&after_id='.$first)->assertUnprocessable();
    }

    public function test_existing_solo_operators_keep_messaging_access_without_widening_other_roles(): void
    {
        [$owner, , $application] = $this->submittedApplication();
        $owner->update(['permissions' => array_values(array_diff(User::PROVIDER_PERMISSIONS, [User::PROVIDER_MESSAGING_PERMISSION]))]);
        $this->assertSame('solo_operator', $owner->providerOperatingMode());
        $this->actingAs($owner)->getJson($this->url($application, 'provider'))->assertOk();
        $owner->update(['permissions' => User::PROVIDER_GOVERNANCE_PERMISSIONS]);
        $this->assertSame('governance', $owner->providerOperatingMode());
        $this->actingAs($owner)->getJson($this->url($application, 'provider'))->assertForbidden();
    }

    public function test_messaging_is_grantable_in_team_settings_without_granting_application_review(): void
    {
        [$owner, , $application] = $this->submittedApplication();
        $owner->update(['permissions' => User::PROVIDER_GOVERNANCE_PERMISSIONS]);
        $catalog = $this->actingAs($owner)->getJson('/provider/team/data')->assertOk();
        $this->assertContains(User::PROVIDER_MESSAGING_PERMISSION, $catalog->json('role_presets.manager'));
        $this->assertSame('Application messages', collect($catalog->json('permission_catalog'))->firstWhere('value', User::PROVIDER_MESSAGING_PERMISSION)['label']);

        $legacyPermissions = array_values(array_diff(User::PROVIDER_PERMISSIONS, [User::PROVIDER_MESSAGING_PERMISSION]));
        $manager = $this->messagingStaff($owner, null, $legacyPermissions);
        $this->actingAs($manager)->getJson('/provider/team/data')->assertOk()->assertJsonPath('role_presets.manager', $legacyPermissions);
        $this->getJson($this->url($application, 'provider'))->assertForbidden();

        $staff = $this->messagingStaff($owner, [$application->scholarship_id]);
        $this->actingAs($staff)->getJson($this->url($application, 'provider'))->assertOk();
        $this->getJson('/provider/workspaces/reviews/data')->assertForbidden();
    }

    public function test_provider_inbox_and_thread_only_allow_approved_staff_with_messaging_and_program_access(): void
    {
        [$owner, $applicant, $assigned] = $this->submittedApplication();
        [, , $unassigned] = $this->submittedApplication($owner, $applicant);
        [$otherOwner, , $otherProvider] = $this->submittedApplication(null, $applicant);
        $assigned->scholarship()->update(['title' => 'Assigned scholarship']);
        $applicant->studentProfile()->update([
            'first_name' => 'Sample', 'last_name' => 'Learner', 'middle_initial' => null, 'suffix' => null,
        ]);
        $inbox = route('provider.application-conversations.inbox', [], false);
        $dataUrl = $inbox.'/data';

        $this->get($inbox)->assertRedirect(route('login'));
        $this->getJson($dataUrl)->assertUnauthorized();
        foreach ([$assigned, $unassigned, $otherProvider] as $application) {
            $this->actingAs($applicant)->postJson($this->url($application).'/messages', $this->message('Private body'))->assertCreated();
        }

        $staff = $this->messagingStaff($owner, [$assigned->scholarship_id]);
        $this->actingAs($staff)->get($inbox)->assertOk()->assertViewIs('provider-application-messages-inbox')
            ->assertHeader('Cache-Control', 'no-store, private');
        $result = $this->getJson($dataUrl.'?filter=unread')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.applicant_name', 'Sample Learner')
            ->assertJsonPath('conversations.0.program_title', 'Assigned scholarship')
            ->assertJsonPath('conversations.0.unread_count', 1)
            ->assertJsonPath('conversations.0.awaiting_reply', true)
            ->assertDontSee('Private body')->assertDontSee($applicant->email);
        $thread = $result->json('conversations.0.href');
        $this->assertStringStartsWith('/provider/r/', $thread);
        $this->get($thread)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('backUrl', $inbox.'?filter=unread&page=1')
            ->assertViewHas('conversationUrl', $this->url($assigned, 'provider'))
            ->assertSee('data-page="providerApplicationMessageThread"', false);
        $this->getJson($this->url($assigned, 'provider'))->assertOk()
            ->assertJsonPath('applicant_name', 'Sample Learner')->assertJsonPath('unread_count', 1);
        foreach ([$unassigned, $otherProvider] as $application) {
            $this->get(route('provider.application-conversations.thread', $application, false))->assertForbidden();
        }
        $this->actingAs($otherOwner)->getJson($dataUrl)->assertOk()->assertJsonCount(1, 'conversations');
        $this->get($thread)->assertForbidden();

        $pending = User::factory()->create(['role' => 'provider']);
        $pending->providerProfile()->update(['verification_status' => 'pending']);
        foreach ([$applicant, User::factory()->create(['role' => 'admin']), $pending,
            $this->messagingStaff($owner, null, ['verify_applications']),
        ] as $actor) {
            $this->actingAs($actor)->getJson($dataUrl)->assertForbidden();
            $this->get($thread)->assertForbidden();
        }
        $suspended = $this->messagingStaff($owner, null)->forceFill(['account_status' => 'suspended']);
        $this->actingAs($suspended)->get($thread)->assertRedirect(route('login'));
        $this->actingAs($suspended)->getJson($dataUrl)->assertForbidden();
        $this->actingAs($this->messagingStaff($owner, []))->getJson($dataUrl)->assertOk()->assertJsonCount(0, 'conversations');
        $assigned->update(['submitted_at' => null]);
        $this->actingAs($staff)->getJson($dataUrl)->assertOk()->assertJsonCount(0, 'conversations');
        $this->get($thread)->assertNotFound();
    }

    public function test_provider_inbox_tracks_unread_and_awaiting_reply_independently(): void
    {
        [$owner, $applicant, $application] = $this->submittedApplication();
        $staff = $this->messagingStaff($owner, [$application->scholarship_id]);
        $inbox = route('provider.application-conversations.inbox-data', [], false);
        $providerConversation = $this->url($application, 'provider');

        $incoming = $this->actingAs($applicant)->postJson($this->url($application).'/messages', $this->message())
            ->assertCreated()->json('chat_message.id');
        $this->actingAs($staff)->getJson($inbox.'?filter=awaiting_reply')->assertOk()
            ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.unread_count', 1);
        $this->patchJson($providerConversation.'/read', ['through_message_id' => $incoming])->assertOk();
        $this->actingAs($owner)->getJson($inbox.'?filter=unread')->assertOk()->assertJsonCount(0, 'conversations');
        $this->getJson($inbox.'?filter=awaiting_reply')->assertOk()->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.unread_count', 0);

        $this->actingAs($staff)->postJson($providerConversation.'/messages', $this->message('Provider reply'))->assertCreated();
        $this->getJson($inbox.'?filter=awaiting_reply')->assertOk()->assertJsonCount(0, 'conversations');
        $this->getJson($inbox)->assertOk()->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.awaiting_reply', false);
        $this->actingAs($applicant)->getJson($this->url($application))->assertOk()
            ->assertJsonPath('messages.1.body', 'Provider reply')->assertJsonPath('unread_count', 1)
            ->assertJsonMissingPath('applicant_name');
        $this->assertDatabaseCount('application_status_histories', 0);
    }

    public function test_provider_inbox_paginates_newest_activity_first_and_sanitizes_return_parameters(): void
    {
        [$owner, $applicant] = $this->submittedApplication();
        $base = now();
        for ($i = 1; $i <= 21; $i++) {
            [, , $application] = $this->submittedApplication($owner, $applicant);
            $application->scholarship()->update(['title' => "Scholarship $i"]);
            $conversation = $application->conversation()->create(['last_message_at' => $base->copy()->addMinutes($i)]);
            $conversation->messages()->create([
                'sender_id' => $applicant->id, 'sender_side' => ApplicationMessage::APPLICANT,
                'body' => 'Test message', 'request_key' => (string) Str::uuid(),
            ]);
        }
        $inbox = route('provider.application-conversations.inbox', [], false);
        $this->actingAs($owner)->getJson($inbox.'/data')->assertOk()->assertJsonCount(20, 'conversations')
            ->assertJsonPath('conversations.0.program_title', 'Scholarship 21')
            ->assertJsonPath('conversations.19.program_title', 'Scholarship 2')
            ->assertJsonPath('pagination', ['page' => 1, 'last_page' => 2]);
        $second = $this->getJson($inbox.'/data?filter=awaiting_reply&page=2')->assertOk()->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.program_title', 'Scholarship 1')->json('conversations.0.href');
        $this->get($second)->assertOk()->assertViewHas('backUrl', $inbox.'?filter=awaiting_reply&page=2');
        $path = explode('?', $second)[0];
        $this->get($path.'?filter=https://example.test&page[]=55')->assertOk()
            ->assertViewHas('backUrl', $inbox.'?filter=all&page=1');
        $this->getJson('/notifications/application-messages')->assertOk()->assertJsonPath('unread_count', 21)
            ->assertJsonCount(8, 'conversations')->assertJsonPath('conversations.0.program_title', 'Scholarship 21');
    }

    public function test_chat_alerts_are_scoped_to_authorized_threads_and_clear_only_when_that_side_reads_them(): void
    {
        [$owner, $applicant, $assigned] = $this->submittedApplication();
        [, , $unassigned] = $this->submittedApplication($owner, $applicant);
        [$otherOwner, , $otherProgram] = $this->submittedApplication(null, $applicant);
        $assigned->scholarship()->update(['title' => 'First scholarship']);
        $staff = $this->messagingStaff($owner, [$assigned->scholarship_id]);
        $alerts = '/notifications/application-messages';
        $this->getJson($alerts)->assertUnauthorized();
        $this->actingAs($applicant);
        foreach ([$otherProgram, $unassigned, $assigned, $assigned] as $application) {
            $incoming = $this->postJson($this->url($application).'/messages', $this->message('Private chat content'))
                ->assertCreated()->json('chat_message.id');
        }
        $this->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 0);

        foreach ([$this->messagingStaff($owner, []), $this->messagingStaff($owner, null, ['verify_applications']),
            User::factory()->create(['role' => 'admin']), User::factory()->create(),
        ] as $actor) {
            $this->actingAs($actor)->getJson($alerts)->assertOk()->assertExactJson(['unread_count' => 0, 'conversations' => []]);
        }
        $this->actingAs($otherOwner)->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonMissing(['program_title' => 'First scholarship']);
        $this->actingAs($owner)->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 2);
        $providerAlert = $this->actingAs($staff)->getJson($alerts)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.program_title', 'First scholarship')->assertJsonPath('conversations.0.unread_count', 2)
            ->assertDontSee('Private chat content')->assertDontSee($applicant->email)->json('conversations.0.href');
        $this->assertStringStartsWith('/provider/r/', $providerAlert);
        $this->get($providerAlert)->assertOk()->assertViewIs('provider-application-message-thread');
        $this->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 1);

        $providerUrl = $this->url($assigned, 'provider');
        $reply = $this->postJson($providerUrl.'/messages', $this->message('Private provider reply'))
            ->assertCreated()->json('chat_message.id');
        $this->patchJson($providerUrl.'/read', ['through_message_id' => $incoming])->assertOk();
        $this->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($owner)->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 1);

        PortalNotification::create(['user_id' => $applicant->id, 'type' => 'portal_update', 'title' => 'Portal update', 'message' => 'One update.']);
        $this->actingAs($applicant)->patchJson('/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $applicantAlert = $this->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('conversations.0.program_title', 'First scholarship')->assertJsonMissingPath('conversations.0.applicant_name')
            ->assertDontSee('Private provider reply')->json('conversations.0.href');
        $this->assertStringStartsWith('/dashboard/r/', $applicantAlert);
        $this->get($applicantAlert)->assertOk()->assertViewIs('dashboard-application-messages');
        $this->patchJson($this->url($assigned).'/read', ['through_message_id' => $reply])->assertOk();
        $this->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseCount('portal_notifications', 1);

        $owner->providerProfile()->update(['verification_status' => 'pending']);
        $this->actingAs($owner->fresh())->getJson($alerts)->assertOk()->assertJsonPath('unread_count', 0);
    }

    private function submittedApplication(?User $owner = null, ?User $applicant = null): array
    {
        $owner ??= User::factory()->create(['role' => 'provider']);
        $applicant ??= User::factory()->create();
        $program = Scholarship::create([
            'provider_id' => $owner->id, 'title' => 'Example scholarship', 'description' => 'Application messaging test.', 'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'submitted_at' => now(),
        ]);

        return [$owner, $applicant, $application];
    }

    private function messagingStaff(User $owner, ?array $programs, array $permissions = [User::PROVIDER_MESSAGING_PERMISSION]): User
    {
        return User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => $permissions, 'assigned_program_ids' => $programs,
        ]);
    }

    private function message(string $body = 'Is this document required?'): array
    {
        return ['body' => $body, 'request_key' => (string) Str::uuid(), 'confirmed' => true];
    }

    private function url(ScholarshipApplication $application, string $side = 'dashboard'): string
    {
        return '/'.$side.'/applications/'.$application->id.'/conversation';
    }
}
