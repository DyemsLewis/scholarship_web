<script setup>
import { ref, watch } from 'vue';
import AlertDialog from '../AlertDialog.vue';
import RecipientOnboardingDialog from './RecipientOnboardingDialog.vue';

const props = defineProps({ endpoint: { type: String, required: true }, recipientName: { type: String, default: '' } });
const emit = defineEmits(['saved']);
const onboarding = ref(null);
const loading = ref(true);
const mode = ref('instructions');
const open = ref(false);
const error = ref('');
const showError = ref(false);
watch(() => props.endpoint, load, { immediate: true });

async function load() {
    loading.value = true;
    error.value = '';
    try {
        onboarding.value = (await window.axios.get(props.endpoint, { portalToast: false })).data.onboarding;
    } catch (failure) {
        error.value = failure.response?.data?.message || 'The onboarding record could not be loaded.';
        showError.value = true;
    } finally {
        loading.value = false;
    }
}
function edit(value) {
    if (!onboarding.value?.actions?.[value === 'completion' ? 'complete' : 'publish']) return;
    mode.value = value;
    open.value = true;
}
function saved(value) {
    onboarding.value = value;
    open.value = false;
    emit('saved');
}
async function reload() {
    open.value = false;
    await load();
}
</script>

<template>
    <section class="mt-4 overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm" data-provider-onboarding>
        <header class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
            <div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Provider onboarding</h2><p class="mt-1 text-sm text-slate-500">Complete the final agreement through your own arrangements.</p></div></div>
            <span v-if="onboarding" :class="['shrink-0 rounded-sm border px-3 py-1 text-xs font-bold uppercase', onboarding.status === 'completed' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : onboarding.final_agreement_required ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-600']">{{ onboarding.status_label }}</span>
        </header>
        <div v-if="loading" class="px-6 py-12 text-center text-sm text-slate-500">Loading onboarding record...</div>
        <div v-else-if="error" class="px-6 py-8"><p class="text-sm text-slate-600">The onboarding record is unavailable.</p><button type="button" class="mt-3 rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700" @click="load">Try again</button></div>
        <template v-else-if="onboarding">
            <p v-if="onboarding.read_only_reason" class="border-b border-slate-200 bg-slate-50 px-6 py-4 text-sm leading-6 text-slate-600">{{ onboarding.read_only_reason }}</p>
            <template v-if="onboarding.final_agreement_required">
                <dl class="grid grid-cols-3 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50/70">
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Intention confirmed</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ onboarding.intention_confirmed_label || 'Awaiting applicant response' }}</dd></div>
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Final agreement</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ onboarding.completed_label || 'Completion not yet recorded' }}</dd></div>
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Confirmed by</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ onboarding.confirmed_by_name || (onboarding.provider_confirmation_recorded ? 'Authorized provider officer' : 'Not yet confirmed') }}</dd></div>
                </dl>
                <div class="space-y-5 px-6 py-6">
                    <details class="group rounded-sm border border-slate-200 bg-slate-50/60">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 text-sm font-bold text-slate-700 [&::-webkit-details-marker]:hidden">Requirement disclosed with the offer<i class="fa-solid fa-chevron-down text-[10px] group-open:rotate-180" aria-hidden="true"></i></summary>
                        <p class="whitespace-pre-line border-t border-slate-200 px-4 py-4 text-sm leading-6 text-slate-600">{{ onboarding.instructions }}</p>
                    </details>
                    <section>
                        <div class="flex items-center justify-between gap-5"><h3 class="text-base font-bold text-slate-950">Provider instructions</h3><button v-if="onboarding.actions.publish" type="button" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="edit('instructions')"><i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>{{ onboarding.provider_instructions ? 'Edit instructions' : 'Publish instructions' }}</button></div>
                        <p class="mt-3 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ onboarding.provider_instructions || 'Explain how to complete the agreement during orientation, first benefit collection, or through the provider office. No separate signing appointment is required.' }}</p>
                        <p v-if="onboarding.instructions_published_label" class="mt-2 text-xs text-slate-500">Published {{ onboarding.instructions_published_label }}<span v-if="onboarding.instructions_published_by_name"> by {{ onboarding.instructions_published_by_name }}</span></p>
                    </section>
                    <section v-if="onboarding.provider_confirmation_recorded" class="border-l-2 border-slate-700 bg-slate-50 px-4 py-4">
                        <h3 class="text-sm font-bold text-slate-950">Completion recorded</h3><p class="mt-1 text-sm leading-6 text-slate-600">{{ onboarding.confirmed_by_name || 'An authorized provider officer' }} confirmed completion on {{ onboarding.confirmed_label }}. The actual contract remains with the provider.</p><p v-if="onboarding.confirmation_note" class="mt-2 whitespace-pre-line text-sm text-slate-600"><strong>Internal reference:</strong> {{ onboarding.confirmation_note }}</p>
                    </section>
                </div>
                <footer v-if="onboarding.actions.complete" class="flex items-center justify-between gap-6 border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="max-w-3xl text-xs leading-5 text-slate-500">Attendance and offer acceptance do not confirm signing. Record completion only after checking your organization's agreement records.</p><button type="button" class="inline-flex h-11 shrink-0 items-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white" @click="edit('completion')"><i class="fa-solid fa-circle-check text-xs text-amber-300" aria-hidden="true"></i>Record completion</button></footer>
            </template>
            <details v-if="onboarding.history.length" class="group border-t border-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-5 px-6 py-4 text-sm font-bold text-slate-700 [&::-webkit-details-marker]:hidden">Onboarding history <span class="flex items-center gap-3 text-xs text-slate-500">{{ onboarding.history.length }} updates<i class="fa-solid fa-chevron-down text-[10px] group-open:rotate-180" aria-hidden="true"></i></span></summary>
                <ol class="divide-y divide-slate-200 border-t border-slate-200"><li v-for="event in onboarding.history" :key="event.revision" class="px-6 py-4"><p class="text-sm font-bold text-slate-950">{{ event.label }}</p><p class="mt-1 text-xs text-slate-500">{{ event.actor || 'Provider' }} · {{ event.recorded_label }}</p><details v-if="event.details.instructions || event.details.note" class="mt-2"><summary class="cursor-pointer text-xs font-semibold text-slate-600">View recorded details</summary><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ event.details.instructions || event.details.note }}</p></details></li></ol>
            </details>
        </template>
    </section>
    <RecipientOnboardingDialog v-if="onboarding" :open="open" :mode="mode" :onboarding="onboarding" :recipient-name="recipientName" @close="open = false" @saved="saved" @reload="reload" />
    <AlertDialog :open="showError" title="Onboarding record unavailable" :message="error" @close="showError = false" />
</template>
