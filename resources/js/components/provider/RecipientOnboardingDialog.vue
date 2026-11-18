<script setup>
import { computed, ref, watch } from 'vue';
import ConfirmationDialog from '../ConfirmationDialog.vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { providerDate } from '../../support/providerDates';

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'instructions' },
    onboarding: { type: Object, required: true },
    recipientName: { type: String, default: '' },
});
const emit = defineEmits(['close', 'saved', 'reload']);
const dialog = ref(null);
const instructions = ref('');
const completedOn = ref('');
const note = ref('');
const confirmed = ref(false);
const reviewing = ref(false);
const busy = ref(false);
const error = ref('');
const conflict = ref(false);
const completing = computed(() => props.mode === 'completion');
const title = computed(() => completing.value ? 'Record onboarding completion' : 'Publish onboarding instructions');
const endpoint = computed(() => completing.value ? props.onboarding.actions?.complete : props.onboarding.actions?.publish);
const today = computed(() => props.onboarding.today || providerDate());
const valid = computed(() => Boolean(endpoint.value) && (completing.value
    ? confirmed.value && /^\d{4}-\d{2}-\d{2}$/.test(completedOn.value) && completedOn.value <= today.value
        && (!props.onboarding.minimum_completed_on || completedOn.value >= props.onboarding.minimum_completed_on) && note.value.length <= 1000
    : Boolean(instructions.value.trim()) && instructions.value.length <= 3000));
const changed = computed(() => completing.value || instructions.value.trim() !== (props.onboarding.provider_instructions || '').trim());
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
watch(() => props.open, open => {
    if (!open) return;
    instructions.value = props.onboarding.provider_instructions || '';
    completedOn.value = today.value;
    note.value = '';
    confirmed.value = false;
    reviewing.value = false;
    busy.value = false;
    error.value = '';
    conflict.value = false;
}, { immediate: true });

function close() {
    if (!busy.value && !reviewing.value) emit('close');
}
function review() {
    if (valid.value && changed.value && !busy.value) reviewing.value = true;
}
async function save() {
    if (conflict.value) {
        reviewing.value = false;
        emit('reload');
        return;
    }
    if (!props.open || !reviewing.value || busy.value || !valid.value) return;
    busy.value = true;
    error.value = '';
    try {
        const payload = completing.value
            ? { completed_on: completedOn.value, note: note.value.trim() || null, confirmed: true }
            : { instructions: instructions.value.trim(), confirmed: true };
        const response = await window.axios.post(endpoint.value, { ...payload, expected_revision: props.onboarding.revision }, { portalToast: false });
        reviewing.value = false;
        emit('saved', response.data.onboarding);
    } catch (failure) {
        conflict.value = failure.response?.status === 409;
        error.value = Object.values(failure.response?.data?.errors || {}).flat()[0]
            || failure.response?.data?.message || 'The onboarding update could not be saved. Try again.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/60 p-6" @click.self="close">
            <form ref="dialog" role="dialog" aria-modal="true" aria-labelledby="onboarding-dialog-title" tabindex="-1" :inert="reviewing || undefined" :aria-hidden="reviewing || undefined" class="flex max-h-[calc(100dvh-3rem)] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-2xl outline-none" data-provider-onboarding-dialog :data-mode="mode" @keydown="handleKeydown" @submit.prevent="review">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-6 py-5">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Provider onboarding</p><h2 id="onboarding-dialog-title" class="mt-1 text-xl font-bold text-slate-950">{{ title }}</h2><p class="mt-1 text-sm text-slate-500">{{ recipientName }}</p></div>
                    </div>
                    <button type="button" :disabled="busy" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600" aria-label="Close onboarding dialog" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="min-h-0 space-y-5 overflow-y-auto px-6 py-5">
                    <template v-if="completing">
                        <p class="border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-slate-700">Confirm that the final agreement was completed through your organization's process. Attendance alone is not proof of signing. Do not upload the contract here.</p>
                        <label class="block text-sm font-semibold text-slate-700" for="onboarding-completed-on">Date completed <span class="font-normal text-slate-500">(Philippine time)</span><input id="onboarding-completed-on" v-model="completedOn" type="date" required :min="onboarding.minimum_completed_on || undefined" :max="today" class="mt-2 h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-950"></label>
                        <label class="block text-sm font-semibold text-slate-700" for="onboarding-note">Internal reference <span class="font-normal text-slate-500">(optional)</span><textarea id="onboarding-note" v-model="note" rows="3" maxlength="1000" class="mt-2 w-full resize-y rounded-md border border-slate-300 px-3 py-2 text-sm leading-6 text-slate-950" placeholder="For example: agreement retained by the scholarship office."></textarea><span class="mt-1 block text-xs font-normal leading-5 text-slate-500">Visible to authorized recipient staff only. Do not paste contract contents or sensitive identifiers.</span></label>
                        <label class="flex items-start gap-3 rounded-sm border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700"><input v-model="confirmed" type="checkbox" required class="mt-1 h-4 w-4 shrink-0"><span>I confirm that the provider's final-agreement requirement has been completed. This does not record benefit distribution.</span></label>
                    </template>
                    <template v-else>
                        <p class="text-sm leading-6 text-slate-600">Explain how to complete the agreement during orientation, a benefit-collection visit, or through your own arrangements. No separate contract-signing schedule is required.</p>
                        <label class="block text-sm font-semibold text-slate-700" for="onboarding-instructions">Instructions for this recipient<textarea id="onboarding-instructions" v-model="instructions" required maxlength="3000" rows="6" class="mt-2 w-full resize-y rounded-md border border-slate-300 px-3 py-3 text-sm leading-6 text-slate-950" placeholder="Tell the recipient where to report, whom to contact, and what to bring."></textarea><span class="mt-1 flex justify-between gap-4 text-xs font-normal text-slate-500"><span>Practical instructions only. Do not add undisclosed obligations or paste the contract.</span><span class="shrink-0 tabular-nums">{{ instructions.length }}/3000</span></span></label>
                    </template>
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-xs leading-5 text-slate-500">Nothing is saved until you confirm.</p>
                    <div class="flex gap-3"><button type="button" :disabled="busy" class="h-10 rounded-md border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="close">Cancel</button><button type="submit" :disabled="busy || !valid || !changed" class="h-10 rounded-md bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50">Review {{ completing ? 'completion' : 'instructions' }}</button></div>
                </footer>
            </form>
        </div>
    </Teleport>
    <ConfirmationDialog :open="open && reviewing" :layer="3200" :title="title" :message="completing ? `Record final-agreement completion for ${recipientName} on ${completedOn}? The contract stays with your organization; no benefit is released by this action.` : `Publish these instructions for ${recipientName} and notify them? The original requirement stays unchanged.`" :confirm-label="conflict ? 'Reload record' : (completing ? 'Record completion' : 'Publish instructions')" :cancel-label="conflict ? 'Cancel' : 'Back to edit'" :busy="busy" :error="error" @confirm="save" @cancel="reviewing = false" />
</template>
