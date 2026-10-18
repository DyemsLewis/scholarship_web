<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const workspace = ref(null);
const profile = ref(null);
const summary = ref({ completion_percentage: 0, ready_sections: 0, total_sections: 0, document_count: 0 });
const nextTask = ref(null);
const sections = ref([]);

const organizationInitials = computed(() => String(profile.value?.name ?? 'Organization')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join(''));

const profilePages = [
    { label: 'Readiness', href: '/provider/workspaces/organization-profile/readiness', icon: 'fa-list-check', active: true },
    { label: 'Public details', href: '/provider/profile/details', icon: 'fa-building', active: false },
    { label: 'Verification', href: '/provider/profile/verification', icon: 'fa-file-shield', active: false },
    { label: 'Representative', href: '/provider/profile/representative', icon: 'fa-id-card', active: false },
];

function verificationClass(status) {
    return {
        approved: 'bg-emerald-100 text-emerald-800',
        rejected: 'bg-rose-100 text-rose-800',
        pending: 'bg-amber-100 text-amber-900',
        unsubmitted: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-slate-200 text-slate-700';
}

function verificationIcon(status) {
    return {
        approved: 'fa-solid fa-circle-check',
        rejected: 'fa-solid fa-circle-xmark',
        pending: 'fa-solid fa-clock',
        unsubmitted: 'fa-solid fa-circle-minus',
    }[status] ?? 'fa-solid fa-circle-info';
}

function readinessClass(isReady) {
    return isReady ? 'text-emerald-700' : 'text-amber-700';
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading profile readiness" message="Checking organization details and verification proof." />
                <ProviderWorkspaceState v-else-if="errorMessage" tone="error" title="Organization profile is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="profile" :show-role-guide="false" title="Profile readiness" description="Check what must be complete before applicants rely on this profile." icon="fa-solid fa-building-shield">
                        <template #leading>
                            <img v-if="profile.logo_url" :src="profile.logo_url" :alt="profile.name" class="h-11 w-11 shrink-0 border border-slate-200 bg-white object-contain p-1">
                            <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-slate-950 text-sm font-black text-white">{{ organizationInitials }}</span>
                        </template>
                        <template #actions>
                            <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[0.65rem] font-black uppercase tracking-wide', verificationClass(profile.verification_status)]"><i :class="verificationIcon(profile.verification_status)" aria-hidden="true"></i>{{ profile.verification_status_label }}</span>
                        </template>
                    </ProviderPageHeader>

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Organization profile pages">
                        <a v-for="page in profilePages" :key="page.label" :href="page.href" :aria-current="page.active ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', page.active ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', page.icon, page.active ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="text-sm font-bold">{{ page.label }}</span>
                        </a>
                    </nav>

                    <section v-if="nextTask" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Next profile task</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-0.5 line-clamp-1 text-sm text-slate-500">{{ nextTask.detail }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-950">Profile requirements</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ profile.name }} / {{ profile.type }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-black text-slate-950">{{ summary.completion_percentage }}%</p>
                                <p class="text-xs text-slate-500">{{ summary.ready_sections }} of {{ summary.total_sections }} areas ready</p>
                            </div>
                        </header>

                        <div class="portal-table-scroll">
                            <table class="portal-data-table min-w-[68rem] table-fixed">
                                <caption class="sr-only">Organization profile readiness requirements</caption>
                                <colgroup><col class="w-[31%]"><col class="w-[19%]"><col class="w-[34%]"><col class="w-[16%]"></colgroup>
                                <thead><tr><th scope="col">Profile area</th><th scope="col">Readiness</th><th scope="col">Still needed</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="section in sections" :key="section.key">
                                        <td><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-100 text-slate-500"><i :class="['fa-solid', section.icon]" aria-hidden="true"></i></span><div><p class="font-bold text-slate-950">{{ section.title }}</p><p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ section.description }}</p></div></div></td>
                                        <td><p :class="['font-semibold', readinessClass(section.is_ready)]"><i :class="['fa-solid mr-1.5', section.is_ready ? 'fa-circle-check' : 'fa-circle-exclamation']" aria-hidden="true"></i>{{ section.status_label }}</p><p class="mt-1 text-xs text-slate-500">{{ section.complete }} of {{ section.total }} complete</p></td>
                                        <td><p v-if="section.missing.length" class="line-clamp-2 text-sm text-slate-700">{{ section.missing.join(', ') }}</p><p v-else class="text-sm text-slate-500">Nothing missing</p></td>
                                        <td><a :href="section.action_url" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ section.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500">
                            <span>{{ summary.document_count }} verification document{{ Number(summary.document_count) === 1 ? '' : 's' }} on record</span>
                            <span>Last updated {{ profile.updated_at || 'not recorded' }}</span>
                        </footer>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
