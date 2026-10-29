<script setup>
import { computed, ref, watch } from 'vue';
import { inputClass, labelClass, secondaryButton } from '../../support/profileEditorStyles';

const form = defineModel({ type: Object, required: true });
const props = defineProps({
    photoUrl: { type: String, default: '' },
    applicantName: { type: String, default: 'Applicant' },
    photoReviewStatus: { type: String, default: '' },
    photoReviewNote: { type: String, default: '' },
    uploadingPhoto: { type: Boolean, default: false },
    deletingPhoto: { type: Boolean, default: false },
    age: { type: Number, default: null },
    isMinor: { type: Boolean, default: false },
    birthdateMin: { type: String, required: true },
    birthdateMax: { type: String, required: true },
    genderOptions: { type: Array, required: true },
    citizenshipOptions: { type: Array, required: true },
    accountManagerOptions: { type: Array, required: true },
    suffixOptions: { type: Array, required: true },
});
defineEmits(['upload-photo', 'open-camera', 'remove-photo', 'middle-initial-input', 'phone-input']);

const photoActionsOpen = ref(false);
const photoUnavailable = ref(false);
const replacementNeeded = computed(() => props.photoReviewStatus === 'needs_replacement');
const photoActionsVisible = computed(() => photoActionsOpen.value || !props.photoUrl || replacementNeeded.value);
const photoBusy = computed(() => props.uploadingPhoto || props.deletingPhoto);
watch(() => props.photoUrl, () => { photoUnavailable.value = false; });

</script>

