<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const accountId = window.location.pathname.match(/\/admin\/accounts\/(\d+)\/edit$/)?.[1] ?? null;
const isEditMode = computed(() => Boolean(accountId));
const isLoading = ref(Boolean(accountId));
const isSaving = ref(false);
const statusMessage = ref('');
const errorMessage = ref('');
const formElement = ref(null);
const form = ref(emptyForm());

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm text-slate-900 uppercase outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';

function emptyForm() {
    return {
        firstName: '',
        lastName: '',
        middleInitial: '',
        email: '',
        username: '',
        contactNumber: '',
        role: 'applicant',
        password: '',
        passwordConfirmation: '',
    };
}

function fillForm(user) {
    form.value = {
        firstName: user?.first_name ?? '',
        lastName: user?.last_name ?? '',
        middleInitial: user?.middle_initial ?? '',
        email: user?.email ?? '',
        username: user?.username ?? '',
        contactNumber: user?.contact_number ?? '',
        role: user?.role ?? 'applicant',
        password: '',
        passwordConfirmation: '',
    };
}

function handleMiddleInitialInput(event) {
    form.value.middleInitial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handleNumberInput(event) {
    form.value.contactNumber = event.target.value.replace(/[^\d+\s().-]/g, '');
}

function resetForm() {
    form.value = emptyForm();
    statusMessage.value = '';
    errorMessage.value = '';
}

async function loadAccount() {
    if (!accountId) {
        return;
    }

    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/admin/users/${accountId}`);

        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load account.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAccount() {
    statusMessage.value = '';
    errorMessage.value = '';

    if (!formElement.value?.reportValidity()) {
        return;
    }

    const numberDigits = form.value.contactNumber.replace(/\D/g, '');

    if (numberDigits.length < 10) {
        errorMessage.value = 'Enter at least 10 digits in the contact number.';
        formElement.value
            ?.querySelector('#admin-contact-number')
            ?.setCustomValidity(errorMessage.value);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#admin-contact-number')?.setCustomValidity('');

    const hasPasswordInput = form.value.password || form.value.passwordConfirmation;

    if ((!isEditMode.value || hasPasswordInput) && form.value.password !== form.value.passwordConfirmation) {
        errorMessage.value = 'Passwords must match.';
        formElement.value
            ?.querySelector('#admin-password-confirmation')
            ?.setCustomValidity(errorMessage.value);
        formElement.value?.reportValidity();
        return;
    }

    formElement.value?.querySelector('#admin-password-confirmation')?.setCustomValidity('');
    isSaving.value = true;

    const payload = {
        first_name: form.value.firstName,
        last_name: form.value.lastName,
        middle_initial: form.value.middleInitial,
        email: form.value.email,
        username: form.value.username,
        contact_number: form.value.contactNumber,
        role: form.value.role,
    };

    if (!isEditMode.value || hasPasswordInput) {
        payload.password = form.value.password;
        payload.password_confirmation = form.value.passwordConfirmation;
    }

    try {
        const response = isEditMode.value
            ? await window.axios.patch(`/admin/users/${accountId}`, payload)
            : await window.axios.post('/admin/users', payload);

        statusMessage.value = response.data.message ?? (isEditMode.value ? 'Account updated successfully.' : 'Account created successfully.');

        if (isEditMode.value) {
            fillForm(response.data.user);
        } else {
            resetForm();
            statusMessage.value = response.data.message ?? 'Account created successfully.';
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to save account.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadAccount);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="users" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Account Form
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ isEditMode ? 'Edit account' : 'Create account' }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Keep account creation and editing separate from the Manage Users table.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            Back to users
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading account...
                </div>

                <form
                    v-else
                    ref="formElement"
                    class="mt-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                    @submit.prevent="saveAccount"
                >
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_5.5rem_minmax(0,1fr)] lg:items-end">
                        <div>
                            <label :class="labelClass" for="admin-first-name">First name</label>
                            <input id="admin-first-name" v-model="form.firstName" type="text" autocomplete="given-name" required placeholder="First name" :class="inputClass">
                        </div>

                        <div class="lg:mx-auto lg:w-[5.5rem]">
                            <label :class="[labelClass, 'lg:text-center']" for="admin-middle-initial">M.I.</label>
                            <input
                                id="admin-middle-initial"
                                :value="form.middleInitial"
                                type="text"
                                inputmode="text"
                                maxlength="1"
                                pattern="[A-Za-z]"
                                required
                                placeholder="M"
                                :class="compactInputClass"
                                @input="handleMiddleInitialInput"
                            >
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-last-name">Last name</label>
                            <input id="admin-last-name" v-model="form.lastName" type="text" autocomplete="family-name" required placeholder="Last name" :class="inputClass">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label :class="labelClass" for="admin-email">Email address</label>
                            <input id="admin-email" v-model="form.email" type="email" autocomplete="email" required placeholder="Email address" :class="inputClass">
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-username">Username</label>
                            <input id="admin-username" v-model="form.username" type="text" autocomplete="username" pattern="[A-Za-z0-9_.-]{4,}" required placeholder="Username" :class="inputClass">
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-contact-number">Contact number</label>
                            <input
                                id="admin-contact-number"
                                :value="form.contactNumber"
                                type="tel"
                                inputmode="numeric"
                                autocomplete="tel"
                                required
                                placeholder="Contact number"
                                :class="inputClass"
                                @input="(event) => { event.target.setCustomValidity(''); handleNumberInput(event); }"
                            >
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-role">Role</label>
                            <select id="admin-role" v-model="form.role" required :class="inputClass">
                                <option value="applicant">Applicant</option>
                                <option value="provider">Provider</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label :class="labelClass" for="admin-password">
                                {{ isEditMode ? 'New password' : 'Password' }}
                            </label>
                            <input
                                id="admin-password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                minlength="8"
                                :required="!isEditMode"
                                :placeholder="isEditMode ? 'Leave blank to keep current password' : 'Password'"
                                :class="inputClass"
                            >
                        </div>

                        <div>
                            <label :class="labelClass" for="admin-password-confirmation">Confirm password</label>
                            <input
                                id="admin-password-confirmation"
                                v-model="form.passwordConfirmation"
                                type="password"
                                autocomplete="new-password"
                                minlength="8"
                                :required="!isEditMode || Boolean(form.password)"
                                placeholder="Confirm password"
                                :class="inputClass"
                                @input="$event.target.setCustomValidity('')"
                            >
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-h-5">
                            <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">
                                {{ statusMessage }}
                            </p>
                            <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">
                                {{ errorMessage }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button
                                v-if="!isEditMode"
                                type="button"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                                @click="resetForm"
                            >
                                Clear
                            </button>
                            <button
                                type="submit"
                                :disabled="isSaving"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                            >
                                {{ isSaving ? 'Saving...' : isEditMode ? 'Update account' : 'Create account' }}
                            </button>
                        </div>
                    </div>
                </form>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
