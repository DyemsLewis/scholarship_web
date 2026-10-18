<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const filterFromPath = {
    '/provider/programs/drafts': 'drafts',
    '/provider/programs/review': 'review',
    '/provider/programs/published': 'published',
    '/provider/programs/closed': 'closed',
};
const filterCopy = {
    all: {
        title: 'Programs',
        description: 'Track each scholarship from draft through publication and closure.',
    },
    drafts: {
        title: 'Drafts & changes',
        description: 'Continue unfinished programs and address requested changes.',
    },
    review: {
        title: 'Under review',
        description: 'See programs currently awaiting an administrator decision.',
    },
    published: {
        title: 'Published programs',
        description: 'Review programs currently visible to applicants.',
    },
    closed: {
        title: 'Closed programs',
        description: 'Reference programs that are no longer accepting applications.',
    },
};

const activeFilter = filterFromPath[window.location.pathname.replace(/\/$/, '')] ?? 'all';
const pageCopy = filterCopy[activeFilter];
const programs = ref([]);
const summary = ref({ total: 0, needs_action: 0, under_review: 0, published: 0 });
const context = ref({ organization_name: '', access_scope: 'all' });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const sort = ref('updated');
const isLoading = ref(true);
const errorMessage = ref('');
let requestVersion = 0;

const rangeLabel = computed(() => {
    if (!pagination.value.total) {
        return '0 programs';
    }

    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;
});

function statusClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        waiting: 'border-sky-200 bg-sky-50 text-sky-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function applicationLabel(program) {
    const received = program.applications.received;
    const limit = program.applications.limit;

    return limit === null ? `${received} received` : `${received} of ${limit}`;
}

function deadlineNote(deadline) {
    if (deadline.days_remaining === null) return '';
    if (deadline.state === 'passed') return 'Application period ended';
    if (deadline.state === 'today') return 'Due today';
    return `${deadline.days_remaining} days remaining`;
}

async function loadPrograms(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/programs/data', {
            params: {
                filter: activeFilter,
                search: search.value.trim() || undefined,
                sort: sort.value,
                page,
            },
        });

        if (version !== requestVersion) return;

        programs.value = response.data.programs ?? [];
        summary.value = response.data.summary ?? summary.value;
        context.value = response.data.context ?? context.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;

        errorMessage.value = error.response?.data?.message
            || 'Programs could not be loaded. Please try again.';
    } finally {
        if (version === requestVersion) {
            isLoading.value = false;
        }
    }
}

function submitSearch() {
    loadPrograms(1);
}

function clearSearch() {
    search.value = '';
    loadPrograms(1);
}

onMounted(() => loadPrograms());
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="program-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Program workspace</p>
                            <h1 id="program-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">
                                {{ pageCopy.title }}
                            </h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <div class="flex items-center gap-5">
                            <div v-if="context.organization_name" class="max-w-xs border-l border-slate-200 pl-5 text-right">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Organization</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ context.organization_name }}</p>
                                <p v-if="context.access_scope === 'assigned'" class="mt-1 text-xs text-slate-500">Assigned programs only</p>
                            </div>
                            <a href="/provider/programs/create" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                Create program
                            </a>
                        </div>
                    </div>
                </header>

                <section class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Program summary">
                    <div class="border-r border-slate-200 px-5 py-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">All programs</p>
                        <p class="mt-1 text-2xl font-bold text-slate-950">{{ summary.total }}</p>
                    </div>
                    <div class="border-r border-slate-200 px-5 py-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Needs action</p>
                        <p class="mt-1 text-2xl font-bold text-slate-950">{{ summary.needs_action }}</p>
                    </div>
                    <div class="border-r border-slate-200 px-5 py-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Under review</p>
                        <p class="mt-1 text-2xl font-bold text-slate-950">{{ summary.under_review }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Published</p>
                        <p class="mt-1 text-2xl font-bold text-slate-950">{{ summary.published }}</p>
                    </div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Program directory">
                    <form class="flex items-end gap-3 border-b border-slate-200 px-5 py-4" @submit.prevent="submitSearch">
                        <label class="min-w-0 flex-1">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">Search programs</span>
                            <span class="flex h-11 items-center border border-slate-300 bg-white px-3 focus-within:border-slate-700">
                                <i class="fa-solid fa-magnifying-glass mr-3 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 outline-none ring-0 placeholder:text-slate-400 focus:ring-0"
                                    placeholder="Search by title, category, or cycle"
                                >
                            </span>
                        </label>
                        <label class="w-56">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">Sort by</span>
                            <select v-model="sort" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 focus:border-slate-700 focus:ring-slate-700" @change="loadPrograms(1)">
                                <option value="updated">Recently updated</option>
                                <option value="deadline">Nearest deadline</option>
                                <option value="title">Program title</option>
                            </select>
                        </label>
                        <button type="submit" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800">
                            Search
                        </button>
                        <button v-if="search" type="button" class="h-11 border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="clearSearch">
                            Clear
                        </button>
                    </form>

                    <div v-if="errorMessage" class="m-5 border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">
                        {{ errorMessage }}
                    </div>

                    <div class="staff-table-viewport">
                        <table class="w-full min-w-[68rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">Programs in the selected lifecycle section</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                    <th class="w-[31%] px-5 py-3">Program</th>
                                    <th class="w-[15%] px-5 py-3">Status</th>
                                    <th class="w-[14%] px-5 py-3">Applications</th>
                                    <th class="w-[18%] px-5 py-3">Deadline</th>
                                    <th class="w-[12%] px-5 py-3">Updated</th>
                                    <th class="w-[10%] px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading && !programs.length">
                                    <td colspan="6" class="px-5 py-12 text-center text-sm font-semibold text-slate-500">
                                        Loading programs...
                                    </td>
                                </tr>
                                <tr v-else-if="!programs.length">
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <p class="font-semibold text-slate-800">No programs found</p>
                                        <p class="mt-1 text-sm text-slate-500">Try another search or open a different program section.</p>
                                    </td>
                                </tr>
                                <tr v-for="program in programs" :key="program.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <img :src="program.image_url" :alt="`${program.title} image`" class="h-11 w-11 shrink-0 border border-slate-200 bg-white object-contain p-1" @error="$event.currentTarget.src = '/uploads/scholarship-default.jpg'">
                                            <div class="min-w-0">
                                                <a :href="program.record_url" class="block truncate font-semibold text-slate-950 hover:text-amber-700">{{ program.title }}</a>
                                                <p class="mt-1 truncate text-xs text-slate-500">
                                                    {{ program.category || 'Uncategorized' }}<template v-if="program.cycle"> · {{ program.cycle }}</template>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(program.status.tone)]">
                                            {{ program.status.label }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ applicationLabel(program) }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ program.slots === null ? 'Slots not set' : `${program.slots} recipient slots` }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ program.application_period.deadline.display }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ deadlineNote(program.application_period.deadline) }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ program.updated_display || 'Not available' }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <a :href="program.record_url" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">
                                            View
                                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">Showing {{ rangeLabel }}</p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="h-9 border border-slate-300 px-3 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="pagination.current_page <= 1 || isLoading"
                                @click="loadPrograms(pagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                            <button
                                type="button"
                                class="h-9 border border-slate-300 px-3 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="pagination.current_page >= pagination.last_page || isLoading"
                                @click="loadPrograms(pagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
