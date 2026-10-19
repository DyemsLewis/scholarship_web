<script setup>
import { computed, ref } from 'vue';
import { formatServiceDateTime, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
import BillingServiceWorkspaceShell from './components/BillingServiceWorkspaceShell.vue';

const {
    purchaseId,
    purchase,
    workspace,
    isLoading,
    isSaving,
    errorMessage,
    successMessage,
    canModify,
    directoryUrl,
    runAction,
} = useProviderServiceWorkspace('updates');

const updateMessage = ref('');
const updates = computed(() => purchase.value?.updates ?? []);

function updateLabel(kind) {
    return {
        clarification_request: 'Question from support',
        provider_response: 'Provider response',
        progress_update: 'Progress update',
        meeting_request: 'Meeting request',
        meeting_confirmed: 'Meeting confirmed',
        meeting_declined: 'Meeting declined',
        provider_file: 'File shared',
        deliverable: 'Deliverable shared',
        completion_confirmed: 'Completion confirmed',
        reopened: 'Revision requested',
    }[kind] ?? String(kind || 'update').replaceAll('_', ' ');
}

async function addUpdate() {
    if (!updateMessage.value.trim()) return;
    const saved = await runAction(
        () => window.axios.post(`/provider/billing/${purchaseId}/updates`, { message: updateMessage.value.trim() }),
        'Response sent.',
    );
    if (saved) updateMessage.value = '';
}
</script>

<template>
    <BillingServiceWorkspaceShell
        :purchase="purchase"
        :workspace="workspace"
        active-section="updates"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :directory-url="directoryUrl"
    >
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-base font-bold text-slate-950">Service updates</h2>
                <p class="mt-1 text-xs text-slate-500">Questions, responses, and delivery notices in one timeline.</p>
            </header>

            <form v-if="canModify" class="border-b border-slate-200 bg-slate-50 px-6 py-5" @submit.prevent="addUpdate">
                <label for="service-response" class="text-sm font-bold text-slate-800">Send a response</label>
                <div class="mt-2 flex items-end gap-3">
                    <textarea id="service-response" v-model="updateMessage" rows="3" minlength="3" maxlength="2000" required class="min-h-20 flex-1 border border-slate-300 bg-white px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950" placeholder="Add information or answer a platform question."></textarea>
                    <button type="submit" :disabled="isSaving || !updateMessage.trim()" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">Send response</button>
                </div>
            </form>

            <div v-if="!updates.length" class="min-h-80 px-6 py-14 text-center">
                <i class="fa-regular fa-message text-2xl text-slate-300" aria-hidden="true"></i>
                <p class="mt-3 text-sm font-bold text-slate-800">No updates have been recorded.</p>
            </div>
            <ol v-else class="divide-y divide-slate-200">
                <li v-for="update in updates" :key="update.id" class="grid grid-cols-[12rem_minmax(0,1fr)_12rem] gap-6 px-6 py-5">
                    <div><p class="text-xs font-bold text-slate-900">{{ update.actor_name }}</p><p class="mt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ update.actor_role === 'provider' ? 'Provider team' : 'Platform support' }}</p></div>
                    <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ updateLabel(update.kind) }}</p><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ update.message }}</p></div>
                    <time class="text-right text-xs font-semibold text-slate-500">{{ formatServiceDateTime(update.created_at) }}</time>
                </li>
            </ol>
        </section>
    </BillingServiceWorkspaceShell>
</template>
