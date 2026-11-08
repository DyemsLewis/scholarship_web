<script setup>
import { confirmOutcome } from '../../support/outcomeConfirmation.js';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { useConcernMutation } from '../../composables/useConcernMutation.js';
import { concernDate, concernError, evidenceError } from '../../support/agreementConcerns.js';
import ConcernEvidenceList from '../agreement-concerns/ConcernEvidenceList.vue';
import RecipientCasePrivacySection from './RecipientCasePrivacySection.vue';

const props = defineProps({ open: Boolean, record: Object, admin: Boolean });
const emit = defineEmits(['close', 'changed']);
const dialog = ref(null), current = ref(null), action = ref(''), details = ref(''), files = ref([]), confirmed = ref(false), finding = ref(''), recommendation = ref(''), cited = ref([]), termsReviewed = ref(false), responsesReviewed = ref(false), discard = ref(false), reloading = ref(false), requestKey = ref('');
const titleId = `${useId()}-review-title`, formId = `${useId()}-review-form`;
const { busy, stale, error, run, reset } = useConcernMutation((method, ...args) => window.axios[method](...args));
const privacyDirty = ref(false), privacyBusy = ref(false);
const locked = computed(() => busy.value || reloading.value || privacyBusy.value);
const dirty = computed(() => privacyDirty.value || (!!action.value && (!!details.value || files.value.length > 0 || confirmed.value || !!finding.value || !!recommendation.value || cited.value.length > 0)));
const recommendations = computed(() => current.value?.case_type === 'benefit_delivery' ? [['no_support_change', 'No support change'], ['delivery_follow_up', 'Delivery follow-up']] : [['no_support_change', 'No support change'], ['correction_requested', 'Correct the records'], ['support_plan', 'Agree a support plan'], ['provider_decision_review', 'Provider may consider a separate support decision']]);
const allowed = computed(() => action.value === 'request' ? current.value?.can_request : action.value === 'statement' ? current.value?.can_statement : action.value === 'appeal' ? current.value?.can_appeal : current.value?.can_review);
const valid = computed(() => allowed.value && !locked.value && !stale.value && !discard.value && confirmed.value && details.value.trim().length >= (action.value === 'outcome' ? 30 : 10) && (action.value !== 'outcome' || (!!finding.value && !!recommendation.value && termsReviewed.value && responsesReviewed.value && (cited.value.length > 0 || files.value.length > 0))));
const title = computed(() => ({ request: 'Request an independent review', statement: 'Add information for review', appeal: 'Appeal the review outcome', information: 'Request more information', outcome: 'Record a review outcome' }[action.value] || 'Case review and appeals'));
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
function clear() { action.value = ''; details.value = ''; files.value = []; confirmed.value = false; finding.value = ''; recommendation.value = ''; cited.value = []; termsReviewed.value = false; responsesReviewed.value = false; requestKey.value = crypto.randomUUID(); }
watch(() => props.open, (open) => { if (open) { reset(); current.value = props.record; clear(); discard.value = false; } }, { immediate: true });
watch(discard, async () => { await nextTick(); (dialog.value?.querySelector(discard.value ? '[data-review-keep]' : 'form textarea:not([disabled])') || dialog.value?.querySelector('[data-review-close]') || dialog.value)?.focus(); });
function close() { if (locked.value) return; if (dirty.value) { discard.value = true; return; } emit('close'); }
async function begin(value) { if (locked.value || privacyDirty.value) return; clear(); action.value = value; await nextTick(); (dialog.value?.querySelector('form select, form textarea') || dialog.value)?.focus(); }
function chooseFiles(event) { const items = Array.from(event.target.files || []); const warning = evidenceError(items); files.value = warning ? [] : items; error.value = warning || ''; if (warning) event.target.value = ''; }
async function save() {
    if (!valid.value) return;
    if (action.value === 'outcome' && !await confirmOutcome({
        record: `${current.value.applicant.name} / ${current.value.program.title}`,
        outcome: { not_established: 'Concern not established', excused: 'Circumstances accepted / excused', confirmed: 'Concern supported by evidence' }[finding.value],
        consequence: 'This saves the finding and recommendation. Any support decision remains a separate action, and the applicant can appeal the finding.',
        danger: finding.value === 'confirmed',
    }, () => props.open && valid.value && action.value === 'outcome')) return;
    const body = new FormData(); body.append('expected_revision', current.value.revision); body.append('request_key', requestKey.value); body.append('details', details.value.trim()); body.append('confirmed', '1');
    if (action.value === 'outcome') { body.append('finding', finding.value); body.append('recommendation', recommendation.value); body.append('terms_reviewed', '1'); body.append('responses_reviewed', '1'); for (const id of cited.value) body.append('evidence_ids[]', id); }
    for (const file of files.value) body.append('evidence[]', file);
    const result = await run('post', current.value.actions[action.value], body);
    if (result) { current.value = result.case; clear(); emit('changed', result); window.dispatchEvent(new CustomEvent('recipient-case-review-updated')); }
    await nextTick(); (error.value ? dialog.value?.querySelector('[data-review-error]') : dialog.value)?.focus({ preventScroll: true });
}
async function reload() {
    if (locked.value) return; reloading.value = true;
    try { const { data } = await window.axios.get(current.value.read_url, { portalToast: false }); current.value = data.case; reset(); confirmed.value = false; termsReviewed.value = false; responsesReviewed.value = false; cited.value = []; emit('changed', data); }
    catch (failure) { error.value = concernError(failure); }
    finally { reloading.value = false; await nextTick(); dialog.value?.focus({ preventScroll: true }); }
}
onBeforeUnmount(reset);
async function privacyRecorded() { await nextTick(); await reload(); window.dispatchEvent(new CustomEvent('recipient-case-review-updated')); }
</script>

