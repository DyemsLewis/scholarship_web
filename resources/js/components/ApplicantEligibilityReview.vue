<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';
import { confirmOutcome } from '../support/outcomeConfirmation';
import FeedbackAlertModal from './FeedbackAlertModal.vue';
import FilePreviewModal from './FilePreviewModal.vue';

const props = defineProps({ scholarshipId: { type: [String, Number], required: true }, embedded: Boolean });
const emit = defineEmits(['changed']);
const context = ref(null), loading = ref(false), open = ref(false), busy = ref(false), error = ref('');
const explanation = ref(''), evidence = ref(null), previewUrl = ref(''), confirmed = ref(false), previewFile = ref(null), dialog = ref(null);
const record = computed(() => context.value?.request);
const visible = computed(() => loading.value || context.value?.criteria?.length || record.value);
const endpoint = computed(() => `/dashboard/scholarships/${props.scholarshipId}/eligibility-review`);
const { handleKeydown } = useModalAccessibility(() => open.value, dialog, close);
let version = 0;

async function load(refresh = false) {
    const current = ++version;
    loading.value = true;
    try {
        const { data } = await window.axios.get(endpoint.value, { portalToast: false });
        if (current === version) context.value = data;
        if (refresh && current === version) emit('changed');
    } catch (failure) { if (current === version) error.value = failure.response?.data?.message || 'The eligibility review status could not load.'; }
    finally { if (current === version) loading.value = false; }
}

function clearFile() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = ''; evidence.value = null;
}
function chooseFile(event) {
    clearFile();
    const file = event.target.files?.[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        error.value = 'Choose a JPG, PNG, WebP, or PDF file up to 5 MB.'; event.target.value = ''; return;
    }
    evidence.value = file;
    if (file.type.startsWith('image/')) previewUrl.value = URL.createObjectURL(file);
}
function begin() { explanation.value = ''; clearFile(); confirmed.value = false; open.value = true; }
async function close() {
    if (busy.value) return;
    if ((explanation.value.trim() || evidence.value) && !await confirmOutcome({ record: 'Eligibility review request', outcome: 'Discard unsent request', consequence: 'Your explanation and selected attachment will not be sent.', confirmLabel: 'Discard request', danger: true }, () => open.value && !busy.value)) return;
    open.value = false; clearFile();
}
async function submit() {
    if (busy.value || !confirmed.value || explanation.value.trim().length < 20 || !context.value?.can_request) return;
    const currentId = props.scholarshipId;
    if (!await confirmOutcome({ record: 'Eligibility review', outcome: 'Send explanation to provider', consequence: 'Your explanation and optional attachment will be shared with authorized reviewers. This does not submit an application or reserve a slot.', confirmLabel: 'Send request' }, () => open.value && !busy.value && props.scholarshipId === currentId)) return;
    busy.value = true;
    try {
        const data = new FormData();
        data.append('context_hash', context.value.context_hash); data.append('explanation', explanation.value.trim()); data.append('confirmed', '1');
        if (evidence.value) data.append('evidence', evidence.value);
        const response = await window.axios.post(endpoint.value, data, { portalToast: false });
        context.value = response.data.context; open.value = false; explanation.value = ''; clearFile(); emit('changed');
    } catch (failure) { error.value = Object.values(failure.response?.data?.errors ?? {}).flat()[0] || failure.response?.data?.message || 'Your request could not be sent.'; }
    finally { busy.value = false; }
}
function viewEvidence() {
    const file = record.value?.evidence;
    if (file) previewFile.value = { original_name: file.name, mime_type: file.mime, size: file.size, view_url: file.url };
}
watch(() => props.scholarshipId, () => { version++; context.value = null; open.value = false; clearFile(); load(); }, { immediate: true });
onBeforeUnmount(() => { version++; clearFile(); });
</script>

