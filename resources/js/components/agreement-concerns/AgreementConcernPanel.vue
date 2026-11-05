<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AgreementConcernDialog from './AgreementConcernDialog.vue';
import { useConcernCollection } from '../../composables/useConcernCollection.js';
import { concernClass, concernDate, concernError, concernLabel } from '../../support/agreementConcerns.js';

const props = defineProps({ historyUrl: { type: String, required: true }, provider: Boolean, context: String });
const emit = defineEmits(['status-change']);
const root = ref(null), selected = ref(null), open = ref(false), opening = ref(false), message = ref(''), openError = ref('');
const { rows, options, createUrl, loading, error, pagination, load, invalidate } = useConcernCollection({ endpoint: () => props.historyUrl, fetcher: (...args) => window.axios.get(...args) });
let version = 0;
watch(() => props.historyUrl, async () => {
    version++; open.value = false; selected.value = null; opening.value = false;
    await load();
    if (window.location.hash === '#agreement-concerns') { await nextTick(); root.value?.scrollIntoView({ block: 'start' }); root.value?.focus({ preventScroll: true }); }
}, { immediate: true });
async function show(row = null) {
    if (opening.value) return;
    openError.value = ''; selected.value = null;
    if (!row) { open.value = true; return; }
    const token = ++version; opening.value = true;
    try {
        const { data } = await window.axios.get(row.read_url, { portalToast: false });
        if (token === version) { selected.value = data.case; open.value = true; }
    } catch (failure) { if (token === version) openError.value = concernError(failure); }
    finally { if (token === version) opening.value = false; }
}
async function changed(data) { message.value = data.message || ''; await load(pagination.value.current_page); if (data.case?.status !== 'draft') emit('status-change', data); }
function close() { open.value = false; load(); }
onBeforeUnmount(() => { version++; invalidate(); });
</script>

<template>
    <section v-if="provider || rows.length || error || openError" id="agreement-concerns" ref="root" data-concern-panel tabindex="-1" class="scroll-mt-5 overflow-hidden rounded-sm border border-slate-300 bg-white shadow-sm" aria-label="Agreement concerns">
        <header class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-question" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Agreement concerns</h2><p class="mt-1 text-sm leading-5 text-slate-600">{{ provider ? 'Ask about a specific accepted obligation before drawing a conclusion.' : 'Review any provider concern and explain your circumstances.' }}</p></div></div><button v-if="provider && createUrl && !error" type="button" :disabled="loading || opening" class="min-h-10 shrink-0 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="show()">New concern</button></header>
        <p v-if="message" role="status" class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-700">{{ message }}</p>
        <p v-if="error || openError" role="alert" class="border-t border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ error || openError }} <button type="button" :disabled="loading" class="ml-2 font-bold underline" @click="openError = ''; load()">Try again</button></p>
        <p v-else-if="loading || opening" role="status" class="border-t border-slate-200 px-5 py-4 text-sm text-slate-500">{{ opening ? 'Opening concern...' : 'Loading concerns...' }}</p>
        <template v-else>
            <div v-for="row in rows" :key="row.id" class="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="min-w-0"><p class="break-words text-sm font-bold text-slate-950">{{ row.subject }}</p><div class="mt-2 flex flex-wrap items-center gap-2"><span :class="['border px-2 py-1 text-xs font-bold', concernClass(row.status)]">{{ concernLabel(row.status) }}</span><span v-if="row.response_due_at" class="text-xs text-slate-500">Response due {{ concernDate(row.response_due_at) }}</span></div></div><button type="button" class="min-h-10 shrink-0 bg-slate-950 px-4 text-sm font-bold text-white" @click="show(row)">{{ row.can_edit ? 'Continue draft' : row.can_respond ? 'Review and respond' : 'View concern' }}</button></div>
            <p v-if="!rows.length" class="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">No concern is recorded. A notice is not a finding and does not automatically change support.</p>
        </template>
        <footer v-if="pagination.last_page > 1" class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-xs"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" :disabled="loading || pagination.current_page <= 1" class="border px-3 py-2 disabled:opacity-40" @click="load(pagination.current_page - 1)">Previous</button><button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" class="border px-3 py-2 disabled:opacity-40" @click="load(pagination.current_page + 1)">Next</button></div></footer>
        <AgreementConcernDialog :open="open" :record="selected" :options="options" :create-url="createUrl" :context="context" :provider="provider" @close="close" @changed="changed" />
    </section>
</template>