<template>
    <div data-personal-editor class="divide-y divide-slate-200 px-4 sm:px-6">
        <section class="py-4" aria-labelledby="personal-photo-title">
            <div data-personal-photo-summary class="flex items-center gap-3 sm:gap-4">
                <div class="h-16 w-16 shrink-0 overflow-hidden rounded-sm border border-slate-200 bg-slate-100">
                    <img v-if="photoUrl && !photoUnavailable" :src="photoUrl" :alt="`${applicantName} applicant photo`" class="h-full w-full object-cover" @error="photoUnavailable = true">
                    <img v-else :src="'/images/demo/default-applicant-avatar.jpg'" alt="No applicant photo available" class="h-full w-full object-cover">
                </div>
                <div class="min-w-0 flex-1">
                    <h3 id="personal-photo-title" class="text-sm font-bold text-slate-950">Applicant photo</h3>
                    <p :class="['mt-1 text-xs leading-5', replacementNeeded || !photoUrl ? 'font-semibold text-amber-800' : 'text-slate-500']">
                        {{ replacementNeeded ? 'Replacement requested' : photoUrl ? 'Photo saved' : 'Required for your profile' }}
                    </p>
                </div>
                <button v-if="photoUrl && !replacementNeeded" type="button" data-photo-controls-toggle :class="secondaryButton" :aria-expanded="photoActionsVisible" aria-controls="personal-photo-controls" :disabled="photoBusy" @click="photoActionsOpen = !photoActionsOpen">
                    {{ photoActionsVisible ? 'Done' : 'Change photo' }}
                </button>
            </div>

            <div v-if="['needs_replacement', 'resubmitted'].includes(photoReviewStatus)" class="mt-3 border-l-2 border-amber-400 pl-3 text-xs leading-5 text-slate-700" role="status">
                <p class="font-semibold">{{ replacementNeeded ? 'Upload a clearer photo for review.' : 'Your replacement photo is awaiting review.' }}</p>
                <p v-if="photoReviewNote" class="mt-1">{{ photoReviewNote }}</p>
            </div>

            <div v-if="photoActionsVisible" id="personal-photo-controls" data-photo-controls class="mt-4">
                <div class="flex flex-wrap items-center gap-2">
                    <label :class="[secondaryButton, 'relative overflow-hidden focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-amber-600', photoBusy ? 'opacity-50' : 'cursor-pointer']">
                        <input type="file" accept="image/jpeg,image/png" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Upload applicant photo" :disabled="photoBusy" @change="$emit('upload-photo', $event)">
                        <i :class="uploadingPhoto ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-arrow-up-from-bracket'" aria-hidden="true"></i>
                        {{ uploadingPhoto ? 'Uploading...' : 'Choose photo' }}
                    </label>
                    <button type="button" :class="secondaryButton" :disabled="photoBusy" @click="$emit('open-camera')"><i class="fa-solid fa-camera text-xs" aria-hidden="true"></i>Open camera</button>
                    <button v-if="photoUrl" type="button" class="inline-flex min-h-9 items-center gap-2 rounded-sm px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-50" :disabled="photoBusy" @click="$emit('remove-photo')"><i :class="deletingPhoto ? 'fa-solid fa-spinner fa-spin' : 'fa-regular fa-trash-can'" aria-hidden="true"></i>Remove photo</button>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">Recent square JPG or PNG, 300 to 4000 pixels, up to 5 MB. Photo changes save immediately.</p>
            </div>
        </section>

        <section class="py-4" aria-labelledby="personal-name-title">
            <h3 id="personal-name-title" class="text-sm font-bold text-slate-950">Official name</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Use the name on your school records.</p>
            <div class="mt-4 grid grid-cols-2 items-start gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_7rem_8rem]">
                <div class="min-w-0"><label :class="labelClass" for="profile-first-name">First name</label><input id="profile-first-name" v-model="form.first_name" autocomplete="given-name" required :class="inputClass"></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-last-name">Last name</label><input id="profile-last-name" v-model="form.last_name" autocomplete="family-name" required :class="inputClass"></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-middle">M.I. <span class="font-normal text-slate-400">(optional)</span></label><input id="profile-middle" :value="form.middle_initial" maxlength="1" aria-label="Middle initial (optional)" :class="[inputClass, 'uppercase']" @input="$emit('middle-initial-input', $event)"></div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-suffix">Suffix <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="profile-suffix" v-model="form.suffix" :class="inputClass"><option value="">None</option><option v-if="form.suffix && !suffixOptions.includes(form.suffix)" :value="form.suffix">{{ form.suffix }}</option><option v-for="option in suffixOptions" :key="option" :value="option">{{ option }}</option></select>
                </div>
            </div>
        </section>

        <section class="py-4" aria-labelledby="personal-identity-title">
            <h3 id="personal-identity-title" class="text-sm font-bold text-slate-950">Personal details</h3>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-3">
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-birthdate">Date of birth</label><input id="profile-birthdate" v-model="form.birthdate" type="date" :min="birthdateMin" :max="birthdateMax" required :class="inputClass">
                    <p v-if="age !== null" class="mt-1 text-xs leading-5 text-slate-500">{{ age }} years old<span v-if="isMinor">. Guardian details required.</span></p>
                </div>
                <div class="min-w-0"><label :class="labelClass" for="profile-gender">Gender <span class="font-normal text-slate-400">(optional)</span></label><select id="profile-gender" v-model="form.gender" :class="inputClass"><option value="">Select gender</option><option v-for="option in genderOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-citizenship">Citizenship</label><select id="profile-citizenship" v-model="form.citizenship_status" required :class="inputClass"><option value="">Select citizenship</option><option v-for="option in citizenshipOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
            </div>
        </section>

        <section class="py-4" aria-labelledby="personal-contact-title">
            <h3 id="personal-contact-title" class="text-sm font-bold text-slate-950">Contact and account</h3>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0"><label :class="labelClass" for="profile-contact">Contact number</label><input id="profile-contact" :value="form.contact_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="09XX XXX XXXX" required :class="inputClass" @input="$emit('phone-input', $event)"></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-account-manager">Account managed by</label><select id="profile-account-manager" v-model="form.account_managed_by" required :class="inputClass"><option value="">Select account manager</option><option v-for="option in accountManagerOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">These details are shared only with authorized reviewers.</p>
        </section>
    </div>
</template>
