import { computed, ref } from 'vue';
import { confirmChange } from '../support/outcomeConfirmation.js';
import { showPortalToast } from '../support/portalToast.js';

const MAX_MESSAGE_LENGTH = 2000;

export function useApplicationConversation(url, {
    side = 'applicant',
    http = window.axios,
    confirm = confirmChange,
    notify = showPortalToast,
    newRequestKey = () => crypto.randomUUID(),
} = {}) {
    const conversation = ref(null);
    const messages = ref([]);
    const draft = ref('');
    const loading = ref('');
    const loadFailed = ref(false);
    const hasOlder = ref(false);
    const hasNewer = ref(false);
    const confirming = ref(false);
    const sending = ref(false);
    const busy = computed(() => Boolean(loading.value) || confirming.value || sending.value);
    const canSend = computed(() => conversation.value?.can_send === true
        && draft.value.trim().length > 0 && draft.value.length <= MAX_MESSAGE_LENGTH);
    const lifetime = new AbortController();
    const config = { signal: lifetime.signal, portalToast: false, headers: { Accept: 'application/json' } };
    let syncedThrough = null;
    let readThrough = null;
    let hasUnread = false;
    let markingRead = false;
    let historyInFlight = false;
    let pendingSend = null;

    function mergeMessages(incoming) {
        const merged = new Map(messages.value.map((message) => [message.id, message]));
        for (const message of incoming) merged.set(message.id, message);
        messages.value = [...merged.values()].sort((left, right) => left.id - right.id);
    }

    function reportError(error, title, fallback) {
        if (lifetime.signal.aborted) return;
        const data = error.response?.data;
        const message = data?.errors?.body?.[0] ?? data?.message ?? fallback;
        notify({ type: 'error', title, message: typeof message === 'string' ? message : fallback, duration: 7000 });
    }

    async function load(mode = 'initial', { silent = false } = {}) {
        if (busy.value || historyInFlight || lifetime.signal.aborted || (mode === 'older' && !hasOlder.value)) return false;
        historyInFlight = true;
        if (!silent) loading.value = mode;
        if (mode === 'initial') loadFailed.value = false;
        const params = mode === 'older' ? { before_id: messages.value[0]?.id }
            : mode === 'refresh' && syncedThrough !== null ? { after_id: syncedThrough } : {};

        try {
            const { data } = await http.get(url, { ...config, params, timeout: 10000 });
            if (!Array.isArray(data?.messages)) throw new Error('Invalid conversation response');
            if (lifetime.signal.aborted) return false;
            conversation.value = {
                program_title: data.program_title, provider_name: data.provider_name,
                applicant_name: data.applicant_name, can_send: data.can_send,
            };
            readThrough = Math.max(readThrough ?? 0, data.read_through_message_id ?? 0);
            hasUnread = data.unread_count > 0;
            mergeMessages(data.messages);
            if (mode !== 'older') syncedThrough = data.last_message_id ?? syncedThrough ?? 0;
            if (params.after_id !== undefined) hasNewer.value = data.has_more;
            else hasOlder.value = data.has_more;
            return true;
        } catch (error) {
            if (lifetime.signal.aborted) return false;
            if (!conversation.value || [401, 403, 404].includes(error.response?.status)) {
                conversation.value = null;
                messages.value = [];
                syncedThrough = null;
                hasOlder.value = false;
                hasNewer.value = false;
                loadFailed.value = true;
            }
            if (!silent) reportError(error, 'Conversation unavailable', 'Messages could not be loaded. Try again.');
            return false;
        } finally {
            loading.value = '';
            historyInFlight = false;
        }
    }

    async function markLatestRead() {
        const target = syncedThrough;
        if (!conversation.value || !hasUnread || !target || target <= (readThrough ?? 0) || markingRead || lifetime.signal.aborted) return;
        markingRead = true;
        try {
            const { data } = await http.patch(`${url}/read`, { through_message_id: target }, config);
            if (lifetime.signal.aborted) return false;
            readThrough = Math.max(readThrough ?? 0, data.read_through_message_id ?? 0);
            if (target === syncedThrough) hasUnread = data.unread_count > 0;
            return true;
        } catch {
            // Reading stays available when a read receipt fails; the next refresh can retry it.
        } finally {
            markingRead = false;
        }
    }

    async function send() {
        if (busy.value || !canSend.value || lifetime.signal.aborted) return false;
        const body = draft.value.trim();
        const eligible = () => canSend.value && !sending.value && !loading.value
            && !lifetime.signal.aborted && draft.value.trim() === body;
        confirming.value = true;
        try {
            if (!await confirm(side === 'provider' ? {
                title: 'Send this reply?',
                message: 'The applicant will see this reply as coming from your organization.',
                confirmLabel: 'Send reply',
            } : {
                title: 'Send this message?',
                message: 'The authorized scholarship provider team will be able to read it.',
                confirmLabel: 'Send message',
            }, eligible) || !eligible()) return false;
            sending.value = true;
            if (pendingSend?.body !== body) pendingSend = { body, request_key: newRequestKey() };
            const { data } = await http.post(`${url}/messages`, { ...pendingSend, confirmed: true }, config);
            if (!data?.chat_message?.id) throw new Error('Invalid send response');
            if (lifetime.signal.aborted) return false;
            mergeMessages([data.chat_message]);
            // Only a history fetch advances syncedThrough, so sending cannot skip unread replies.
            pendingSend = null;
            if (draft.value.trim() === body) draft.value = '';
            return true;
        } catch (error) {
            reportError(error, 'Message not sent', error.response?.status === 429
                ? 'Too many messages were sent. Wait a moment before trying again.'
                : 'We could not confirm that the message was sent. Your draft is still here.');
            return false;
        } finally {
            sending.value = false;
            confirming.value = false;
        }
    }

    return {
        conversation, messages, draft, loading, loadFailed, hasOlder, hasNewer, confirming, sending, busy, canSend,
        maxLength: MAX_MESSAGE_LENGTH, load, markLatestRead, send, dispose: () => lifetime.abort(),
    };
}
