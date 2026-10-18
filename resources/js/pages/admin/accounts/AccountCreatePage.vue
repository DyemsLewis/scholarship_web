<script setup>
import { reactive, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { limitPhoneNumber } from '../../../support/phoneNumber';
import { showPortalToast } from '../../../support/portalToast';

const saving = ref(false);
const errorMessage = ref('');
const errors = ref({});
const form = reactive({
    role: 'applicant',
    first_name: '',
    middle_initial: '',
    last_name: '',
    email: '',
    username: '',
    contact_number: '',
    password: '',
    password_confirmation: '',
});
const inputClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm text-slate-900 outline-none focus:border-slate-950';
const labelClass = 'text-sm font-bold text-slate-800';

function fieldError(field) {
    return errors.value?.[field]?.[0] ?? '';
}

async function createAccount() {
    if (saving.value) return;
    saving.value = true;
    errorMessage.value = '';
    errors.value = {};

    try {
        const response = await window.axios.post('/admin/users', {
            ...form,
            middle_initial: form.middle_initial.trim().toUpperCase(),
            contact_number: form.contact_number.trim(),
            account_title: null,
            permissions: null,
        });
        showPortalToast({ title: 'Account created', message: response.data.message });
        window.location.href = `/admin/workspaces/accounts/${response.data.user.id}`;
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message ?? 'Review the account details and try again.';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <a href="/admin/workspaces/accounts" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to accounts</a>

        <AdminWorkspaceHeader
            eyebrow="Account access"
            title="Create account"
            description="Add one applicant or scholarship provider login."
            icon="fa-solid fa-user-plus"
        />

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <form class="admin-panel mt-4 overflow-hidden" @submit.prevent="createAccount">
            <section class="border-b border-slate-200 px-6 py-5">
                <h2 class="text-base font-black text-slate-950">Account purpose</h2>
                <div class="mt-4 max-w-md">
                    <label :class="labelClass" for="account-role">Account type</label>
                    <select id="account-role" v-model="form.role" :class="inputClass">
                        <option value="applicant">Applicant</option>
                        <option value="provider">Scholarship provider</option>
                    </select>
                    <p class="mt-2 text-xs text-slate-500">The role cannot be changed after creation.</p>
                </div>
            </section>

            <section class="border-b border-slate-200 px-6 py-5">
                <h2 class="text-base font-black text-slate-950">Identity and login</h2>
                <div class="mt-4 grid grid-cols-3 gap-5">
                    <label><span :class="labelClass">First name</span><input v-model="form.first_name" required maxlength="255" :class="inputClass"><span v-if="fieldError('first_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('first_name') }}</span></label>
                    <label><span :class="labelClass">Middle initial</span><input v-model="form.middle_initial" required maxlength="1" :class="inputClass" @input="form.middle_initial = form.middle_initial.replace(/[^a-z]/gi, '').slice(0, 1).toUpperCase()"><span v-if="fieldError('middle_initial')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('middle_initial') }}</span></label>
                    <label><span :class="labelClass">Last name</span><input v-model="form.last_name" required maxlength="255" :class="inputClass"><span v-if="fieldError('last_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('last_name') }}</span></label>
                    <label><span :class="labelClass">Email</span><input v-model="form.email" required type="email" maxlength="255" :class="inputClass"><span v-if="fieldError('email')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('email') }}</span></label>
                    <label><span :class="labelClass">Username</span><input v-model="form.username" required minlength="4" maxlength="255" :class="inputClass"><span v-if="fieldError('username')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('username') }}</span></label>
                    <label><span :class="labelClass">Contact number</span><input :value="form.contact_number" required inputmode="tel" :class="inputClass" @input="form.contact_number = limitPhoneNumber($event.target.value)"><span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('contact_number') }}</span></label>
                </div>
            </section>

            <section class="px-6 py-5">
                <h2 class="text-base font-black text-slate-950">Temporary access</h2>
                <div class="mt-4 grid max-w-4xl grid-cols-2 gap-5">
                    <label><span :class="labelClass">Temporary password</span><input v-model="form.password" required type="password" minlength="8" autocomplete="new-password" :class="inputClass"><span v-if="fieldError('password')" class="mt-1 block text-xs font-semibold text-rose-700">{{ fieldError('password') }}</span></label>
                    <label><span :class="labelClass">Confirm password</span><input v-model="form.password_confirmation" required type="password" minlength="8" autocomplete="new-password" :class="inputClass"></label>
                </div>
            </section>

            <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4">
                <p class="text-xs text-slate-500">Share temporary credentials securely.</p>
                <button type="submit" :disabled="saving" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50">{{ saving ? 'Creating...' : 'Create account' }}</button>
            </footer>
        </form>
    </AdminWorkspacePage>
</template>
