<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';
import { labelFromKey } from '../../../support/display.js';
import { reviewErrorMessage } from '../../../support/adminReviewPresentation.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';

const props = defineProps({ document: { type: Object, default: null }, provider: { type: Object, required: true }, allowed: { type: Boolean, default: true } });
const emit = defineEmits(['close', 'saved', 'reload', 'busy']);
const dialog = ref(null);
const headingId = `${useId()}-provider-document`;
const outcome = ref('');
const notes = ref('');
const compared = ref(false);
const saving = ref(false);
const stale = ref(false);
const error = ref('');
const previewFailed = ref(false);
const representativeName = computed(() => [props.provider.first_name, props.provider.middle_initial, props.provider.last_name].filter(Boolean).join(' ') || props.provider.name);
const canSave = computed(() => props.allowed && !!props.document?.review_url && Number.isInteger(props.document?.revision)
    && !saving.value && !stale.value && (outcome.value === 'rejected' ? !!notes.value.trim()
        : outcome.value === 'approved' && compared.value && props.document?.file_available !== false && !previewFailed.value));
const previewKind = computed(() => {
    if (props.document?.file_available === false || previewFailed.value) return '';
    if (props.document?.mime_type?.startsWith('image/')) return 'image';
    return props.document?.mime_type === 'application/pdf' ? 'pdf' : '';
});
const { handleKeydown } = useModalAccessibility(() => !!props.document, dialog, close);
watch(() => props.document, () => {
    outcome.value = ''; notes.value = ''; compared.value = false;
    stale.value = false; error.value = ''; previewFailed.value = false;
}, { immediate: true });
watch(saving, value => emit('busy', value), { flush: 'sync' });
function close() { if (!saving.value) emit('close'); }
async function save() {
    if (!canSave.value) return;
    if (!await confirmOutcome({ record: `${props.provider.provider_name || props.provider.name} - ${props.document.original_name}`,
        outcome: outcome.value === 'approved' ? 'Accept document' : 'Request replacement', danger: outcome.value === 'rejected',
        consequence: 'This records this document review only. The provider account decision remains separate.' }, () => canSave.value)) return;
    saving.value = true; error.value = '';
    try {
        const response = await window.axios.patch(props.document.review_url, {
            status: outcome.value, expected_revision: props.document.revision,
            review_notes: notes.value.trim() || null, compared: compared.value,
        }, { portalToast: false });
        emit('saved', response.data);
    } catch (failure) {
        stale.value = [404, 409].includes(failure.response?.status);
        error.value = stale.value ? 'This proof changed or was removed. Reload and inspect the current file before deciding.'
            : reviewErrorMessage(failure, 'The review could not be saved. Your note has been kept.');
    } finally { saving.value = false; }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="document" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-5" @click.self="close">
            <section ref="dialog" data-provider-document-review class="provider-document-dialog flex w-full max-w-[90rem] flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="headingId" :aria-busy="saving" tabindex="-1" @keydown="handleKeydown">
                <header class="flex shrink-0 items-center justify-between gap-5 border-b border-slate-200 px-6 py-4">
                    <div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Review organization proof</p><h2 :id="headingId" class="mt-1 text-xl font-bold text-slate-950">{{ labelFromKey(document.document_type) }}</h2><p class="mt-1 break-words text-xs text-slate-500">{{ document.original_name }}</p></div></div>
                    <button type="button" :disabled="saving" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" aria-label="Close document review" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="grid min-h-0 flex-1 grid-cols-[minmax(0,1.6fr)_minmax(22rem,1fr)] overflow-y-auto">
                    <div class="flex min-h-0 flex-col border-r border-slate-200 bg-slate-100 p-4">
                        <p class="mb-3 text-xs font-bold text-slate-600">Submitted file</p>
                        <img v-if="previewKind === 'image'" :src="document.view_url" :alt="labelFromKey(document.document_type)" class="min-h-0 w-full flex-1 object-contain" @error="previewFailed = true">
                        <iframe v-else-if="previewKind === 'pdf'" :key="document.revision" :src="document.view_url" title="Organization document preview" class="min-h-[26rem] w-full flex-1 border border-slate-200 bg-white" @error="previewFailed = true"></iframe>
                        <div v-else class="grid min-h-[26rem] flex-1 place-content-center gap-3 px-5 text-center text-sm text-slate-600"><i class="fa-regular fa-file-lines text-3xl" aria-hidden="true"></i><p>{{ document.file_available === false ? 'The file is unavailable. Request a replacement.' : 'Download and inspect this file before accepting it.' }}</p></div>
                        <a v-if="document.file_available !== false" :href="document.download_url" data-file-preview="false" class="mt-3 inline-flex min-h-10 items-center justify-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700"><i class="fa-solid fa-download" aria-hidden="true"></i>Download file</a>
                    </div>
                    <div class="space-y-5 p-6">
                        <section><h3 class="text-sm font-bold text-slate-950">Compare with organization details</h3><dl class="mt-3 space-y-3 text-sm"><div><dt class="text-xs text-slate-500">Organization</dt><dd class="mt-1 break-words font-semibold">{{ provider.legal_name || provider.provider_name }}</dd></div><div><dt class="text-xs text-slate-500">Registration</dt><dd class="mt-1 break-words font-semibold">{{ provider.registration_authority || 'Not provided' }} · {{ provider.registration_number || 'Not provided' }}</dd></div><div><dt class="text-xs text-slate-500">Representative</dt><dd class="mt-1 break-words font-semibold">{{ representativeName }}<template v-if="provider.representative_position"> · {{ provider.representative_position }}</template></dd></div></dl></section>
                        <p v-if="document.reviewed_at" class="border-t border-slate-200 pt-4 text-xs leading-5 text-slate-600">Last reviewed by {{ document.reviewed_by }} on {{ document.reviewed_at }}.<span v-if="document.review_notes" class="mt-1 block">{{ document.review_notes }}</span></p>
                        <form v-if="allowed" id="provider-document-decision" @submit.prevent="save">
                            <fieldset :disabled="saving || stale" class="space-y-4 border-t border-slate-200 pt-4">
                                <legend class="sr-only">Document decision</legend>
                                <label class="block"><span class="mb-1.5 block text-sm font-bold">Document outcome</span><select v-model="outcome" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="" disabled>Choose an outcome</option><option value="approved" :disabled="document.file_available === false || previewFailed">Accept document</option><option value="rejected">Request replacement</option></select></label>
                                <label v-if="outcome === 'approved'" class="flex items-start gap-2 text-sm leading-6 text-slate-700"><input v-model="compared" type="checkbox" class="mt-1.5 shrink-0">I inspected this file and compared it with the organization details.</label>
                                <label v-if="outcome" class="block"><span class="mb-1.5 block text-xs font-bold">{{ outcome === 'rejected' ? 'What must the provider correct?' : 'Reviewer note (optional)' }}</span><textarea v-model="notes" rows="3" maxlength="1500" :required="outcome === 'rejected'" class="w-full border border-slate-300 bg-white px-3 py-2 text-sm" :placeholder="outcome === 'rejected' ? 'Explain which document or details need replacing.' : 'Add context only if needed.'"></textarea></label>
                            </fieldset>
                        </form>
                        <p class="text-xs leading-5 text-slate-500">Accepting this file does not verify the provider account or authenticate it with the issuing authority. The final organization decision is separate.</p>
                        <div v-if="error" role="alert" class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950"><p>{{ error }}</p><button v-if="stale" type="button" class="mt-2 font-bold underline underline-offset-2" @click="emit('reload')">Reload current record</button></div>
                    </div>
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-xs text-slate-500">Review one document at a time.</p><div class="flex shrink-0 gap-3"><button type="button" :disabled="saving" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-40" @click="close">Cancel</button><button type="submit" form="provider-document-decision" :disabled="!canSave" class="h-10 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ saving ? 'Saving...' : outcome === 'rejected' ? 'Request replacement' : 'Accept document' }}</button></div></footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.provider-document-dialog { height: min(56rem, calc(100dvh - 2.5rem)); }
</style>
