<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const isUpdatingMode = ref(false);
const errorMessage = ref('');
const modeError = ref('');
const organization = ref(null);
const representative = ref(null);
const operatingMode = ref('governance');
const coverage = ref([]);
const summary = ref({});
const nextAction = ref(null);
const recentActivity = ref([]);
const showModeModal = ref(false);
const targetMode = ref('solo_operator');
const currentPassword = ref('');
const responsibilityPage = ref(1);
const activityPage = ref(1);
const pageSize = 5;
const allowedViews = ['overview', 'responsibilities', 'access', 'activity'];
const requestedView = new URL(window.location.href).searchParams.get('view');
const activeView = ref(allowedViews.includes(requestedView) ? requestedView : 'overview');

const isSoloOperator = computed(() => operatingMode.value === 'solo_operator');
const viewMeta = computed(() => ({
    overview: {
        title: 'Governance overview',
        description: 'Review ownership coverage, access health, and the next organization action.',
        icon: 'fa-solid fa-shield-halved',
    },
    responsibilities: {
        title: 'Responsibility coverage',
        description: 'See who is accountable for each provider workflow.',
        icon: 'fa-solid fa-list-check',
    },
    access: {
        title: 'Operating model',
        description: 'Review how staff permissions and operating access are assigned.',
        icon: 'fa-solid fa-key',
    },
    activity: {
        title: 'Governance activity',
        description: 'Review changes to access, organization records, and operating policies.',
        icon: 'fa-solid fa-clock-rotate-left',
    },
}[activeView.value]));
const coveragePercent = computed(() => {
    const total = Number(summary.value.coverage_total ?? 0);

    return total ? Math.round((Number(summary.value.coverage_count ?? 0) / total) * 100) : 0;
});
const accessReviewCount = computed(() => (
    Number(summary.value.broad_access_count ?? 0)
    + Number(summary.value.unverified_staff_count ?? 0)
));
const governanceNeedsAttention = computed(() => (
    Number(summary.value.gap_count ?? 0) > 0
    || accessReviewCount.value > 0
    || organization.value?.verification_status !== 'approved'
));
const controlRegister = computed(() => [
    {
        label: 'Workflow ownership',
        evidence: `${summary.value.coverage_count ?? 0} of ${summary.value.coverage_total ?? 0} responsibilities have an owner`,
        state: Number(summary.value.gap_count ?? 0) > 0 ? 'attention' : 'controlled',
    },
    {
        label: 'Staff identity',
        evidence: Number(summary.value.unverified_staff_count ?? 0) > 0
            ? `${summary.value.unverified_staff_count} active account${Number(summary.value.unverified_staff_count) === 1 ? '' : 's'} await email verification`
            : 'All active staff accounts are verified',
        state: Number(summary.value.unverified_staff_count ?? 0) > 0 ? 'attention' : 'controlled',
    },
    {
        label: 'Privilege scope',
        evidence: Number(summary.value.broad_access_count ?? 0) > 0
            ? `${summary.value.broad_access_count} account${Number(summary.value.broad_access_count) === 1 ? '' : 's'} span several workflows`
            : 'No broad operational access requires review',
        state: Number(summary.value.broad_access_count ?? 0) > 0 ? 'attention' : 'controlled',
    },
    {
        label: 'Organization verification',
        evidence: organization.value?.verification_status === 'approved'
            ? 'Organization record is approved'
            : `Organization record is ${organization.value?.verification_status_label?.toLowerCase() ?? 'pending'}`,
        state: organization.value?.verification_status === 'approved' ? 'controlled' : 'attention',
    },
]);
const responsibilityPageCount = computed(() => Math.max(1, Math.ceil(coverage.value.length / pageSize)));
const activityPageCount = computed(() => Math.max(1, Math.ceil(recentActivity.value.length / pageSize)));
const paginatedCoverage = computed(() => {
    const page = Math.min(Math.max(responsibilityPage.value, 1), responsibilityPageCount.value);
    const start = (page - 1) * pageSize;

    return coverage.value.slice(start, start + pageSize);
});
const paginatedActivity = computed(() => {
    const page = Math.min(Math.max(activityPage.value, 1), activityPageCount.value);
    const start = (page - 1) * pageSize;

    return recentActivity.value.slice(start, start + pageSize);
});
const responsibilityRange = computed(() => paginationRange(coverage.value.length, responsibilityPage.value));
const activityRange = computed(() => paginationRange(recentActivity.value.length, activityPage.value));
const verificationTone = computed(() => {
    if (organization.value?.verification_status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (organization.value?.verification_status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
});
const verificationIcon = computed(() => {
    if (organization.value?.verification_status === 'approved') {
        return 'fa-solid fa-circle-check';
    }

    if (organization.value?.verification_status === 'rejected') {
        return 'fa-solid fa-circle-xmark';
    }

    return 'fa-solid fa-hourglass-half';
});

const activityLabels = {
    provider_team_account_created: 'Team member added',
    provider_team_account_updated: 'Team access updated',
    provider_team_account_status_updated: 'Team status changed',
    provider_profile_updated: 'Organization record updated',
    provider_verification_document_uploaded: 'Verification file added',
    provider_verification_document_deleted: 'Verification file removed',
    provider_operating_mode_updated: 'Operating model changed',
};

function initials(name) {
    return String(name ?? 'Organization')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function activityLabel(action) {
    return activityLabels[action] ?? 'Governance record updated';
}

function paginationRange(total, requestedPage) {
    if (!total) {
        return { start: 0, end: 0 };
    }

    const pageCount = Math.max(1, Math.ceil(total / pageSize));
    const page = Math.min(Math.max(requestedPage, 1), pageCount);
    const start = ((page - 1) * pageSize) + 1;

    return { start, end: Math.min(start + pageSize - 1, total) };
}

function changeResponsibilityPage(requestedPage) {
    responsibilityPage.value = Math.min(Math.max(requestedPage, 1), responsibilityPageCount.value);
}

function changeActivityPage(requestedPage) {
    activityPage.value = Math.min(Math.max(requestedPage, 1), activityPageCount.value);
}

async function loadGovernance() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/governance/data');
        organization.value = response.data.organization;
        representative.value = response.data.representative;
        operatingMode.value = response.data.operating_mode;
        coverage.value = response.data.coverage ?? [];
        summary.value = response.data.summary ?? {};
        nextAction.value = response.data.next_action;
        recentActivity.value = response.data.recent_activity ?? [];
        responsibilityPage.value = 1;
        activityPage.value = 1;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the organization workspace.';
    } finally {
        isLoading.value = false;
    }
}

function openModeModal(mode) {
    targetMode.value = mode;
    currentPassword.value = '';
    modeError.value = '';
    showModeModal.value = true;
}

function closeModeModal() {
    if (isUpdatingMode.value) {
        return;
    }

    showModeModal.value = false;
    currentPassword.value = '';
    modeError.value = '';
}

async function updateOperatingMode() {
    modeError.value = '';

    if (!currentPassword.value) {
        modeError.value = 'Enter your current password to confirm this change.';
        return;
    }

    isUpdatingMode.value = true;

    try {
        await window.axios.patch('/provider/governance/operating-mode', {
            mode: targetMode.value,
            current_password: currentPassword.value,
        });
        window.location.href = '/provider';
    } catch (error) {
        modeError.value = error.response?.data?.errors?.current_password?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to change the operating model.';
    } finally {
        isUpdatingMode.value = false;
    }
}

onMounted(loadGovernance);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="border border-slate-300 bg-white px-5 py-6 text-sm text-slate-600 shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                    <i class="fa-solid fa-circle-notch mr-2 animate-spin text-slate-400" aria-hidden="true"></i>
                    Loading governance controls...
                </div>

                <div v-else-if="errorMessage" class="border border-rose-300 border-l-4 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="border border-slate-300 border-t-[3px] border-t-slate-950 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-5 py-2 text-[0.65rem] font-bold uppercase tracking-[0.16em] text-slate-500">
                            <span>Governance workspace</span>
                            <span class="flex items-center gap-1.5 tracking-[0.08em] text-slate-600">
                                <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                                Organization owner
                            </span>
                        </div>

                        <div class="flex flex-col gap-5 px-5 py-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center border border-slate-950 bg-slate-950 text-sm font-black tracking-wide text-white">
                                    {{ initials(organization?.name) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-slate-500">Organization owner · {{ isSoloOperator ? 'Solo operator' : 'Governance mode' }}</p>
                                    <h1 class="mt-1 flex items-center gap-2.5 text-2xl font-bold tracking-[-0.02em] text-slate-950">
                                        <i :class="[viewMeta.icon, 'text-base text-slate-500']" aria-hidden="true"></i>
                                        {{ viewMeta.title }}
                                    </h1>
                                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ viewMeta.description }}</p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                <span :class="['border px-3 py-2 text-xs font-black uppercase tracking-wide', verificationTone, organization?.verification_status === 'approved' ? 'border-emerald-200' : organization?.verification_status === 'rejected' ? 'border-rose-200' : 'border-amber-200']">
                                    <i :class="[verificationIcon, 'mr-1.5']" aria-hidden="true"></i>
                                    {{ organization?.verification_status_label }}
                                </span>
                                <a href="/provider/team/accounts/create" class="border border-slate-950 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                    <i class="fa-solid fa-user-plus mr-2 text-slate-300" aria-hidden="true"></i>Add team member
                                </a>
                            </div>
                        </div>
                        <dl class="grid border-t border-slate-200 bg-slate-50 text-xs text-slate-600 sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-slate-200">
                            <div class="px-5 py-3">
                                <dt class="font-bold uppercase tracking-[0.08em] text-slate-400"><i class="fa-solid fa-building mr-1.5" aria-hidden="true"></i>Organization</dt>
                                <dd class="mt-1 truncate font-semibold text-slate-800">{{ organization?.name }}</dd>
                            </div>
                            <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0 lg:border-l-0">
                                <dt class="font-bold uppercase tracking-[0.08em] text-slate-400"><i class="fa-solid fa-user-tie mr-1.5" aria-hidden="true"></i>Representative</dt>
                                <dd class="mt-1 truncate font-semibold text-slate-800">{{ representative?.name }}</dd>
                            </div>
                            <div class="border-t border-slate-200 px-5 py-3 lg:border-t-0">
                                <dt class="font-bold uppercase tracking-[0.08em] text-slate-400"><i class="fa-solid fa-users-gear mr-1.5" aria-hidden="true"></i>Operating policy</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ isSoloOperator ? 'Solo operator' : 'Role-based team' }}</dd>
                            </div>
                            <div class="border-t border-slate-200 px-5 py-3 sm:border-l lg:border-t-0">
                                <dt class="font-bold uppercase tracking-[0.08em] text-slate-400"><i class="fa-solid fa-graduation-cap mr-1.5" aria-hidden="true"></i>Published</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ organization?.published_program_count }}</dd>
                            </div>
                        </dl>
                    </header>

                    <section v-if="activeView === 'overview'" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div>
                            <div class="min-w-0">
                                <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                                    <div>
                                        <h2 class="flex items-center gap-2 text-lg font-bold text-slate-950">
                                            <i class="fa-solid fa-shield-halved text-sm text-slate-500" aria-hidden="true"></i>
                                            Control health
                                        </h2>
                                        <p class="mt-1 text-sm text-slate-500">Four required governance checks.</p>
                                    </div>
                                    <span :class="['inline-flex w-fit items-center border px-2.5 py-1.5 text-[0.68rem] font-black uppercase tracking-wide', governanceNeedsAttention ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-emerald-300 bg-emerald-50 text-emerald-800']">
                                        <i :class="[governanceNeedsAttention ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-check', 'mr-2']" aria-hidden="true"></i>
                                        {{ governanceNeedsAttention ? 'Review required' : 'Controls in order' }}
                                    </span>
                                </div>

                                <div class="portal-table-scroll">
                                    <table class="portal-data-table min-w-[42rem]">
                                        <thead>
                                            <tr>
                                                <th scope="col">Area</th>
                                                <th scope="col">Current state</th>
                                                <th scope="col">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="control in controlRegister" :key="control.label">
                                                <td class="w-[34%] font-semibold text-slate-900">{{ control.label }}</td>
                                                <td class="w-[48%] text-sm text-slate-600">{{ control.evidence }}</td>
                                                <td class="w-[18%]">
                                                    <span :class="['inline-flex items-center gap-2 whitespace-nowrap font-semibold', control.state === 'controlled' ? 'text-emerald-700' : 'text-amber-700']">
                                                        <i :class="control.state === 'controlled' ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation'" aria-hidden="true"></i>
                                                        {{ control.state === 'controlled' ? 'Ready' : 'Review' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <aside v-if="nextAction" class="flex flex-col gap-4 border-t border-slate-300 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div>
                                        <p class="text-[0.65rem] font-bold uppercase tracking-[0.1em] text-slate-500"><i class="fa-solid fa-arrow-right mr-1.5" aria-hidden="true"></i>Priority action</p>
                                        <h2 class="mt-1 text-base font-bold leading-6 text-slate-950">{{ nextAction.title }}</h2>
                                        <p class="mt-0.5 text-sm leading-5 text-slate-600">{{ nextAction.description }}</p>
                                    </div>
                                </div>
                                <div class="shrink-0 sm:text-right">
                                    <a :href="nextAction.href" class="inline-flex w-full items-center justify-between border border-slate-950 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto">
                                        {{ nextAction.label }}
                                        <i class="fa-solid fa-arrow-right ml-4 text-xs text-slate-300" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </aside>
                        </div>
                    </section>

                    <section v-if="activeView === 'responsibilities'" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex flex-col gap-4 border-b border-slate-300 px-5 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                            <div>
                                <h2 class="flex items-center gap-2 text-lg font-bold text-slate-950">
                                    <i class="fa-solid fa-list-check text-sm text-slate-500" aria-hidden="true"></i>
                                    Operational accountability
                                </h2>
                                <p class="mt-1 text-sm text-slate-500">Each workflow needs an active owner.</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="text-lg font-black text-slate-950">{{ summary.coverage_count }}/{{ summary.coverage_total }}</p>
                                    <p class="text-[0.68rem] font-bold uppercase tracking-wide text-slate-500">Controls assigned</p>
                                </div>
                                <a href="/provider/team" class="border border-slate-300 px-3.5 py-2.5 text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"><i class="fa-solid fa-user-gear mr-2" aria-hidden="true"></i>Manage assignments</a>
                            </div>
                        </div>

                        <div class="h-1 bg-slate-200" aria-hidden="true">
                            <div :class="['h-full', Number(summary.gap_count ?? 0) > 0 ? 'bg-amber-500' : 'bg-emerald-600']" :style="{ width: `${coveragePercent}%` }"></div>
                        </div>

                        <div class="portal-table-scroll">
                            <table class="portal-data-table min-w-[60rem]">
                                <thead>
                                    <tr>
                                        <th scope="col">Workflow</th>
                                        <th scope="col">Accountable role</th>
                                        <th scope="col">Assigned staff</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in paginatedCoverage" :key="item.permission">
                                        <td class="w-[34%] align-top">
                                            <p class="font-semibold text-slate-900">{{ item.label }}</p>
                                        </td>
                                        <td class="w-[22%] align-top text-slate-700">{{ item.role }}</td>
                                        <td class="w-[29%] align-top">
                                            <ul v-if="item.members.length" class="space-y-1 text-sm text-slate-700">
                                                <li v-for="member in item.members" :key="`${item.permission}-${member.id}`">
                                                    <span class="font-semibold text-slate-900">{{ member.name }}</span>
                                                    <span class="text-slate-500"> — {{ member.role }}</span>
                                                </li>
                                            </ul>
                                            <span v-else class="font-semibold text-slate-400">No staff assigned</span>
                                        </td>
                                        <td class="w-[15%] align-top">
                                            <span :class="['inline-flex items-center gap-2 font-semibold', item.status === 'covered' ? 'text-emerald-700' : 'text-amber-700']">
                                                <i :class="item.status === 'covered' ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation'" aria-hidden="true"></i>
                                                {{ item.status === 'covered' ? 'Covered' : 'Unassigned' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="coverage.length > pageSize" class="flex flex-col gap-3 border-t border-slate-300 bg-slate-50 px-5 py-3 text-xs sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="font-semibold text-slate-600">
                                Showing {{ responsibilityRange.start }}–{{ responsibilityRange.end }} of {{ coverage.length }} responsibilities
                            </p>
                            <nav class="flex items-center gap-2" aria-label="Responsibility coverage pagination">
                                <button
                                    type="button"
                                    class="border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:border-slate-300 disabled:hover:bg-white"
                                    :disabled="responsibilityPage <= 1"
                                    aria-label="Previous responsibility coverage page"
                                    @click="changeResponsibilityPage(responsibilityPage - 1)"
                                >
                                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                </button>
                                <span class="min-w-20 text-center text-slate-500" aria-live="polite">Page {{ responsibilityPage }} of {{ responsibilityPageCount }}</span>
                                <button
                                    type="button"
                                    class="border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:border-slate-300 disabled:hover:bg-white"
                                    :disabled="responsibilityPage >= responsibilityPageCount"
                                    aria-label="Next responsibility coverage page"
                                    @click="changeResponsibilityPage(responsibilityPage + 1)"
                                >
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </button>
                            </nav>
                        </div>
                    </section>

                    <section v-if="activeView === 'access'" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="border-b border-slate-300">
                            <div class="px-5 py-5 sm:px-6">
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <h2 class="flex items-center gap-2 text-xl font-bold text-slate-950"><i class="fa-solid fa-key text-base text-slate-500" aria-hidden="true"></i>{{ isSoloOperator ? 'Solo operator' : 'Role-based team' }}</h2>
                                    <span class="border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[0.65rem] font-black uppercase tracking-wide text-emerald-800"><i class="fa-solid fa-circle-check mr-1.5" aria-hidden="true"></i>Active policy</span>
                                </div>
                                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                    {{ isSoloOperator
                                        ? 'The representative handles every provider workflow.'
                                        : 'The representative governs access; staff handle assigned workflows.' }}
                                </p>
                            </div>
                            <dl class="grid border-t border-slate-300 bg-slate-50 sm:grid-cols-3 sm:divide-x sm:divide-slate-200">
                                <div class="px-5 py-3.5">
                                    <dt class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-400"><i class="fa-solid fa-user-tie mr-1.5" aria-hidden="true"></i>Representative scope</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ isSoloOperator ? 'All provider workflows' : 'Profile and team governance' }}</dd>
                                </div>
                                <div class="border-t border-slate-200 px-5 py-3.5 sm:border-t-0">
                                    <dt class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-400"><i class="fa-solid fa-users-gear mr-1.5" aria-hidden="true"></i>Staff records</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-800">{{ summary.active_staff_count ?? 0 }} active · {{ summary.suspended_staff_count ?? 0 }} suspended</dd>
                                </div>
                                <div class="border-t border-slate-200 px-5 py-3.5 sm:border-t-0">
                                    <dt class="text-[0.65rem] font-bold uppercase tracking-wide text-slate-400"><i class="fa-solid fa-lock mr-1.5" aria-hidden="true"></i>Change control</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-800">Password confirmation required</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <h2 class="flex items-center gap-2 text-base font-bold text-slate-950"><i class="fa-solid fa-list-check text-sm text-slate-500" aria-hidden="true"></i>Policy comparison</h2>
                            <p class="mt-1 text-sm text-slate-500">Choose the policy that fits your staffing model.</p>
                        </div>

                        <div class="portal-table-scroll">
                            <table class="portal-data-table min-w-[64rem]">
                                <thead>
                                    <tr>
                                        <th scope="col">Policy</th>
                                        <th scope="col">Representative access</th>
                                        <th scope="col">Delegation model</th>
                                        <th scope="col">Best suited for</th>
                                        <th scope="col" class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="w-[18%] font-semibold text-slate-900">Role-based team</td>
                                        <td class="w-[22%] text-sm text-slate-600">Profile, team access, and governance controls</td>
                                        <td class="w-[24%] text-sm text-slate-600">Named staff receive only their assigned workflow permissions.</td>
                                        <td class="w-[20%] text-sm text-slate-600">Organizations with two or more operators</td>
                                        <td class="w-[16%] text-right">
                                            <span v-if="!isSoloOperator" class="inline-flex items-center gap-2 font-semibold text-emerald-700"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Active</span>
                                            <button v-else type="button" class="border border-slate-300 px-3.5 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50" @click="openModeModal('governance')"><i class="fa-solid fa-arrow-right mr-2" aria-hidden="true"></i>Select</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-semibold text-slate-900">Solo operator</td>
                                        <td class="text-sm text-slate-600">Every provider workflow and governance control</td>
                                        <td class="text-sm text-slate-600">The representative completes operational work directly.</td>
                                        <td class="text-sm text-slate-600">A provider operated by one person</td>
                                        <td class="text-right">
                                            <span v-if="isSoloOperator" class="inline-flex items-center gap-2 font-semibold text-emerald-700"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Active</span>
                                            <button v-else type="button" class="border border-slate-300 px-3.5 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50" @click="openModeModal('solo_operator')"><i class="fa-solid fa-arrow-right mr-2" aria-hidden="true"></i>Select</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500 sm:px-6">
                            <i class="fa-solid fa-circle-info mr-1.5" aria-hidden="true"></i>Changing policy updates representative permissions. Staff assignments are retained.
                        </div>
                    </section>

                    <section v-if="activeView === 'activity'" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex flex-col gap-2 border-b border-slate-300 px-5 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                            <div>
                                <h2 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-clock-rotate-left text-sm text-slate-500" aria-hidden="true"></i>Recorded changes</h2>
                            </div>
                            <p class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-list-check mr-1.5" aria-hidden="true"></i>{{ recentActivity.length }} records</p>
                        </div>
                        <div v-if="recentActivity.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[48rem]">
                                <thead>
                                    <tr>
                                        <th scope="col">Activity</th>
                                        <th scope="col">Performed by</th>
                                        <th scope="col">Date and time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="activity in paginatedActivity" :key="activity.id">
                                        <td class="w-[55%]">
                                            <p class="font-semibold text-slate-900">{{ activityLabel(activity.action) }}</p>
                                            <p v-if="activity.description" class="mt-0.5 text-xs leading-5 text-slate-500">{{ activity.description }}</p>
                                        </td>
                                        <td class="w-[25%] text-slate-700">{{ activity.actor }}</td>
                                        <td class="w-[20%] whitespace-nowrap text-slate-600">{{ activity.occurred_at }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="recentActivity.length > pageSize" class="flex flex-col gap-3 border-t border-slate-300 bg-slate-50 px-5 py-3 text-xs sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="font-semibold text-slate-600">
                                Showing {{ activityRange.start }}–{{ activityRange.end }} of {{ recentActivity.length }} activity records
                            </p>
                            <nav class="flex items-center gap-2" aria-label="Governance activity pagination">
                                <button
                                    type="button"
                                    class="border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:border-slate-300 disabled:hover:bg-white"
                                    :disabled="activityPage <= 1"
                                    aria-label="Previous governance activity page"
                                    @click="changeActivityPage(activityPage - 1)"
                                >
                                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                </button>
                                <span class="min-w-20 text-center text-slate-500" aria-live="polite">Page {{ activityPage }} of {{ activityPageCount }}</span>
                                <button
                                    type="button"
                                    class="border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:border-slate-300 disabled:hover:bg-white"
                                    :disabled="activityPage >= activityPageCount"
                                    aria-label="Next governance activity page"
                                    @click="changeActivityPage(activityPage + 1)"
                                >
                                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                </button>
                            </nav>
                        </div>
                        <div v-if="!recentActivity.length" class="px-5 py-10 text-center">
                            <i class="fa-solid fa-clock-rotate-left text-xl text-slate-300" aria-hidden="true"></i>
                            <p class="mt-2 text-sm font-semibold text-slate-600">No governance changes yet.</p>
                        </div>
                    </section>
                </template>
            </div>
        </section>

        <div v-if="showModeModal" class="fixed inset-0 z-[2200] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeModeModal" @keydown.esc="closeModeModal">
            <section class="w-full max-w-lg border border-slate-300 bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="operating-mode-title">
                <div class="flex items-center justify-between bg-slate-950 px-5 py-2 text-[0.65rem] font-bold uppercase tracking-[0.14em] text-slate-300">
                    <span><i class="fa-solid fa-lock mr-1.5" aria-hidden="true"></i>Security-controlled change</span>
                    <span class="font-mono">IAM-01</span>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[0.68rem] font-black uppercase tracking-[0.14em] text-slate-500">Confirm access policy</p>
                        <h2 id="operating-mode-title" class="mt-1 flex items-center gap-2 text-xl font-bold"><i class="fa-solid fa-key text-base text-slate-500" aria-hidden="true"></i>{{ targetMode === 'solo_operator' ? 'Enable solo operator?' : 'Use role-based team?' }}</h2>
                    </div>
                    <button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-500 transition hover:bg-slate-50" aria-label="Close" @click="closeModeModal">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <form class="p-5" @submit.prevent="updateOperatingMode">
                    <div class="border border-amber-300 border-l-4 bg-amber-50 p-4 text-sm leading-6 text-slate-700">
                        <template v-if="targetMode === 'solo_operator'">This representative account will receive access to every provider workflow. Team assignments will remain unchanged.</template>
                        <template v-else>This representative account will return to profile and team governance. Operational work must be assigned to team accounts.</template>
                    </div>
                    <label for="governance-password" class="mt-5 block text-sm font-bold text-slate-700">Current password</label>
                    <input id="governance-password" v-model="currentPassword" type="password" autocomplete="current-password" required class="mt-2 w-full border border-slate-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-slate-700 focus:ring-2 focus:ring-slate-200">
                    <p v-if="modeError" class="mt-2 text-sm font-semibold text-rose-700">{{ modeError }}</p>
                    <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closeModeModal">Cancel</button>
                        <button type="submit" :disabled="isUpdatingMode" class="border border-slate-950 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                            {{ isUpdatingMode ? 'Updating...' : 'Confirm change' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</template>
