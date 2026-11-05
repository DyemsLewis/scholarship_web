<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { useConcernMutation } from '../../composables/useConcernMutation.js';
import { concernClass, concernDate, concernError, concernLabel, evidenceError, responseKinds } from '../../support/agreementConcerns.js';
import ConcernEvidenceList from './ConcernEvidenceList.vue';
import RecipientCaseReviewLauncher from '../case-reviews/RecipientCaseReviewLauncher.vue';

const props = defineProps({ open: Boolean, record: Object, options: { type: Array, default: () => [] }, createUrl: String, context: String, provider: Boolean });
const emit = defineEmits(['close', 'changed']);
const dialog = ref(null), current = ref(null), step = ref(1), intent = ref('view');
const subject = ref(''), details = ref(''), termKey = ref(''), responseBody = ref(''), responseKind = ref('');
const savedSubject = ref(''), savedDetails = ref('');
const days = ref(7), confirmed = ref(false), upload = ref(null), responseFiles = ref([]);
const discard = ref(false), cancelPrompt = ref(false), reloading = ref(false);
const requestKey = ref(''), responseKey = ref('');
const { busy, stale, error, run, reset } = useConcernMutation((method, ...args) => window.axios[method](...args));
const titleId = `${useId()}-concern-title`;
const formId = `${useId()}-concern-form`;
const locked = computed(() => busy.value || reloading.value);
const editing = computed(() => props.provider && (!current.value || current.value.can_edit));
const replying = computed(() => !props.provider && intent.value === 'respond');
const quote = computed(() => current.value?.obligation || props.options.find((item) => item.key === termKey.value));
const dirty = computed(() => replying.value ? !!responseBody.value.trim() || !!responseKind.value || responseFiles.value.length > 0 || confirmed.value
    : subject.value !== savedSubject.value || details.value !== savedDetails.value || !!upload.value || confirmed.value);
