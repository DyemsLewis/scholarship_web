<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useConcernMutation } from '../../composables/useConcernMutation.js';
import { concernDate, concernError } from '../../support/agreementConcerns.js';

const props = defineProps({ url: { type: String, required: true }, disabled: Boolean });
const emit = defineEmits(['dirty-change', 'busy-change', 'recorded']);
const privacy = ref(null), access = ref(null), loading = ref(false), loadError = ref(''), editing = ref(false), purpose = ref(''), rationale = ref(''), reference = ref(''), assessment = ref(''), audience = ref('originating_award'), recheck = ref(''), hold = ref(false), confirmed = ref(false), requestKey = ref(''), saved = ref('');
const formId = `${useId()}-privacy`;
const section = ref(null);
const { busy, stale, error, run, reset } = useConcernMutation((method, ...args) => window.axios[method](...args));
const locked = computed(() => props.disabled || busy.value || loading.value);
const valid = computed(() => !locked.value && !stale.value && privacy.value?.can_record && confirmed.value && assessment.value && recheck.value && purpose.value.trim().length >= 10 && rationale.value.trim().length >= 30);
watch(editing, (value) => emit('dirty-change', value));
watch(() => busy.value || loading.value, (value) => emit('busy-change', value));
let version = 0;
async function load(page = 1, accessPage = 1) {
    if (locked.value) return;
    const token = ++version; loading.value = true; loadError.value = '';
    try {
        const { data } = await window.axios.get(props.url, { params: { page, access_page: accessPage }, portalToast: false });
        if (token !== version) return;
        privacy.value = data.privacy; access.value = data.access; reset(); confirmed.value = false;
    } catch (failure) { if (token === version) loadError.value = concernError(failure); }
    finally { if (token === version) { loading.value = false; await nextTick(); (section.value?.querySelector('form select:enabled') || section.value)?.focus({ preventScroll: true }); } }
}
function open(event) { if (event.target.open && !privacy.value && !loading.value) load(); }
async function begin() {
    if (locked.value || editing.value) return;
    assessment.value = ''; purpose.value = ''; rationale.value = ''; reference.value = ''; recheck.value = ''; hold.value = privacy.value.audit_retention_hold; confirmed.value = false; audience.value = 'originating_award'; requestKey.value = crypto.randomUUID(); saved.value = ''; reset(); editing.value = true;
    await nextTick(); section.value?.querySelector('form select:enabled')?.focus();
}
async function save() {
    if (!valid.value) return;
    const data = await run('post', privacy.value.record_url, { expected_revision: privacy.value.revision, request_key: requestKey.value, assessment: assessment.value, audience: audience.value, purpose: purpose.value.trim(), basis_reference: reference.value.trim() || null, rationale: rationale.value.trim(), recheck_on: recheck.value, audit_retention_hold: hold.value, confirmed: true });
    if (!data) return;
    privacy.value = data.privacy; saved.value = data.message; editing.value = false; emit('recorded');
}
onBeforeUnmount(() => { version++; reset(); emit('dirty-change', false); emit('busy-change', false); });
</script>

