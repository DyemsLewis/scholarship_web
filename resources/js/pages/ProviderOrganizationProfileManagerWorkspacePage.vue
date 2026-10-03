<script setup>
import { computed, onMounted, ref } from 'vue';
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

function taskClass(state) {
    return {
        attention: 'border-rose-300 bg-rose-50 text-rose-900',
        waiting: 'border-sky-300 bg-sky-50 text-sky-900',
        improve: 'border-amber-300 bg-amber-50 text-amber-950',
        complete: 'border-orange-300 bg-orange-50 text-orange-950',
    }[state] ?? 'border-slate-300 bg-white text-slate-900';
}

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
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-orange-600 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <img v-if="profile.logo_url" :src="profile.logo_url" :alt="profile.name" class="h-12 w-12 shrink-0 rounded-md border border-slate-200 bg-white object-contain p-1">
                                <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-black text-orange-300">{{ organizationInitials }}</span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-orange-700">Organization profile manager</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Organization profile</h1>
                                    <p class="mt-1 text-sm text-slate-600">Keep applicant-facing details and verification proof accurate.</p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3 text-left lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0 lg:text-right">
                                <div class="flex items-center gap-2 lg:justify-end">
                                    <span :class="['rounded px-2.5 py-1 text-[0.65rem] font-black uppercase tracking-wide', verificationClass(profile.verification_status)]">{{ profile.verification_status_label }}</span>
                                </div>
                                <p class="mt-2 text-xs font-bold text-slate-900">{{ profile.name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ profile.type }}</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextTask" :class="['mt-4 overflow-hidden rounded-lg border shadow-sm', taskClass(nextTask.state)]">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(15rem,.7fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-4 text-white lg:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-orange-300">Next task</p>
                                    <i :class="['mt-2 text-lg fa-solid', taskIcon(nextTask.state)]"></i>
                                </div>
                            </div>
                            <div class="min-w-0 border-b border-current/10 px-5 py-4 lg:border-b-0 lg:border-r">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-bold">{{ nextTask.title }}</h2>
                                    <span class="rounded bg-white/70 px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide">{{ nextTask.label }}</span>
                                </div>
                                <p class="mt-1 text-sm opacity-80">{{ workspace.organization_name }}</p>
                            </div>
                            <div class="flex items-center border-b border-current/10 px-5 py-4 lg:border-b-0 lg:border-r">
                                <p class="text-sm font-semibold leading-6">{{ nextTask.detail }}</p>
                            </div>
                            <div class="flex items-center px-5 py-4">
                                <a :href="nextTask.action_url" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-black text-white transition hover:bg-slate-800 lg:w-auto">
                                    {{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-orange-300"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">Organization profile is current</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">No required profile or verification work is waiting.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-orange-700">Profile readiness</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">What applicants and administrators rely on</h2>
                                    <p class="mt-1 text-sm text-slate-500">Complete one area at a time; optional improvements are not publishing blockers.</p>
                                </div>
                                <div class="shrink-0 sm:text-right">
                                    <p class="text-2xl font-black text-slate-950">{{ summary.completion_percentage }}%</p>
                                    <p class="text-xs font-semibold text-slate-500">{{ summary.ready_sections }} of {{ summary.total_sections }} areas ready</p>
                                </div>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-orange-600 transition-all" :style="{ width: `${summary.completion_percentage}%` }"></div>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-200">
                            <article v-for="section in sections" :key="section.key" class="grid gap-4 px-5 py-5 sm:px-6 lg:grid-cols-[3.5rem_minmax(14rem,.9fr)_minmax(16rem,1.2fr)_9rem] lg:items-center">
                                <span :class="['grid h-11 w-11 place-items-center rounded-md', section.is_ready ? 'bg-emerald-100 text-emerald-700' : 'bg-orange-100 text-orange-800']">
                                    <i :class="['fa-solid', section.icon]"></i>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-sm font-bold text-slate-950">{{ section.title }}</h3>
                                        <span :class="['rounded px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', section.is_ready ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">{{ section.status_label }}</span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ section.description }}</p>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ section.complete }} of {{ section.total }} details complete</p>
                                    <p v-if="section.missing.length" class="mt-1 text-xs text-slate-500">Still needed: {{ section.missing.slice(0, 3).join(', ') }}<span v-if="section.missing.length > 3"> and {{ section.missing.length - 3 }} more</span></p>
                                    <p v-else class="mt-1 text-xs text-emerald-700">No required detail is missing.</p>
                                </div>
                                <a :href="section.action_url" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-center text-xs font-bold text-slate-800 transition hover:border-slate-900 hover:bg-slate-50">
                                    {{ section.action_label }}
                                </a>
                            </article>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-bold text-slate-900">Verification evidence</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ summary.document_count }} document{{ Number(summary.document_count) === 1 ? '' : 's' }} submitted</p>
                                </div>
                                <div v-if="recentDocuments.length" class="flex flex-wrap gap-x-5 gap-y-2">
                                    <a v-for="document in recentDocuments" :key="document.id" :href="document.view_url" target="_blank" rel="noopener" class="text-xs font-bold text-slate-700 hover:text-slate-950">
                                        <i class="fa-regular fa-file-lines mr-1.5 text-orange-700"></i>{{ document.original_name }}
                                        <span :class="['ml-1 font-semibold', documentStatusClass(document.status)]">{{ documentStatusLabel(document.status) }}</span>
                                    </a>
                                </div>
                                <a v-else href="/provider/profile/verification" class="text-xs font-bold text-orange-800 hover:text-orange-950">Upload verification proof <i class="fa-solid fa-arrow-right ml-1"></i></a>
                            </div>
                        </div>
                    </section>

                    <p class="mt-4 border-l-2 border-orange-600 px-4 py-2 text-sm text-slate-600">
                        This workspace maintains organization information and verification evidence. Team access and individual staff accounts remain with the Team Administrator.
                    </p>
                </template>
            </div>
        </section>
    </main>
</template>
