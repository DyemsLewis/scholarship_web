<script setup>
import { onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const workspace = ref(null);
const profile = ref(null);
const summary = ref({ completion_percentage: 0, ready_sections: 0, total_sections: 0, document_count: 0 });
const nextTask = ref(null);
const sections = ref([]);
const documents = ref([]);

function verificationClass(status) {
    if (status === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (status === 'rejected') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (status === 'pending') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
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
        documents.value = response.data.recent_documents ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The organization profile could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="profile-readiness-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-building-circle-check" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Organization profile</p><h1 id="profile-readiness-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Profile readiness</h1><p class="mt-1 text-sm leading-6 text-slate-600">Keep the public profile accurate and the organization proof ready for review.</p></div></div>
                        <span v-if="profile" :class="['inline-flex border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', verificationClass(profile.verification_status)]">
                            {{ profile.verification_status_label }}
                        </span>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-else-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Checking profile readiness...</p></div>

                <template v-else>
                    <section v-if="nextTask" class="mt-4 border border-amber-300 border-l-4 border-l-amber-500 bg-amber-50 px-5 py-4 shadow-sm" aria-labelledby="next-profile-task">
                        <div class="flex items-center gap-4">
                            <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-200 text-slate-950"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Next profile task</p>
                                <h2 id="next-profile-task" class="mt-1 text-base font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ nextTask.detail }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right text-[9px] text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="organization-record-title">
                        <header class="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
                            <img v-if="profile.logo_url" :src="profile.logo_url" :alt="profile.name" class="h-14 w-14 border border-slate-200 bg-white object-contain p-1.5">
                            <span v-else class="grid h-14 w-14 place-items-center border border-slate-200 bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-building-columns text-lg"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Organization record</p>
                                <h2 id="organization-record-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ profile.name }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ profile.type }}<span v-if="profile.updated_at"> · Updated {{ profile.updated_at }}</span></p>
                            </div>
                            <div class="border-l border-slate-200 pl-6 text-right">
                                <p class="text-2xl font-black text-slate-950">{{ summary.completion_percentage }}%</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">{{ summary.ready_sections }} of {{ summary.total_sections }} areas ready</p>
                            </div>
                        </header>

                        <div class="staff-table-viewport">
                            <table class="w-full min-w-[70rem] table-fixed border-collapse text-left">
                                <caption class="sr-only">Organization profile readiness by area</caption>
                                <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><th class="w-[30%] px-5 py-3">Profile area</th><th class="w-[18%] px-5 py-3">Status</th><th class="w-[36%] px-5 py-3">Still needed</th><th class="w-[16%] px-5 py-3 text-right">Action</th></tr></thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="section in sections" :key="section.key" class="align-middle hover:bg-slate-50/70">
                                        <td class="px-5 py-4"><div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-500"><i :class="['fa-solid', section.icon]" aria-hidden="true"></i></span><div><p class="text-sm font-bold text-slate-950">{{ section.title }}</p><p class="mt-1 line-clamp-1 text-xs text-slate-500">{{ section.description }}</p></div></div></td>
                                        <td class="px-5 py-4"><p :class="['text-sm font-bold', section.is_ready ? 'text-emerald-700' : 'text-amber-700']">{{ section.status_label }}</p><p class="mt-1 text-xs text-slate-500">{{ section.complete }} of {{ section.total }} complete</p></td>
                                        <td class="px-5 py-4"><p class="text-sm text-slate-700">{{ section.missing.length ? section.missing.join(', ') : 'No missing information' }}</p></td>
                                        <td class="px-5 py-4 text-right"><a :href="section.action_url" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">Open<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-500">
                            <span>{{ summary.document_count }} proof file{{ Number(summary.document_count) === 1 ? '' : 's' }} on record</span>
                            <a href="/provider/workspaces/organization-profile/verification" class="font-bold text-slate-800 hover:text-slate-950">Legal verification <i class="fa-solid fa-arrow-right ml-1 text-[9px]" aria-hidden="true"></i></a>
                        </footer>
                    </section>

                    <section v-if="documents.length" class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-proof-title">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-5 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><h2 id="recent-proof-title" class="text-base font-bold text-slate-950">Recent verification proof</h2></header>
                        <div class="divide-y divide-slate-200">
                            <div v-for="document in documents" :key="document.id" class="flex items-center gap-4 px-5 py-3.5">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-900">{{ document.original_name }}</p><p class="mt-1 text-xs text-slate-500">Uploaded {{ document.uploaded_at }}</p></div>
                                <a :href="document.view_url" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 hover:border-slate-950 hover:text-slate-950" aria-label="Open proof"><i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></a>
                            </div>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
