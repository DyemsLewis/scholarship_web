<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import BenefitDeliveryReportDialog from './BenefitDeliveryReportDialog.vue';
import { concernError } from '../../support/agreementConcerns.js';
import { deliveryClass } from '../../support/benefitDeliveryReports.js';

const props = defineProps({ historyUrl: { type: String, required: true }, provider: Boolean });
const emit = defineEmits(['status-change']);
const root = ref(null), rows = ref([]), legacy = ref([]), options = ref([]), createUrl = ref(''), pagination = ref({}), legacyPagination = ref({});
const loading = ref(false), opening = ref(false), error = ref(''), message = ref(''), selected = ref(null), open = ref(false);
let version = 0;
async function load(page = 1, legacyPage = 1) {
    const token = ++version; loading.value = true; error.value = '';
    options.value = []; createUrl.value = '';
    try {
        const { data } = await window.axios.get(props.historyUrl, { params: { page, legacy_page: legacyPage }, portalToast: false });
        if (token !== version) return;
        rows.value = data.reports.data; pagination.value = data.reports; legacy.value = data.legacy_reports?.data || []; legacyPagination.value = data.legacy_reports || {};
        options.value = data.options || []; createUrl.value = data.create_url || '';
    } catch (failure) { if (token === version) { error.value = concernError(failure); rows.value = []; legacy.value = []; } }
    finally { if (token === version) loading.value = false; }
}
watch(() => props.historyUrl, async () => {
    open.value = false; selected.value = null; opening.value = false; rows.value = []; options.value = []; legacy.value = []; createUrl.value = '';
    await load(); if (window.location.hash === '#benefit-delivery-reports') { await nextTick(); root.value?.scrollIntoView({ block: 'start' }); root.value?.focus({ preventScroll: true }); }
}, { immediate: true });
async function show(row = null, importUrl = null) {
    if (opening.value || loading.value) return;
    error.value = ''; selected.value = null;
    if (!row && !importUrl) { open.value = true; return; }
    const token = version; opening.value = true;
    try {
        const { data } = importUrl ? await window.axios.post(importUrl, {}, { portalToast: false }) : await window.axios.get(row.read_url, { portalToast: false });
        if (token === version) { selected.value = data.report; open.value = true; }
    } catch (failure) { if (token === version) error.value = concernError(failure); }
    finally { if (token === version) opening.value = false; }
}
async function changed(data) { message.value = data.message || ''; await load(pagination.value.current_page || 1, legacyPagination.value.current_page || 1); emit('status-change', data); }
function close() { open.value = false; load(pagination.value.current_page || 1, legacyPagination.value.current_page || 1); }
onBeforeUnmount(() => { version++; });
</script>

<template>
    <section v-if="provider || open || loading || options.length || rows.length || legacy.length || error" id="benefit-delivery-reports" ref="root" tabindex="-1" data-delivery-panel class="mt-4 scroll-mt-5 overflow-hidden rounded-sm border border-slate-300 bg-white shadow-sm" aria-label="Benefit delivery reports">
        <header class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Benefit delivery reports</h2><p class="mt-1 text-sm leading-5 text-slate-600">{{ provider ? 'Respond to delivery concerns without assuming receipt.' : 'Missing, incomplete, or unclear delivery? Ask the provider here, even before a release is recorded.' }}</p></div></div><button v-if="!provider && options.length && createUrl" type="button" :disabled="loading || opening" class="min-h-10 shrink-0 border border-slate-300 px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="show()">Report a benefit issue</button></header>
        <p v-if="message" role="status" class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-600">{{ message }}</p>
        <p v-if="error" role="alert" class="border-t border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ error }} <button type="button" :disabled="loading" class="ml-2 font-bold underline" @click="load()">Try again</button></p>
        <p v-else-if="loading || opening" role="status" class="border-t border-slate-200 px-5 py-4 text-sm text-slate-500">{{ opening ? 'Opening report...' : 'Loading reports...' }}</p>
        <template v-else>
            <article v-for="row in rows" :key="row.id" class="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div class="min-w-0"><p class="break-words text-sm font-bold text-slate-950">{{ row.subject }}</p><div class="mt-2 flex flex-wrap items-center gap-2"><span :class="['border px-2 py-1 text-xs font-bold', deliveryClass(row.status)]">{{ row.status_label }}</span><span v-if="row.follow_up_on" class="text-xs text-slate-500">Follow-up {{ row.follow_up_on }}</span></div></div><button type="button" class="min-h-10 shrink-0 bg-slate-950 px-4 text-sm font-bold text-white" @click="show(row)">{{ row.can_respond || row.can_propose ? 'Review and respond' : 'View report' }}</button></article>
            <div v-for="row in legacy" :key="`legacy:${row.id}`" class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-sm font-bold">{{ row.title }}</p><p class="mt-1 text-xs text-slate-500">Earlier receipt issue. Open it to continue with applicant-confirmed resolution.</p></div><button type="button" class="min-h-10 shrink-0 border border-slate-300 px-4 text-sm font-bold" @click="show(null, row.import_url)">Open receipt issue</button></div>
            <p v-if="!rows.length && !legacy.length" class="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">No delivery reports are recorded. Use normal receipt confirmation when you receive a benefit without a problem.</p>
        </template>
        <footer v-for="[key, pager] in [['reports', pagination], ['legacy', legacyPagination]]" v-show="pager.last_page > 1" :key="key" class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-xs"><span>{{ key === 'legacy' ? 'Earlier issues' : 'Reports' }}: {{ pager.current_page }} of {{ pager.last_page }}</span><div class="flex gap-2"><button v-for="direction in [-1, 1]" :key="direction" type="button" :disabled="loading || opening || (direction < 0 ? pager.current_page <= 1 : pager.current_page >= pager.last_page)" class="border px-3 py-2 disabled:opacity-40" @click="load(key === 'reports' ? pager.current_page + direction : pagination.current_page, key === 'legacy' ? pager.current_page + direction : legacyPagination.current_page)">{{ direction < 0 ? 'Previous' : 'Next' }}</button></div></footer>
        <BenefitDeliveryReportDialog :open="open" :record="selected" :provider="provider" :options="options" :create-url="createUrl" @close="close" @changed="changed" />
    </section>
</template>