<template>
    <section v-if="visible" :class="embedded ? 'border-t border-slate-200 px-5 py-5 sm:px-6' : 'student-card mb-4 p-4 sm:p-5'" data-applicant-eligibility-review>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="student-kicker">Eligibility reconsideration</p><h3 class="mt-1 text-base font-bold text-slate-950">{{ record ? record.status_label : 'Have circumstances the provider should consider?' }}</h3></div>
            <button v-if="context?.can_request" type="button" class="inline-flex min-h-10 items-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white" @click="begin"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i>Request eligibility review</button>
            <button v-if="record" type="button" :disabled="loading" class="min-h-10 rounded-md border border-slate-300 px-3 text-xs font-bold text-slate-700 disabled:opacity-50" @click="load(true)">Refresh status</button>
        </div>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ loading ? 'Checking request availability...' : context?.can_request ? 'Explain the required differences below. You may attach a photo or document to support your explanation.' : context?.reason }}</p>
        <p v-if="record && !record.context_matches && !record.applied" class="mt-2 text-sm font-semibold text-amber-800">Your profile or the program rules changed. A previous approval cannot be used for the new comparison.</p>
        <div v-if="record" class="mt-3 space-y-2 text-sm text-slate-700">
            <p v-if="record.decision_note"><strong>Provider response:</strong> {{ record.decision_note }}</p>
            <details><summary class="w-fit cursor-pointer py-1 font-bold">Your explanation and attachment</summary><p class="mt-2 whitespace-pre-line leading-6">{{ record.explanation }}</p><button v-if="record.evidence" type="button" class="mt-2 inline-flex min-h-10 items-center gap-2 rounded border border-slate-300 px-3 font-bold" @click="viewEvidence"><i class="fa-regular fa-image" aria-hidden="true"></i>View supporting file</button></details>
            <a v-if="record.status === 'approved' && record.context_matches && !record.applied" :href="`/dashboard/applications/start/${scholarshipId}`" class="inline-flex min-h-10 items-center gap-2 font-bold text-slate-950">Continue to application<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        <p class="mt-3 text-xs leading-5 text-slate-500">Review is not selection. Your profile must stay accurate and verified; deadlines, documents, and available application spaces still apply.</p>
    </section>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5" @click.self="close">
            <form ref="dialog" class="flex max-h-[92dvh] w-full max-w-2xl flex-col overflow-hidden rounded-md bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="eligibility-request-title" tabindex="-1" @keydown="handleKeydown" @submit.prevent="submit">
                <header class="flex items-start justify-between gap-4 border-t-4 border-amber-400 bg-slate-950 px-5 py-4 text-white"><div><p class="text-xs font-bold uppercase tracking-widest text-amber-300">Eligibility review</p><h2 id="eligibility-request-title" class="mt-1 text-xl font-bold">Explain your circumstances</h2></div><button type="button" :disabled="busy" aria-label="Close eligibility request" class="grid h-9 w-9 place-items-center rounded border border-white/30" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
                    <section><h3 class="text-sm font-bold text-slate-950">Required rules to reconsider</h3><ul class="mt-2 divide-y divide-slate-200"><li v-for="criterion in context.criteria" :key="criterion.key" class="py-2 text-sm"><strong>{{ criterion.label }}</strong><p class="mt-1 text-xs leading-5 text-slate-600">Your profile: {{ criterion.student_value || 'Not provided' }}. Program rule: {{ criterion.requirement }}.</p></li></ul></section>
                    <label class="block text-sm font-bold text-slate-900">Why should the provider reconsider?<textarea v-model="explanation" required minlength="20" maxlength="2000" rows="4" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm font-normal leading-6" placeholder="Explain the difference and any relevant circumstances. Keep your profile truthful."></textarea><span class="mt-1 block text-xs font-normal text-slate-500">20 to 2,000 characters. Do not include passwords or bank details.</span></label>
                    <div><label class="block text-sm font-bold text-slate-900">Supporting photo or document <span class="font-normal text-slate-500">(optional)</span><input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" :disabled="busy" class="mt-2 block w-full rounded-md border border-slate-300 bg-white p-2 text-sm font-normal" @change="chooseFile"></label><p class="mt-1 text-xs text-slate-500">One JPG, PNG, WebP, or PDF, up to 5 MB. Only you and authorized provider reviewers can open it.</p><img v-if="previewUrl" :src="previewUrl" alt="Selected supporting picture" class="mt-3 max-h-52 w-full rounded border border-slate-200 object-contain"></div>
                    <label class="flex items-start gap-3 border-l-2 border-amber-400 bg-amber-50 p-3 text-sm leading-6"><input v-model="confirmed" type="checkbox" required class="mt-1 h-4 w-4 shrink-0"><span>I confirm this explanation is accurate and agree to share this request and attachment with the provider. I understand approval only permits applying.</span></label>
                </div>
                <footer class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" :disabled="busy" class="min-h-11 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold" @click="close">Cancel</button><button type="submit" :disabled="busy || !confirmed || explanation.trim().length < 20" class="min-h-11 rounded-md bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-50">{{ busy ? 'Sending...' : 'Send request' }}</button></footer>
            </form>
        </div>
    </Teleport>
    <FeedbackAlertModal v-model="error" title="Eligibility review request" />
    <FilePreviewModal :file="previewFile" title="Eligibility supporting file" @close="previewFile = null" />
</template>
