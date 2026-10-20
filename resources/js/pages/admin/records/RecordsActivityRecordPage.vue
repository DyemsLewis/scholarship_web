<script setup>
import { onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const entryId = document.getElementById('app')?.dataset.entryId;
const loading = ref(true);
const errorMessage = ref('');
const entry = ref(null);

function roleLabel(value) {
    return value === 'system' ? 'System' : String(value ?? '').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadRecord() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/workspaces/records/activity/${entryId}/data`);
        entry.value = response.data.entry ?? null;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The activity record could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer">
        <a href="/admin/workspaces/records/activity" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to activity records</a>

        <AdminWorkspaceHeader
            eyebrow="Audit entry"
            title="Activity record"
            :description="entry?.action_label || 'Immutable platform activity record.'"
            icon="fa-solid fa-file-shield"
        />

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading activity record...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <section v-else-if="entry" class="admin-panel mt-4 overflow-hidden">
            <header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Recorded event</p><h2 class="mt-1 text-lg font-black leading-7 text-slate-950">{{ entry.description }}</h2></header>

            <dl class="grid grid-cols-4 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50">
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Actor</dt><dd class="mt-1 truncate text-sm font-bold text-slate-900">{{ entry.actor_name }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Role</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ roleLabel(entry.actor_role) }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Source IP</dt><dd class="mt-1 font-mono text-xs font-bold text-slate-900">{{ entry.ip_address || 'Not recorded' }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Recorded</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ entry.created_at }}</dd></div>
            </dl>

            <section class="border-b border-slate-200 px-6 py-5">
                <h3 class="text-sm font-black text-slate-950">Recorded details</h3>
                <dl v-if="entry.metadata.length" class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                    <div v-for="item in entry.metadata" :key="item.label" class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 py-3"><dt class="text-sm font-semibold text-slate-500">{{ item.label }}</dt><dd class="break-words text-sm font-semibold text-slate-900">{{ item.value }}</dd></div>
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
