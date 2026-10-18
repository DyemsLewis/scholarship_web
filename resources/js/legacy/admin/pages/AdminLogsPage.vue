<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const selectedAction = ref('all');
const selectedEntry = ref(null);
const entries = ref([]);
const filters = ref({ all: 0 });
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});

const actionFilters = computed(() => Object.entries(filters.value).map(([action, count]) => ({
    value: action,
    label: action === 'all' ? 'All activity' : actionLabel(action),
    count,
})));

function actionLabel(action) {
    return String(action)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function actionClass(action) {
    if (action === 'login_failed') {
        return 'bg-rose-100 text-rose-800';
    }

    if (action === 'account_created') {
        return 'bg-amber-100 text-amber-800';
    }

    if (['login', 'registered'].includes(action)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (action === 'logout') {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-slate-100 text-slate-700';
}

function actionIcon(action) {
    if (action === 'login_failed') {
        return 'fa-triangle-exclamation';
    }

    if (action === 'account_created' || action === 'registered') {
        return 'fa-user-plus';
    }

    if (action === 'login') {
        return 'fa-right-to-bracket';
    }

    if (action === 'logout') {
        return 'fa-right-from-bracket';
    }

    return 'fa-clock-rotate-left';
}

async function loadLogs(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/log-entries', {
            params: {
                page,
                per_page: pagination.value.per_page,
                action: selectedAction.value,
            },
        });

        entries.value = response.data.entries;
        filters.value = response.data.filters;
        pagination.value = response.data.pagination;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load activity logs.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(() => loadLogs());
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="logs" />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Activity records"
                    title="Review platform activity"
                    description="Review account and security actions recorded by the platform."
                    icon="fa-solid fa-clock-rotate-left"
                >
                    <template #meta>
                        <span>{{ pagination.total }} recorded actions</span>
                        <span>Showing {{ pagination.from ?? 0 }}-{{ pagination.to ?? 0 }}</span>
                    </template>
                </TaskPageHeader>

                <section class="admin-panel mt-5 overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <label class="flex min-w-0 flex-1 items-center gap-3 sm:max-w-sm">
                            <span class="shrink-0 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Show</span>
                            <select v-model="selectedAction" class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500" @change="loadLogs(1)">
                                <option v-for="filter in actionFilters" :key="filter.value" :value="filter.value">
                                    {{ filter.label }} ({{ filter.count }})
                                </option>
                            </select>
                        </label>
                        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100" @click="loadLogs(pagination.current_page)">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            Refresh
                        </button>
                    </div>

                    <div v-if="isLoading" class="p-8 text-center text-sm text-slate-500">
                        Loading activity logs...
                    </div>

                    <div v-else-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {{ errorMessage }}
                    </div>

                    <div v-else-if="entries.length" class="divide-y divide-slate-200">
                        <article
                            v-for="entry in entries"
                            :key="entry.id"
                            class="flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50"
                        >
                            <div :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md text-xs', actionClass(entry.action)]">
                                <i :class="['fa-solid', actionIcon(entry.action)]" aria-hidden="true"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="line-clamp-1 text-sm font-bold leading-5 text-slate-950">{{ entry.description }}</h4>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', actionClass(entry.action)]">
                                                {{ actionLabel(entry.action) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ entry.actor_name }} &middot; {{ actionLabel(entry.actor_role || 'system') }} &middot; {{ entry.created_at }}
                                        </p>
                                    </div>
                                    <button type="button" class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="selectedEntry = entry">
                                        View details
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div v-else class="p-8 text-center">
                        <p class="text-sm font-bold text-slate-900">No activity for this filter</p>
                        <p class="mt-1 text-sm text-slate-500">Choose another activity type to see other records.</p>
                    </div>

                    <div
                        v-if="pagination.last_page > 1"
                        class="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-sm text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                :disabled="pagination.current_page <= 1 || isLoading"
                                class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="loadLogs(pagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                :disabled="pagination.current_page >= pagination.last_page || isLoading"
                                class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="loadLogs(pagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </section>

            </div>
        </section>

        <div v-if="selectedEntry" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/65 p-4" role="dialog" aria-modal="true" aria-labelledby="activity-record-title" @click.self="selectedEntry = null">
            <section class="flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-950 p-5 text-white">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Activity record</p>
                        <h2 id="activity-record-title" class="mt-1 text-xl font-black">{{ actionLabel(selectedEntry.action) }}</h2>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-white/20 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Close activity details" @click="selectedEntry = null">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="overflow-y-auto p-5 sm:p-6">
                    <p class="text-base font-bold leading-6 text-slate-950">{{ selectedEntry.description }}</p>

                    <dl class="mt-5 grid gap-4 border-y border-slate-200 py-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Performed by</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ selectedEntry.actor_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Account role</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ actionLabel(selectedEntry.actor_role || 'system') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Recorded at</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ selectedEntry.created_at }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">IP address</dt>
                            <dd class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ selectedEntry.ip_address || 'Not recorded' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Recorded details</p>
                        <p class="mt-2 rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-700">
                            {{ selectedEntry.metadata_summary || 'No additional details were recorded for this action.' }}
                        </p>
                    </div>
                </div>

                <footer class="flex shrink-0 justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="selectedEntry = null">Close</button>
                </footer>
            </section>
        </div>
    </main>
</template>
