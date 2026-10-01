<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import ReviewOfficerSidebar from '../components/ReviewOfficerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const usesReviewOfficerWorkspace = window.location.pathname.startsWith('/admin/workspaces/reviews');
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const reviewQueueUrl = usesPortalManagerWorkspace
    ? '/admin/workspaces/portal/reviews?type=monitoring'
    : (usesReviewOfficerWorkspace ? '/admin/workspaces/reviews?type=monitoring' : '/admin/reviews?type=monitoring');
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const reviewError = ref('');
const record = ref(null);
const showReviewModal = ref(false);
const requestedSection = new URLSearchParams(window.location.search).get('section');
const sections = [
    { key: 'overview', label: 'Current review', icon: 'fa-solid fa-list-check' },
    { key: 'activity', label: 'Activity record', icon: 'fa-solid fa-clock-rotate-left' },
    { key: 'oversight', label: 'Admin history', icon: 'fa-solid fa-shield-halved' },
];
const activeSection = ref(sections.some((section) => section.key === requestedSection) ? requestedSection : 'overview');
const reviewForm = ref(defaultReviewForm());
const canExportData = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('export_data'),
));

const confirmedPercent = computed(() => {
    const total = Number(record.value?.requirements_total ?? 0);
    if (!total) return 0;
    return Math.round((Number(record.value?.requirements_confirmed ?? 0) / total) * 100);
});

function defaultReviewForm() {
    return { outcome: 'reviewed', notes: '' };
}

function selectSection(section) {
    activeSection.value = section;
    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState(window.history.state, '', url);
}

