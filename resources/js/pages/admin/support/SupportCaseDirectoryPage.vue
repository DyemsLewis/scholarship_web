<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const slug = window.location.pathname.split('/').filter(Boolean).pop();
const configs = {
    'needs-response': { queue: 'needs_response', eyebrow: 'Support inbox', title: 'Needs response', description: 'Review cases waiting for platform support.', empty: 'No cases need a response', icon: 'fa-solid fa-inbox' },
    waiting: { queue: 'waiting', eyebrow: 'Coordination', title: 'Waiting for provider', description: 'Track cases completed by platform support.', empty: 'No cases are waiting for a provider', icon: 'fa-solid fa-clock' },
    resolved: { queue: 'resolved', eyebrow: 'Case history', title: 'Resolved cases', description: 'Open completed records when follow-up is needed.', empty: 'No resolved cases yet', icon: 'fa-solid fa-circle-check' },
};
const config = configs[slug] ?? configs['needs-response'];
const query = new URLSearchParams(window.location.search);
const loading = ref(true);
const errorMessage = ref('');
const search = ref(query.get('search') ?? '');
const category = ref(query.get('category') ?? 'all');
const categories = ref([]);
const items = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 cases');

async function loadCases(page = 1) {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/admin/workspaces/support/data', { params: { page, per_page: 10, queue: config.queue, category: category.value, search: search.value.trim() || undefined } });
        items.value = response.data.items ?? [];
        categories.value = response.data.categories ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'This support queue could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadCases(page);
}

watch([search, category], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadCases(1), 250);
});
onMounted(loadCases);
</script>

<template>
    <AdminWorkspacePage workspace="support_officer">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon" />
        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <AdminTableFrame title="Support cases" :description="resultLabel" class="mt-4">
            <template #filters>
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <label class="relative"><span class="sr-only">Search support cases</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" placeholder="Search subject, reporter, or program" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <select v-model="category" aria-label="Concern category" class="h-11 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="all">All categories</option><option v-for="option in categories" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                </div>
            </template>

            <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading support cases...</div>
            <table v-else-if="items.length" class="w-full min-w-[62rem] border-collapse text-left">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Case</th><th class="border-b border-slate-200 px-4 py-3">Reporter</th><th class="border-b border-slate-200 px-4 py-3">Coordination</th><th class="border-b border-slate-200 px-4 py-3">Submitted</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5"><p class="max-w-md truncate text-sm font-bold text-slate-950">{{ item.subject }}</p><p class="mt-1 text-xs font-semibold text-amber-700">{{ item.category_label }}<span v-if="item.has_attachment" class="ml-2 text-slate-500"><i class="fa-solid fa-paperclip mr-1" aria-hidden="true"></i>Attachment</span></p></td>
                        <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i :class="item.reporter.type === 'Provider team' ? 'fa-solid fa-building-columns' : 'fa-solid fa-user'" aria-hidden="true"></i><img v-if="item.reporter.image_url" :src="item.reporter.image_url" :alt="item.reporter.type === 'Provider team' ? `${item.reporter.name} logo` : `${item.reporter.name} profile photo`" :class="['absolute inset-0 h-full w-full bg-white', item.reporter.image_fit === 'contain' ? 'object-contain p-1' : 'object-cover']" @error="$event.currentTarget.remove()"></span><span class="min-w-0"><span class="block max-w-xs truncate text-sm font-semibold text-slate-800">{{ item.reporter.name }}</span><span class="mt-0.5 block max-w-xs truncate text-xs text-slate-500">{{ item.reporter.type }} · {{ item.reporter.email }}</span></span></div></td>
                        <td class="px-4 py-3.5"><p class="text-sm font-semibold text-slate-700">{{ item.coordination_label }}</p><p class="mt-0.5 max-w-xs truncate text-xs text-slate-500">{{ item.program?.title || 'General platform concern' }}</p></td>
                        <td class="px-4 py-3.5 text-sm text-slate-600">{{ item.submitted_at }}</td>
                        <td class="px-5 py-3.5 text-right"><a :href="item.action_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${item.subject}`" title="Open case"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else :title="config.empty" description="Try another category or search." :icon="config.icon" />

            <template v-if="pagination.last_page > 1" #footer><div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div></template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
