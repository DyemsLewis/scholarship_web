<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const workspace = ref(null);
const profile = ref(null);
const summary = ref({ completion_percentage: 0, ready_sections: 0, total_sections: 0, document_count: 0 });
const nextTask = ref(null);
const sections = ref([]);
const recentDocuments = ref([]);

const organizationInitials = computed(() => String(profile.value?.name ?? 'Organization')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join(''));

function taskIcon(state) {
    return {
        attention: 'fa-triangle-exclamation',
        waiting: 'fa-clock',
        improve: 'fa-wand-magic-sparkles',
        complete: 'fa-pen-to-square',
    }[state] ?? 'fa-list-check';
}

function verificationClass(status) {
    return {
        approved: 'bg-emerald-100 text-emerald-800',
        rejected: 'bg-rose-100 text-rose-800',
        pending: 'bg-amber-100 text-amber-900',
        unsubmitted: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-slate-200 text-slate-700';
}

function documentStatusClass(status) {
    return {
        approved: 'text-emerald-700',
        rejected: 'text-rose-700',
        pending: 'text-amber-700',
        submitted: 'text-amber-700',
    }[status] ?? 'text-slate-500';
}

function documentStatusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadWorkspace() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/organization-profile/data');
        workspace.value = response.data.workspace;
        profile.value = response.data.profile;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        sections.value = response.data.sections ?? [];
        recentDocuments.value = response.data.recent_documents ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load organization profile work.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading organization profile...
                </div>

                <div v-else-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <ProviderPageHeader role-key="profile" title="Organization profile" description="Keep applicant-facing details and verification proof accurate and trustworthy." icon="fa-solid fa-building-shield">
                        <template #leading>
                            <div class="shrink-0">
                                <img v-if="profile.logo_url" :src="profile.logo_url" :alt="profile.name" class="h-12 w-12 shrink-0 rounded border border-slate-200 bg-white object-contain p-1">
                                <span v-else class="grid h-12 w-12 place-items-center rounded bg-amber-300 text-sm font-black text-slate-950">{{ organizationInitials }}</span>
                            </div>
                        </template>
                        <template #actions>
                            <span :class="['rounded px-2.5 py-1 text-[0.65rem] font-black uppercase tracking-wide', verificationClass(profile.verification_status)]">{{ profile.verification_status_label }}</span>
                        </template>
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ profile.name }}</span>
                            <span><i class="fa-solid fa-tag mr-2 text-slate-400"></i>{{ profile.type }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950">
                                <i :class="['fa-solid text-sm', taskIcon(nextTask.state)]"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ workspace.organization_name }}</p>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <span class="inline-flex bg-amber-100 px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide text-amber-900">{{ nextTask.label }}</span>
                                <p class="mt-1 max-w-sm text-xs font-semibold text-slate-600">{{ nextTask.detail }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                {{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                            </a>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">Organization profile is current</h2>
                            <p class="mt-0.5 text-xs text-slate-500">No required profile or verification work is waiting.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-3.5">
                            <div>
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Profile readiness</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">Required profile areas</h2>
                            </div>
                            <div class="flex shrink-0 items-center gap-4">
                                <div class="text-right">
                                    <p class="text-lg font-black text-slate-950">{{ summary.completion_percentage }}%</p>
                                    <p class="text-[0.68rem] font-semibold text-slate-500">{{ summary.ready_sections }} of {{ summary.total_sections }} ready</p>
                                </div>
                                <div class="h-1.5 w-44 overflow-hidden bg-slate-200">
                                    <div class="h-full bg-amber-500 transition-all" :style="{ width: `${summary.completion_percentage}%` }"></div>
                                </div>
                            </div>
                        </header>

                        <div class="divide-y divide-slate-200">
                            <article v-for="section in sections" :key="section.key" class="grid gap-4 px-5 py-4 lg:grid-cols-[2.75rem_minmax(15rem,1fr)_minmax(18rem,1.15fr)_9rem] lg:items-center">
                                <span :class="['grid h-9 w-9 place-items-center', section.is_ready ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800']">
                                    <i :class="['fa-solid', section.icon]"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-sm font-bold text-slate-950">{{ section.title }}</h3>
                                        <span :class="['px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', section.is_ready ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900']">{{ section.status_label }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ section.description }}</p>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ section.complete }} of {{ section.total }} details complete</p>
                                    <p v-if="section.missing.length" class="mt-1 text-xs text-slate-500">Still needed: {{ section.missing.slice(0, 3).join(', ') }}<span v-if="section.missing.length > 3"> and {{ section.missing.length - 3 }} more</span></p>
                                    <p v-else class="mt-1 text-xs text-slate-500">Required details complete.</p>
                                </div>
                                <a :href="section.action_url" class="border border-slate-300 bg-white px-3.5 py-2.5 text-center text-xs font-bold text-slate-800 transition hover:border-slate-900 hover:bg-slate-50">
                                    {{ section.action_label }}
                                </a>
                            </article>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="grid items-center gap-4 lg:grid-cols-[minmax(14rem,.8fr)_minmax(0,1.5fr)_9rem]">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-white text-amber-700"><i class="fa-solid fa-file-shield"></i></span>
                                    <div>
                                        <p class="text-sm font-bold text-slate-900">Verification evidence</p>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ summary.document_count }} document{{ Number(summary.document_count) === 1 ? '' : 's' }} submitted</p>
                                    </div>
                                </div>
                                <div v-if="recentDocuments.length" class="flex min-w-0 items-center gap-5">
                                    <a v-for="document in recentDocuments.slice(0, 2)" :key="document.id" :href="document.view_url" target="_blank" rel="noopener" class="min-w-0 text-xs font-bold text-slate-700 hover:text-slate-950">
                                        <span class="block max-w-52 truncate"><i class="fa-regular fa-file-lines mr-1.5 text-amber-700"></i>{{ document.original_name }}</span>
                                        <span :class="['mt-0.5 block font-semibold', documentStatusClass(document.status)]">{{ documentStatusLabel(document.status) }}</span>
                                    </a>
                                    <span v-if="recentDocuments.length > 2" class="shrink-0 text-xs font-semibold text-slate-500">+{{ recentDocuments.length - 2 }} more</span>
                                </div>
                                <p v-else class="text-xs font-semibold text-slate-500">No verification document submitted.</p>
                                <a href="/provider/profile/verification" class="border border-slate-300 bg-white px-3.5 py-2.5 text-center text-xs font-bold text-slate-800 transition hover:border-slate-900">Manage proof</a>
                            </div>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
