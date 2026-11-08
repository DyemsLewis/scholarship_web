<script setup>
import { confirmOutcome } from '../../support/outcomeConfirmation.js';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { useConcernMutation } from '../../composables/useConcernMutation.js';
import { concernDate, concernError, evidenceError } from '../../support/agreementConcerns.js';
import { deliveryBodyValid, deliveryClass, deliveryIssues, deliveryReplies } from '../../support/benefitDeliveryReports.js';
import ConcernEvidenceList from '../agreement-concerns/ConcernEvidenceList.vue';
import RecipientCaseReviewLauncher from '../case-reviews/RecipientCaseReviewLauncher.vue';

const props = defineProps({ open: Boolean, record: Object, provider: Boolean, options: { type: Array, default: () => [] }, createUrl: String });
const emit = defineEmits(['close', 'changed']);
const dialog = ref(null), current = ref(null), mode = ref('view'), sourceKey = ref(''), kind = ref(''), details = ref('');
const period = ref(''), files = ref([]), confirmed = ref(false), date = ref(''), followUp = ref(''), deliveryRecord = ref('');
const requestKey = ref(''), discard = ref(false), reloading = ref(false);
const titleId = `${useId()}-delivery-title`, formId = `${useId()}-delivery-form`;
const { busy, stale, error, run, reset } = useConcernMutation((method, ...args) => window.axios[method](...args));
const creating = computed(() => !current.value);
const editing = computed(() => creating.value || mode.value === 'respond');
const source = computed(() => props.options.find((item) => `${item.type}:${item.id}` === sourceKey.value));
const locked = computed(() => busy.value || reloading.value);
const dirty = computed(() => editing.value && (!!kind.value || !!sourceKey.value || !!details.value || !!period.value || files.value.length > 0 || confirmed.value || !!date.value || !!followUp.value));
const choices = computed(() => creating.value ? deliveryIssues : props.provider ? deliveryReplies : (current.value?.response_choices || []).map((item) => [item.value, item.label]));
const title = computed(() => creating.value ? 'Report a benefit issue' : editing.value ? (props.provider ? 'Respond to delivery report' : 'Your delivery response') : 'Benefit delivery report');
const valid = computed(() => !locked.value && !stale.value && !discard.value && deliveryBodyValid({ creating: creating.value, provider: props.provider, kind: kind.value, source: source.value, period: period.value, details: details.value, confirmed: confirmed.value, files: files.value, record: deliveryRecord.value, date: date.value, followUp: followUp.value }));
const latest = computed(() => current.value?.responses?.at(-1));
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);

