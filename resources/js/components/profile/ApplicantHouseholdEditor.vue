<script setup>
import { disclosureSummary, inputClass, labelClass, optionalLabel, sectionTitle } from '../../support/profileEditorStyles';
import { labelFromKey } from '../../support/display';

const form = defineModel({ type: Object, required: true });
defineProps({
    incomeOptions: { type: Array, required: true },
    relationshipOptions: { type: Array, required: true },
    scholarshipOptions: { type: Array, required: true },
    portalScholarships: { type: Array, default: () => [] },
    guardianRequired: { type: Boolean, default: false },
    hasGuardianDetails: { type: Boolean, default: false },
    guardianReason: { type: String, default: '' },
});
defineEmits(['guardian-phone-input']);
</script>

<template>
    <div id="profile-household" data-household-editor class="divide-y divide-slate-200 px-4 sm:px-6">
        <section class="py-4" aria-labelledby="household-eligibility-title">
            <h3 id="household-eligibility-title" :class="sectionTitle">Household eligibility</h3>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0"><label :class="labelClass" for="profile-income">Household income bracket</label><select id="profile-income" v-model="form.income_bracket" required :class="inputClass"><option value="">Select income bracket</option><option v-for="option in incomeOptions" :key="option" :value="option">{{ option }}</option></select></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-household-size">Household size</label><input id="profile-household-size" v-model="form.household_size" type="number" min="1" max="30" required placeholder="Number of people" :class="inputClass"></div>
            </div>
        </section>

        <component :is="guardianRequired ? 'section' : 'details'" data-guardian-section class="group py-4" :open="!guardianRequired && hasGuardianDetails ? true : undefined" aria-labelledby="household-guardian-title">
            <component :is="guardianRequired ? 'h3' : 'summary'" id="household-guardian-title" :class="guardianRequired ? sectionTitle : disclosureSummary">
                <span>Guardian or account contact <span v-if="!guardianRequired" :class="optionalLabel">(optional)</span></span>
                <i v-if="!guardianRequired" class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </component>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ guardianRequired ? guardianReason : 'Add a trusted contact if someone helps with applications.' }}</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0"><label :class="labelClass" for="profile-guardian">Guardian name</label><input id="profile-guardian" v-model="form.guardian_name" :required="guardianRequired" maxlength="255" placeholder="Parent or guardian" :class="inputClass"></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-guardian-relationship">Relationship to applicant</label><select id="profile-guardian-relationship" v-model="form.guardian_relationship" :required="guardianRequired" :class="inputClass"><option value="">Select relationship</option><option v-if="form.guardian_relationship && !relationshipOptions.includes(form.guardian_relationship)" :value="form.guardian_relationship">{{ labelFromKey(form.guardian_relationship) }}</option><option v-for="option in relationshipOptions" :key="option" :value="option">{{ option }}</option></select></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-guardian-contact">Guardian contact number</label><input id="profile-guardian-contact" :value="form.guardian_contact" type="tel" inputmode="tel" maxlength="20" :required="guardianRequired" placeholder="09XX XXX XXXX" :class="inputClass" @input="$emit('guardian-phone-input', $event)"></div>
                <div class="min-w-0"><label :class="labelClass" for="profile-guardian-email">Guardian email</label><input id="profile-guardian-email" v-model="form.guardian_email" type="email" :required="guardianRequired" maxlength="255" placeholder="guardian@example.com" :class="inputClass"></div>
            </div>
            <label class="mt-4 flex cursor-pointer items-start gap-2.5 text-xs font-semibold leading-5 text-slate-700"><input id="profile-guardian-account-owner" v-model="form.guardian_is_account_owner" type="checkbox" class="mt-0.5 h-4 w-4 rounded-sm border-slate-300 accent-slate-950">Guardian signs in and manages this account</label>
        </component>

        <section class="py-4" aria-labelledby="household-scholarship-title">
            <h3 id="household-scholarship-title" :class="sectionTitle">Other scholarship support</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Only declare scholarships outside this portal. Portal awards are checked automatically.</p>
            <div class="mt-4">
                <label :class="labelClass" for="profile-current-scholarship">Outside scholarship status</label>
                <select id="profile-current-scholarship" v-model="form.current_scholarship_status" required :class="inputClass"><option value="">Select an option</option><option v-for="option in scholarshipOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
            </div>
            <div v-if="['receiving', 'pending'].includes(form.current_scholarship_status)" class="mt-4">
                <label :class="labelClass" for="profile-current-scholarship-details">Outside provider and program</label>
                <textarea id="profile-current-scholarship-details" v-model="form.current_scholarship_details" required rows="2" maxlength="1000" placeholder="Provider and program name" :class="inputClass"></textarea>
            </div>
            <details v-if="portalScholarships.length" data-portal-scholarships class="mt-3">
                <summary class="cursor-pointer text-xs font-semibold leading-5 text-slate-600">{{ portalScholarships.length }} {{ portalScholarships.length === 1 ? 'award' : 'awards' }} recorded in this portal</summary>
                <ul class="mt-2 space-y-1 border-l-2 border-slate-200 pl-3 text-xs leading-5 text-slate-600"><li v-for="record in portalScholarships" :key="record.application_id">{{ record.title }}</li></ul>
            </details>
            <p v-else class="mt-3 text-xs leading-5 text-slate-500">No active award is recorded in this portal.</p>
            <p class="mt-4 text-xs leading-5 text-slate-500">Household details are shared only for eligibility review.</p>
        </section>
    </div>
</template>