<template>
    <details ref="section" data-case-privacy tabindex="-1" class="border-t border-slate-200 pt-3" @toggle="open">
        <summary class="cursor-pointer text-sm font-bold"><i class="fa-solid fa-lock mr-2 text-slate-500" aria-hidden="true"></i>Privacy &amp; access</summary>
        <div class="mt-4 space-y-4">
            <p v-if="loading" role="status" class="text-sm text-slate-500">Loading privacy information...</p>
            <p v-if="loadError" role="alert" class="text-sm text-amber-900">{{ loadError }} <button type="button" :disabled="locked" class="font-bold underline" @click="load()">Try again</button></p>
            <template v-if="privacy">
                <div class="border-l-2 border-slate-400 bg-slate-50 px-3 py-3"><p class="text-sm font-bold">{{ privacy.sharing_label }}</p><p class="mt-1 text-xs leading-5 text-slate-600">{{ privacy.notice }}</p></div>
                <p class="text-xs text-slate-500">{{ privacy.assessment_state_label }}. {{ privacy.audit_retention_hold ? 'Access-log retention is on hold until explicitly released.' : `Access metadata is normally retained for ${privacy.audit_retention_days} days.` }}</p>
                <details><summary class="cursor-pointer text-xs font-bold text-slate-600">Why external sharing is disabled</summary><ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5 text-slate-600"><li v-for="reason in privacy.blockers" :key="reason">{{ reason }}</li></ul></details>
                <section aria-label="Case access history" class="border-t border-slate-200 pt-3"><h4 class="text-sm font-bold">Recent case and file access</h4><p class="mt-1 text-xs leading-5 text-slate-500">Repeated requests may be grouped within five minutes. This records authorized access, not proof that a document was read. {{ privacy.can_record ? 'Blocked and unavailable requests are included for review officers.' : 'Only authorized accesses are shown, using team labels for staff.' }}</p>
                    <ul class="mt-3 divide-y divide-slate-200"><li v-for="entry in access?.data || []" :key="entry.id" class="flex flex-wrap justify-between gap-2 py-2 text-xs leading-5"><span><strong>{{ entry.actor_name || entry.actor_label }}</strong><span v-if="entry.actor_name" class="text-slate-500"> / {{ entry.actor_label }}</span><span class="block text-slate-600">{{ entry.resource_label }} / {{ entry.outcome_label }}</span></span><time class="text-slate-500">{{ concernDate(entry.at) }}</time></li></ul>
                    <p v-if="!access?.data?.length" class="mt-3 text-xs text-slate-500">No retained access records to display.</p>
                    <div v-if="access?.last_page > 1" class="mt-3 flex items-center justify-between text-xs"><button type="button" :disabled="locked || editing || access.current_page <= 1" class="font-bold underline disabled:opacity-40" @click="load(privacy.assessments.current_page, access.current_page - 1)">Previous</button><span>{{ access.current_page }} of {{ access.last_page }}</span><button type="button" :disabled="locked || editing || access.current_page >= access.last_page" class="font-bold underline disabled:opacity-40" @click="load(privacy.assessments.current_page, access.current_page + 1)">Next</button></div>
                </section>
                <details v-if="privacy.assessments?.data?.length" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-xs font-bold text-slate-600">Privacy assessment history</summary><article v-for="item in privacy.assessments.data" :key="item.id" class="mt-3 border-l-2 border-slate-200 pl-3 text-xs leading-5"><p class="font-bold">{{ item.label }}</p><p class="text-slate-500">{{ concernDate(item.created_at) }} / Recheck {{ item.recheck_on }}</p><p>{{ item.audience }}</p><template v-if="item.rationale"><p class="mt-2 whitespace-pre-wrap break-words"><strong>Purpose:</strong> {{ item.purpose }}</p><p class="mt-1 whitespace-pre-wrap break-words">{{ item.rationale }}</p><p v-if="item.basis_reference" class="mt-1 break-words text-slate-500">Policy reference: {{ item.basis_reference }}</p><p class="mt-1 text-slate-500">Recorded by {{ item.recorder_name }}</p></template></article><div v-if="privacy.assessments.last_page > 1" class="mt-3 flex justify-between text-xs"><button type="button" :disabled="locked || editing || privacy.assessments.current_page <= 1" class="font-bold underline disabled:opacity-40" @click="load(privacy.assessments.current_page - 1, access.current_page)">Previous</button><span>{{ privacy.assessments.current_page }} of {{ privacy.assessments.last_page }}</span><button type="button" :disabled="locked || editing || privacy.assessments.current_page >= privacy.assessments.last_page" class="font-bold underline disabled:opacity-40" @click="load(privacy.assessments.current_page + 1, access.current_page)">Next</button></div></details>
                <button v-if="privacy.can_record && !editing" type="button" data-privacy-begin :disabled="locked" class="min-h-10 border border-slate-300 px-3 text-xs font-bold disabled:opacity-40" @click="begin">Record internal assessment</button>
                <form v-if="editing" :id="formId" data-privacy-form class="border-t border-slate-200 pt-4" @submit.prevent="save"><h4 class="text-sm font-bold">Internal privacy assessment</h4><p class="mt-1 text-xs leading-5 text-slate-500">This cannot authorize disclosure or change support. Detailed notes are visible only to authorized admin reviewers.</p><fieldset :disabled="locked || stale" class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2"><label class="block text-xs font-bold">Assessment<select v-model="assessment" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-2 font-normal"><option value="" disabled>Select an assessment</option><option value="blocked">Do not disclose</option><option value="policy_review_needed">Further privacy review needed</option><option value="not_necessary">Disclosure is not necessary</option></select></label><label class="block text-xs font-bold">Proposed audience<select v-model="audience" class="mt-2 h-11 w-full border border-slate-300 bg-white px-2 font-normal"><option value="originating_award">Originating award only</option><option value="proposed_other_provider">Other provider (not enabled)</option></select></label></div>
                    <label class="block text-xs font-bold">Purpose<textarea v-model="purpose" required minlength="10" maxlength="1000" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm font-normal" placeholder="Explain why this review is needed."></textarea></label>
                    <label class="block text-xs font-bold">Policy or lawful-basis reference <span class="font-normal text-slate-500">(optional)</span><input v-model="reference" maxlength="500" class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm font-normal"><span class="mt-1 block font-normal leading-5 text-slate-500">A citation or consent checkbox alone does not establish permission.</span></label>
                    <label class="block text-xs font-bold">Reasons and safeguards<textarea v-model="rationale" required minlength="30" maxlength="2000" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm font-normal" placeholder="Explain necessity, accuracy, unresolved issues, and safeguards (at least 30 characters)."></textarea></label>
                    <label class="block text-xs font-bold">Recheck date<input v-model="recheck" type="date" required class="mt-2 block h-11 w-full border border-slate-300 bg-white px-3 text-sm font-normal"><span class="mt-1 block font-normal text-slate-500">Within one year. A later case change supersedes this assessment.</span></label>
                    <label class="flex items-start gap-3 text-xs leading-5"><input v-model="hold" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0"><span>Keep case access logs beyond routine retention while the documented review requires them. Unchecking explicitly releases an existing hold; it does not delete case records.</span></label>
                    <label class="flex items-start gap-3 bg-slate-50 p-3 text-xs leading-5"><input v-model="confirmed" required type="checkbox" class="mt-0.5 h-4 w-4 shrink-0"><span>I reviewed this case and understand that this assessment does not share data, authorize a blacklist, or decide an application.</span></label>
                </fieldset><p v-if="error" role="alert" class="mt-3 text-sm leading-6 text-amber-950">{{ error }} <button v-if="stale" type="button" :disabled="locked" class="font-bold underline" @click="load()">Reload privacy status</button></p><div class="mt-4 flex flex-wrap justify-end gap-2"><button type="button" :disabled="locked" class="min-h-10 border border-slate-300 px-3 text-xs font-bold" @click="editing = false">Discard assessment</button><button type="submit" data-privacy-save :disabled="!valid" class="min-h-10 bg-slate-950 px-3 text-xs font-bold text-white disabled:opacity-40">{{ busy ? 'Saving...' : 'Save internal assessment' }}</button></div></form>
                <p v-if="saved" role="status" class="text-xs leading-5 text-slate-600">{{ saved }}</p>
            </template>
        </div>
    </details>
</template>
