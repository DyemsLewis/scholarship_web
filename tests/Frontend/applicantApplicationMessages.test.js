import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { useApplicationConversation } from '../../resources/js/composables/useApplicationConversation.js';
import { providerApplicationMessagesLinks } from '../../resources/js/support/providerApplicationMessagesLink.js';

const url = '/dashboard/applications/15/conversation';
const message = (id, sender_side = 'provider') => ({ id, sender_side, body: `Message ${id}`, sent_at: '2026-11-19T10:00:00.000Z' });
const history = (messages, details = {}) => ({ data: {
    messages, has_more: false, last_message_id: messages.at(-1)?.id ?? null, can_send: true,
    unread_count: 0, provider_name: 'Example Foundation', program_title: 'Example Scholarship', ...details,
} });

function scenario(side = 'applicant') {
    const calls = [], notifications = [], confirmations = [];
    const handlers = { get: async () => history([]), post: async () => ({ data: { chat_message: message(10, 'applicant') } }),
        patch: async () => ({ data: { read_through_message_id: 10, unread_count: 0 } }) };
    let decision = async () => true;
    let keys = 0;
    const http = Object.fromEntries(Object.keys(handlers).map((method) => [method, (...args) => { calls.push([method, ...args]); return handlers[method](...args); }]));
    const chat = useApplicationConversation(url, {
        http, side, confirm: async (options, allowed) => { confirmations.push(options); return allowed() && await decision(); },
        notify: (notice) => notifications.push(notice), newRequestKey: () => `request-${++keys}`,
    });
    return { chat, handlers, calls, notifications, confirmations, decide: (handler) => { decision = handler; } };
}

test('provider replies confirm organization identity and only approved permitted providers see the inbox link', async () => {
    const { chat, confirmations, handlers } = scenario('provider');
    handlers.get = async () => history([], { applicant_name: 'Sample Learner' });
    assert.equal(await chat.load(), true);
    assert.equal(chat.conversation.value.applicant_name, 'Sample Learner');
    chat.draft.value = 'Please check your schedule.';
    assert.equal(await chat.send(), true);
    assert.equal(confirmations[0].confirmLabel, 'Send reply');
    assert.match(confirmations[0].message, /organization/);

    assert.deepEqual(providerApplicationMessagesLinks(null), []);
    assert.deepEqual(providerApplicationMessagesLinks({ can_post_scholarships: true, permissions: ['verify_applications'] }), []);
    assert.deepEqual(providerApplicationMessagesLinks({ can_post_scholarships: false, permissions: ['manage_application_messages'] }), []);
    assert.equal(providerApplicationMessagesLinks({ can_post_scholarships: true, permissions: ['manage_application_messages'] })[0].href, '/provider/workspaces/messages');
});

test('only valid drafts ask for confirmation and cancelling leaves the draft unsent', async () => {
    const { chat, calls, confirmations, decide } = scenario();
    await chat.load();
    chat.draft.value = '   ';
    assert.equal(await chat.send(), false);
    chat.draft.value = 'x'.repeat(2001);
    assert.equal(await chat.send(), false);
    assert.equal(confirmations.length, 0);
    chat.draft.value = 'Will the schedule change?';
    decide(async () => false);
    assert.equal(await chat.send(), false);
    assert.equal(confirmations[0].confirmLabel, 'Send message');
    assert.equal(chat.draft.value, 'Will the schedule change?');
    assert.equal(calls.filter(([method]) => method === 'post').length, 0);
});

test('failed sends show one notification and retry with the same key; success clears the draft', async () => {
    const { chat, handlers, calls, notifications } = scenario();
    await chat.load();
    chat.draft.value = '  Is the interview online?  ';
    handlers.post = async () => { throw new Error('Connection lost'); };
    assert.equal(await chat.send(), false);
    assert.equal(chat.draft.value, '  Is the interview online?  ');
    assert.equal(notifications.length, 1);
    assert.equal(notifications[0].title, 'Message not sent');
    handlers.post = async () => ({ data: { chat_message: message(10, 'applicant') } });
    assert.equal(await chat.send(), true);
    const posts = calls.filter(([method]) => method === 'post');
    assert.deepEqual(posts.map(([, endpoint, data]) => [endpoint, data]), [
        [`${url}/messages`, { body: 'Is the interview online?', request_key: 'request-1', confirmed: true }],
        [`${url}/messages`, { body: 'Is the interview online?', request_key: 'request-1', confirmed: true }],
    ]);
    assert.equal(posts[0][3].portalToast, false);
    assert.equal(chat.draft.value, '');
    assert.deepEqual(chat.messages.value.map(({ id }) => id), [10]);
});

