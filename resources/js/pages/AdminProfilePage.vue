<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
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
    validationErrors.value = {};

    try {
        const response = await window.axios.patch('/admin/profile', { ...form });

        applyUser(response.data.user);
    } catch (error) {
        validationErrors.value = error.response?.data?.errors ?? {};
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProfile);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="profile" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header class="admin-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Admin Profile
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Account and identity
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Manage your administrator identity, contact details, and portal credentials.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading admin profile...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 shadow-sm">
                        <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                            <div class="flex min-w-0 items-center gap-4">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-amber-300 text-lg font-black text-slate-950">
                                    {{ adminInitials }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Administrator account</p>
                                    <h3 class="mt-1 truncate font-display text-2xl font-bold text-white">
                                        {{ user?.display_name || user?.name || 'Admin' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-300">System oversight and platform administration</p>
                                </div>
                            </div>
                            <span class="w-fit shrink-0 rounded-md bg-white/10 px-3 py-1.5 text-xs font-bold uppercase text-white ring-1 ring-white/15">
                                Admin
                            </span>
                        </div>
                        <div class="grid border-t border-white/10 bg-white/[0.04] sm:grid-cols-3 sm:divide-x sm:divide-white/10">
                            <div class="flex items-start gap-3 p-4">
                                <i class="fa-solid fa-at mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Username</p>
                                    <p class="mt-1 truncate text-sm font-semibold text-white">{{ user?.username || 'Not set' }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 border-t border-white/10 p-4 sm:border-t-0">
                                <i class="fa-solid fa-envelope mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Email</p>
                                    <p class="mt-1 truncate text-sm font-semibold text-white">{{ user?.email || 'Not set' }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 border-t border-white/10 p-4 sm:border-t-0">
                                <i class="fa-solid fa-phone mt-0.5 text-amber-300" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Contact</p>
                                    <p class="mt-1 truncate text-sm font-semibold text-white">{{ user?.contact_number || 'Not set' }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <form class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" @submit.prevent="saveProfile">
                        <div class="flex items-center gap-3 p-5 sm:p-6">
                            <span class="grid h-10 w-10 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-user-gear" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="font-bold text-slate-950">Profile details</p>
                                <p class="mt-0.5 text-sm text-slate-500">Information used to identify your administrator account.</p>
                            </div>
                        </div>

                        <section class="grid gap-5 border-t border-slate-200 p-5 sm:p-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Personal identity</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Your name and the label shown across the admin portal.</p>
                            </div>
                            <div>
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

                        <section class="grid gap-5 border-t border-slate-200 p-5 sm:p-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                            <div>
                                <p class="text-sm font-bold text-slate-950">Account and contact</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Credentials and contact details for this account.</p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
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
                                    <input v-model="form.contact_number" type="text" placeholder="0917 000 0000" :class="inputClass">
                                    <span v-if="fieldError('contact_number')" class="mt-1 block text-xs font-semibold text-rose-600">{{ fieldError('contact_number') }}</span>
                                </label>
                            </div>
                        </section>

                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <p class="text-xs leading-5 text-slate-500">Password changes are handled through account management.</p>
                            <button type="submit" :disabled="isSaving" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70">
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
