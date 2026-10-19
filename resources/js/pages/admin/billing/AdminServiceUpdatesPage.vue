<script setup>
import { computed, ref } from 'vue';
import { formatAdminServiceDateTime, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, isSaving, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('updates');
const updateForm = ref({ kind: 'progress_update', message: '' });
const timeline = computed(() => [...(purchase.value?.updates ?? [])].reverse());

async function addUpdate() {
    if (!updateForm.value.message.trim()) return;
    const saved = await runAction(
        () => window.axios.post(`/admin/billing/${purchaseId}/updates`, { kind: updateForm.value.kind, message: updateForm.value.message.trim() }),
        'Update added.',
    );
    if (saved) updateForm.value.message = '';
}
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="updates" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Service updates</h2><p class="mt-1 text-sm text-slate-500">Send one clear response or leave an internal note.</p></header>
            <form class="grid grid-cols-[14rem_minmax(0,1fr)_auto] items-end gap-3 border-b border-slate-200 bg-slate-50 px-6 py-5" @submit.prevent="addUpdate">
                <label class="text-sm font-bold text-slate-700">Type<select v-model="updateForm.kind" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="progress_update">Provider update</option><option value="clarification_request">Request information</option><option value="internal_note">Internal note</option></select></label>
                <label class="text-sm font-bold text-slate-700">Message<textarea v-model="updateForm.message" rows="2" maxlength="2000" class="mt-2 w-full border border-slate-300 bg-white px-3 py-2 font-normal" placeholder="Write a concise update."></textarea></label>
                <button type="submit" :disabled="isSaving || !updateForm.message.trim()" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ isSaving ? 'Adding...' : 'Add update' }}</button>
            </form>

            <div v-if="!timeline.length" class="min-h-80 px-6 py-16 text-center"><i class="fa-regular fa-message text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-900">No updates recorded</p></div>
            <div v-else class="divide-y divide-slate-200">
                <article v-for="entry in timeline" :key="entry.id" :class="['px-6 py-5', entry.visible_to_provider ? 'bg-white' : 'bg-amber-50/50']">
                    <div class="flex items-center gap-2"><p class="text-sm font-bold text-slate-900">{{ entry.actor_name }}</p><span v-if="!entry.visible_to_provider" class="bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-900">Internal</span><span class="ml-auto text-xs text-slate-500">{{ formatAdminServiceDateTime(entry.created_at) }}</span></div>
                    <p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ entry.message }}</p>
                </article>
            </div>
        </section>
    </AdminServiceWorkspaceShell>
</template>
