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

const isSoloOperator = computed(() => operatingMode.value === 'solo_operator');
const coveragePercent = computed(() => {
    const total = Number(summary.value.coverage_total ?? 0);

    return total ? Math.round((Number(summary.value.coverage_count ?? 0) / total) * 100) : 0;
});
const verificationTone = computed(() => {
    if (organization.value?.verification_status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (organization.value?.verification_status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading organization governance...
                </div>

                <div v-else-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="h-1 bg-slate-950"></div>
                        <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-black tracking-wide text-amber-300">
                                    {{ initials(organization?.name) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">Organization workspace</p>
                                    <h1 class="mt-1 font-display text-2xl font-bold text-slate-950">Governance and access</h1>
                                    <p class="mt-1 max-w-2xl text-sm text-slate-600">Assign responsibility, protect account access, and keep each provider workflow accountable.</p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                <span :class="['rounded-md px-3 py-1.5 text-xs font-black uppercase tracking-wide', verificationTone]">
                                    {{ organization?.verification_status_label }}
                                </span>
                                <a href="/provider/team/accounts/create" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                    <i class="fa-solid fa-user-plus mr-2 text-amber-300"></i>Add team member
                                </a>
                            </div>
                        </div>

                        <div class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 px-5 py-3.5 sm:border-r lg:border-b-0">
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-slate-500">Organization</p>
                                <p class="mt-1 truncate text-sm font-bold text-slate-950">{{ organization?.name }}</p>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-3.5 lg:border-b-0 lg:border-r">
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-slate-500">Representative</p>
                                <p class="mt-1 truncate text-sm font-bold text-slate-950">{{ representative?.name }}</p>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-3.5 sm:border-r sm:border-b-0">
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-slate-500">Operating model</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">{{ isSoloOperator ? 'Solo operator' : 'Role-based team' }}</p>
                            </div>
                            <div class="px-5 py-3.5">
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-slate-500">Programs</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">{{ organization?.published_program_count }} published · {{ organization?.program_count }} total</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextAction" class="mt-4 overflow-hidden rounded-lg border border-amber-300 bg-amber-50 shadow-sm">
                        <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3.5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-arrow-right"></i>
                                </span>
                                <div>
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.18em] text-amber-800">Next governance action</p>
                                    <h2 class="mt-1 text-base font-bold text-slate-950">{{ nextAction.title }}</h2>
                                    <p class="mt-0.5 text-sm text-slate-600">{{ nextAction.description }}</p>
                                </div>
                            </div>
                            <a :href="nextAction.href" class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800">
                                {{ nextAction.label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i>
                            </a>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="grid divide-y divide-slate-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
                            <div class="px-5 py-4">
                                <p class="text-xs font-bold text-slate-500">Responsibility coverage</p>
                                <p class="mt-1 text-xl font-black text-slate-950">{{ summary.coverage_count }}/{{ summary.coverage_total }}</p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full bg-emerald-500" :style="{ width: `${coveragePercent}%` }"></div>
                                </div>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-xs font-bold text-slate-500">Active staff</p>
                                <p class="mt-1 text-xl font-black text-slate-950">{{ summary.active_staff_count }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ summary.suspended_staff_count }} suspended</p>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-xs font-bold text-slate-500">Coverage gaps</p>
                                <p :class="['mt-1 text-xl font-black', summary.gap_count ? 'text-amber-700' : 'text-emerald-700']">{{ summary.gap_count }}</p>
                                <p class="mt-1 text-xs text-slate-500">Unassigned workflows</p>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-xs font-bold text-slate-500">Access checks</p>
                                <p :class="['mt-1 text-xl font-black', (summary.broad_access_count || summary.unverified_staff_count) ? 'text-amber-700' : 'text-emerald-700']">
                                    {{ Number(summary.broad_access_count ?? 0) + Number(summary.unverified_staff_count ?? 0) }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">Items to review</p>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Responsibility map</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Who owns each workflow</h2>
                            </div>
                            <a href="/provider/team" class="text-sm font-bold text-slate-700 transition hover:text-slate-950">Manage team access <i class="fa-solid fa-arrow-right ml-1 text-xs"></i></a>
                        </div>

                        <div class="hidden grid-cols-[minmax(15rem,1.2fr)_minmax(14rem,1fr)_minmax(16rem,1.15fr)_7.5rem] gap-4 border-b border-slate-200 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 lg:grid">
                            <span>Workflow</span>
                            <span>Recommended role</span>
                            <span>Assigned to</span>
                            <span>Status</span>
                        </div>

                        <article v-for="item in coverage" :key="item.permission" class="grid gap-3 border-b border-slate-200 px-5 py-4 last:border-b-0 lg:grid-cols-[minmax(15rem,1.2fr)_minmax(14rem,1fr)_minmax(16rem,1.15fr)_7.5rem] lg:items-center lg:gap-4 lg:px-6">
                            <div>
                                <h3 class="text-sm font-bold text-slate-950">{{ item.label }}</h3>
                                <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ item.description }}</p>
                            </div>
                            <p class="text-sm font-semibold text-slate-700">{{ item.role }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="member in item.members" :key="`${item.permission}-${member.id}`" class="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700" :title="member.role">
                                    {{ member.name }}
                                </span>
                                <span v-if="!item.members.length" class="text-sm font-semibold text-slate-400">No one assigned</span>
                            </div>
                            <span :class="['w-fit rounded-md px-2.5 py-1.5 text-[0.68rem] font-black uppercase tracking-wide', item.status === 'covered' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
                                {{ item.status === 'covered' ? 'Covered' : 'Unassigned' }}
                            </span>
                        </article>
                    </section>

                    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,.65fr)]">
                        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                                <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Account model</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">How work is assigned</h2>
                            </div>
                            <div class="divide-y divide-slate-200">
                                <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="font-bold text-slate-950">Role-based team</h3>
                                            <span v-if="!isSoloOperator" class="rounded bg-emerald-100 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide text-emerald-800">Current</span>
                                        </div>
                                        <p class="mt-1 text-sm text-slate-500">The representative governs access while staff handle assigned workflows.</p>
                                    </div>
                                    <button v-if="isSoloOperator" type="button" class="shrink-0 rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="openModeModal('governance')">Use role-based team</button>
                                </div>
                                <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="font-bold text-slate-950">Solo operator</h3>
                                            <span v-if="isSoloOperator" class="rounded bg-amber-100 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide text-amber-800">Current</span>
                                        </div>
                                        <p class="mt-1 text-sm text-slate-500">One representative receives every operational permission. Best for a one-person provider.</p>
                                    </div>
                                    <button v-if="!isSoloOperator" type="button" class="shrink-0 rounded-md border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="openModeModal('solo_operator')">Use solo operator</button>
                                </div>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="border-b border-slate-200 px-5 py-4">
                                <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Recent changes</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Governance activity</h2>
                            </div>
                            <div v-if="recentActivity.length" class="divide-y divide-slate-200">
                                <div v-for="activity in recentActivity" :key="activity.id" class="px-5 py-3.5">
                                    <p class="text-sm font-bold text-slate-900">{{ activityLabel(activity.action) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ activity.actor }} · {{ activity.occurred_at }}</p>
                                </div>
                            </div>
                            <div v-else class="px-5 py-8 text-center">
                                <i class="fa-solid fa-clock-rotate-left text-xl text-slate-300"></i>
                                <p class="mt-2 text-sm font-semibold text-slate-500">No governance changes recorded yet.</p>
                            </div>
                        </section>
                    </div>
                </template>
            </div>
        </section>

        <div v-if="showModeModal" class="fixed inset-0 z-[2200] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeModeModal" @keydown.esc="closeModeModal">
            <section class="w-full max-w-lg overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="operating-mode-title">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Confirm account model</p>
                        <h2 id="operating-mode-title" class="mt-1 text-xl font-bold">{{ targetMode === 'solo_operator' ? 'Enable solo operator?' : 'Use role-based team?' }}</h2>
                    </div>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50" aria-label="Close" @click="closeModeModal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form class="p-5" @submit.prevent="updateOperatingMode">
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-slate-700">
                        <template v-if="targetMode === 'solo_operator'">This representative account will receive access to every provider workflow. Team assignments will remain unchanged.</template>
                        <template v-else>This representative account will return to profile and team governance. Operational work must be assigned to team accounts.</template>
                    </div>
                    <label for="governance-password" class="mt-5 block text-sm font-bold text-slate-700">Current password</label>
                    <input id="governance-password" v-model="currentPassword" type="password" autocomplete="current-password" required class="mt-2 w-full rounded-md border border-slate-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                    <p v-if="modeError" class="mt-2 text-sm font-semibold text-rose-700">{{ modeError }}</p>
                    <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closeModeModal">Cancel</button>
                        <button type="submit" :disabled="isUpdatingMode" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                            {{ isUpdatingMode ? 'Updating...' : 'Confirm change' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</template>
