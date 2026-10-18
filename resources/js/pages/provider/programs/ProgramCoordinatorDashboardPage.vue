<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const context = ref({ organization_name: '', access_scope: 'all' });
const summary = ref({ total: 0, needs_action: 0, under_review: 0, published: 0 });
const priorities = ref([]);
const deadlines = ref([]);
const recent = ref([]);
const isLoading = ref(true);
const errorMessage = ref('');

const focusMessage = computed(() => {
    if (summary.value.needs_action === 0) {
        return 'No program drafts or requested changes need attention.';
    }

    return `${summary.value.needs_action} program${summary.value.needs_action === 1 ? '' : 's'} need your attention.`;
});

function statusClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        waiting: 'border-sky-200 bg-sky-50 text-sky-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function deadlineNote(deadline) {
    if (deadline.days_remaining === 0) return 'Due today';
    if (deadline.days_remaining === 1) return '1 day remaining';
    return `${deadline.days_remaining} days remaining`;
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/programs/dashboard/data');
        context.value = response.data.context ?? context.value;
        summary.value = response.data.summary ?? summary.value;
        priorities.value = response.data.priorities ?? [];
        deadlines.value = response.data.deadlines ?? [];
        recent.value = response.data.recent ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            || 'The program dashboard could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="coordinator-dashboard-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Program coordination</p>
                            <h1 id="coordinator-dashboard-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">Program dashboard</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">See what needs attention before opening the full program directory.</p>
                        </div>
                        <a href="/provider/programs" class="inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800">
                            Open programs
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <section class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Program overview">
                        <div class="border-r border-slate-200 px-5 py-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Programs</p>
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

                    <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-12 text-center text-sm font-semibold text-slate-500 shadow-sm">
                        Loading program priorities...
                    </div>

                    <div v-else class="mt-4 space-y-4">
                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="priority-work-heading">
                            <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">Current focus</p>
                                    <h2 id="priority-work-heading" class="mt-0.5 text-lg font-bold text-slate-950">Priority work</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ focusMessage }}</p>
                                </div>
                                <a href="/provider/programs/drafts" class="text-xs font-bold text-slate-700 hover:text-amber-700">View drafts & changes</a>
                            </header>

                            <div v-if="priorities.length" class="divide-y divide-slate-200">
                                <article v-for="program in priorities" :key="program.id" class="grid grid-cols-[1fr_13rem_7rem] items-center gap-5 px-6 py-4">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-wide text-amber-700">{{ program.priority_label }}</p>
                                        <h3 class="mt-1 truncate font-semibold text-slate-950">{{ program.title }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ program.category || 'Uncategorized' }}<template v-if="program.cycle"> · {{ program.cycle }}</template></p>
                                    </div>
                                    <span :class="['justify-self-start border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(program.status.tone)]">
                                        {{ program.status.label }}
                                    </span>
                                    <a :href="program.record_url" class="inline-flex h-9 items-center justify-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">
                                        Open
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </article>
                            </div>
                            <p v-else class="px-6 py-8 text-sm text-slate-500">Nothing needs action right now.</p>
                        </section>

                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="upcoming-deadlines-heading">
                            <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-slate-500">Schedule</p>
                                    <h2 id="upcoming-deadlines-heading" class="mt-0.5 text-lg font-bold text-slate-950">Upcoming deadlines</h2>
                                </div>
                                <a href="/provider/programs/published" class="text-xs font-bold text-slate-700 hover:text-amber-700">View published programs</a>
                            </header>

                            <div v-if="deadlines.length" class="divide-y divide-slate-200">
                                <article v-for="program in deadlines" :key="program.id" class="grid grid-cols-[1fr_13rem_7rem] items-center gap-5 px-6 py-4">
                                    <div class="min-w-0">
                                        <h3 class="truncate font-semibold text-slate-950">{{ program.title }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ program.category || 'Uncategorized' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ program.deadline.display }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ deadlineNote(program.deadline) }}</p>
                                    </div>
                                    <a :href="program.record_url" class="inline-flex h-9 items-center justify-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                        View
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </article>
                            </div>
                            <p v-else class="px-6 py-8 text-sm text-slate-500">No published application deadlines are scheduled.</p>
                        </section>

                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-programs-heading">
                            <header class="border-b border-slate-200 px-6 py-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-slate-500">Activity</p>
                                <h2 id="recent-programs-heading" class="mt-0.5 text-lg font-bold text-slate-950">Recently updated</h2>
                            </header>

                            <div v-if="recent.length" class="divide-y divide-slate-200">
                                <a v-for="program in recent" :key="program.id" :href="program.record_url" class="grid grid-cols-[1fr_12rem_8rem] items-center gap-5 px-6 py-3.5 hover:bg-slate-50">
                                    <span class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ program.title }}</span>
                                    <span :class="['justify-self-start border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(program.status.tone)]">{{ program.status.label }}</span>
                                    <span class="text-right text-xs font-medium text-slate-500">{{ program.updated_display || 'Not available' }}</span>
                                </a>
                            </div>
                            <p v-else class="px-6 py-8 text-sm text-slate-500">No program updates are available yet.</p>
                        </section>
                    </div>
                </template>
            </div>
        </section>
    </main>
</template>