function statusClass(status) {
    if (['stable', 'reviewed', 'confirmed', 'released'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (['attention', 'overdue', 'needs_replacement', 'not_met', 'escalated'].includes(status)) return 'bg-amber-100 text-amber-900';
    if (['terminated', 'closed'].includes(status)) return 'bg-slate-200 text-slate-700';
    return 'bg-sky-100 text-sky-800';
}

function activityIcon(type) {
    return {
        requirement: 'fa-solid fa-file-circle-check',
        adjustment: 'fa-solid fa-calendar-plus',
        follow_up: 'fa-solid fa-handshake-angle',
        benefit: 'fa-solid fa-gift',
        outcome: 'fa-solid fa-gavel',
    }[type] ?? 'fa-solid fa-circle-check';
}

function openReviewModal() {
    reviewForm.value = defaultReviewForm();
    reviewError.value = '';
    showReviewModal.value = true;
}

function closeReviewModal() {
    if (isSaving.value) return;
    showReviewModal.value = false;
}

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/monitoring/${applicationId}/data`);
        record.value = response.data.record;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this monitoring record.';
    } finally {
        isLoading.value = false;
    }
}

async function submitReview() {
    if (isSaving.value) return;
    isSaving.value = true;
    reviewError.value = '';
    try {
        const response = await window.axios.post(
            `/admin/monitoring/${applicationId}/oversight-reviews`,
            reviewForm.value,
        );
        record.value = response.data.record;
        showReviewModal.value = false;
        selectSection('oversight');
        showPortalToast({ type: 'success', title: 'Oversight recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        reviewError.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to record this review.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadRecord);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <ReviewOfficerSidebar v-else-if="usesReviewOfficerWorkspace" />
        <AdminSidebar v-else active="reviews" />

        <section class="admin-page">
            <div class="admin-container">
                <a :href="reviewQueueUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to monitoring oversight
                </a>

                <TaskPageHeader
                    theme="admin"
                    eyebrow="Monitoring oversight"
                    title="Recipient monitoring record"
                    :description="record ? `${record.program_title} · ${record.provider_name}` : 'Reviewing recipient monitoring activity.'"
                    icon="fa-solid fa-chart-line"
                >
                    <template v-if="record" #meta>
                        <span>{{ record.oversight_label }}</span>
                        <span>{{ record.last_activity_at || 'No recorded activity' }}</span>
                    </template>
                    <template #actions>
                        <a v-if="record && canExportData" :href="`/admin/export/monitoring?application_id=${record.application_id}`" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i class="fa-solid fa-download mr-1.5 text-xs" aria-hidden="true"></i>Export record
                        </a>
                        <button type="button" :disabled="!record" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="openReviewModal">
                            Record review
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading monitoring record...</div>
                <div v-else-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

                <div v-else-if="record" class="admin-content-stack">
                    <section class="admin-panel flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 font-bold text-white">
                            {{ String(record.applicant_name || 'R').charAt(0).toUpperCase() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Recipient</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950">{{ record.applicant_name }}</h2>
                            <p class="mt-0.5 text-sm text-slate-500">{{ record.applicant_email }}</p>
                        </div>
                        <span :class="['self-start rounded-md px-2.5 py-1.5 text-xs font-bold sm:self-center', statusClass(record.oversight_status)]">{{ record.oversight_label }}</span>
                    </section>

                    <nav class="admin-panel grid gap-1 p-1 sm:grid-cols-3" aria-label="Monitoring review sections">
                        <button v-for="section in sections" :key="section.key" type="button" :class="['flex items-center justify-center gap-2 rounded-md px-4 py-3 text-sm font-bold transition', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950']" @click="selectSection(section.key)">
                            <i :class="section.icon" aria-hidden="true"></i>{{ section.label }}
                        </button>
                    </nav>

                    <template v-if="activeSection === 'overview'">
                        <section class="admin-panel grid overflow-hidden sm:grid-cols-2 xl:grid-cols-4">
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-r xl:border-b-0"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Support</p><p class="mt-1 font-bold text-slate-950">{{ record.support_status_label }}</p></div>
                            <div class="border-b border-slate-200 px-5 py-4 xl:border-b-0 xl:border-r"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Requirements</p><p class="mt-1 font-bold text-slate-950">{{ record.requirements_confirmed }} of {{ record.requirements_total }} confirmed</p></div>
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0 sm:border-r"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Benefit releases</p><p class="mt-1 font-bold text-slate-950">{{ record.released_total }} of {{ record.release_total }} released</p></div>
                            <div class="px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Attention items</p><p class="mt-1 font-bold text-slate-950">{{ record.attention_items.length }}</p></div>
                        </section>

                        <section v-if="record.attention_items.length" class="admin-panel overflow-hidden">
                            <header class="border-b border-slate-200 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Admin check</p><h3 class="mt-1 text-lg font-bold text-slate-950">Items needing attention</h3></header>
                            <div class="divide-y divide-slate-200">
                                <div v-for="(item, index) in record.attention_items" :key="`${item.source}-${index}`" class="flex gap-3 px-5 py-4">
                                    <i class="fa-solid fa-triangle-exclamation mt-1 text-sm text-amber-600" aria-hidden="true"></i>
                                    <div><p class="text-sm font-bold text-slate-950">{{ item.title }}</p><p class="mt-1 text-sm leading-5 text-slate-600">{{ item.detail }}</p></div>
                                </div>
                            </div>
                        </section>

                        <section class="admin-panel overflow-hidden">
                            <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Requirements</p><h3 class="mt-1 text-lg font-bold text-slate-950">Current compliance</h3></div><span class="text-sm font-bold text-slate-700">{{ confirmedPercent }}%</span></header>
                            <div v-if="record.requirements.length" class="divide-y divide-slate-200">
                                <div v-for="requirement in record.requirements" :key="requirement.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                                    <div class="min-w-0 flex-1"><p class="text-sm font-bold text-slate-950">{{ requirement.title }}</p><p class="mt-1 text-xs text-slate-500">{{ requirement.cycle_title }} · Due {{ requirement.due_on || 'not set' }}</p></div>
                                    <span :class="['self-start rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:self-center', statusClass(requirement.status)]">{{ requirement.status_label }}</span>
                                    <a v-if="requirement.file" :href="requirement.file.view_url" target="_blank" rel="noopener noreferrer" class="text-xs font-bold text-slate-700 underline decoration-slate-300 underline-offset-4">View evidence</a>
                                </div>
                            </div>
                            <p v-else class="px-5 py-8 text-center text-sm text-slate-500">No monitoring requirements have been published.</p>
                        </section>
                    </template>

                    <section v-else-if="activeSection === 'activity'" class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Evidence trail</p><h3 class="mt-1 text-lg font-bold text-slate-950">Monitoring activity</h3></header>
                        <div v-if="record.timeline.length" class="divide-y divide-slate-200">
                            <article v-for="item in record.timeline" :key="item.id" class="flex gap-4 px-5 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600"><i :class="activityIcon(item.type)" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2"><h4 class="text-sm font-bold text-slate-950">{{ item.title }}</h4><span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{{ item.status_label }}</span></div>
                                    <p v-if="item.detail" class="mt-1 text-sm leading-5 text-slate-600">{{ item.detail }}</p>
                                    <div v-if="item.files.length" class="mt-2 flex flex-wrap gap-2"><a v-for="file in item.files" :key="file.view_url" :href="file.view_url" target="_blank" rel="noopener noreferrer" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-paperclip mr-1.5" aria-hidden="true"></i>{{ file.name || 'View evidence' }}</a></div>
                                </div>
                                <time class="shrink-0 text-xs text-slate-500">{{ item.occurred_at || 'Date unavailable' }}</time>
                            </article>
                        </div>
                        <p v-else class="px-5 py-8 text-center text-sm text-slate-500">No monitoring activity has been recorded.</p>
                    </section>

                    <section v-else class="admin-panel overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Admin audit</p><h3 class="mt-1 text-lg font-bold text-slate-950">Oversight history</h3></div><button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="openReviewModal">Record review</button></header>
                        <div v-if="record.oversight_history.length" class="divide-y divide-slate-200">
                            <article v-for="review in record.oversight_history" :key="review.id" class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-2"><p class="text-sm font-bold text-slate-950">{{ review.outcome_label }}</p><span class="text-xs text-slate-500">{{ review.reviewed_at }}</span></div>
                                <p v-if="review.notes" class="mt-2 text-sm leading-5 text-slate-600">{{ review.notes }}</p>
                                <p class="mt-2 text-xs text-slate-500">By {{ review.reviewed_by || 'Administrator' }} · {{ review.flags_count }} issue{{ review.flags_count === 1 ? '' : 's' }} captured</p>
                            </article>
                        </div>
                        <p v-else class="px-5 py-8 text-center text-sm text-slate-500">No admin review has been recorded yet.</p>
                    </section>
                </div>
            </div>
        </section>

        <Teleport to="body">
            <div v-if="showReviewModal" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeReviewModal" @keydown.esc="closeReviewModal">
                <section class="w-full max-w-xl overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="oversight-review-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Admin oversight</p><h2 id="oversight-review-title" class="mt-1 text-xl font-bold">Record review outcome</h2></div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close" @click="closeReviewModal"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form @submit.prevent="submitReview">
                        <div class="space-y-4 px-5 py-5">
                            <p v-if="reviewError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ reviewError }}</p>
                            <fieldset><legend class="text-xs font-bold text-slate-700">Outcome</legend><div class="mt-2 space-y-2">
                                <label v-for="option in [{ value: 'reviewed', label: 'Reviewed', help: 'No admin action is currently required.' }, { value: 'follow_up_required', label: 'Provider follow-up required', help: 'Ask the provider to clarify or correct the record.' }, { value: 'escalated', label: 'Escalate concern', help: 'Record a serious issue for administrative follow-up.' }]" :key="option.value" :class="['flex cursor-pointer gap-3 rounded-md border p-3', reviewForm.outcome === option.value ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="reviewForm.outcome" type="radio" :value="option.value" class="mt-1"><span><strong class="block text-sm">{{ option.label }}</strong><span class="mt-0.5 block text-xs text-slate-500">{{ option.help }}</span></span></label>
                            </div></fieldset>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Review note <span v-if="reviewForm.outcome === 'reviewed'" class="font-normal text-slate-400">(optional)</span></span><textarea v-model="reviewForm.notes" rows="4" maxlength="2000" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-950 outline-none focus:border-slate-600" :placeholder="reviewForm.outcome === 'reviewed' ? 'Optional audit note' : 'Explain what the provider or admin should address.'"></textarea></label>
                        </div>
                        <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" :disabled="isSaving" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReviewModal">Cancel</button><button type="submit" :disabled="isSaving" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSaving ? 'Saving...' : 'Save review' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>
    </main>
</template>