const title = computed(() => editing.value ? (current.value ? 'Prepare agreement concern' : 'New agreement concern') : replying.value ? 'Respond to the concern' : 'Agreement concern');
const validFacts = computed(() => !!termKey.value && subject.value.trim().length >= 5 && subject.value.trim().length <= 160 && details.value.trim().length >= 10 && details.value.trim().length <= 4000);
const canContinue = computed(() => (editing.value || replying.value) && !locked.value && !stale.value && !discard.value && !cancelPrompt.value && (replying.value
    ? current.value?.can_respond && responseBody.value.trim().length >= 10 && responseBody.value.trim().length <= 4000 && !!responseKind.value && confirmed.value
    : step.value === 1 ? validFacts.value : step.value === 2 ? current.value?.evidence?.length > 0 && !upload.value : current.value?.can_issue && confirmed.value));
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
watch(() => props.open, (open) => {
    if (!open) return;
    reset(); current.value = props.record ?? null; step.value = 1; intent.value = 'view';
    subject.value = current.value?.subject || ''; details.value = current.value?.details || ''; termKey.value = current.value?.obligation?.key || '';
    savedSubject.value = subject.value; savedDetails.value = details.value;
    responseBody.value = ''; responseKind.value = ''; responseFiles.value = []; upload.value = null;
    confirmed.value = false; days.value = 7; discard.value = false; cancelPrompt.value = false;
    requestKey.value = crypto.randomUUID(); responseKey.value = crypto.randomUUID();
}, { immediate: true });
watch([discard, cancelPrompt], async () => {
    await nextTick();
    const target = dialog.value?.querySelector(discard.value || cancelPrompt.value ? '[data-concern-keep]' : 'textarea:not([disabled])');
    (target ?? dialog.value)?.focus({ preventScroll: true });
});
function close() {
    if (locked.value) return;
    if (dirty.value) { discard.value = true; return; }
    emit('close');
}
function accept(data) {
    if (!data) return false;
    current.value = data.case; confirmed.value = false; emit('changed', data);
    nextTick(() => dialog.value?.focus({ preventScroll: true }));
    return true;
}
async function reload() {
    if (locked.value || !current.value?.read_url) return;
    reloading.value = true;
    try {
        const { data } = await window.axios.get(current.value.read_url, { portalToast: false });
        current.value = data.case; reset(); confirmed.value = false; emit('changed', data);
        await nextTick(); dialog.value?.focus({ preventScroll: true });
    } catch (failure) { error.value = concernError(failure, 'The concern could not be reloaded.'); }
    finally { reloading.value = false; }
}
async function next() {
    if (!canContinue.value) return;
    if (replying.value) {
        const body = new FormData();
        body.append('expected_revision', current.value.revision); body.append('request_key', responseKey.value);
        body.append('response_kind', responseKind.value); body.append('details', responseBody.value.trim()); body.append('confirmed', '1');
        for (const file of responseFiles.value) body.append('evidence[]', file);
        const result = await run('post', current.value.response_url, body);
        if (accept(result)) { responseBody.value = ''; responseKind.value = ''; responseFiles.value = []; intent.value = 'view'; }
    } else if (step.value === 1) {
        const body = { subject: subject.value.trim(), details: details.value.trim(), ...(current.value ? { expected_revision: current.value.revision } : { request_key: requestKey.value, term_key: termKey.value }) };
        const result = await run(current.value ? 'patch' : 'post', current.value?.edit_url || props.createUrl, body);
        if (accept(result)) { subject.value = current.value.subject; details.value = current.value.details; savedSubject.value = subject.value; savedDetails.value = details.value; step.value = 2; }
    } else if (step.value === 2) {
        step.value = 3;
    } else {
        const result = await run('post', current.value.issue_url, { expected_revision: current.value.revision, response_days: Number(days.value), confirmed: true });
        if (accept(result)) intent.value = 'view';
    }
    if (error.value) { await nextTick(); dialog.value?.querySelector('[data-concern-error]')?.focus(); }
}
async function addFile() {
    if (!upload.value || locked.value || stale.value) return;
    const warning = evidenceError([upload.value], 1);
    if (warning) { error.value = warning; return; }
    const body = new FormData(); body.append('expected_revision', current.value.revision); body.append('evidence', upload.value);
    const result = await run('post', current.value.upload_url, body);
    if (accept(result)) { upload.value = null; const input = dialog.value?.querySelector('[data-provider-file]'); if (input) input.value = ''; }
}
async function removeFile(file) {
    accept(await run('delete', file.remove_url, { data: { expected_revision: current.value.revision } }));
}
async function cancelDraft() {
    if (!current.value?.can_edit) return;
    if (accept(await run('patch', current.value.cancel_url, { expected_revision: current.value.revision, confirmed: true }))) { cancelPrompt.value = false; emit('close'); }
}
function selectResponseFiles(event) {
    const files = Array.from(event.target.files || []);
    const warning = evidenceError(files);
    if (warning) { error.value = warning; event.target.value = ''; responseFiles.value = []; return; }
    error.value = ''; responseFiles.value = files;
}
onBeforeUnmount(reset);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2300] grid place-items-center bg-slate-950/65 p-2 sm:p-5" @click.self="close">
            <section ref="dialog" data-concern-dialog role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-busy="locked" tabindex="-1" class="flex max-h-[calc(100dvh-1rem)] w-full max-w-3xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start gap-3 border-b-2 border-amber-400 px-4 py-4 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-file-circle-question" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Accepted scholarship</p><h2 :id="titleId" class="mt-1 text-xl font-bold">{{ title }}</h2><p class="mt-1 text-xs leading-5 text-slate-500">{{ current?.program?.title || context }}</p></div>
                    <button type="button" :disabled="locked" aria-label="Close agreement concern" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <ol v-if="editing" class="grid shrink-0 grid-cols-3 border-b border-slate-200 text-xs font-bold"><li v-for="(label, index) in ['Accepted term', 'Evidence', 'Send notice']" :key="label" :class="['px-4 py-3', step === index + 1 ? 'bg-amber-50 text-slate-950' : 'text-slate-500']"><span class="mr-2">{{ index + 1 }}.</span>{{ label }}</li></ol>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-concern-body>
                    <p class="border-l-2 border-amber-400 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-slate-700">A concern is not a confirmed breach. Reporting or responding does not automatically change support or notify other providers.</p>
                    <div v-if="current && !editing" class="flex flex-wrap items-center gap-3"><span :class="['border px-2.5 py-1 text-xs font-bold', concernClass(current.status)]">{{ concernLabel(current.status) }}</span><span class="text-xs text-slate-500">Respond by {{ concernDate(current.response_due_at) }}</span></div>
                    <RecipientCaseReviewLauncher v-if="current && !editing" :read-url="`/${provider ? 'provider' : 'dashboard'}/recipient-cases/${current.id}`" />
                    <form :id="formId" @submit.prevent="next">
                        <fieldset :disabled="locked || stale || discard || cancelPrompt" class="space-y-5">
                            <legend class="sr-only">{{ title }}</legend>
                            <template v-if="editing && step === 1">
                                <label class="block"><span class="text-sm font-bold">Accepted obligation</span><select v-model="termKey" :disabled="!!current" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-50"><option value="" disabled>Choose a saved obligation</option><option v-if="current" :value="current.obligation.key">{{ current.obligation.label }}</option><option v-for="option in current ? [] : options" :key="option.key" :value="option.key">{{ option.label }}</option></select></label>
                                <blockquote v-if="quote" class="border-l-2 border-slate-300 pl-4 text-sm leading-6 text-slate-600">{{ quote.text }}</blockquote>
                                <label class="block"><span class="text-sm font-bold">Short subject</span><input v-model="subject" required minlength="5" maxlength="160" class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm" placeholder="For example: Enrollment record needs clarification"></label>
                                <label class="block"><span class="text-sm font-bold">What needs the applicant's response?</span><textarea v-model="details" required minlength="10" maxlength="4000" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-3 text-sm leading-6" placeholder="Describe the dates and facts. Do not present an allegation as a finding."></textarea></label>
                                <p class="text-xs text-slate-500">Continue saves a private draft. Nothing is sent yet.</p>
                            </template>
                            <template v-else-if="editing && step === 2">
                                <div><h3 class="text-base font-bold">Support the concern with evidence</h3><p class="mt-1 text-sm leading-6 text-slate-600">Add at least one relevant file. PDF, JPG, or PNG; up to 5 MB each and five files.</p></div>
                                <ConcernEvidenceList :files="current.evidence" removable :busy="locked || stale" @remove="removeFile" />
                                <div v-if="current.evidence.length < 5" class="space-y-3"><label class="block"><span class="text-sm font-bold">Supporting file</span><input data-provider-file type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 px-3 py-2 text-sm" @change="upload = $event.target.files?.[0] || null"></label><button type="button" :disabled="!upload" class="min-h-10 border border-slate-300 px-4 text-sm font-bold disabled:opacity-40" @click="addFile">Add file</button></div>
                                <p class="text-xs leading-5 text-slate-500">Only the files listed above will accompany the notice. Removed files are not shared.</p>
                            </template>
                            <template v-else-if="editing && step === 3">
                                <div><h3 class="text-base font-bold">{{ current.subject }}</h3><p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ current.details }}</p></div>
                                <details class="border-y border-slate-200 py-3"><summary class="cursor-pointer text-sm font-bold">Accepted term and {{ current.evidence.length }} supporting file(s)</summary><blockquote class="mt-3 border-l-2 border-slate-300 pl-4 text-sm leading-6 text-slate-600">{{ current.obligation.text }}</blockquote><ConcernEvidenceList class="mt-3" :files="current.evidence" /></details>
                                <label class="block"><span class="text-sm font-bold">Time to respond</span><select v-model="days" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option v-for="value in [3, 7, 14, 21, 30]" :key="value" :value="value">{{ value }} calendar days after sending</option></select></label>
                                <label class="flex items-start gap-3 text-sm leading-6"><input v-model="confirmed" required type="checkbox" class="mt-1.5 shrink-0"><span>I checked the accepted term and files. This sends a notice for response, not a final finding.</span></label>
                            </template>
                            <template v-else-if="replying">
                                <h3 class="text-base font-bold">{{ current.subject }}</h3>
                                <label class="block"><span class="text-sm font-bold">How would you like to respond?</span><select v-model="responseKind" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="" disabled>Choose a response</option><option v-for="[value, label] in responseKinds" :key="value" :value="value">{{ label }}</option></select></label>
                                <label class="block"><span class="text-sm font-bold">Your explanation</span><textarea v-model="responseBody" required rows="5" minlength="10" maxlength="4000" class="mt-2 w-full border border-slate-300 px-3 py-3 text-sm leading-6" placeholder="Explain what happened, correct any information, or describe the exception you need."></textarea></label>
                                <label class="block"><span class="text-sm font-bold">Supporting files <span class="font-normal text-slate-500">(optional)</span></span><input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 px-3 py-2 text-sm" @change="selectResponseFiles"><span class="mt-1 block text-xs text-slate-500">Up to three files, 5 MB each. Do not include passwords or bank details.</span></label>
                                <label class="flex items-start gap-3 text-sm leading-6"><input v-model="confirmed" required type="checkbox" class="mt-1.5 shrink-0"><span>I reviewed this response and want to send it to the provider.</span></label>
                                <p v-if="current.deadline_passed" class="text-xs leading-5 text-slate-600">The requested date has passed, but you can still respond while this notice is awaiting a reply.</p>
                                <p v-if="!current.can_respond" class="text-sm font-semibold text-amber-900">A response can no longer be submitted here. Your unsent text has been retained above.</p>
                            </template>
                            <template v-else-if="current">
                                <div><h3 class="text-lg font-bold">{{ current.subject }}</h3><p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ current.details }}</p></div>
                                <section><h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Accepted obligation</h3><blockquote class="mt-2 border-l-2 border-slate-300 pl-4 text-sm leading-6">{{ current.obligation?.text }}</blockquote><p class="mt-2 text-xs text-slate-500">Accepted {{ concernDate(current.agreement?.accepted_at) }}</p></section>
                                <section><h3 class="mb-2 text-sm font-bold">Provider evidence</h3><ConcernEvidenceList :files="current.evidence" /></section>
                                <section v-for="response in current.responses" :key="response.id" class="border-t border-slate-200 pt-4"><h3 class="text-sm font-bold">{{ response.kind_label }}</h3><p class="mt-1 text-xs text-slate-500">{{ response.author_name }} &middot; {{ concernDate(response.created_at) }}</p><p class="mt-3 whitespace-pre-line break-words text-sm leading-6">{{ response.body }}</p><ConcernEvidenceList class="mt-3" :files="response.evidence" /></section>
                                <p v-if="current.status === 'under_review'" class="text-sm leading-6 text-slate-600">The applicant's response is recorded. No finding or support change has been made through this concern.</p>
                            </template>
                        </fieldset>
                    </form>
                    <details v-if="current?.history?.length && !editing && !replying" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-sm font-bold text-slate-600">Case history</summary><ol class="mt-2 divide-y divide-slate-200"><li v-for="event in current.history" :key="event.revision" class="py-3"><p class="text-sm leading-6">{{ event.note }}</p><p class="mt-1 text-xs text-slate-500">{{ event.actor_name }} &middot; {{ concernDate(event.created_at) }}</p></li></ol></details>
                    <details v-if="provider && !editing && (subject !== savedSubject || details !== savedDetails)" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-sm font-bold">Your unsaved draft text</summary><p class="mt-3 text-sm font-bold">{{ subject }}</p><p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ details }}</p></details>
                    <div v-if="error" data-concern-error role="alert" tabindex="-1" class="border-l-2 border-amber-400 bg-amber-50 px-3 py-3 text-sm leading-6"><p>{{ error }}</p><button v-if="stale && current?.read_url" type="button" :disabled="locked" class="mt-2 font-bold underline" @click="reload">Reload current concern</button><p v-else-if="stale" class="mt-2 text-xs">Close this dialog to reload the list and find any saved draft. Your unsaved text remains here until you close.</p></div>
                </div>
                <footer class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                    <div v-if="discard || cancelPrompt" role="alert" class="space-y-3"><p class="text-sm font-bold">{{ cancelPrompt ? 'Cancel this saved, unsent draft?' : 'Close without saving your latest text?' }}</p><p class="text-xs text-slate-600">{{ cancelPrompt ? 'No notice will be sent. Scholarship support stays unchanged.' : 'Previously saved draft content remains available.' }}</p><div class="flex flex-wrap justify-end gap-2"><button data-concern-keep type="button" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold" @click="discard = false; cancelPrompt = false">Keep editing</button><button type="button" :disabled="locked" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="cancelPrompt ? cancelDraft() : emit('close')">{{ cancelPrompt ? 'Cancel saved draft' : 'Close without saving' }}</button></div></div>
                    <div v-else class="flex flex-wrap items-center justify-end gap-2"><button v-if="editing && current" type="button" :disabled="locked || stale" class="mr-auto min-h-10 text-xs font-bold text-slate-600 underline disabled:opacity-40" @click="cancelPrompt = true">Cancel draft</button><button v-if="editing && step > 1" type="button" :disabled="locked" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold" @click="step--; confirmed = false">Previous</button><button type="button" :disabled="locked" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold" @click="close">Close</button><button v-if="editing || replying" type="submit" :form="formId" :disabled="!canContinue" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ busy ? 'Saving...' : replying ? 'Send response' : step === 3 ? 'Send notice' : 'Continue' }}</button><button v-else-if="current?.can_respond" type="button" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="intent = 'respond'; confirmed = false">Respond to concern</button></div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
