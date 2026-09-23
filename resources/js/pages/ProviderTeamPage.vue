<script setup>
import { onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
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
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <TaskPageHeader
                    theme="provider"
                    eyebrow="Team and access"
                    title="Team accounts"
                    description="Control who can use the provider workspace and which programs they can access."
                    icon="fa-solid fa-users-gear"
                    action-href="/provider/team/accounts/create"
                    action-label="Add team member"
                >
                    <template #meta>
                        <span>{{ accounts.length }} delegated account{{ accounts.length === 1 ? '' : 's' }}</span>
                        <span>Provider ownership stays with the representative</span>
                    </template>
                </TaskPageHeader>

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <section class="provider-panel mt-4 overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h2 class="font-bold text-slate-950">Team directory</h2>
                            <p class="mt-1 text-sm text-slate-500">Edit a member to change their role, permissions, or program scope.</p>
                        </div>
                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ accounts.length }} total</span>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading team accounts...</div>
                    <div v-else-if="accounts.length === 0" class="p-8 text-center">
                        <i class="fa-solid fa-user-group text-2xl text-slate-300"></i>
                        <p class="mt-3 text-sm font-bold text-slate-900">No team accounts yet</p>
                        <p class="mt-1 text-sm text-slate-500">Create one when another staff member needs provider access.</p>
                    </div>
                    <div v-else>
                        <div class="hidden grid-cols-[minmax(16rem,1.3fr)_minmax(15rem,1fr)_minmax(10rem,.65fr)_11rem] gap-4 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500 xl:grid">
                            <span>Member</span>
                            <span>Role and permissions</span>
                            <span>Program access</span>
                            <span class="text-center">Actions</span>
                        </div>
                        <article v-for="account in accounts" :key="account.id" class="grid gap-3 border-b border-slate-200 px-4 py-3 transition last:border-b-0 hover:bg-slate-50 xl:grid-cols-[minmax(16rem,1.3fr)_minmax(15rem,1fr)_minmax(10rem,.65fr)_11rem] xl:items-center xl:px-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-[11px] font-black text-amber-200">
                                    {{ accountInitials(account.name) }}
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ account.name }}</h3>
                                        <span :class="['rounded px-2 py-1 text-[0.68rem] font-bold uppercase tracking-wide', account.account_status === 'suspended' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800']">
                                            {{ account.account_status === 'suspended' ? 'Suspended' : 'Active' }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">
                                        {{ account.email }}
                                        <template v-if="account.username">
                                            <span class="mx-1 text-slate-300">&middot;</span>
                                            @{{ account.username }}
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div>
                                <p class="text-sm font-bold text-slate-900">{{ account.team_role_label }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <span v-for="permission in (account.permissions || []).slice(0, 2)" :key="permission" class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600">
                                        {{ permissionLabels[permission] ?? permission }}
                                    </span>
                                    <span v-if="(account.permissions || []).length > 2" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-500">
                                        +{{ account.permissions.length - 2 }} more
                                    </span>
                                    <span v-if="(account.permissions || []).length === 0" class="text-xs font-semibold text-slate-500">
                                        No delegated access
                                    </span>
                                </div>
                            </div>

                            <p class="text-sm font-semibold text-slate-700">
                                {{ account.program_access_mode === 'selected'
                                    ? `${account.assigned_programs?.length ?? 0} selected program${(account.assigned_programs?.length ?? 0) === 1 ? '' : 's'}`
                                    : 'All programs' }}
                            </p>

                            <div class="flex w-full gap-2 xl:w-44 xl:justify-self-center">
                                <a :href="`/provider/team/accounts/${account.id}/edit`" class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-100">Edit</a>
                                <button type="button" :disabled="updatingId === account.id" class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="toggleStatus(account)">
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
