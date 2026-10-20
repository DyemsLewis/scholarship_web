<script setup>
import { onMounted, ref } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const sessionId = window.location.pathname.match(/selection-activities\/(\d+)/)?.[1];
const loading = ref(true);
const errorMessage = ref('');
const session = ref(null);
const sections = [
    { key: 'summary', label: 'Summary', icon: 'fa-solid fa-calendar-check' },
    { key: 'applicants', label: 'Applicants', icon: 'fa-solid fa-users' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
];
const requestedSection = window.location.hash.replace('#', '');
const activeSection = ref(sections.some((section) => section.key === requestedSection) ? requestedSection : 'summary');

function chooseSection(section) {
    if (!sections.some((item) => item.key === section)) return;
    activeSection.value = section;
    window.history.replaceState({}, '', `${window.location.pathname}#${section}`);
}

function statusClass(value) {
    if (value === 'attention' || value === 'pending') return 'bg-amber-100 text-amber-900';
    if (['completed', 'present', 'acknowledged'].includes(value)) return 'bg-emerald-100 text-emerald-800';
    return 'bg-slate-100 text-slate-700';
}

async function loadSession() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/workspaces/reviews/selection-activities/${sessionId}/data`);
        session.value = response.data.session;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The selection activity could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadSession);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer">
        <a href="/admin/workspaces/reviews/selection-activities" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Selection activities
        </a>

        <AdminWorkspaceHeader
            eyebrow="Application oversight"
            :title="session?.title || 'Selection activity'"
            :description="session ? `${session.program} · ${session.provider}` : 'Review session readiness and recorded evidence.'"
            icon="fa-solid fa-calendar-check"
        >
            <template v-if="session" #actions>
                <span :class="['inline-flex px-3 py-2 text-xs font-bold uppercase tracking-wide', statusClass(session.oversight_status)]">
                    {{ session.attention_message ? 'Needs attention' : session.status_label }}
                </span>
            </template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading selection activity...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <template v-else-if="session">
            <section class="admin-panel mt-4 overflow-hidden">
                <dl class="grid grid-cols-4 divide-x divide-slate-200">
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Stage</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.stage_label }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Schedule</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.starts_at }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Applicants</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.counts.participants }} assigned</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Proof</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.counts.proof }} file{{ session.counts.proof === 1 ? '' : 's' }}</dd></div>
                </dl>
            </section>

            <nav class="admin-panel mt-4 grid grid-cols-3 overflow-hidden" aria-label="Selection activity sections">
                <button
                    v-for="section in sections"
                    :key="section.key"
                    type="button"
                    :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50']"
                    @click="chooseSection(section.key)"
                >
                    <i :class="section.icon" aria-hidden="true"></i>{{ section.label }}
                </button>
            </nav>

            <template v-if="activeSection === 'summary'">
                <p v-if="session.attention_message" class="mt-4 border-l-4 border-amber-500 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-950">
                    {{ session.attention_message }}
                </p>

                <section class="admin-panel mt-4 overflow-hidden">
                    <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Session details</h2><p class="mt-0.5 text-sm text-slate-500">Provider-recorded schedule and readiness.</p></div></header>
                    <dl class="grid grid-cols-4 border-b border-slate-200">
                        <div class="px-6 py-4"><dt class="text-xs font-semibold text-slate-500">Mode</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.mode }}</dd></div>
                        <div class="px-6 py-4"><dt class="text-xs font-semibold text-slate-500">Ends</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.ends_at || 'Not specified' }}</dd></div>
                        <div class="px-6 py-4"><dt class="text-xs font-semibold text-slate-500">Attendance pending</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.counts.pending_attendance }}</dd></div>
                        <div class="px-6 py-4"><dt class="text-xs font-semibold text-slate-500">Proof rule</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ session.proof_required ? 'Required' : 'Optional' }}</dd></div>
                    </dl>
                    <div v-if="session.venue || session.location_address || session.instructions" class="px-6 py-4 text-sm text-slate-700">
                        <p v-if="session.venue || session.location_address"><strong class="text-slate-950">Location:</strong> {{ [session.venue, session.location_address].filter(Boolean).join(' · ') }}</p>
                        <p v-if="session.instructions" class="mt-2"><strong class="text-slate-950">Instructions:</strong> {{ session.instructions }}</p>
                    </div>
                </section>

                <section class="admin-panel mt-4 min-h-[18rem] overflow-hidden">
                    <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Recorded proof</h2><p class="mt-0.5 text-sm text-slate-500">Current files only.</p></div></div>
                        <span class="text-sm font-bold text-slate-600">{{ session.evidence.length }} files</span>
                    </header>
                    <div v-if="session.evidence.length" class="staff-table-viewport"><table class="portal-data-table min-w-[56rem]">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">File</th><th class="border-b border-slate-200 px-4 py-3">Scope</th><th class="border-b border-slate-200 px-4 py-3">Recorded</th><th class="border-b border-slate-200 px-5 py-3 text-right">Preview</th></tr></thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="file in session.evidence" :key="file.id">
                                <td class="px-5 py-4"><strong class="block max-w-sm truncate text-sm text-slate-950" data-file-name>{{ file.name }}</strong><span class="mt-0.5 block text-xs text-slate-500">{{ file.category }} · {{ file.size }}</span></td>
                                <td class="px-4 py-4 text-sm font-semibold text-slate-700">{{ file.scope_label }}</td>
                                <td class="px-4 py-4"><p class="text-sm text-slate-700">{{ file.uploaded_at }}</p><p class="mt-0.5 text-xs text-slate-500">{{ file.uploaded_by || 'Provider team' }}</p></td>
                                <td class="px-5 py-4 text-right"><a :href="file.view_url" data-file-preview="true" :data-file-name="file.name" class="inline-grid h-9 w-9 place-items-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" title="Preview proof" aria-label="Preview proof"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a></td>
                            </tr>
                        </tbody>
                    </table></div>
                    <AdminEmptyState v-else title="No proof recorded" :description="session.proof_required ? 'The provider still needs to add required session proof.' : 'This session does not currently include a proof file.'" icon="fa-solid fa-file-shield" />
                </section>
            </template>

            <section v-else-if="activeSection === 'applicants'" class="admin-panel mt-4 min-h-[24rem] overflow-hidden">
                <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-users" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Assigned applicants</h2><p class="mt-0.5 text-sm text-slate-500">Acknowledgment and attendance only.</p></div></header>
                <div v-if="session.participants.length" class="staff-table-viewport"><table class="portal-data-table min-w-[60rem]">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Applicant</th><th class="border-b border-slate-200 px-4 py-3">Assignment</th><th class="border-b border-slate-200 px-4 py-3">Attendance</th><th class="border-b border-slate-200 px-5 py-3">Proof</th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="applicant in session.participants" :key="applicant.assignment_id">
                            <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-user" aria-hidden="true"></i><img v-if="applicant.photo_url" :src="applicant.photo_url" :alt="`${applicant.name} profile`" class="absolute inset-0 h-full w-full object-cover" @error="$event.currentTarget.remove()"></span><strong class="text-sm text-slate-950">{{ applicant.name }}</strong></div></td>
                            <td class="px-4 py-4"><span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(applicant.assignment_status)]">{{ applicant.assignment_status_label }}</span><p class="mt-1 text-xs text-slate-500">{{ applicant.acknowledged_at ? `Confirmed ${applicant.acknowledged_at}` : 'Not yet confirmed' }}</p></td>
                            <td class="px-4 py-4"><span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(applicant.attendance_status)]">{{ applicant.attendance_label }}</span><p v-if="applicant.attendance_recorded_at" class="mt-1 text-xs text-slate-500">{{ applicant.attendance_recorded_at }}</p></td>
                            <td class="px-5 py-4 text-sm font-semibold text-slate-700">{{ applicant.proof_count }} file{{ applicant.proof_count === 1 ? '' : 's' }}</td>
                        </tr>
                    </tbody>
                </table></div>
                <AdminEmptyState v-else title="No assigned applicants" description="The provider has not assigned an applicant to this session." icon="fa-solid fa-users" />
            </section>

            <section v-else class="admin-panel mt-4 min-h-[24rem] overflow-hidden">
                <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Activity history</h2><p class="mt-0.5 text-sm text-slate-500">Recorded changes from the provider workflow.</p></div></header>
                <div v-if="session.history.length" class="divide-y divide-slate-200">
                    <article v-for="item in session.history" :key="item.id" class="grid grid-cols-[11rem_minmax(0,1fr)_12rem] items-center gap-5 px-6 py-4">
                        <p class="text-xs font-semibold text-slate-500">{{ item.occurred_at }}</p>
                        <div><h3 class="text-sm font-bold text-slate-950">{{ item.event }}</h3><p v-if="item.status_change" class="mt-0.5 text-xs text-slate-500">{{ item.status_change }}</p></div>
                        <p class="text-right text-xs font-semibold text-slate-600">{{ item.actor }}</p>
                    </article>
                </div>
                <AdminEmptyState v-else title="No history recorded" description="Activity will appear after the provider changes this session." icon="fa-solid fa-clock-rotate-left" />
            </section>
        </template>
    </AdminWorkspacePage>
</template>