test('a pending confirmation blocks duplicate prompts and rechecks the draft', async () => {
    const { chat, calls, decide, confirmations } = scenario();
    await chat.load();
    chat.draft.value = 'First message';
    let accept;
    decide(() => new Promise((resolve) => { accept = resolve; }));
    const saving = chat.send();
    assert.equal(await chat.send(), false);
    assert.equal(confirmations.length, 1);
    chat.draft.value = 'Different message';
    accept(true);
    assert.equal(await saving, false);
    assert.equal(calls.filter(([method]) => method === 'post').length, 0);
});

test('older history preserves the refresh cursor and sending does not skip unseen replies', async () => {
    const { chat, handlers, calls } = scenario();
    handlers.get = async () => history([message(4), message(5)], { has_more: true, unread_count: 2 });
    await chat.load();
    chat.draft.value = 'My reply';
    await chat.send();
    handlers.get = async (_, { params }) => params.before_id ? history([message(2)]) : history([message(6), message(10, 'applicant')]);
    await chat.load('older');
    await chat.load('refresh');
    assert.deepEqual(chat.messages.value.map(({ id }) => id), [2, 4, 5, 6, 10]);
    assert.deepEqual(calls.filter(([method]) => method === 'get').map(([, , { params }]) => params), [{}, { before_id: 4 }, { after_id: 5 }]);
    assert.equal(chat.hasOlder.value, false);
});

test('read tracking uses the loaded history; denied access clears visible messages without discarding the draft', async () => {
    const { chat, handlers, calls } = scenario();
    handlers.get = async () => history([message(8)], { unread_count: 1 });
    handlers.patch = async () => ({ data: { read_through_message_id: 8, unread_count: 0 } });
    await chat.load();
    await chat.markLatestRead();
    await chat.markLatestRead();
    const reads = calls.filter(([method]) => method === 'patch');
    assert.equal(reads.length, 1);
    assert.deepEqual(reads[0].slice(1, 3), [`${url}/read`, { through_message_id: 8 }]);
    chat.draft.value = 'Keep this draft';
    handlers.get = async () => { throw { response: { status: 403 } }; };
    assert.equal(await chat.load('refresh'), false);
    assert.deepEqual(chat.messages.value, []);
    assert.equal(chat.canSend.value, false);
    assert.equal(chat.draft.value, 'Keep this draft');
});

test('background history updates do not interrupt a draft or start a competing history request', async () => {
    const { chat, notifications, handlers, calls } = scenario();
    await chat.load();
    chat.draft.value = 'A reply in progress';
    let resolvePoll;
    handlers.get = () => new Promise((resolve) => { resolvePoll = resolve; });
    const pending = chat.load('refresh', { silent: true });
    assert.equal(chat.canSend.value, true);
    assert.equal(chat.busy.value, false);
    assert.equal(await chat.load('refresh'), false);
    assert.equal(calls.filter(([method]) => method === 'get').length, 2);
    assert.deepEqual(calls.at(-1)[2].params, { after_id: 0 });
    resolvePoll(history([message(8)]));
    assert.equal(await pending, true);
    assert.equal(chat.draft.value, 'A reply in progress');
    assert.deepEqual(chat.messages.value.map((item) => item.id), [8]);

    handlers.get = async () => { throw new Error('Temporary connection failure'); };
    assert.equal(await chat.load('refresh', { silent: true }), false);
    assert.equal(chat.loadFailed.value, false);
    assert.deepEqual(notifications, []);
    assert.equal(chat.draft.value, 'A reply in progress');
});

test('both entry points, the thread and the inbox compile, rendering text safely', () => {
    for (const path of [
        'pages/UserApplicationMessagesPage.vue', 'pages/provider/messages/ApplicationMessagesInboxPage.vue', 'components/application-detail/ApplicantApplicationShell.vue',
        'components/monitoring-detail/ApplicantMonitoringShell.vue',
    ]) {
        const source = readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const bindings = compileScript(descriptor, { id: path }).bindings;
        assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: path, id: path, compilerOptions: { bindingMetadata: bindings } }).errors, []);
        if (path.includes('Shell')) assert.match(source, /:href="detail.identity.conversation_href"/);
        else if (path.includes('InboxPage')) {
            assert.match(source, /\{\{ row.applicant_name \}\}/);
            assert.doesNotMatch(source, /v-html|message.body/);
        } else {
            assert.match(source, /\{\{ message.body \}\}/);
            assert.doesNotMatch(source, /v-html/);
        }
    }
});
