<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const form = ref(emptyForm());

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';
const compactInputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-center text-sm uppercase text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100';

const profileFields = computed(() => [
    { label: 'First name', value: user.value?.first_name },
    { label: 'Last name', value: user.value?.last_name },
    { label: 'Middle initial', value: user.value?.middle_initial },
    { label: 'Contact number', value: user.value?.contact_number },
    { label: 'School', value: user.value?.school },
    { label: 'Course / strand', value: user.value?.course_or_strand },
    { label: 'Year level', value: user.value?.year_level },
    { label: 'GWA / average', value: user.value?.gwa },
    { label: 'Address', value: user.value?.address },
    { label: 'Birthdate', value: user.value?.birthdate },
    { label: 'Guardian name', value: user.value?.guardian_name },
    { label: 'Guardian contact', value: user.value?.guardian_contact },
]);
const completedProfileFields = computed(() => profileFields.value.filter((field) => Boolean(field.value)).length);
const profileCompletion = computed(() => Math.round((completedProfileFields.value / profileFields.value.length) * 100));

function emptyForm() {
    return {
        first_name: '',
        last_name: '',
        middle_initial: '',
        contact_number: '',
        school: '',
        course_or_strand: '',
        year_level: '',
        gwa: '',
        address: '',
        birthdate: '',
        guardian_name: '',
        guardian_contact: '',
    };
}

function fillForm(payload) {
    form.value = {
        first_name: payload?.first_name ?? '',
        last_name: payload?.last_name ?? '',
        middle_initial: payload?.middle_initial ?? '',
        contact_number: payload?.contact_number ?? '',
        school: payload?.school ?? '',
        course_or_strand: payload?.course_or_strand ?? '',
        year_level: payload?.year_level ?? '',
        gwa: payload?.gwa ?? '',
        address: payload?.address ?? '',
        birthdate: payload?.birthdate ?? '',
        guardian_name: payload?.guardian_name ?? '',
        guardian_contact: payload?.guardian_contact ?? '',
    };
}

function handleMiddleInitialInput(event) {
    form.value.middle_initial = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
}

function handlePhoneInput(key, event) {
    form.value[key] = event.target.value.replace(/[^\d+\s().-]/g, '');
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        fillForm(response.data.user);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant profile.';
    } finally {
        isLoading.value = false;
    }
}

async function saveProfile() {
    isSaving.value = true;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch('/dashboard/profile', form.value);

        user.value = response.data.user;
        fillForm(response.data.user);
        statusMessage.value = response.data.message ?? 'Profile updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update applicant profile.';
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
        <ApplicantSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-end">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Applicant Profile
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Complete your scholarship profile
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Providers use these details when reviewing eligibility, documents, and award decisions.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Profile Readiness
                            </p>
                            <div class="mt-3 flex items-end justify-between gap-4">
                                <p class="font-display text-4xl font-bold text-slate-950">
                                    {{ profileCompletion }}%
                                </p>
                                <p class="pb-1 text-sm text-slate-500">
                                    {{ completedProfileFields }}/{{ profileFields.length }} details complete
                                </p>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full bg-amber-500 transition-all"
                                    :style="{ width: `${profileCompletion}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading profile...
                </div>

                <div v-else class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Editable Details
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Student record
                        </h3>

                        <form class="mt-5 grid gap-4" @submit.prevent="saveProfile">
                            <div class="grid gap-4 md:grid-cols-[1fr_5rem_1fr] md:items-end">
                                <div>
                                    <label :class="labelClass" for="profile-first-name">First name</label>
                                    <input id="profile-first-name" v-model="form.first_name" required :class="inputClass">
                                </div>
                                <div>
                                    <label :class="[labelClass, 'md:text-center']" for="profile-middle">M.I.</label>
                                    <input
                                        id="profile-middle"
                                        :value="form.middle_initial"
                                        maxlength="1"
                                        required
                                        :class="compactInputClass"
                                        @input="handleMiddleInitialInput"
                                    >
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-last-name">Last name</label>
                                    <input id="profile-last-name" v-model="form.last_name" required :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-contact">Contact number</label>
                                    <input id="profile-contact" :value="form.contact_number" required :class="inputClass" @input="handlePhoneInput('contact_number', $event)">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-gwa">GWA / average</label>
                                    <input id="profile-gwa" v-model="form.gwa" type="number" min="0" max="100" step="0.01" placeholder="Example: 92.50" :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="profile-school">School</label>
                                    <input id="profile-school" v-model="form.school" placeholder="School name" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-course">Course / strand</label>
                                    <input id="profile-course" v-model="form.course_or_strand" placeholder="BSIT, STEM, ABM..." :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-year">Year level</label>
                                    <input id="profile-year" v-model="form.year_level" placeholder="1st year / Grade 12" :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-birthdate">Birthdate</label>
                                    <input id="profile-birthdate" v-model="form.birthdate" type="date" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-address">Address</label>
                                    <input id="profile-address" v-model="form.address" placeholder="Home address" :class="inputClass">
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="profile-guardian">Guardian name</label>
                                    <input id="profile-guardian" v-model="form.guardian_name" placeholder="Parent or guardian" :class="inputClass">
                                </div>
                                <div>
                                    <label :class="labelClass" for="profile-guardian-contact">Guardian contact</label>
                                    <input id="profile-guardian-contact" :value="form.guardian_contact" placeholder="Guardian contact number" :class="inputClass" @input="handlePhoneInput('guardian_contact', $event)">
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p v-if="statusMessage" class="text-sm font-semibold text-emerald-700">{{ statusMessage }}</p>
                                    <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="isSaving"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {{ isSaving ? 'Saving profile...' : 'Save profile' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Readiness Checklist
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Completed details
                        </h3>

                        <div class="mt-5 grid gap-3">
                            <div
                                v-for="field in profileFields"
                                :key="field.label"
                                class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"
                            >
                                <span class="font-semibold text-slate-500">
                                    {{ field.label }}
                                </span>
                                <span :class="field.value ? 'font-bold text-slate-950' : 'font-semibold text-rose-600'">
                                    {{ field.value || 'Missing' }}
                                </span>
                            </div>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
