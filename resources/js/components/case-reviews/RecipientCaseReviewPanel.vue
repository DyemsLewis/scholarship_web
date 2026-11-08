<script setup>
import StaffTableIdentity from '../StaffTableIdentity.vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import RecipientCaseReviewDialog from './RecipientCaseReviewDialog.vue';
import { concernError, concernDate } from '../../support/agreementConcerns.js';
const props = defineProps({ historyUrl: { type: String, required: true }, admin: Boolean });
const emit = defineEmits(['changed']);
const root = ref(null), rows = ref([]), pagination = ref({}), loading = ref(false), opening = ref(false), error = ref(''), message = ref(''), selected = ref(null);
const status = ref('open'), type = ref(''), search = ref('');
const selectionError = ref('');
let requestedPage = 1;
let version = 0, openVersion = 0, timer;
async function load(page = 1) {
    if (props.admin) clearTimeout(timer);
    requestedPage = page;
    selectionError.value = '';
    const token = ++version; loading.value = true; error.value = '';
    try { const { data } = await window.axios.get(props.historyUrl, { params: { page, ...(props.admin ? { status: status.value, type: type.value || undefined, search: search.value.trim() || undefined } : {}) }, portalToast: false }); if (token !== version) return; rows.value = data.cases.data; pagination.value = data.cases; }
    catch (failure) { if (token === version) { error.value = concernError(failure); rows.value = []; } }
    finally { if (token === version) loading.value = false; }
}
watch(() => props.historyUrl, async () => { selected.value = null; rows.value = []; await load(); if (window.location.hash === '#case-reviews') { await nextTick(); root.value?.scrollIntoView({ block: 'start' }); root.value?.focus({ preventScroll: true }); } }, { immediate: true });
watch([status, type], () => load());
watch(search, () => { version++; loading.value = true; clearTimeout(timer); timer = setTimeout(() => load(), 300); });
async function show(row) {
    if (opening.value) return; const token = ++openVersion; opening.value = true; error.value = ''; selectionError.value = '';
    try { const { data } = await window.axios.get(row.read_url, { portalToast: false }); if (token === openVersion) selected.value = data.case; }
    catch (failure) { if (token === openVersion) (props.admin ? selectionError : error).value = concernError(failure); }
    finally { if (token === openVersion) opening.value = false; }
}
async function changed(data) { message.value = data.message || ''; await load(pagination.value.current_page || 1); emit('changed', data); }
function refreshFromElsewhere() { if (!selected.value && !opening.value) load(pagination.value.current_page || 1); }
window.addEventListener('recipient-case-review-updated', refreshFromElsewhere);
onBeforeUnmount(() => { version++; openVersion++; clearTimeout(timer); window.removeEventListener('recipient-case-review-updated', refreshFromElsewhere); });
</script>

