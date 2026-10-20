<script setup>
import { computed, reactive, ref } from 'vue';
import { formatServiceDateTime, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
import BillingServiceWorkspaceShell from './components/BillingServiceWorkspaceShell.vue';

const {
    purchaseId, purchase, workspace, isLoading, isSaving, errorMessage, successMessage, directoryUrl, runAction,
} = useProviderServiceWorkspace('closeout');
const completion = reactive({ rating: '', feedback: '' });
const revisionReason = ref('');
const cancellationReason = ref('');
const canRequestCancellation = computed(() => purchase.value?.status === 'paid'
    && !['completed', 'cancelled'].includes(purchase.value?.fulfillment_status)
    && purchase.value?.cancellation_status !== 'requested');

async function confirmCompletion() {
    await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/confirm`, {
        rating: completion.rating || null,
        feedback: completion.feedback.trim() || null,
    }), 'Service completion confirmed.');
}

async function requestRevision() {
    const saved = await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/reopen`, {
        reason: revisionReason.value.trim(),
    }), 'Revision requested.');
    if (saved) revisionReason.value = '';
}

async function requestCancellation() {
    const saved = await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/cancellation`, {
        reason: cancellationReason.value.trim(),
    }), 'Cancellation request sent.');
    if (saved) cancellationReason.value = '';
}

function readable(value) {
    return String(value || 'Not recorded').replaceAll('_', ' ');
}
</script>

<template>
    <BillingServiceWorkspaceShell :purchase="purchase" :workspace="workspace" active-section="closeout" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :directory-url="directoryUrl">
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Closeout</h2><p class="mt-1 text-xs text-slate-500">Accept delivery, request a revision, or review closure.</p></div></div>
                <a :href="`/provider/billing/${purchaseId}/history`" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950">View history <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
            </header>

            <div v-if="purchase.fulfillment_status === 'provider_review'" class="divide-y divide-slate-200">
                <form class="px-6 py-5" @submit.prevent="confirmCompletion">
                    <h3 class="text-sm font-bold text-slate-950">Accept the delivered work</h3>
                    <p class="mt-1 text-sm text-slate-600">Confirm only after checking the shared files.</p>
                    <div class="mt-4 grid grid-cols-[9rem_minmax(0,1fr)_auto] items-end gap-3">
                        <label class="text-xs font-bold text-slate-700">Rating<select v-model="completion.rating" class="mt-2 h-10 w-full border border-slate-300 bg-white px-3 text-sm"><option value="">Optional</option><option v-for="rating in 5" :key="rating" :value="rating">{{ rating }} / 5</option></select></label>
                        <label class="text-xs font-bold text-slate-700">Feedback<input v-model="completion.feedback" maxlength="2000" class="mt-2 h-10 w-full border border-slate-300 px-3 text-sm" placeholder="Optional"></label>
                        <button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-check']" aria-hidden="true"></i>Confirm complete</button>
                    </div>
                </form>
                <form class="px-6 py-5" @submit.prevent="requestRevision">
                    <h3 class="text-sm font-bold text-slate-950">Request a revision</h3>
                    <div class="mt-3 flex gap-3"><input v-model="revisionReason" minlength="10" maxlength="2000" required class="h-10 flex-1 border border-slate-300 px-3 text-sm" placeholder="What still needs to be addressed?"><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 border border-slate-950 px-4 text-sm font-bold text-slate-950 disabled:opacity-40"><i class="fa-solid fa-paper-plane text-[10px]" aria-hidden="true"></i>Send request</button></div>
                </form>
            </div>

            <div v-else-if="purchase.fulfillment_status === 'completed'" class="px-6 py-5">
                <h3 class="text-sm font-bold text-emerald-800">Service completed</h3>
                <p class="mt-1 text-sm text-slate-600">Confirmed {{ formatServiceDateTime(purchase.provider_confirmed_at) }}<span v-if="purchase.provider_rating"> · {{ purchase.provider_rating }}/5 rating</span></p>
                <p v-if="purchase.provider_feedback" class="mt-3 border-l-2 border-amber-400 pl-3 text-sm text-slate-700">{{ purchase.provider_feedback }}</p>
                <form class="mt-5 border-t border-slate-200 pt-5" @submit.prevent="requestRevision"><label class="text-xs font-bold text-slate-700">Reopen for a necessary correction</label><div class="mt-2 flex gap-3"><input v-model="revisionReason" minlength="10" maxlength="2000" required class="h-10 flex-1 border border-slate-300 px-3 text-sm" placeholder="Explain the correction needed"><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700"><i class="fa-solid fa-rotate-left text-[10px]" aria-hidden="true"></i>Reopen</button></div></form>
            </div>

            <div v-else-if="purchase.fulfillment_status === 'cancelled'" class="px-6 py-5">
                <h3 class="text-sm font-bold text-slate-950">Service cancelled</h3>
                <dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200 text-sm"><div class="flex gap-8 py-3"><dt class="w-44 text-slate-500">Reason</dt><dd class="font-semibold text-slate-900">{{ purchase.cancellation_reason }}</dd></div><div class="flex gap-8 py-3"><dt class="w-44 text-slate-500">Decision</dt><dd class="font-semibold text-slate-900">{{ purchase.cancellation_decision_note }}</dd></div><div class="flex gap-8 py-3"><dt class="w-44 text-slate-500">Refund record</dt><dd class="font-semibold capitalize text-slate-900">{{ readable(purchase.refund_status) }}<span v-if="purchase.refund_reference"> · {{ purchase.refund_reference }}</span></dd></div></dl>
            </div>

            <div v-else class="px-6 py-5"><h3 class="text-sm font-bold text-slate-950">Delivery is still active</h3><p class="mt-1 text-sm text-slate-600">Closeout decisions become available when work is ready for review.</p></div>

            <div v-if="purchase.cancellation_status && purchase.fulfillment_status !== 'cancelled'" class="border-t border-slate-200 bg-slate-50 px-6 py-4 text-sm"><p class="font-bold capitalize text-slate-900">Cancellation {{ readable(purchase.cancellation_status) }}</p><p class="mt-1 text-slate-600">{{ purchase.cancellation_decision_note || purchase.cancellation_reason }}</p></div>

            <details v-if="canRequestCancellation" class="border-t border-slate-200 px-6 py-4">
                <summary class="cursor-pointer text-sm font-bold text-slate-700">Request cancellation</summary>
                <form class="mt-4" @submit.prevent="requestCancellation"><textarea v-model="cancellationReason" rows="3" minlength="20" maxlength="2000" required class="w-full border border-slate-300 px-3 py-2 text-sm" placeholder="Explain why the service should be cancelled."></textarea><div class="mt-3 flex justify-end"><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 border border-rose-300 px-4 text-sm font-bold text-rose-800 disabled:opacity-40"><i class="fa-solid fa-paper-plane text-[10px]" aria-hidden="true"></i>Send for review</button></div></form>
            </details>
        </section>
    </BillingServiceWorkspaceShell>
</template>
