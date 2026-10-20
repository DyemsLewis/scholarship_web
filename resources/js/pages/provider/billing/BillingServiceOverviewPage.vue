<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { formatServiceMoney, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
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
} = useProviderServiceWorkspace('overview');

const briefForm = reactive({ request_summary: '', requested_outcome: '' });
const editingBrief = ref(false);
const milestones = computed(() => purchase.value?.milestones ?? []);
const completedMilestones = computed(() => milestones.value.filter((item) => item.completed).length);
const progress = computed(() => milestones.value.length
    ? Math.round((completedMilestones.value / milestones.value.length) * 100)
    : 0);

watch(purchase, (value) => {
    if (!value) return;
    briefForm.request_summary = value.request_summary ?? '';
    briefForm.requested_outcome = value.requested_outcome ?? '';
}, { immediate: true });

async function saveBrief() {
    const saved = await runAction(
        () => window.axios.patch(`/provider/billing/${purchaseId}/request`, { ...briefForm }),
        'Request brief updated.',
    );
    if (saved) editingBrief.value = false;
}

</script>

<template>
    <BillingServiceWorkspaceShell
        :purchase="purchase"
        :workspace="workspace"
        active-section="overview"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :directory-url="directoryUrl"
    >
        <section v-if="purchase.status !== 'paid'" class="mt-4 border border-amber-200 border-l-4 border-l-amber-400 bg-white px-6 py-5 shadow-sm">
            <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-800"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Payment has not been confirmed</h2><p class="mt-1 text-sm text-slate-600">Service work starts after PayMongo confirms payment.</p></div></div>
            <div class="mt-4 flex gap-3">
                <a v-if="purchase.checkout_url" :href="purchase.checkout_url" class="inline-flex h-10 items-center bg-slate-950 px-4 text-sm font-bold text-white">Continue payment</a>
                <a href="/provider/workspaces/billing/services" class="inline-flex h-10 items-center border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700">Browse services</a>
            </div>
        </section>

        <template v-else>
            <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                    <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Service brief</h2><p class="mt-1 text-xs text-slate-500">The agreed need and expected result.</p></div></div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Paid</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ formatServiceMoney(purchase.amount, purchase.currency) }}</p>
                        </div>
                        <button v-if="canModify && !editingBrief" type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:border-slate-950" @click="editingBrief = true"><i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>Edit brief</button>
                    </div>
                </header>

                <form v-if="editingBrief" class="px-6 py-5" @submit.prevent="saveBrief">
                    <label for="request-summary" class="text-sm font-bold text-slate-800">What your team needs</label>
                    <textarea id="request-summary" v-model="briefForm.request_summary" rows="4" minlength="20" maxlength="2000" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950"></textarea>
                    <label for="requested-outcome" class="mt-5 block text-sm font-bold text-slate-800">Expected outcome</label>
                    <textarea id="requested-outcome" v-model="briefForm.requested_outcome" rows="3" minlength="10" maxlength="1200" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950"></textarea>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700" @click="editingBrief = false"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>Cancel</button>
                        <button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>Save brief</button>
                    </div>
                </form>
                <dl v-else class="divide-y divide-slate-200">
                    <div class="px-6 py-5">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">What your team needs</dt>
                        <dd class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ purchase.request_summary || 'No additional brief was recorded.' }}</dd>
                    </div>
                    <div class="px-6 py-5">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Expected outcome</dt>
                        <dd class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || 'No expected outcome was recorded.' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Delivery progress</h2><p class="mt-1 text-xs text-slate-500">{{ completedMilestones }} of {{ milestones.length }} steps complete</p></div></div>
                    <span class="text-sm font-bold text-slate-700">{{ progress }}%</span>
                </header>
                <ul v-if="milestones.length" class="divide-y divide-slate-200">
                    <li v-for="milestone in milestones" :key="milestone.id" class="flex items-center gap-3 px-6 py-3.5 text-sm font-semibold" :class="milestone.completed ? 'text-slate-500' : 'text-slate-800'">
                        <i :class="['fa-solid', milestone.completed ? 'fa-circle-check text-emerald-600' : 'fa-circle text-slate-300']" aria-hidden="true"></i>
                        {{ milestone.label }}
                    </li>
                </ul>
                <div v-else class="px-6 py-10 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">Progress steps will appear when platform support starts the service.</p></div>
                <p v-if="purchase.fulfillment_notes" class="border-t border-slate-200 bg-slate-50 px-6 py-4 text-sm leading-6 text-slate-600">{{ purchase.fulfillment_notes }}</p>
            </section>

        </template>
    </BillingServiceWorkspaceShell>
</template>
