<script setup>
import { onMounted, reactive, ref } from 'vue';
import CoverImagePickerModal from '../../../components/CoverImagePickerModal.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const isUploadingLogo = ref(false);
const isUpdatingCover = ref(false);
const errorMessage = ref('');
const errors = ref({});
const profile = ref(null);
const logoInput = ref(null);
const coverPickerOpen = ref(false);
const coverImages = ref([]);
const form = reactive({
    provider_name: '',
    provider_type: '',
    provider_website: '',
    provider_description: '',
    provider_mission: '',
    provider_year_established: '',
});

const fieldClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none focus:border-slate-950';
const labelClass = 'text-sm font-bold text-slate-800';

function applyProfile(data) {
    profile.value = data;
    const values = data?.values ?? {};
    for (const key of Object.keys(form)) form[key] = values[key] ?? '';
}

function firstError(field) {
    return errors.value?.[field]?.[0] ?? '';
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/organization-profile/data');
        applyProfile(response.data.profile);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The public profile could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function loadCoverOptions() {
    try {
        const response = await window.axios.get('/provider/cover-images');
        coverImages.value = response.data.images ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Background choices could not be loaded.';
    }
}

async function saveProfile() {
    isSaving.value = true;
    errors.value = {};
    errorMessage.value = '';
    try {
        await window.axios.patch('/provider/workspaces/organization-profile/public', form);
        await loadProfile();
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message || 'The public profile could not be saved.';
    } finally {
        isSaving.value = false;
    }
}

async function uploadLogo(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    isUploadingLogo.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('logo_file', file);
    try {
        await window.axios.post('/provider/profile/logo', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
        await loadProfile();
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The organization logo could not be uploaded.';
    } finally {
        isUploadingLogo.value = false;
        if (logoInput.value) logoInput.value.value = '';
    }
}

async function updateCover(source, { key = '', file = null } = {}) {
    isUpdatingCover.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('cover_source', source);
    if (key) payload.append('cover_key', key);
    if (file) payload.append('cover_file', file);

    try {
        const response = await window.axios.post('/provider/profile/cover', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const cover = response.data.cover;
        profile.value = {
            ...profile.value,
            cover_image_source: cover.source,
            cover_image_key: cover.key,
            cover_image_url: cover.url,
        };
        coverPickerOpen.value = false;
    } catch (error) {
        const validationMessage = Object.values(error.response?.data?.errors ?? {}).flat()[0];
        errorMessage.value = validationMessage || error.response?.data?.message || 'The organization background could not be updated.';
    } finally {
        isUpdatingCover.value = false;
    }
}

onMounted(() => Promise.all([loadProfile(), loadCoverOptions()]));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="public-profile-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-building" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Organization profile</p><h1 id="public-profile-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Public profile</h1><p class="mt-1 text-sm leading-6 text-slate-600">Control the organization identity applicants see across scholarship pages.</p></div></div>
                        <div class="flex items-center gap-3">
                            <a href="/provider/previews/organization" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i>Preview as applicant</a>
                            <button type="submit" form="public-profile-form" :disabled="isLoading || isSaving" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"><i :class="['fa-solid text-amber-300', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Save profile' }}</button>
                        </div>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading public profile...</p></div>

                <form v-else id="public-profile-form" class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="saveProfile">
                    <section class="border-b border-slate-200 px-6 py-6" aria-labelledby="public-identity-title">
                        <div class="flex items-center gap-5">
                            <img v-if="profile?.logo_url" :src="profile.logo_url" :alt="profile.name" class="h-20 w-20 border border-slate-200 bg-white object-contain p-2">
                            <span v-else class="grid h-20 w-20 place-items-center bg-slate-100 text-2xl text-slate-400"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
                            <div class="flex-1"><h2 id="public-identity-title" class="text-lg font-bold text-slate-950">Organization identity</h2><p class="mt-1 text-sm text-slate-500">Use a clear logo and the registered public-facing name.</p></div>
                            <input ref="logoInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="uploadLogo">
                            <button type="button" :disabled="isUploadingLogo" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="logoInput?.click()"><i :class="['fa-solid text-[10px]', isUploadingLogo ? 'fa-spinner fa-spin' : 'fa-arrow-up-from-bracket']" aria-hidden="true"></i>{{ isUploadingLogo ? 'Uploading...' : 'Replace logo' }}</button>
                        </div>

                        <div class="mt-6 border-t border-slate-200 pt-6">
                            <div class="flex items-end justify-between gap-6">
                                <div><h3 class="text-sm font-bold text-slate-950">Organization background</h3><p class="mt-1 text-xs leading-5 text-slate-500">Shown on the public provider page. This is separate from the official logo.</p></div>
                                <button type="button" :disabled="isUpdatingCover" class="inline-flex h-10 shrink-0 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="coverPickerOpen = true"><i class="fa-regular fa-image text-xs" aria-hidden="true"></i>{{ isUpdatingCover ? 'Updating...' : 'Choose background' }}</button>
                            </div>
                            <figure class="relative mt-4 aspect-[4/1] min-h-40 overflow-hidden bg-slate-100">
                                <img :src="profile?.cover_image_url" alt="Organization background preview" class="absolute inset-0 h-full w-full object-cover">
                                <div class="absolute inset-0 bg-gradient-to-r from-slate-950/55 via-slate-950/10 to-transparent"></div>
                                <figcaption class="absolute bottom-4 left-4 text-sm font-bold text-white">{{ profile?.name }}</figcaption>
                            </figure>
                        </div>

                        <div class="mt-6 grid grid-cols-3 gap-5">
                            <label class="col-span-2"><span :class="labelClass">Organization name</span><input v-model="form.provider_name" type="text" :class="fieldClass"><span v-if="firstError('provider_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_name') }}</span></label>
                            <label><span :class="labelClass">Organization type</span><select v-model="form.provider_type" :class="fieldClass"><option value="">Select type</option><option value="foundation">Foundation</option><option value="non_profit">Non-profit organization</option><option value="school">School</option><option value="government">Government</option><option value="company">Company</option><option value="other">Other</option></select><span v-if="firstError('provider_type')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_type') }}</span></label>
                            <label class="col-span-2"><span :class="labelClass">Website <span class="font-normal text-slate-400">(optional)</span></span><input v-model="form.provider_website" type="text" :class="fieldClass" placeholder="https://example.org"></label>
                            <label><span :class="labelClass">Year established <span class="font-normal text-slate-400">(optional)</span></span><input v-model="form.provider_year_established" type="number" min="1800" :max="new Date().getFullYear()" :class="fieldClass"></label>
                        </div>
                    </section>

                    <section class="px-6 py-6" aria-labelledby="organization-story-title">
                        <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-align-left" aria-hidden="true"></i></span><div><h2 id="organization-story-title" class="text-lg font-bold text-slate-950">About the organization</h2><p class="mt-1 text-sm text-slate-500">Keep this concise so applicants can understand the provider quickly.</p></div></div>
                        <label class="mt-5 block"><span :class="labelClass">Public description</span><textarea v-model="form.provider_description" rows="4" class="mt-2 w-full border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none focus:border-slate-950" placeholder="Describe who you support and what the organization does."></textarea><div class="mt-1 flex justify-between text-xs"><span v-if="firstError('provider_description')" class="font-semibold text-rose-700">{{ firstError('provider_description') }}</span><span v-else></span><span class="text-slate-400">{{ form.provider_description.length }}/1500</span></div></label>
                        <label class="mt-5 block"><span :class="labelClass">Mission <span class="font-normal text-slate-400">(optional)</span></span><textarea v-model="form.provider_mission" rows="3" class="mt-2 w-full border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none focus:border-slate-950" placeholder="State the organization mission in one or two sentences."></textarea></label>
                    </section>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-xs font-semibold text-slate-500">Changes to verified identity details may require another administrator review.</p><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Save public profile' }}</button></footer>
                </form>
            </div>
        </section>

        <CoverImagePickerModal
            :open="coverPickerOpen"
            title="Choose an organization background"
            description="Use a prepared system image or upload a wide image owned by your organization."
            :images="coverImages"
            :selected-source="profile?.cover_image_source || 'default'"
            :selected-key="profile?.cover_image_key || ''"
            :busy="isUpdatingCover"
            @close="coverPickerOpen = false"
            @select-system="updateCover('system', { key: $event.key })"
            @upload="updateCover('upload', { file: $event })"
            @reset="updateCover('default')"
        />
    </main>
</template>
