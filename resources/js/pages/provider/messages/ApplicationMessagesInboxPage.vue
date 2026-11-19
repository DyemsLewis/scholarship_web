<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ProviderPageHeader from '../../../components/ProviderPageHeader.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import { startVisiblePolling } from '../../../support/visiblePolling.js';
import ProviderSidebar from '../ProviderSidebar.vue';

const { inboxUrl, inboxDataUrl, inboxFilter, inboxPage } = document.getElementById('app').dataset;
const filters = [
    { value: 'all', label: 'All' },
    { value: 'unread', label: 'Unread' },
    { value: 'awaiting_reply', label: 'Awaiting reply' },
];
const emptyText = {
    all: 'Conversations will appear here when an applicant sends a message.',
    unread: 'There are no unread conversations in your inbox.',
    awaiting_reply: 'No conversations are waiting for a provider reply.',
};
const page = Number(inboxPage);
const result = ref(null);
const loading = ref(false);
const failed = ref(false);
const lifetime = new AbortController();
let requestInFlight = false;
let stopPolling;
const timestamp = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

function inboxHref(filter, pageNumber = 1) {
    return inboxUrl + '?' + new URLSearchParams({ filter, page: pageNumber }).toString();
}

async function loadInbox({ silent = false } = {}) {
    if (requestInFlight || lifetime.signal.aborted) return;
    requestInFlight = true;
    if (!silent) {
        loading.value = true;
        failed.value = false;
    }
    try {
        const { data } = await window.axios.get(inboxDataUrl, {
            params: { filter: inboxFilter, page },
            headers: { Accept: 'application/json' },
            signal: lifetime.signal,
            portalToast: false,
            timeout: 10000,
        });
        if (!Array.isArray(data?.conversations) || !data.pagination) throw new Error('Invalid inbox response');
        if (!lifetime.signal.aborted) {
            result.value = data;
            failed.value = false;
        }
    } catch (error) {
        if (!lifetime.signal.aborted && (!silent || [401, 403].includes(error.response?.status))) {
            result.value = null;
            failed.value = true;
        }
    } finally {
        loading.value = false;
        requestInFlight = false;
    }
}

onMounted(() => {
    void loadInbox();
    // Keep users on later pages from having rows move out from under them.
    if (page === 1) stopPolling = startVisiblePolling(() => loadInbox({ silent: true }), { interval: 18000 });
});
onBeforeUnmount(() => {
    stopPolling?.();
    lifetime.abort();
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[94rem] space-y-4">
                <ProviderPageHeader
                    eyebrow="Applicant communication"
                    title="Messages"
                    description="Reply to applicants for scholarships you work on. Record decisions and schedules in their own workspaces."
                    icon="fa-regular fa-comments"
                />

                <section class="overflow-hidden rounded-sm border border-slate-300 bg-white shadow-sm" aria-label="Application message inbox">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-3 sm:px-5">
                        <nav class="flex flex-wrap items-center gap-1.5" aria-label="Filter conversations">
                            <a
                                v-for="filter in filters" :key="filter.value"
                                :href="inboxHref(filter.value)" :aria-current="filter.value === inboxFilter ? 'page' : undefined"
                                :class="['rounded-md px-3 py-2 text-sm font-bold sm:px-4', filter.value === inboxFilter ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-100']"
                            >{{ filter.label }}</a>
                        </nav>
                        <button type="button" class="student-button student-button-secondary student-button-compact" :disabled="loading" @click="loadInbox">
                            <i :class="['fa-solid fa-rotate-right', loading ? 'fa-spin' : '']" aria-hidden="true"></i>{{ loading ? 'Refreshing...' : 'Refresh' }}
                        </button>
                    </div>

                    <StaffTableLoadError v-if="failed" message="The message inbox could not be loaded. Try again." @retry="loadInbox" />
                    <p v-else-if="!result" class="px-5 py-8 text-sm text-slate-600" role="status">Loading conversations...</p>
                    <div v-else-if="!result.conversations.length" class="px-5 py-12 text-center">
                        <span class="mx-auto grid size-11 place-items-center rounded-md bg-slate-100 text-slate-600"><i class="fa-regular fa-comments" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-950">{{ page > 1 ? 'No conversations on this page' : 'No conversations to show' }}</p>
                        <p v-if="page === 1" class="mt-1 text-sm text-slate-600">{{ emptyText[inboxFilter] }}</p>
                        <a v-else :href="inboxHref(inboxFilter)" class="mt-2 inline-block text-sm font-bold text-slate-700 underline">Go to first page</a>
                    </div>
                    <template v-else>
                        <ul class="divide-y divide-slate-200" :aria-busy="loading" data-provider-message-inbox>
                            <li v-for="row in result.conversations" :key="row.key" class="grid items-center gap-3 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-5 lg:grid-cols-[minmax(0,1fr)_minmax(12rem,auto)_auto]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span :class="['grid size-10 shrink-0 place-items-center rounded-md', row.unread_count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">
                                        <i class="fa-regular fa-message" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="break-words text-base font-bold text-slate-950">{{ row.applicant_name }}</p>
                                        <p class="break-words text-sm leading-5 text-slate-600">{{ row.program_title }}</p>
                                    </div>
                                </div>
                                <div class="min-w-0 sm:col-start-1 sm:row-start-2 lg:col-start-auto lg:row-start-auto">
                                    <div class="flex flex-wrap items-center gap-1.5 text-xs font-bold">
                                        <span :class="['rounded px-2 py-1', row.awaiting_reply ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700']">{{ row.awaiting_reply ? 'Awaiting reply' : 'Provider replied' }}</span>
                                        <span v-if="row.unread_count" class="rounded bg-sky-100 px-2 py-1 text-sky-900">{{ row.unread_count }} unread</span>
                                    </div>
                                    <time class="mt-1 block text-xs text-slate-600" :datetime="row.last_message_at">{{ timestamp.format(new Date(row.last_message_at)) }}</time>
                                </div>
                                <a :href="row.href" :aria-label="'Open conversation with ' + row.applicant_name + ' for ' + row.program_title" class="student-button student-button-secondary justify-center sm:col-start-2 sm:row-span-2 sm:row-start-1 lg:col-start-auto lg:row-span-1 lg:row-start-auto">
                                    Open conversation <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </li>
                        </ul>
                        <nav v-if="result.pagination.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:px-5" aria-label="Conversation pages">
                            <p class="text-sm text-slate-600">Page {{ result.pagination.page }} of {{ result.pagination.last_page }}</p>
                            <div class="flex gap-2">
                                <a v-if="page > 1" :href="inboxHref(inboxFilter, page - 1)" class="student-button student-button-secondary student-button-compact">Previous</a>
                                <a v-if="page < result.pagination.last_page" :href="inboxHref(inboxFilter, page + 1)" class="student-button student-button-secondary student-button-compact">Next</a>
                            </div>
                        </nav>
                    </template>
                </section>
            </div>
        </section>
    </main>
</template>