<template>
    <Teleport to="body"><div v-if="open && current" class="fixed inset-0 z-[2300] grid place-items-center bg-slate-950/65 p-2 sm:p-5" @click.self="close">
        <section ref="dialog" data-case-review-dialog role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-busy="locked" tabindex="-1" class="flex max-h-[calc(100dvh-1rem)] w-full max-w-4xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]" @keydown="handleKeydown">
            <header class="flex shrink-0 items-start gap-3 border-b-2 border-amber-400 px-4 py-4 sm:px-6"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ current.type_label }}</p><h2 :id="titleId" class="mt-1 text-xl font-bold">{{ title }}</h2><p class="mt-1 text-xs leading-5 text-slate-500">{{ current.program.title }} / {{ current.applicant.name }}</p></div><button type="button" data-review-close :disabled="locked" aria-label="Close case review" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-case-review-body>
                <p class="border-l-2 border-amber-400 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-slate-700">A review is not an automatic penalty, receipt confirmation, or warning to other providers. Any support decision is a separate, recorded action.</p>
                <div><span class="inline-block border border-slate-300 bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ current.review_state_label }}</span><h3 class="mt-3 text-base font-bold">{{ current.subject }}</h3><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ current.details }}</p></div>
                <details :open="admin && !action" class="border-y border-slate-200 py-3"><summary class="cursor-pointer text-sm font-bold">{{ current.source_label }}</summary><blockquote class="mt-3 whitespace-pre-wrap break-words border-l-2 border-slate-300 pl-3 text-sm leading-6 text-slate-600">{{ current.source_text || 'No detailed source description was recorded.' }}</blockquote><p v-if="current.accepted_consequences" class="mt-3 text-xs leading-5 text-slate-600"><strong>Accepted consequence:</strong> {{ current.accepted_consequences }}</p><p v-if="current.response_due_at" class="mt-3 text-xs text-slate-500">Response opportunity: {{ concernDate(current.response_due_at) }}. {{ current.response_received_at ? 'Applicant response recorded.' : 'No original applicant response recorded; silence does not establish a concern.' }}</p></details>
                <section v-if="current.reviews?.length"><h3 class="text-sm font-bold">{{ current.reviews[0].under_appeal ? 'Outcome under appeal' : 'Latest review outcome' }}</h3><p class="mt-2 text-sm font-bold">{{ current.reviews[0].finding_label }}</p><p class="mt-1 text-xs text-slate-500">{{ current.reviews[0].reviewer_name }} / {{ concernDate(current.reviews[0].created_at) }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ current.reviews[0].rationale }}</p><p class="mt-2 text-xs font-bold text-slate-600">{{ current.reviews[0].recommendation_label }}</p><p class="mt-2 text-xs leading-5 text-slate-500">Appeal by {{ concernDate(current.reviews[0].appeal_due_at) }}. Late appeals remain available with an explanation. An appeal does not automatically reverse an already recorded support decision.</p></section>
                <details class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-sm font-bold">Statements and supporting proof ({{ current.statements?.length || 0 }})</summary><ConcernEvidenceList class="mt-3" :files="current.evidence || []" /><article v-for="statement in current.statements || []" :key="statement.id" class="mt-4 border-l-2 border-slate-200 pl-3"><p class="text-xs font-bold text-slate-600">{{ statement.label }} / {{ statement.author_name }} / {{ concernDate(statement.created_at) }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6">{{ statement.body }}</p></article></details>
                <details v-if="current.reviews?.length > 1 || current.history?.length" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-xs font-bold text-slate-600">Earlier outcomes and audit history</summary><article v-for="review in current.reviews.slice(1)" :key="review.id" class="mt-3 border-l-2 border-slate-200 pl-3 text-xs leading-5"><p class="font-bold">{{ review.finding_label }} / {{ review.reviewer_name }}</p><p class="whitespace-pre-wrap break-words">{{ review.rationale }}</p></article><p v-for="(event, index) in current.history || []" :key="index" class="mt-3 text-xs leading-5 text-slate-500">{{ concernDate(event.created_at) }} / {{ event.actor_name }}: {{ event.note }}</p></details>
                <p v-if="current.independent_reviewer_required" class="border border-amber-300 bg-amber-50 p-3 text-sm leading-6">A different review officer must decide this appeal. You can read the record but cannot decide your own earlier outcome.</p>
                <RecipientCasePrivacySection v-if="!action && current.privacy_url" :url="current.privacy_url" :disabled="busy || reloading || discard" @dirty-change="privacyDirty = $event" @busy-change="privacyBusy = $event" @recorded="privacyRecorded" />
                <form v-if="action" :id="formId" @submit.prevent="save"><fieldset :disabled="locked || stale || discard" class="space-y-4 border-t border-slate-200 pt-4"><legend class="sr-only">{{ title }}</legend>
                    <template v-if="action === 'outcome'">
                        <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-bold">Finding<select v-model="finding" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm font-normal"><option value="" disabled>No finding selected</option><option value="not_established">Concern not established</option><option value="excused">Circumstances accepted / excused</option><option value="confirmed">Concern supported by evidence</option></select></label><label class="block text-sm font-bold">Recommended next step<select v-model="recommendation" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm font-normal"><option value="" disabled>Select a recommendation</option><option v-for="[value, label] in recommendations" :key="value" :value="value">{{ label }}</option></select></label></div>
                        <fieldset v-if="current.evidence?.length" class="space-y-2"><legend class="mb-2 text-sm font-bold">Proof considered</legend><label v-for="file in current.evidence" :key="file.id" class="flex items-center gap-3 text-sm"><input v-model="cited" type="checkbox" :value="file.id" class="h-4 w-4"><span class="break-words">{{ file.name }}</span></label></fieldset>
                        <label class="flex items-start gap-3 text-xs leading-5"><input v-model="termsReviewed" required type="checkbox" class="mt-0.5 h-4 w-4 shrink-0">I reviewed the actual accepted term or reported benefit, not a later program rule.</label><label class="flex items-start gap-3 text-xs leading-5"><input v-model="responsesReviewed" required type="checkbox" class="mt-0.5 h-4 w-4 shrink-0">I considered the statements, evidence, and opportunity to respond. No response alone does not prove a concern.</label>
                    </template>
                    <label class="block text-sm font-bold">{{ action === 'outcome' ? 'Reasons for the outcome' : action === 'appeal' ? 'Why should this outcome be reconsidered?' : 'What needs review?' }}<textarea v-model="details" required :minlength="action === 'outcome' ? 30 : 10" maxlength="4000" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm font-normal leading-6"></textarea><span class="mt-1 block text-xs font-normal text-slate-500">{{ action === 'outcome' ? 'Explain the evidence and how the response affects the finding (at least 30 characters).' : 'Give relevant facts and dates (at least 10 characters). For a late appeal, explain the delay as well.' }}</span></label>
                    <label class="block text-sm font-bold">Additional proof <span class="font-normal text-slate-500">(optional)</span><input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 bg-white p-2 text-xs font-normal" @change="chooseFiles"><span class="mt-1 block text-xs font-normal text-slate-500">Up to 3 private PDF, JPG, or PNG files, 5 MB each.</span></label>
                    <label class="flex items-start gap-3 bg-slate-50 px-3 py-3 text-xs leading-5"><input v-model="confirmed" required type="checkbox" class="mt-0.5 h-4 w-4 shrink-0"><span>I confirm this statement is accurate. It will be shared with the parties and authorized reviewers, not other providers.</span></label>
                </fieldset></form>
                <p v-if="error" role="alert" tabindex="-1" data-review-error class="border border-amber-300 bg-amber-50 p-3 text-sm leading-6 text-amber-950">{{ error }} <button v-if="stale" type="button" :disabled="locked" class="ml-2 font-bold underline" @click="reload">Reload case</button></p>
                <div v-if="discard" role="alert" class="border border-amber-300 bg-amber-50 p-4 text-sm"><p class="font-bold">Discard your unsent statement?</p><div class="mt-3 flex gap-3"><button type="button" data-review-keep class="bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="discard = false">Keep editing</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold" @click="emit('close')">Discard and close</button></div></div>
            </div>
            <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-6">
                <button type="button" :disabled="locked" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="close">{{ action ? 'Cancel' : 'Close' }}</button>
                <button v-if="action" :form="formId" type="submit" :disabled="!valid" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ busy ? 'Saving...' : action === 'outcome' ? 'Record outcome' : 'Send statement' }}</button>
                <fieldset v-else :disabled="locked || privacyDirty || discard" class="contents disabled:[&>button]:opacity-40">
                    <button v-if="current.can_request" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="begin('request')">Request review</button>
                    <button v-if="current.can_statement" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="begin('statement')">Add information</button>
                    <button v-if="current.can_appeal" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="begin('appeal')">Appeal outcome</button>
                    <button v-if="current.can_review" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold" @click="begin('information')">Request information</button>
                    <button v-if="current.can_review" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="begin('outcome')">Review outcome</button>
                </fieldset>
            </footer>
        </section>
    </div></Teleport>
</template>
