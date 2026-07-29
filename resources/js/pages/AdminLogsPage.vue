<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const selectedAction = ref('all');
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

function applyAction(action) {
    selectedAction.value = action;
    loadLogs(1);
}

onMounted(() => loadLogs());
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="logs" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Logs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Activity Logs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review clean admin activity records. The page shows {{ pagination.per_page }} entries at a time.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadLogs(pagination.current_page)"
                        >
                            Refresh Logs
                        </button>
                    </div>
                </header>

                <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Activity Trail</p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">Recent platform activity</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                            Showing {{ pagination.from ?? 0 }} to {{ pagination.to ?? 0 }} of {{ pagination.total }} records.
                        </p>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-for="filter in actionFilters"
                            :key="filter.value"
                            type="button"
                            :class="[
                                'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                selectedAction === filter.value
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400 hover:bg-slate-50'
                            ]"
                            @click="applyAction(filter.value)"
                        >
                            {{ filter.label }} ({{ filter.count }})
                        </button>
                    </div>

                    <div v-if="isLoading" class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
                        Loading activity logs...
                    </div>

                    <div v-else-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">
                        {{ errorMessage }}
                    </div>

                    <div v-else-if="entries.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                        <article
                            v-for="entry in entries"
                            :key="entry.id"
                            class="flex items-start gap-3 border-b border-slate-200 p-4 transition last:border-b-0 hover:bg-slate-50"
                        >
                            <div :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md text-sm', actionClass(entry.action)]">
                                <i :class="['fa-solid', actionIcon(entry.action)]" aria-hidden="true"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-bold text-slate-950 sm:text-base">{{ entry.description }}</h4>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', actionClass(entry.action)]">
                                                {{ actionLabel(entry.action) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ entry.actor_name }} &middot; {{ actionLabel(entry.actor_role || 'system') }}
                                            <template v-if="entry.ip_address"> &middot; {{ entry.ip_address }}</template>
                                        </p>
                                    </div>
                                    <time class="shrink-0 text-xs font-semibold text-slate-400">{{ entry.created_at }}</time>
                                </div>
                                <p v-if="entry.metadata_summary" class="mt-2 line-clamp-2 rounded-md bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-500">
                                    {{ entry.metadata_summary }}
                                </p>
                            </div>
                        </article>
                    </div>

                    <div v-else class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                        <p class="text-sm font-bold text-slate-900">No activity for this filter</p>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Choose another activity type to see other records.</p>
                    </div>

                    <div
                        v-if="pagination.last_page > 1"
                        class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between"
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

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
