<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from './ProviderFooter.vue';
import ProviderSidebar from './ProviderSidebar.vue';
import TaskPageHeader from './TaskPageHeader.vue';

const props = defineProps({
    mode: {
        type: String,
        required: true,
        validator: (value) => ['edit', 'manage', 'monitoring'].includes(value),
    },
});

const isLoading = ref(true);
const errorMessage = ref('');
const programs = ref([]);
const searchQuery = ref('');

const pageCopy = computed(() => {
    if (props.mode === 'edit') {
        return {
        kicker: 'Program setup',
        title: 'Edit programs',
        description: 'Choose a program to update its setup and allowed fields.',
        emptyTitle: 'No programs available to edit',
        emptyDescription: 'Create a program first, then return here when its setup needs an update.',
        action: 'Edit setup',
        icon: 'fa-solid fa-pen-to-square',
        };
    }

    if (props.mode === 'monitoring') {
        return {
            kicker: 'Recipient support',
            title: 'Recipient monitoring',
            description: 'Choose a program to review academic updates and benefit releases.',
            emptyTitle: 'No programs ready for monitoring',
            emptyDescription: 'Programs appear here after recipients have been selected.',
            action: 'Open monitoring',
            icon: 'fa-solid fa-chart-line',
        };
    }

    return {
        kicker: 'Program operations',
        title: 'Manage programs',
        description: 'Open active, reviewed, or closed program workspaces.',
        emptyTitle: 'No programs ready to manage',
        emptyDescription: 'Programs appear here after their initial setup is ready for review or publication.',
        action: 'Open workspace',
        icon: 'fa-solid fa-table-columns',
    };
});

const modePrograms = computed(() => programs.value.filter((program) => (
    props.mode === 'edit'
        ? true
        : props.mode === 'monitoring'
            ? !['draft', 'rejected'].includes(program.status) && Number(program.awarded_slots_count ?? 0) > 0
            : !['draft', 'rejected'].includes(program.status)
)));

const visiblePrograms = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (!query) return modePrograms.value;

    return modePrograms.value.filter((program) => [
        program.title,
        program.category,
        statusLabel(program.status),
    ].filter(Boolean).join(' ').toLowerCase().includes(query));
});

function programMetric(program) {
    if (props.mode === 'monitoring') {
        const recipientCount = Number(program.awarded_slots_count ?? 0);

        return `${recipientCount} recipient${recipientCount === 1 ? '' : 's'}`;
    }

    return statusLabel(program.status);
}

function statusLabel(status) {
    return {
        draft: 'Draft',
        pending_review: 'In admin review',
        published: 'Published',
        rejected: 'Needs changes',
        closed: 'Closed',
    }[status] ?? String(status || 'Draft').replace(/_/g, ' ');
}

function statusClass(status) {
    if (status === 'published') return 'bg-emerald-100 text-emerald-800';
    if (status === 'pending_review') return 'bg-sky-100 text-sky-800';
    if (status === 'rejected') return 'bg-rose-100 text-rose-800';
    if (status === 'closed') return 'bg-slate-200 text-slate-700';

    return 'bg-amber-100 text-amber-800';
}

function programUrl(program) {
    if (props.mode === 'edit') return `/provider/programs/${program.id}/edit`;
    if (props.mode === 'monitoring') return `/provider/programs/${program.id}/monitoring`;

    return `/provider/programs/${program.id}`;
}

async function loadPrograms() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/dashboard/data');
        programs.value = Array.isArray(response.data.scholarships) ? response.data.scholarships : [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load programs.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadPrograms);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <TaskPageHeader
                    theme="provider"
                    :eyebrow="pageCopy.kicker"
                    :title="pageCopy.title"
                    :description="pageCopy.description"
                    :icon="pageCopy.icon"
                    :action-href="mode === 'edit' ? '/provider/programs/create' : ''"
                    :action-label="mode === 'edit' ? 'Create program' : ''"
                >
                    <template #meta>
                        <span>{{ modePrograms.length }} available program{{ modePrograms.length === 1 ? '' : 's' }}</span>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="provider-panel mt-4 p-6 text-sm text-slate-500">
                    Loading programs...
                </div>
                <div v-else-if="errorMessage" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>
                <section v-else class="provider-panel mt-4 overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <label class="relative w-full sm:max-w-sm">
                            <span class="sr-only">Search programs</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="searchQuery" type="search" placeholder="Search programs" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500">
                        </label>
                        <p class="text-xs font-semibold text-slate-500">{{ visiblePrograms.length }} shown</p>
                    </div>

                    <div v-if="visiblePrograms.length" class="hidden grid-cols-[minmax(0,1fr)_9rem_9rem] items-center gap-3 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500 lg:grid">
                        <span>Program</span>
                        <span class="text-center">{{ mode === 'monitoring' ? 'Recipients' : 'Status' }}</span>
                        <span class="text-center">Action</span>
                    </div>
                    <div v-if="visiblePrograms.length" class="divide-y divide-slate-200">
                        <article v-for="program in visiblePrograms" :key="program.id" class="grid gap-3 px-4 py-3 transition hover:bg-slate-50 sm:px-5 lg:grid-cols-[minmax(0,1fr)_9rem_9rem] lg:items-center">
                            <div class="flex min-w-0 items-center gap-3">
                                <img :src="program.image_url || '/uploads/scholarship-default.jpg'" :alt="program.title" class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ program.title }}</h3>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ program.category || 'Scholarship program' }}</p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 lg:justify-center">
                                <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400 lg:hidden">{{ mode === 'monitoring' ? 'Recipients' : 'Status' }}</span>
                                <span v-if="mode === 'monitoring'" class="text-xs font-bold text-slate-700">{{ programMetric(program) }}</span>
                                <span v-else :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(program.status)]">{{ programMetric(program) }}</span>
                            </div>
                            <a :href="programUrl(program)" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                {{ pageCopy.action }}
                                <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                            </a>
                        </article>
                    </div>

                    <div v-else class="p-6 text-center">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i :class="pageCopy.icon" aria-hidden="true"></i></span>
                        <h2 class="mt-3 text-sm font-bold text-slate-950">{{ searchQuery ? 'No matching programs' : pageCopy.emptyTitle }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ searchQuery ? 'Try a different program name or status.' : pageCopy.emptyDescription }}</p>
                    </div>
                </section>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
