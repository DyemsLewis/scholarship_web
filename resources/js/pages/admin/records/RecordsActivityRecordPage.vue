<script setup>
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { staffQueueReturn } from '../../../support/staffQueueState.js';

const entryId = document.getElementById('app')?.dataset.entryId;
const backUrl = staffQueueReturn(window, 'adminRecords', '/admin/workspaces/records/activity');
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/workspaces/records/activity/${entryId}/data`, { signal });
    if (!response.data.entry) throw new Error('Missing record');
    return response.data.entry;
}, { failureMessage: 'The activity record could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const entry = computed(() => resource.data.value);
const ready = computed(() => !!entry.value && !loading.value && !errorMessage.value);

function roleLabel(value) {
    return value === 'system' ? 'System' : String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

const loadRecord = () => resource.load();

onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer" class="admin-records-workspace">
        <a :href="backUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to activity records</a>

        <AdminWorkspaceHeader
            eyebrow="Audit entry"
            title="Activity record"
            :description="entry?.action_label || 'Immutable platform activity record.'"
            icon="fa-solid fa-file-shield"
        />

        <section v-if="!ready" class="admin-panel mt-4"><AdminLoadState :loading="loading" :error="errorMessage" loading-message="Loading activity record..." @retry="loadRecord" /></section>

        <section v-else-if="ready" class="admin-panel mt-4 overflow-hidden">
            <header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Recorded event</p><h2 class="mt-1 text-lg font-black leading-7 text-slate-950">{{ entry.description }}</h2></header>

            <dl class="grid grid-cols-4 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50">
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Actor</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ entry.actor_name }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Role</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ roleLabel(entry.actor_role) }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Source IP</dt><dd class="mt-1 font-mono text-xs font-bold text-slate-900">{{ entry.ip_address || 'Not recorded' }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Recorded</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ entry.created_at }}</dd></div>
            </dl>

            <div class="border-b border-slate-200 px-6 py-4"><p class="text-sm text-slate-600">This is a recorded event, not the current decision. Later entries may supersede it.</p><div v-if="entry.related_trails?.length" class="mt-3 flex flex-wrap gap-2"><a v-for="trail in entry.related_trails" :key="trail.url" :href="trail.url" class="inline-flex h-10 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>{{ trail.label }}</a></div></div>

            <section class="border-b border-slate-200 px-6 py-5">
                <h3 class="text-sm font-black text-slate-950">Recorded details</h3>
                <dl v-if="entry.metadata?.length" class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                    <div v-for="item in entry.metadata" :key="item.label" class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 py-3"><dt class="text-sm font-semibold text-slate-500">{{ item.label }}</dt><dd class="whitespace-pre-wrap break-words text-sm font-semibold text-slate-900">{{ item.value }}</dd></div>
                </dl>
                <p v-else class="mt-2 text-sm text-slate-500">No additional details were recorded.</p>
            </section>

            <details class="group">
                <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50"><span class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-desktop text-xs" aria-hidden="true"></i></span>Client information</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                <p class="break-words border-t border-slate-200 bg-slate-50 px-6 py-4 font-mono text-xs leading-6 text-slate-600">{{ entry.user_agent || 'User agent not recorded.' }}</p>
            </details>
        </section>
    </AdminWorkspacePage>
</template>
