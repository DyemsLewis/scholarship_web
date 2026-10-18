<script setup>
import AdminEmptyState from '../components/AdminEmptyState.vue';

defineProps({
    accounts: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    showIssue: {
        type: Boolean,
        default: false,
    },
    emptyTitle: {
        type: String,
        default: 'No matching accounts',
    },
});

function roleLabel(role) {
    return {
        applicant: 'Applicant',
        provider: 'Provider',
        admin: 'Administrator',
    }[role] ?? 'Account';
}

function roleIcon(role) {
    return {
        applicant: 'fa-solid fa-user-graduate',
        provider: 'fa-solid fa-building-columns',
        admin: 'fa-solid fa-user-shield',
    }[role] ?? 'fa-solid fa-user';
}

function primaryIssue(account) {
    if (account.account_status === 'suspended') return 'Suspended';
    if (!account.email_verified) return 'Email unverified';
    if (account.must_reset_password) return 'Password reset';
    return 'No issue';
}

function issueClass(account) {
    if (account.account_status === 'suspended') return 'bg-rose-100 text-rose-800';
    if (!account.email_verified) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">
        Loading accounts...
    </div>

    <table v-else-if="accounts.length" class="w-full min-w-[56rem] border-collapse text-left">
        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">
            <tr>
                <th class="border-b border-slate-200 px-5 py-3">Account</th>
                <th class="border-b border-slate-200 px-4 py-3">Role</th>
                <th v-if="showIssue" class="border-b border-slate-200 px-4 py-3">Needs attention</th>
                <th v-else class="border-b border-slate-200 px-4 py-3">Sign-in</th>
                <th class="border-b border-slate-200 px-4 py-3">Access</th>
                <th class="border-b border-slate-200 px-4 py-3">Created</th>
                <th class="border-b border-slate-200 px-5 py-3 text-right">Open</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white">
            <tr v-for="account in accounts" :key="account.id" class="hover:bg-slate-50/70">
                <td class="px-5 py-3.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-600">
                            <i :class="roleIcon(account.role)" aria-hidden="true"></i>
                            <img v-if="account.avatar_url" :src="account.avatar_url" :alt="account.role === 'provider' ? `${account.name} logo` : `${account.name} profile photo`" :class="['absolute inset-0 h-full w-full bg-white', account.role === 'provider' ? 'object-contain p-1' : 'object-cover']" @error="$event.currentTarget.remove()">
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold text-slate-950">{{ account.name }}</span>
                            <span class="mt-0.5 block truncate text-xs text-slate-500">{{ account.email }}</span>
                        </span>
                    </div>
                </td>
                <td class="px-4 py-3.5 text-sm font-semibold text-slate-700">{{ roleLabel(account.role) }}</td>
                <td v-if="showIssue" class="px-4 py-3.5">
                    <span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', issueClass(account)]">{{ primaryIssue(account) }}</span>
                </td>
                <td v-else class="px-4 py-3.5">
                    <span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', account.email_verified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">
                        {{ account.email_verified ? 'Verified' : 'Unverified' }}
                    </span>
                    <span v-if="account.must_reset_password" class="ml-1 inline-flex bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-700">Reset due</span>
                </td>
                <td class="px-4 py-3.5">
                    <span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', account.account_status === 'suspended' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800']">
                        {{ account.account_status === 'suspended' ? 'Suspended' : 'Active' }}
                    </span>
                </td>
                <td class="px-4 py-3.5 text-sm text-slate-600">{{ account.created_at }}</td>
                <td class="px-5 py-3.5 text-right">
                    <a :href="`/admin/workspaces/accounts/${account.id}`" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 transition hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${account.name}`" title="Open account">
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </td>
            </tr>
        </tbody>
    </table>

    <AdminEmptyState
        v-else
        :title="emptyTitle"
        description="Try another search or filter."
        icon="fa-solid fa-user-check"
    />
</template>
