<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from './provider/ProviderSidebar.vue';
import { useApplicationConversation } from '../composables/useApplicationConversation.js';
import { APPLICATION_MESSAGES_READ_EVENT } from '../support/applicationMessageEvents.js';
import { startVisiblePolling } from '../support/visiblePolling.js';

const { conversationUrl, conversationSide, backUrl, backLabel } = document.getElementById('app').dataset;
const side = conversationSide === 'provider' ? 'provider' : 'applicant';
const isProvider = side === 'provider';
const {
    conversation, messages, draft, loading, loadFailed, hasOlder, hasNewer,
    confirming, sending, busy, canSend, maxLength, load, markLatestRead, send, dispose,
} = useApplicationConversation(conversationUrl, { side });
const history = ref(null);
const composer = ref(null);
const announcement = ref('');
const newMessagesBelow = ref(false);
let followingLatest = true;
let stopPolling;
const timestamp = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

function senderLabel(message) {
    if (!isProvider) return message.sender_side === 'applicant' ? 'You' : 'Provider';
    return message.sender_side === 'provider' ? 'Your organization' : (conversation.value.applicant_name || 'Applicant');
}

function scrollToLatest() {
    if (history.value) history.value.scrollTop = history.value.scrollHeight;
    followingLatest = true;
    newMessagesBelow.value = false;
}

async function markViewed() {
    if (followingLatest && document.visibilityState === 'visible' && await markLatestRead()) {
        window.dispatchEvent(new Event(APPLICATION_MESSAGES_READ_EVENT));
    }
}

function onHistoryScroll() {
    const element = history.value;
    if (!element) return;
    followingLatest = element.scrollHeight - element.scrollTop - element.clientHeight <= 12;
    if (followingLatest) {
        newMessagesBelow.value = false;
        void markViewed();
    }
}

function catchUp() {
    scrollToLatest();
    void markViewed();
}

async function loadMessages(mode = 'initial', { silent = false } = {}) {
    const previousHeight = history.value?.scrollHeight ?? 0;
    const previousTop = history.value?.scrollTop ?? 0;
    const count = messages.value.length;
    if (!silent) announcement.value = '';
    if (!await load(mode, { silent })) return;

    await nextTick();
    const addedMessages = messages.value.length > count;
    if (mode === 'older' && history.value) {
        history.value.scrollTop = previousTop + history.value.scrollHeight - previousHeight;
    } else if (!silent || (followingLatest && document.visibilityState === 'visible')) {
        if (addedMessages || !silent) scrollToLatest();
        void markViewed();
    } else if (addedMessages) {
        followingLatest = false;
        newMessagesBelow.value = true;
    }
    if (mode === 'refresh' && (addedMessages || !silent)) {
        announcement.value = addedMessages ? 'New messages loaded.' : 'No new messages.';
    }
}

async function sendMessage() {
    announcement.value = '';
    if (!await send()) return;
    await nextTick();
    scrollToLatest();
    composer.value?.focus({ preventScroll: true });
    announcement.value = 'Message sent.';
}