<template>
    <section v-if="admin || selected || rows.length || loading || error" id="case-reviews" ref="root" data-case-review-panel tabindex="-1" class="mt-4 scroll-mt-5 overflow-hidden rounded-sm border border-slate-300 bg-white shadow-sm" aria-label="Case reviews and appeals">
        <header class="flex items-start gap-3 border-b border-slate-200 px-5 py-4"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Case reviews and appeals</h2><p class="mt-1 text-sm leading-5 text-slate-600">{{ admin ? 'Review the source, both parties’ statements, and proof before recording an outcome.' : 'Read independent review outcomes, add requested information, or appeal.' }}</p></div></header>
        <div v-if="admin" class="grid grid-cols-[minmax(0,1fr)_14rem_17rem] gap-3 border-b border-slate-200 p-4"><label class="text-xs font-bold text-slate-600">Subject<input v-model="search" type="search" maxlength="160" placeholder="Search case subject" class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm font-normal"></label><label class="text-xs font-bold text-slate-600">Case type<select v-model="type" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm font-normal"><option value="">All case types</option><option value="agreement_concern">Agreement concern</option><option value="benefit_delivery">Benefit delivery</option></select></label><label class="text-xs font-bold text-slate-600">Review status<select v-model="status" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm font-normal"><option value="open">All pending reviews</option><option value="appeal_pending">Appeals</option><option value="awaiting_information">Awaiting information</option><option value="reviewed">Outcomes recorded</option><option value="all">All reviews</option></select></label></div>
        <p v-if="message" role="status" class="border-b border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-700">{{ message }}</p>
        <p v-if="error" role="alert" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ error }} <button type="button" :disabled="loading" class="ml-2 font-bold underline" @click="load(admin ? requestedPage : 1)">Try again</button></p>
        <p v-if="admin && selectionError" role="alert" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ selectionError }}</p>
        <div :class="admin ? 'staff-table-viewport' : ''" :aria-busy="loading || opening">
            <p v-if="loading || opening" role="status" class="px-5 py-8 text-center text-sm text-slate-500">{{ opening ? 'Opening case...' : 'Loading reviews...' }}</p>
            <template v-else-if="!error"><p v-if="!rows.length" class="px-5 py-12 text-center text-sm text-slate-500">No matching case reviews.</p>
                <table v-else-if="admin" class="w-full min-w-[60rem] table-fixed text-left"><colgroup><col><col class="w-44"><col class="w-56"><col class="w-24"></colgroup><thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-600"><tr><th class="px-5 py-3">Applicant and case</th><th class="px-5 py-3">Type / requested</th><th class="px-5 py-3">Review status</th><th class="px-5 py-3 text-right">Open</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50"><td class="px-5 py-4"><div class="flex items-center gap-3"><StaffTableIdentity :src="row.applicant.photo_url" class="h-11 w-11" /><div class="min-w-0"><p class="break-words text-sm font-bold">{{ row.applicant.name }}</p><p class="mt-1 break-words text-xs text-slate-700">{{ row.subject }}</p><p class="mt-1 break-words text-xs text-slate-500">{{ row.program.title }}</p></div></div></td><td class="px-5 py-4 text-xs leading-5 text-slate-600">{{ row.type_label }}<span class="mt-1 block">{{ concernDate(row.escalated_at) }}</span></td><td class="px-5 py-4"><span :class="['inline-block border px-2 py-1 text-xs font-bold', row.review_state === 'appeal_pending' ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-300 bg-slate-100 text-slate-700']">{{ row.review_state_label }}</span></td><td class="px-5 py-4 text-right"><button type="button" class="staff-row-action h-9 w-9" :aria-label="`Open case for ${row.applicant.name}`" @click="show(row)" title="Open case"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></button></td></tr></tbody></table>
                <div v-else v-for="row in rows" :key="row.id" class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="break-words text-sm font-bold">{{ row.subject }}</p><p class="mt-2 text-xs text-slate-600">{{ row.review_state_label }} / {{ row.finding_label }}</p></div><button type="button" class="min-h-10 shrink-0 bg-slate-950 px-4 text-sm font-bold text-white" @click="show(row)">Open review</button></div>
            </template>
        </div><footer v-if="pagination.last_page > 1 || admin" class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs"><span>{{ pagination.total || 0 }} review(s)</span><div class="flex gap-2"><button v-for="direction in [-1, 1]" :key="direction" :disabled="loading || opening || Boolean(admin && error) || (direction < 0 ? pagination.current_page <= 1 : pagination.current_page >= pagination.last_page)" class="min-h-9 border border-slate-300 bg-white px-3 font-bold disabled:opacity-40" @click="load(pagination.current_page + direction)">{{ direction < 0 ? 'Previous' : 'Next' }}</button></div></footer>
        <RecipientCaseReviewDialog :open="!!selected" :record="selected" :admin="admin" @close="selected = null; load(pagination.current_page || 1)" @changed="changed" />
    </section>
</template>
