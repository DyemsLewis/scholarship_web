<script setup>
import { computed, ref } from 'vue';
import { completedQualificationOptions, profileValueLabel } from '../../support/applicantProfileLabels';
import { disclosureSummary, inputClass, labelClass, optionalLabel, secondaryButton, sectionTitle } from '../../support/profileEditorStyles';

const form = defineModel({ type: Object, required: true });
const props = defineProps({
    educationOptions: { type: Array, required: true },
    enrollmentOptions: { type: Array, required: true },
    courseOptions: { type: Array, required: true },
    yearOptions: { type: Array, required: true },
    academicYearOptions: { type: Array, required: true },
    termOptions: { type: Array, required: true },
    gradingScaleOptions: { type: Array, required: true },
    schoolTypeOptions: { type: Array, required: true },
    pathCopy: { type: Object, required: true },
    fieldRelevant: { type: Function, required: true },
    fieldRequired: { type: Function, required: true },
    requiresGrades: { type: Boolean, default: false },
    ocrActive: { type: Boolean, default: false },
    manualResultAllowed: { type: Boolean, default: false },
    extractedResult: { type: String, default: '' },
});
defineEmits(['open-evidence']);

const showRecordPeriod = computed(() => props.requiresGrades || form.value.academic_year || form.value.academic_term || props.fieldRelevant('grading_scale'));
const customCourse = computed(() => props.courseOptions.length && (form.value.course_or_strand === 'Other'
    || (form.value.course_or_strand && !props.courseOptions.includes(form.value.course_or_strand))));
const identifierSummary = computed(() => [
    props.schoolTypeOptions.find((option) => option.value === form.value.school_type)?.label,
    form.value.learner_reference_number ? 'Learner ID saved' : '',
].filter(Boolean).join(' / ') || 'Institution type and learner ID, if available.');
const hasQualification = computed(() => Boolean(form.value.completed_qualification) && form.value.completed_qualification !== 'none');
const qualificationLabel = computed(() => completedQualificationOptions.find((option) => option.value === form.value.completed_qualification)?.label);
const currentYear = new Date().getFullYear();
const customAcademicYear = ref(false);

function changeQualification() {
    if (!hasQualification.value) {
        form.value.completed_qualification_school = '';
        form.value.completed_qualification_year = '';
    }
    if (form.value.completed_qualification !== 'other') form.value.completed_qualification_details = '';
}
</script>

