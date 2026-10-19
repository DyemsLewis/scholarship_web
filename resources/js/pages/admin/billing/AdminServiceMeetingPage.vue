<script setup>
import { computed, ref, watch } from 'vue';
import { formatAdminServiceDateTime, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, isSaving, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('meeting');
const responseNote = ref('');
const joinUrl = ref('');
const needsJoinUrl = computed(() => purchase.value?.meeting_mode === 'online');

watch(purchase, (payload) => {
    if (!payload) return;
    responseNote.value = payload.meeting_admin_note ?? '';
    joinUrl.value = payload.meeting_join_url ?? '';
});

async function decideMeeting(status) {
    await runAction(
        () => window.axios.patch(`/admin/billing/${purchaseId}/meeting`, {
            meeting_status: status,
            meeting_admin_note: responseNote.value.trim() || null,
            meeting_join_url: status === 'confirmed' && needsJoinUrl.value ? joinUrl.value.trim() : null,
        }),
        status === 'confirmed' ? 'Meeting confirmed.' : 'Meeting declined.',
    );
}
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="meeting" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Service meeting</h2><p class="mt-1 text-sm text-slate-500">Confirm the provider’s proposed time or request another.</p></header>
            <div v-if="purchase.meeting_scheduled_for" class="px-6 py-5">
                <dl class="grid grid-cols-3 divide-x divide-slate-200 border border-slate-200 bg-slate-50">
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Schedule</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ formatAdminServiceDateTime(purchase.meeting_scheduled_for) }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Method</dt><dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_mode }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_status }}</dd></div>
                </dl>
                <div class="mt-5"><h3 class="text-sm font-black text-slate-900">Purpose</h3><p class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ purchase.meeting_purpose }}</p></div>
                <label v-if="purchase.meeting_status === 'requested' && needsJoinUrl" class="mt-5 block max-w-4xl text-sm font-bold text-slate-700">Online meeting link<input v-model="joinUrl" type="url" maxlength="2048" class="mt-2 h-11 w-full border border-slate-300 px-4 font-normal" placeholder="https://meet.example.com/..." required></label>
                <label v-if="purchase.meeting_status === 'requested'" class="mt-5 block max-w-4xl text-sm font-bold text-slate-700">Response note <span class="font-normal text-slate-500">required when declining</span><textarea v-model="responseNote" rows="3" maxlength="1000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Add joining instructions or explain why another time is needed."></textarea></label>
                <a v-if="purchase.meeting_status === 'confirmed' && purchase.meeting_join_url" :href="purchase.meeting_join_url" target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white"><i class="fa-solid fa-video" aria-hidden="true"></i>Open meeting</a>
                <div v-if="purchase.meeting_status !== 'requested' && purchase.meeting_admin_note" class="mt-5 border-l-2 border-slate-300 pl-4"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Response</p><p class="mt-1 text-sm leading-6 text-slate-600">{{ purchase.meeting_admin_note }}</p></div>
            </div>
            <div v-else class="min-h-80 px-6 py-16 text-center"><i class="fa-regular fa-calendar text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-900">No meeting requested</p><p class="mt-1 text-sm text-slate-500">A provider request will appear here for review.</p></div>
            <footer v-if="purchase.meeting_status === 'requested'" class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" :disabled="isSaving" class="h-11 border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 disabled:opacity-40" @click="decideMeeting('declined')">Decline</button><button type="button" :disabled="isSaving || (needsJoinUrl && !joinUrl.trim())" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40" @click="decideMeeting('confirmed')">Confirm meeting</button></footer>
        </section>
    </AdminServiceWorkspaceShell>
</template>