onMounted(() => {
    void loadMessages();
    stopPolling = startVisiblePolling(() => conversation.value && loadMessages('refresh', { silent: true }), { interval: 12000 });
});
onBeforeUnmount(() => {
    stopPolling?.();
    dispose();
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar v-if="isProvider" />
        <ApplicantSidebar v-else />
        <section class="student-page">
            <div class="mx-auto w-full max-w-5xl space-y-4">
                <a :href="backUrl" class="inline-flex min-h-8 items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to {{ backLabel }}
                </a>

                <ProviderPageHeader v-if="isProvider" eyebrow="Private application conversation" title="Reply to applicant" icon="fa-regular fa-comment-dots" />
                <ApplicantPageHeader v-else compact eyebrow="Private application conversation" title="Message provider" icon="fa-regular fa-comment-dots" />

                <div v-if="loadFailed" class="student-card rounded-md border-slate-300 px-6 py-10 text-center">
                    <p class="font-bold text-slate-950">Conversation unavailable</p>
                    <button type="button" class="student-button student-button-secondary mt-3" @click="loadMessages()">Try again</button>
                </div>
                <div v-else-if="!conversation" class="student-card rounded-md border-slate-300 p-6 text-sm text-slate-600" role="status">
                    <i class="fa-solid fa-circle-notch fa-spin mr-2" aria-hidden="true"></i>Loading conversation...
                </div>

                <section v-else data-application-chat class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-label="Application conversation">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-6">
                        <div class="min-w-0">
                            <h2 class="break-words text-base font-bold text-slate-950 sm:text-lg">{{ isProvider ? conversation.applicant_name : conversation.provider_name }}</h2>
                            <p class="mt-0.5 break-words text-xs leading-5 text-slate-600 sm:text-sm">{{ conversation.program_title }}</p>
                        </div>
                        <button type="button" class="student-button student-button-secondary student-button-compact" :disabled="busy" @click="loadMessages('refresh')">
                            <i :class="['fa-solid fa-rotate-right', loading === 'refresh' ? 'fa-spin' : '']" aria-hidden="true"></i>
                            {{ loading === 'refresh' ? 'Refreshing...' : hasNewer ? 'Load more new messages' : 'Refresh' }}
                        </button>
                    </header>

                    <div ref="history" tabindex="0" aria-label="Message history" data-chat-history class="max-h-[min(50dvh,28rem)] min-h-48 overflow-y-auto overscroll-contain bg-slate-50/70 px-4 py-4 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-amber-500 sm:px-6" @scroll.passive="onHistoryScroll">
                        <div v-if="hasOlder" class="mb-4 text-center">
                            <button type="button" class="student-button student-button-secondary student-button-compact" :disabled="busy" @click="loadMessages('older')">
                                {{ loading === 'older' ? 'Loading...' : 'Load older messages' }}
                            </button>
                        </div>
                        <div v-if="!messages.length" class="flex min-h-40 flex-col items-center justify-center gap-2 text-center">
                            <span class="grid size-10 place-items-center rounded-md border border-slate-200 bg-white text-slate-600"><i class="fa-regular fa-comments" aria-hidden="true"></i></span>
                            <p class="text-sm font-bold text-slate-950">No messages yet</p>
                            <p class="text-xs leading-5 text-slate-600">{{ conversation.can_send && !isProvider ? 'Ask about this application, a schedule, or what you need to do next.' : 'There is no message history for this application.' }}</p>
                        </div>
                        <ol v-else class="space-y-3">
                            <li v-for="message in messages" :key="message.id" :class="['flex', message.sender_side === side ? 'justify-end' : 'justify-start']">
                                <article :class="['min-w-0 max-w-[91%] rounded-md border px-3 py-2.5 sm:max-w-[75%] sm:px-4', message.sender_side === side ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white']">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600">
                                        <span class="font-bold text-slate-800">{{ senderLabel(message) }}</span>
                                        <time :datetime="message.sent_at">{{ timestamp.format(new Date(message.sent_at)) }}</time>
                                    </p>
                                    <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-950 [overflow-wrap:anywhere]">{{ message.body }}</p>
                                </article>
                            </li>
                        </ol>
                    </div>
                    <div v-if="newMessagesBelow" class="border-t border-slate-200 bg-amber-50 px-4 py-2 text-center">
                        <button type="button" class="student-button student-button-secondary student-button-compact" @click="catchUp">
                            <i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i>Go to new messages
                        </button>
                    </div>

                    <form v-if="conversation.can_send" class="border-t border-slate-200 px-4 py-4 sm:px-6" @submit.prevent="sendMessage">
                        <label for="application-message-body" class="block text-sm font-bold text-slate-950">{{ isProvider ? 'Your reply' : 'Your message' }}</label>
                        <textarea id="application-message-body" ref="composer" v-model="draft" :maxlength="maxLength" :readonly="confirming || sending" rows="3" aria-describedby="application-message-privacy" :placeholder="isProvider ? 'Write a reply to the applicant' : 'Write a message to the provider'" class="mt-2 block min-h-20 w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm leading-6 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"></textarea>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                            <p id="application-message-privacy" class="text-xs leading-5 text-slate-600">Don’t share passwords, bank details, or identity documents here.</p>
                            <button type="submit" class="student-button student-button-primary" :disabled="!canSend || busy" data-chat-send>
                                <i :class="sending ? 'fa-solid fa-spinner fa-spin' : 'fa-regular fa-paper-plane'" aria-hidden="true"></i>{{ sending ? 'Sending...' : isProvider ? 'Send reply' : 'Send message' }}
                            </button>
                        </div>
                    </form>
                    <p v-else class="border-t border-slate-200 px-4 py-4 text-sm text-slate-600 sm:px-6">This provider is not accepting messages right now. Existing messages are still available to read.</p>
                </section>
                <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
            </div>
        </section>
    </main>
</template>
