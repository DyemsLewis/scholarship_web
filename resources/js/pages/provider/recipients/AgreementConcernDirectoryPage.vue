<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import AgreementConcernDialog from '../../../components/agreement-concerns/AgreementConcernDialog.vue';
import { useConcernCollection } from '../../../composables/useConcernCollection.js';
import { concernClass, concernDate, concernError, concernLabel, concernStatuses } from '../../../support/agreementConcerns.js';

const status = ref('open'), program = ref(''), search = ref(''), selected = ref(null), opening = ref(false), selectionError = ref(''), message = ref('');
const { rows, programs, loading, error, pagination, load, retry, invalidate } = useConcernCollection({
    endpoint: () => '/provider/workspaces/recipients/concerns/data', params: () => ({ status: status.value, program_id: program.value || undefined, search: search.value.trim() || undefined }),
    fetcher: (...args) => window.axios.get(...args),
});
let timer, version = 0;
async function show(row) {
    if (opening.value) return;
    const token = ++version; opening.value = true; selectionError.value = '';
    try { const { data } = await window.axios.get(row.read_url, { portalToast: false }); if (token === version) selected.value = data.case; }
    catch (failure) { if (token === version) selectionError.value = concernError(failure); }
    finally { if (token === version) opening.value = false; }
}
async function changed(data) { message.value = data.message || ''; await load(pagination.value.current_page); }
watch([status, program], () => { clearTimeout(timer); load(); }, { flush: 'sync' });
watch(search, () => { invalidate(); loading.value = true; error.value = ''; clearTimeout(timer); timer = setTimeout(() => load(), 300); }, { flush: 'sync' });
onMounted(load);
onBeforeUnmount(() => { invalidate(); version++; clearTimeout(timer); });
</script>

<template>
    <main class="student-shell"><ProviderSidebar /><section class="student-page" aria-labelledby="concern-queue-title"><div class="provider-application-workspace mx-auto max-w-[94rem]">
        <header class="flex items-center justify-between gap-6 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-file-circle-question" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Recipient management</p><h1 id="concern-queue-title" class="mt-1 font-display text-2xl font-bold text-slate-950">Agreement concerns</h1><p class="mt-2 text-sm text-slate-600">Track notices and applicant responses. A concern is not a confirmed breach.</p></div></div><a href="/provider/workspaces/recipients/active" class="inline-flex min-h-10 shrink-0 items-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700">Open recipient records<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></header>
        <p v-if="message" role="status" class="mt-4 border border-slate-200 bg-white px-5 py-3 text-sm text-slate-700">{{ message }}</p>
        <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Agreement concern queue">
            <div class="grid grid-cols-[minmax(0,1fr)_16rem_14rem] gap-3 border-b border-slate-200 p-4"><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Find a concern</span><input v-model="search" type="search" maxlength="160" placeholder="Applicant name or subject" class="h-11 w-full border border-slate-300 px-3 text-sm"></label><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Program</span><select v-model="program" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="">All assigned programs</option><option v-for="item in programs" :key="item.id" :value="item.id">{{ item.title }}</option></select></label><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Status</span><select v-model="status" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option v-for="[value, label] in concernStatuses" :key="value" :value="value">{{ label }}</option></select></label></div>
            <p v-if="selectionError" role="alert" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ selectionError }}</p>
            <StaffTableLoadError v-if="error && !loading" :message="error" @retry="retry" />
            <div class="staff-table-viewport" :aria-busy="loading || opening"><p v-if="loading" role="status" class="py-20 text-center text-sm text-slate-500">Loading concerns...</p><div v-else-if="!rows.length && !error" class="px-6 py-20 text-center"><span class="mx-auto grid h-10 w-10 place-items-center bg-slate-100 text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span><h2 class="mt-3 text-base font-bold">No matching concerns</h2><p class="mt-2 text-sm text-slate-500">Start a notice from an accepted recipient's record when a specific obligation needs clarification.</p></div>
                <table v-else-if="!error" class="w-full min-w-[60rem] table-fixed text-left"><colgroup><col><col class="w-56"><col class="w-48"><col class="w-28"></colgroup><thead class="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-[0.14em] text-slate-600"><tr><th class="px-5 py-3">Applicant and concern</th><th class="px-5 py-3">Response deadline</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50"><td class="px-5 py-4"><div class="flex items-center gap-3"><StaffTableIdentity :src="row.applicant.photo_url" class="h-11 w-11" /><div class="min-w-0"><p class="break-words text-sm font-bold">{{ row.applicant.name }}</p><p class="mt-1 break-words text-xs text-slate-600">{{ row.subject }}</p><p class="mt-1 break-words text-xs text-slate-500">{{ row.program.title }}</p></div></div></td><td class="px-5 py-4 text-xs leading-5 text-slate-600">{{ row.response_due_at ? concernDate(row.response_due_at) : 'Not sent yet' }}<span v-if="row.deadline_passed" class="mt-1 block font-bold text-amber-800">Response still allowed</span></td><td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold', concernClass(row.status)]">{{ concernLabel(row.status) }}</span></td><td class="px-5 py-4 text-right"><button type="button" :disabled="opening" class="staff-row-action h-9 w-9" :aria-label="'Open concern for ' + row.applicant.name" @click="show(row)" title="Open concern"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></button></td></tr></tbody></table>
            </div><footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs"><span>{{ pagination.total }} concern(s)<span v-if="opening" role="status"> &middot; Opening...</span></span><div class="flex gap-2"><button type="button" :disabled="loading || Boolean(error) || pagination.current_page <= 1" class="min-h-9 border border-slate-300 bg-white px-3 font-bold disabled:opacity-40" @click="load(pagination.current_page - 1)">Previous</button><button type="button" :disabled="loading || Boolean(error) || pagination.current_page >= pagination.last_page" class="min-h-9 border border-slate-300 bg-white px-3 font-bold disabled:opacity-40" @click="load(pagination.current_page + 1)">Next</button></div></footer>
        </section><AgreementConcernDialog :open="!!selected" :record="selected" provider @close="selected = null" @changed="changed" />
    </div></section></main>
</template>
