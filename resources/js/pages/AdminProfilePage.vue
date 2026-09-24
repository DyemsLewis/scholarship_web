<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { limitPhoneNumber } from '../support/phoneNumber';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const validationErrors = ref({});
const user = ref(null);
const form = reactive({
    first_name: '',
    last_name: '',
    middle_initial: '',
    display_name: '',
    email: '',
    username: '',
    contact_number: '',
});

const labelClass = 'text-xs font-bold uppercase tracking-[0.14em] text-slate-500';
const inputClass = 'mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const adminInitials = computed(() => {
    const name = user.value?.display_name || user.value?.name || 'Admin';

    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
});

function applyUser(payload) {
    user.value = payload;
    form.first_name = payload?.first_name ?? '';
    form.last_name = payload?.last_name ?? '';
    form.middle_initial = payload?.middle_initial ?? '';
    form.display_name = payload?.display_name ?? payload?.name ?? '';
    form.email = payload?.email ?? '';
    form.username = payload?.username ?? '';
    form.contact_number = payload?.contact_number ?? '';
}

function fieldError(field) {
    return validationErrors.value?.[field]?.[0] ?? '';
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/profile/data');

        applyUser(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load admin profile.';
    } finally {
        isLoading.value = false;
    }
}

async function saveProfile() {
    isSaving.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    validationErrors.value = {};

    try {
        const response = await window.axios.patch('/admin/profile', { ...form });

        applyUser(response.data.user);
        successMessage.value = 'Profile changes saved.';
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message ?? (Object.keys(validationErrors.value).length ? '' : 'Unable to save profile changes.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProfile);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="profile" />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Admin profile"
                    title="Account and identity"
                    description="Update your contact details and administrator credentials."
                    icon="fa-solid fa-id-badge"
                >
                    <template #meta>
                        <span>{{ user?.username || 'Username not set' }}</span>
                        <span>{{ user?.email || 'Email not set' }}</span>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">
                    Loading admin profile...
                </div>

                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm">
                        {{ successMessage }}
                    </p>

                    <form class="admin-panel overflow-hidden" @submit.prevent="saveProfile">
                        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-black text-amber-300">{{ adminInitials }}</span>
                                <div class="min-w-0">
                                    <p class="truncate text-lg font-black text-slate-950">{{ user?.display_name || user?.name || 'Admin' }}</p>
                                    <p class="mt-0.5 text-sm text-slate-500">Administrator account</p>
                                </div>
                            </div>
                            <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase text-slate-700">Admin</span>
                        </div>

                        <section class="border-t border-slate-200 p-5 sm:p-6">
                            <div class="mb-4">
                                <h2 class="text-sm font-black text-slate-950">Personal identity</h2>
                                <p class="mt-1 text-xs text-slate-500">Name displayed across the admin portal.</p>
                            </div>
                            <div class="max-w-4xl">
                                <div class="grid gap-4 md:grid-cols-[1fr_5rem_1fr]">
                                    <label>
                                        <span :class="labelClass">First name</span>
                                        <input v-model="form.first_name" type="text" placeholder="First name" :class="inputClass">
                                        <span v-if="fieldError('first_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('first_name') }}</span>
                                    </label>
                                    <label>
                                        <span :class="labelClass">M.I.</span>
                                        <input v-model="form.middle_initial" maxlength="1" type="text" placeholder="A" :class="inputClass">
                                        <span v-if="fieldError('middle_initial')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('middle_initial') }}</span>
                                    </label>
                                    <label>
                                        <span :class="labelClass">Last name</span>
                                        <input v-model="form.last_name" type="text" placeholder="Last name" :class="inputClass">
                                        <span v-if="fieldError('last_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('last_name') }}</span>
                                    </label>
                                </div>
                                <label class="mt-4 block">
                                    <span :class="labelClass">Display name</span>
                                    <input v-model="form.display_name" type="text" placeholder="Scholarship Admin" :class="inputClass">
                                    <span v-if="fieldError('display_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('display_name') }}</span>
                                </label>
                            </div>
                        </section>

                        <section class="border-t border-slate-200 p-5 sm:p-6">
                            <div class="mb-4">
                                <h2 class="text-sm font-black text-slate-950">Account and contact</h2>
                                <p class="mt-1 text-xs text-slate-500">Sign-in identity and contact number.</p>
                            </div>
                            <div class="grid max-w-4xl gap-4 md:grid-cols-2">
                                <label>
                                    <span :class="labelClass">Email</span>
                                    <input v-model="form.email" type="email" placeholder="admin@example.com" :class="inputClass">
                                    <span v-if="fieldError('email')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('email') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Username</span>
                                    <input v-model="form.username" type="text" placeholder="admin" :class="inputClass">
                                    <span v-if="fieldError('username')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('username') }}</span>
                                </label>
                                <label>
                                    <span :class="labelClass">Contact number</span>
                                    <input :value="form.contact_number" type="tel" inputmode="tel" maxlength="20" placeholder="0917 000 0000" :class="inputClass" @input="form.contact_number = limitPhoneNumber($event.target.value)">
                                    <span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('contact_number') }}</span>
                                </label>
                            </div>
                        </section>

                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="text-xs leading-5 text-slate-500">Only this administrator account is updated.</p>
                            <button type="submit" :disabled="isSaving" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70">
                                {{ isSaving ? 'Saving...' : 'Save profile' }}
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </section>
    </main>
</template>
