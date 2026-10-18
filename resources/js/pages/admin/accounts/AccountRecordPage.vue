<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { showPortalToast } from '../../../support/portalToast';

const accountId = window.location.pathname.match(/\/admin\/workspaces\/accounts\/(\d+)$/)?.[1];
const loading = ref(true);
const actionBusy = ref('');
const errorMessage = ref('');
const actionError = ref('');
const account = ref(null);
const roleContext = ref({ label: 'Records', count: 0 });
const statusDialogOpen = ref(false);
const suspensionReason = ref('');

const roleLabel = computed(() => ({
    applicant: 'Applicant',
    provider: 'Scholarship provider',
    admin: 'Administrator',
}[account.value?.role] ?? 'Portal account'));
const isSuspended = computed(() => account.value?.account_status === 'suspended');
const signInState = computed(() => {
    if (isSuspended.value) return 'Access suspended';
    if (!account.value?.email_verified) return 'Email verification needed';
    if (account.value?.must_reset_password) return 'Password reset required';
    return 'Ready to sign in';
});

function roleIcon(role) {
    return {
        applicant: 'fa-solid fa-user-graduate',
        provider: 'fa-solid fa-building-columns',
        admin: 'fa-solid fa-user-shield',
    }[role] ?? 'fa-solid fa-user';
}

function firstError(error, fallback) {
    return Object.values(error.response?.data?.errors ?? {})[0]?.[0]
        ?? error.response?.data?.message
        ?? fallback;
}