<template>
    <div id="profile-academic" data-learning-editor class="divide-y divide-slate-200 px-4 sm:px-6">
        <section class="py-4" aria-labelledby="learning-studies-title">
            <h3 id="learning-studies-title" :class="sectionTitle">{{ form.enrollment_status === 'Not currently enrolled' ? 'Most recent studies' : 'Current studies' }}</h3>
            <p v-if="form.enrollment_status === 'Not currently enrolled'" class="mt-1 text-xs leading-5 text-slate-500">Use your most recent school record. If the listed education paths do not fit, choose Other; do not invent a strand or Grade 12. Record your completed qualification below.</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-education-level">Education level</label>
                    <select id="profile-education-level" v-model="form.education_level" required :class="inputClass">
                        <option value="">Select education level</option>
                        <option v-for="option in educationOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-enrollment">Enrollment status</label>
                    <select id="profile-enrollment" v-model="form.enrollment_status" required :class="inputClass">
                        <option value="">Select status</option>
                        <option v-if="form.enrollment_status && !enrollmentOptions.includes(form.enrollment_status)" :value="form.enrollment_status">{{ profileValueLabel('enrollmentStatus', form.enrollment_status) }}</option>
                        <option v-for="option in enrollmentOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
                <div class="min-w-0 sm:col-span-2">
                    <label :class="labelClass" for="profile-school">School / learning institution</label>
                    <input id="profile-school" v-model="form.school" required maxlength="255" placeholder="School or learning center" :class="inputClass">
                </div>
                <div v-if="fieldRelevant('course_or_strand')" class="min-w-0">
                    <label :class="labelClass" for="profile-course">{{ pathCopy.courseLabel }} <span v-if="!fieldRequired('course_or_strand')" :class="optionalLabel">(optional)</span></label>
                    <select v-if="courseOptions.length" id="profile-course" v-model="form.course_or_strand" :required="fieldRequired('course_or_strand')" :class="inputClass">
                        <option value="">Select {{ pathCopy.courseLabel.toLowerCase() }}</option>
                        <option v-if="form.course_or_strand && !courseOptions.includes(form.course_or_strand)" :value="form.course_or_strand">{{ form.course_or_strand }}</option>
                        <option v-for="option in courseOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <input v-else id="profile-course" v-model="form.course_or_strand" :required="fieldRequired('course_or_strand')" maxlength="255" :placeholder="pathCopy.coursePlaceholder" :class="inputClass">
                    <input v-if="customCourse" v-model="form.course_or_strand" :aria-label="`Exact ${pathCopy.courseLabel.toLowerCase()}`" maxlength="255" :placeholder="`Type exact ${pathCopy.courseLabel.toLowerCase()}`" :class="[inputClass, 'mt-2']">
                </div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-year">{{ pathCopy.yearLabel }} <span v-if="!fieldRequired('year_level')" :class="optionalLabel">(if known)</span></label>
                    <select v-if="yearOptions.length" id="profile-year" v-model="form.year_level" :required="fieldRequired('year_level')" :class="inputClass">
                        <option value="">Select {{ pathCopy.yearLabel.toLowerCase() }}</option>
                        <option v-if="form.year_level && !yearOptions.includes(form.year_level)" :value="form.year_level">{{ form.year_level }}</option>
                        <option v-for="option in yearOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <input v-else id="profile-year" v-model="form.year_level" :required="fieldRequired('year_level')" maxlength="100" :placeholder="pathCopy.yearPlaceholder" :class="inputClass">
                </div>
            </div>
        </section>

        <section v-if="showRecordPeriod" class="py-4" aria-labelledby="learning-result-title">
            <h3 id="learning-result-title" :class="sectionTitle">Record period and result</h3>
            <p class="mt-1 text-xs leading-5 text-slate-500">Use the period shown on your academic record.</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-academic-year">Academic year</label>
                    <input v-if="customAcademicYear" id="profile-academic-year" v-model="form.academic_year" :required="fieldRequired('academic_year')" maxlength="20" pattern="[0-9]{4}-[0-9]{4}" placeholder="Example: 2013-2014" :class="inputClass">
                    <select v-else id="profile-academic-year" v-model="form.academic_year" :required="fieldRequired('academic_year')" :class="inputClass">
                        <option value="">Select academic year</option>
                        <option v-if="form.academic_year && !academicYearOptions.includes(form.academic_year)" :value="form.academic_year">{{ form.academic_year }}</option>
                        <option v-for="option in academicYearOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <button type="button" data-custom-academic-year class="mt-2 text-xs font-semibold text-slate-600 underline underline-offset-2 hover:text-slate-950" @click="customAcademicYear = !customAcademicYear">{{ customAcademicYear ? 'Use listed years' : 'Use an earlier / other year' }}</button>
                </div>
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-academic-term">Term / grading period</label>
                    <select id="profile-academic-term" v-model="form.academic_term" :required="fieldRequired('academic_term')" :class="inputClass">
                        <option value="">Select record period</option>
                        <option v-for="option in termOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div v-if="ocrActive && fieldRelevant('grading_scale')" data-managed-academic-result class="min-w-0 sm:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500">Academic result</p>
                            <p class="mt-1 break-words text-sm font-semibold text-slate-950">{{ extractedResult }}</p>
                        </div>
                        <button type="button" :class="secondaryButton" @click="$emit('open-evidence')">Supporting evidence<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ manualResultAllowed ? 'Scan needs attention. Enter the result in Supporting evidence for review.' : 'Read from your supporting evidence. A reviewer confirms this result.' }}</p>
                </div>
                <div v-else-if="fieldRelevant('grading_scale')" class="min-w-0">
                    <label :class="labelClass" for="profile-grading-scale">Grading scale</label>
                    <select id="profile-grading-scale" v-model="form.grading_scale" :required="fieldRequired('grading_scale')" :class="inputClass">
                        <option value="">Select grading scale</option>
                        <option v-for="option in gradingScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div v-if="!ocrActive && fieldRelevant('gwa')" class="min-w-0">
                    <label :class="labelClass" for="profile-gwa">{{ pathCopy.gwaLabel }}</label>
                    <input id="profile-gwa" v-model="form.gwa" type="number" min="0" :max="form.grading_scale === 'grade_point' ? 5 : 100" step="0.01" :required="fieldRequired('gwa')" :placeholder="form.grading_scale === 'grade_point' ? 'Example: 1.75' : 'Example: 92.50'" :class="inputClass">
                </div>
            </div>
            <p v-if="!ocrActive && fieldRelevant('grading_scale')" class="mt-2 text-xs leading-5 text-slate-500">Use your school's scale. Pass/fail results are reviewed from supporting records.</p>
        </section>

        <details data-completed-qualification :open="Boolean(form.completed_qualification)" class="group py-4">
            <summary :class="disclosureSummary">
                <span>Completed qualification <span :class="optionalLabel">(if applicable)</span></span>
                <i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ qualificationLabel || 'Record a qualification you already finished, including high school before K-12. Keep current studies separate.' }}</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0 sm:col-span-2">
                    <label :class="labelClass" for="profile-completed-qualification">Highest completed qualification</label>
                    <select id="profile-completed-qualification" v-model="form.completed_qualification" :class="inputClass" @change="changeQualification">
                        <option value="">Not recorded</option>
                        <option v-for="option in completedQualificationOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <template v-if="hasQualification">
                    <div v-if="form.completed_qualification === 'other'" class="min-w-0 sm:col-span-2">
                        <label :class="labelClass" for="profile-qualification-details">Qualification name</label>
                        <input id="profile-qualification-details" v-model="form.completed_qualification_details" required maxlength="255" placeholder="Use the name on your completion record" :class="inputClass">
                    </div>
                    <div class="min-w-0">
                        <label :class="labelClass" for="profile-qualification-school">Awarding school / institution</label>
                        <input id="profile-qualification-school" v-model="form.completed_qualification_school" required maxlength="255" placeholder="School or institution on your completion record" :class="inputClass">
                    </div>
                    <div class="min-w-0">
                        <label :class="labelClass" for="profile-qualification-year">Completion year <span :class="optionalLabel">(optional)</span></label>
                        <input id="profile-qualification-year" v-model="form.completed_qualification_year" type="number" min="1900" :max="currentYear" step="1" placeholder="Example: 2014" :class="inputClass">
                    </div>
                </template>
            </div>
            <p v-if="hasQualification" class="mt-3 text-xs leading-5 text-slate-600">After saving, upload a matching diploma or completion record in Supporting evidence. This does not automatically make it equivalent to a program's required qualification.</p>
        </details>

        <details data-school-identifiers class="group py-4">
            <summary :class="disclosureSummary">
                <span>School identifiers <span :class="optionalLabel">(optional)</span></span>
                <i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <p class="mt-1 text-xs leading-5 text-slate-500">{{ identifierSummary }}</p>
            <div class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <label :class="labelClass" for="profile-school-type">Institution type</label>
                    <select id="profile-school-type" v-model="form.school_type" :class="inputClass"><option value="">Select institution type</option><option v-for="option in schoolTypeOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                </div>
                <div class="min-w-0"><label :class="labelClass" for="profile-lrn">{{ pathCopy.idLabel }}</label><input id="profile-lrn" v-model="form.learner_reference_number" maxlength="50" :placeholder="pathCopy.idPlaceholder" :class="inputClass"></div>
            </div>
        </details>
    </div>
</template>
