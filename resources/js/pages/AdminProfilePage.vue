<script setup>
import { onMounted, reactive, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
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
    statusMessage.value = '';

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
    statusMessage.value = '';
    validationErrors.value = {};

    try {
        const response = await window.axios.patch('/admin/profile', { ...form });

        applyUser(response.data.user);
        statusMessage.value = response.data.message ?? 'Admin profile updated.';
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message ?? 'Unable to update admin profile.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProfile);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="profile" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="admin-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Admin Profile
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Edit your account details
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Keep your admin identity and contact details updated without opening user management.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading admin profile...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </p>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">
                                    Signed in as
                                </p>
                                <h3 class="mt-2 font-display text-2xl font-bold text-slate-950">
                                    {{ user?.name || 'Admin' }}
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ user?.email }}
                                </p>
                            </div>
                            <span class="w-fit rounded-md bg-amber-100 px-3 py-1.5 text-xs font-bold uppercase text-amber-800">
                                Admin
                            </span>
                        </div>
                    </section>

                    <form class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveProfile">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Profile Details
                        </p>

                        <div class="mt-5 grid gap-4 md:grid-cols-[1fr_5rem_1fr]">
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

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <label>
                                <span :class="labelClass">Display name</span>
                                <input v-model="form.display_name" type="text" placeholder="Scholarship Admin" :class="inputClass">
                                <span v-if="fieldError('display_name')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('display_name') }}</span>
                            </label>
                            <label>
                                <span :class="labelClass">Contact number</span>
                                <input v-model="form.contact_number" type="text" placeholder="0917 000 0000" :class="inputClass">
                                <span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('contact_number') }}</span>
                            </label>
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
                        </div>

                        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs leading-5 text-slate-500">
                                Password changes are still handled through account management.
                            </p>
                            <button
                                type="submit"
                                :disabled="isSaving"
                                class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                {{ isSaving ? 'Saving...' : 'Save profile' }}
                            </button>
                        </div>
                    </form>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