async function loadAccount() {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/admin/workspaces/accounts/${accountId}/data`);
        account.value = response.data.user;
        roleContext.value = response.data.role_context ?? roleContext.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The account record could not be loaded.';
    } finally {
        loading.value = false;
    }
}

async function runAction(key, request, successTitle) {
    if (actionBusy.value) return;
    actionBusy.value = key;
    actionError.value = '';

    try {
        const response = await request();
        account.value = { ...account.value, ...response.data.user };
        showPortalToast({ title: successTitle, message: response.data.message });
    } catch (error) {
        actionError.value = firstError(error, 'The account action could not be completed.');
    } finally {
        actionBusy.value = '';
    }
}

function resendVerification() {
    runAction(
        'verification',
        () => window.axios.post(`/admin/users/${account.value.id}/verification-email`),
        'Verification sent',
    );
}

function requirePasswordReset() {
    runAction(
        'password',
        () => window.axios.post(`/admin/users/${account.value.id}/force-password-reset`),
        'Password reset required',
    );
}

function openStatusDialog() {
    suspensionReason.value = '';
    actionError.value = '';
    statusDialogOpen.value = true;
}

function closeStatusDialog() {
    if (!actionBusy.value) statusDialogOpen.value = false;
}

async function updateStatus() {
    if (!isSuspended.value && suspensionReason.value.trim().length < 5) {
        actionError.value = 'Add a short reason for suspending this account.';
        return;
    }

    await runAction(
        'status',
        () => window.axios.patch(`/admin/users/${account.value.id}/status`, {
            account_status: isSuspended.value ? 'active' : 'suspended',
            suspension_reason: isSuspended.value ? null : suspensionReason.value.trim(),
        }),
        isSuspended.value ? 'Account reactivated' : 'Account suspended',
    );

    if (!actionError.value) statusDialogOpen.value = false;
}

onMounted(loadAccount);
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <a href="/admin/workspaces/accounts" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to accounts</a>

        <AdminWorkspaceHeader
            eyebrow="Account record"
            :title="account?.name || 'Account details'"
            :description="account ? `${roleLabel} · @${account.username}` : 'Review account access.'"
            icon="fa-solid fa-user-gear"
        >
            <template v-if="account" #actions>
                <span :class="['px-3 py-2 text-[10px] font-bold uppercase tracking-wide', isSuspended ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800']">{{ isSuspended ? 'Suspended' : 'Active' }}</span>
            </template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading account record...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <template v-else-if="account">
            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
                    <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-lg text-slate-600">
                        <i :class="roleIcon(account.role)" aria-hidden="true"></i>
                        <img v-if="account.avatar_url" :src="account.avatar_url" alt="" class="absolute inset-0 h-full w-full object-cover" @error="$event.currentTarget.remove()">
                    </span>
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-black text-slate-950">{{ account.name }}</h2>
                        <p class="mt-1 truncate text-sm text-slate-500">{{ account.email }}</p>
                    </div>
                </div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200">
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Sign-in state</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ signInState }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Username</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ account.username }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Created</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ account.created_at }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ roleContext.label }}</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ roleContext.count }}</dd></div>
                </dl>
            </section>

            <section class="admin-panel mt-4 overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-base font-black text-slate-950">Sign-in controls</h2>
                    <p class="mt-1 text-sm text-slate-500">Use only the action needed for this account.</p>
                </header>

                <p v-if="actionError && !statusDialogOpen" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm font-semibold text-rose-800" role="alert">{{ actionError }}</p>

                <div class="divide-y divide-slate-200">
                    <div class="flex items-center justify-between gap-6 px-6 py-5">
                        <div><h3 class="text-sm font-bold text-slate-950">Email verification</h3><p class="mt-1 text-sm text-slate-500">{{ account.email_verified ? 'The email address is verified.' : 'Send a new verification link.' }}</p></div>
                        <span v-if="account.email_verified" class="text-xs font-bold text-emerald-700">Verified</span>
                        <button v-else type="button" :disabled="Boolean(actionBusy)" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 disabled:opacity-40" @click="resendVerification">{{ actionBusy === 'verification' ? 'Sending...' : 'Resend verification' }}</button>
                    </div>
                    <div class="flex items-center justify-between gap-6 px-6 py-5">
                        <div><h3 class="text-sm font-bold text-slate-950">Password access</h3><p class="mt-1 text-sm text-slate-500">Require a password change before the next sign-in.</p></div>
                        <button type="button" :disabled="Boolean(actionBusy) || account.must_reset_password" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 disabled:opacity-40" @click="requirePasswordReset">{{ account.must_reset_password ? 'Reset already required' : actionBusy === 'password' ? 'Preparing...' : 'Require password reset' }}</button>
                    </div>
                    <div class="flex items-center justify-between gap-6 px-6 py-5">
                        <div><h3 class="text-sm font-bold text-slate-950">Account status</h3><p class="mt-1 text-sm text-slate-500">{{ isSuspended ? 'Restore sign-in access when the issue is resolved.' : 'Suspend access when an account should no longer sign in.' }}</p></div>
                        <button type="button" :disabled="Boolean(actionBusy)" :class="['border px-3 py-2 text-xs font-bold disabled:opacity-40', isSuspended ? 'border-slate-950 bg-slate-950 text-white' : 'border-rose-300 bg-white text-rose-700']" @click="openStatusDialog">{{ isSuspended ? 'Reactivate account' : 'Suspend account' }}</button>
                    </div>
                </div>
            </section>
        </template>
    </AdminWorkspacePage>

    <div v-if="statusDialogOpen" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/70 p-4" @click.self="closeStatusDialog">
        <section class="w-full max-w-lg border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="status-dialog-title">
            <header class="border-b border-slate-200 px-5 py-4">
                <h2 id="status-dialog-title" class="text-lg font-black text-slate-950">{{ isSuspended ? 'Reactivate this account?' : 'Suspend this account?' }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ isSuspended ? 'The user will be able to sign in again.' : 'The user will lose sign-in access immediately.' }}</p>
            </header>
            <div class="px-5 py-4">
                <label v-if="!isSuspended" class="block"><span class="text-sm font-bold text-slate-800">Reason</span><textarea v-model="suspensionReason" rows="3" maxlength="1000" class="mt-2 w-full border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-950" placeholder="Briefly explain why access is being suspended."></textarea></label>
                <p v-if="actionError" class="mt-3 border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">{{ actionError }}</p>
            </div>
            <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button type="button" class="border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" :disabled="Boolean(actionBusy)" @click="closeStatusDialog">Cancel</button>
                <button type="button" :disabled="Boolean(actionBusy)" :class="['px-4 py-2.5 text-sm font-bold text-white disabled:opacity-40', isSuspended ? 'bg-slate-950' : 'bg-rose-700']" @click="updateStatus">{{ actionBusy === 'status' ? 'Saving...' : isSuspended ? 'Reactivate' : 'Suspend' }}</button>
            </footer>
        </section>
    </div>
</template>