function clearForm() {
    sourceKey.value = ''; kind.value = ''; details.value = ''; period.value = ''; files.value = [];
    confirmed.value = false; date.value = ''; followUp.value = ''; deliveryRecord.value = ''; requestKey.value = crypto.randomUUID();
}
watch(() => props.open, (value) => { if (value) { reset(); current.value = props.record || null; mode.value = 'view'; discard.value = false; clearForm(); } }, { immediate: true });
watch(kind, () => { confirmed.value = false; });
watch(discard, async () => { await nextTick(); (dialog.value?.querySelector(discard.value ? '[data-delivery-keep]' : '[data-delivery-close]') || dialog.value)?.focus({ preventScroll: true }); });
function close() { if (locked.value) return; if (dirty.value) { discard.value = true; return; } emit('close'); }
async function begin() { mode.value = 'respond'; clearForm(); await nextTick(); dialog.value?.querySelector('select')?.focus(); }
function chooseFiles(event) {
    const selected = Array.from(event.target.files || []), warning = evidenceError(selected);
    files.value = warning ? [] : selected; error.value = warning || ''; if (warning) event.target.value = '';
}
async function submit() {
    if (!valid.value) return;
    if (!creating.value && !await confirmOutcome({
        record: `${current.value.program.title} / ${current.value.benefit?.title || current.value.subject}`,
        outcome: choices.value.find(([value]) => value === kind.value)?.[1] || kind.value,
        consequence: props.provider ? 'Your response will be recorded. Only the applicant can confirm receipt or accept the clarification.'
            : ['receipt_confirmed', 'clarification_accepted'].includes(kind.value) ? 'This response will close your report. Confirm receipt only if you actually received the benefit.' : 'Your response will be sent to the provider and kept in this report.',
    }, () => props.open && valid.value && !creating.value)) return;
    const body = new FormData();
    body.append('request_key', requestKey.value); body.append('confirmed', '1'); body.append('details', details.value.trim());
    if (creating.value) {
        body.append('source_type', source.value.type); body.append('source_id', source.value.id);
        body.append('issue_kind', kind.value); if (source.value.period_required) body.append('benefit_period', period.value.trim());
    } else {
        body.append('expected_revision', current.value.revision); body.append('response_kind', kind.value);
        if (props.provider && kind.value === 'correction_planned') body.append('follow_up_on', followUp.value);
        if (props.provider && kind.value === 'delivery_proposed') { body.append('delivery_record_id', deliveryRecord.value); body.append('delivered_on', date.value); }
        if (!props.provider && kind.value === 'receipt_confirmed') body.append('received_on', date.value);
    }
    for (const file of files.value) body.append('evidence[]', file);
    const result = await run('post', creating.value ? props.createUrl : props.provider ? current.value.propose_url : current.value.response_url, body);
    if (result) { current.value = result.report; mode.value = 'view'; clearForm(); emit('changed', result); }
    await nextTick(); (error.value ? dialog.value?.querySelector('[data-delivery-error]') : dialog.value)?.focus({ preventScroll: true });
}
async function reload() {
    if (locked.value || !current.value?.read_url) return;
    reloading.value = true;
    try { const { data } = await window.axios.get(current.value.read_url, { portalToast: false }); current.value = data.report; reset(); confirmed.value = false; emit('changed', data); }
    catch (failure) { error.value = concernError(failure); }
    finally { reloading.value = false; await nextTick(); dialog.value?.focus({ preventScroll: true }); }
}
onBeforeUnmount(reset);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2300] grid place-items-center bg-slate-950/65 p-2 sm:p-5" @click.self="close">
            <section ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-busy="locked" tabindex="-1" data-delivery-dialog class="flex max-h-[calc(100dvh-1rem)] w-full max-w-3xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start gap-3 border-b-2 border-amber-400 px-4 py-4 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit delivery</p><h2 :id="titleId" class="mt-1 text-xl font-bold">{{ title }}</h2><p v-if="current" class="mt-1 text-xs leading-5 text-slate-500">{{ current.program.title }}<span v-if="provider"> / {{ current.applicant.name }}</span></p></div>
                    <button type="button" data-delivery-close :disabled="locked" aria-label="Close delivery report" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-delivery-body>
                    <p class="border-l-2 border-amber-400 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-slate-700">{{ provider ? 'A correction plan or delivery proof does not confirm receipt. The applicant must respond.' : 'Report only the benefit and period affected. Reporting a delivery issue does not change your application decision.' }}</p>
                    <template v-if="current">
                        <div><span :class="['inline-block border px-2.5 py-1 text-xs font-bold', deliveryClass(current.status)]">{{ current.status_label }}</span><h3 class="mt-3 text-base font-bold">{{ current.benefit?.title || current.subject }}</h3><p v-if="current.benefit_period" class="mt-1 text-sm text-slate-500">Period: {{ current.benefit_period }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ current.details }}</p><p v-if="current.follow_up_on" class="mt-2 text-xs font-bold text-slate-600">Provider follow-up: {{ current.follow_up_on }}</p></div>
                        <ConcernEvidenceList :files="current.evidence || []" />
                        <section v-if="latest && !editing" class="border-t border-slate-200 pt-4"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Latest response</p><h3 class="mt-2 text-sm font-bold">{{ latest.label }}</h3><p class="mt-1 text-xs text-slate-500">{{ latest.author_name }} / {{ concernDate(latest.created_at) }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ latest.body }}</p><p v-if="latest.delivered_on || latest.follow_up_on" class="mt-2 text-xs text-slate-600">{{ latest.delivered_on ? `Reported receipt/delivery date: ${latest.delivered_on}` : `Planned follow-up: ${latest.follow_up_on}` }}</p><ConcernEvidenceList class="mt-3" :files="latest.evidence || []" /></section>
                        <details v-if="current.responses?.length > 1 || current.history?.length" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-xs font-bold text-slate-600">Earlier responses and report history</summary><div v-for="reply in (current.responses || []).slice(0, -1)" :key="reply.id" class="mt-4 border-l-2 border-slate-200 pl-3"><p class="text-sm font-bold">{{ reply.label }}</p><p class="mt-1 text-xs text-slate-500">{{ reply.author_name }} / {{ concernDate(reply.created_at) }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ reply.body }}</p><ConcernEvidenceList class="mt-2" :files="reply.evidence || []" /></div><p v-for="(event, index) in current.history || []" :key="index" class="mt-3 text-xs leading-5 text-slate-500">{{ concernDate(event.created_at) }} / {{ event.actor_name }}: {{ event.note }}</p></details>
                    </template>
                    <RecipientCaseReviewLauncher v-if="current && !editing" :read-url="`/${provider ? 'provider' : 'dashboard'}/recipient-cases/${current.id}`" />
                    <form v-if="editing" :id="formId" @submit.prevent="submit">
                        <fieldset :disabled="locked || stale || discard" class="space-y-4 border-t border-slate-200 pt-4">
                            <legend class="sr-only">{{ title }}</legend>
                            <template v-if="creating">
                                <label class="block text-sm font-bold">Affected benefit<select v-model="sourceKey" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="" disabled>Choose a benefit or published release</option><option v-for="item in options" :key="`${item.type}:${item.id}`" :value="`${item.type}:${item.id}`">{{ item.title }}{{ item.scheduled_at ? ` / ${concernDate(item.scheduled_at)}` : item.period_required ? ' / Specify affected period' : ' / No release scheduled' }}</option></select></label>
                                <label v-if="source?.period_required" class="block text-sm font-bold">Benefit period<input v-model="period" required maxlength="120" placeholder="For example: First semester 2026-2027" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"></label>
                                <p v-if="source && !source.scheduled_at" class="text-xs leading-5 text-slate-500">{{ source.period_required ? 'This report covers only the period you name. Earlier schedules and confirmed receipts remain unchanged.' : 'No official release date is recorded. Ask the provider to clarify the expected delivery.' }}</p>
                            </template>
                            <label class="block text-sm font-bold">{{ creating ? 'What happened?' : 'Your response' }}<select v-model="kind" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="" disabled>Choose a response</option><option v-for="[value, label] in choices" :key="value" :value="value">{{ label }}</option></select></label>
                            <template v-if="provider && !creating && kind === 'delivery_proposed'">
                                <label class="block text-sm font-bold">Recorded release<select v-model="deliveryRecord" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="" disabled>Select an actual recorded release</option><option v-for="item in current.delivery_records" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
                                <p v-if="!current.delivery_records?.length" class="text-xs leading-5 text-amber-800">Record the actual benefit release in Benefit releases first, then reload this report. This form does not create a payment or distribution schedule.</p>
                            </template>
                            <label v-if="kind === 'correction_planned'" class="block text-sm font-bold">Planned follow-up date<input v-model="followUp" type="date" required class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"><span class="mt-1 block text-xs font-normal leading-5 text-slate-500">A follow-up commitment only, not a recorded release or new payment.</span></label>
                            <label v-if="['delivery_proposed', 'receipt_confirmed'].includes(kind)" class="block text-sm font-bold">{{ provider ? 'Actual delivery date' : 'Date you received the benefit' }}<input v-model="date" type="date" required class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"></label>
                            <label class="block text-sm font-bold">{{ creating ? 'Explain the issue' : 'Response details' }}<span v-if="!creating && ['receipt_confirmed', 'clarification_accepted'].includes(kind)" class="font-normal text-slate-500"> (optional)</span><textarea v-model="details" :required="creating || provider || ['still_unresolved', 'additional_information'].includes(kind)" :minlength="creating || provider || ['still_unresolved', 'additional_information'].includes(kind) ? 10 : undefined" maxlength="4000" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm font-normal leading-6" placeholder="Give the relevant dates and facts. Do not include bank passwords or sensitive account numbers."></textarea><span v-if="creating || provider || ['still_unresolved', 'additional_information'].includes(kind)" class="mt-1 block text-xs font-normal text-slate-500">At least 10 characters.</span></label>
                            <label class="block text-sm font-bold">{{ kind === 'delivery_proposed' ? 'Delivery or correction proof (required)' : 'Supporting files (optional)' }}<input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" :required="kind === 'delivery_proposed'" class="mt-2 block w-full border border-slate-300 bg-white p-2 text-xs font-normal" @change="chooseFiles"><span class="mt-1 block text-xs font-normal text-slate-500">Up to 3 PDF, JPG, or PNG files, 5 MB each. Only authorized staff and the applicant can view them.</span></label>
                            <label class="flex items-start gap-3 bg-slate-50 px-3 py-3 text-xs leading-5"><input v-model="confirmed" type="checkbox" required class="mt-0.5 h-4 w-4 shrink-0"><span>{{ kind === 'receipt_confirmed' ? 'I confirm I actually received the reported benefit. This closes my report.' : kind === 'clarification_accepted' ? 'My question was answered. This closes the question, not a receipt confirmation.' : 'I confirm this statement is accurate. A correction plan or provider response will not close the report without the applicant.' }}</span></label>
                        </fieldset>
                    </form>
                    <div v-if="error" role="alert" tabindex="-1" data-delivery-error class="border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-950">{{ error }}<button v-if="stale && current" type="button" :disabled="locked" class="ml-2 font-bold underline" @click="reload">Reload report</button></div>
                    <div v-if="discard" role="alert" class="border border-amber-300 bg-amber-50 p-4 text-sm"><p class="font-bold">Discard your unsent response?</p><div class="mt-3 flex gap-3"><button type="button" data-delivery-keep class="bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="discard = false">Keep editing</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold" @click="emit('close')">Discard and close</button></div></div>
                </div>
                <footer class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-6">
                    <button type="button" :disabled="locked" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="close">{{ editing ? 'Cancel' : 'Close' }}</button>
                    <button v-if="editing" :form="formId" type="submit" :disabled="!valid" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ busy ? 'Sending...' : creating ? 'Send report' : kind === 'receipt_confirmed' ? 'Confirm receipt' : 'Send response' }}</button>
                    <button v-else-if="current?.can_propose || current?.can_respond" type="button" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="begin">{{ provider ? 'Respond to report' : 'Review and respond' }}</button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
