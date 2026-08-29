<script setup>
import { onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSectionNav from '../components/ProviderSectionNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const isLoading = ref(true);
const errorMessage = ref('');
const organization = ref(null);
const accounts = ref([]);
const updatingId = ref(null);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const permissionLabels = {
    manage_programs: 'Programs',
    review_applications: 'Applications',
    manage_reports: 'Reported issues',
    manage_profile: 'Organization profile',
    manage_team: 'Team accounts',
    manage_billing: 'Optional services',
};

function accountInitials(name) {
    return String(name ?? 'Team member')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

async function loadTeam() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/team/data');
        organization.value = response.data.organization;
        accounts.value = response.data.accounts ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider team accounts.';
    } finally {
        isLoading.value = false;
    }
}

async function toggleStatus(account) {
    const suspending = account.account_status !== 'suspended';
    const confirmed = await requestConfirmation({
        title: suspending ? 'Suspend this team account?' : 'Reactivate this team account?',
        message: suspending
            ? `${account.name} will no longer be able to sign in.`
            : `${account.name} will regain access based on the assigned permissions.`,
        confirmLabel: suspending ? 'Suspend account' : 'Reactivate account',
        tone: suspending ? 'danger' : 'default',
    });

    if (!confirmed) {
        return;
    }

    updatingId.value = account.id;

    try {
        const response = await window.axios.patch(`/provider/team/accounts/${account.id}/status`, {
            account_status: suspending ? 'suspended' : 'active',
        });
        const index = accounts.value.findIndex((item) => item.id === account.id);

        if (index >= 0) {
            accounts.value[index] = response.data.account;
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update this account.';
    } finally {
        updatingId.value = null;
    }
}

onMounted(loadTeam);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Team & Access</p>
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Accounts and access</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Give each staff member only the portal access needed for their work at {{ organization?.name ?? 'your organization' }}.
                            </p>
                        </div>
                        <a href="/provider/team/accounts/create" class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800">
                            Create team account
                        </a>
                    </div>
                </header>

                <ProviderSectionNav section="organization" />

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-white">
                                <i class="fa-solid fa-users-gear" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Access directory</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Team members</h2>
                                <p class="mt-0.5 text-sm text-slate-500">Delegated accounts only; primary provider ownership stays separate.</p>
                            </div>
                        </div>
                        <span class="w-fit rounded-md bg-white px-3 py-2 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                            {{ accounts.length }} account{{ accounts.length === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading team accounts...</div>
                    <div v-else-if="accounts.length === 0" class="p-8 text-center">
                        <i class="fa-solid fa-user-group text-2xl text-slate-300"></i>
                        <p class="mt-3 text-sm font-bold text-slate-900">No team accounts yet</p>
                        <p class="mt-1 text-sm text-slate-500">Create one when another staff member needs provider access.</p>
                    </div>
                    <div v-else class="divide-y divide-slate-200">
                        <article v-for="account in accounts" :key="account.id" class="grid gap-4 p-4 transition hover:bg-slate-50 sm:p-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_auto] lg:items-center">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-900 text-xs font-black text-amber-200">
                                    {{ accountInitials(account.name) }}
                                </span>
                                <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ account.name }}</h3>
                                    <span :class="['rounded px-2 py-1 text-[0.68rem] font-bold uppercase tracking-wide', account.account_status === 'suspended' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800']">
                                        {{ account.account_status === 'suspended' ? 'Suspended' : 'Active' }}
                                    </span>
                                </div>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ account.email }} - @{{ account.username }}</p>
                                <p class="mt-1 text-xs font-semibold text-amber-700">{{ account.team_role_label }}</p>
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Permissions</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <span v-for="permission in account.permissions" :key="permission" class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600">
                                        {{ permissionLabels[permission] ?? permission }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex gap-2 lg:justify-end">
                                <a :href="`/provider/team/accounts/${account.id}/edit`" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">Edit</a>
                                <button type="button" :disabled="updatingId === account.id" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="toggleStatus(account)">
                                    {{ updatingId === account.id ? 'Updating...' : account.account_status === 'suspended' ? 'Reactivate' : 'Suspend' }}
                                </button>
                            </div>
                        </article>
                    </div>
                </section>

                <ProviderFooter />
            </div>
        </section>
    </main>

    <ConfirmationDialog v-bind="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
</template>
