<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    context: {
        type: String,
        default: 'account',
    },
    required: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const content = {
    account: {
        title: 'I agree to the Terms and Privacy Notice.',
        summary: 'The portal may process account, profile, document, location, and scholarship activity data for matching, applications, notifications, and review.',
        details: [
            'Information should be truthful and kept up to date.',
            'For younger applicants, a parent or guardian confirms they are allowed to manage or support the account.',
            'The decision support score is only a guide; scholarship providers still make final review decisions.',
        ],
    },
    application: {
        title: 'I confirm this application can be submitted for provider review.',
        summary: 'Your profile, checklist, notes, DSS result, and attached documents may be shared with the scholarship provider and portal admins for review.',
        details: [
            'Submitted information should be accurate and belong to the applicant.',
            'Prepared documents may be attached automatically when they match the program requirements.',
            'Final approval, rejection, or awarding remains with the scholarship provider.',
        ],
    },
    document: {
        title: 'I confirm I am allowed to upload this document.',
        summary: 'Uploaded files may be stored and used for scholarship matching, application review, verification, and audit history.',
        details: [
            'The file should be correct, readable, and related to the applicant or authorized organization.',
            'Documents may be reviewed by providers or admins depending on where they are uploaded.',
            'False, altered, or unauthorized documents may affect account or application status.',
        ],
    },
    providerDocument: {
        title: 'I confirm I am authorized to upload this provider proof.',
        summary: 'Provider verification files may be reviewed by admins to decide whether the account can publish scholarships.',
        details: [
            'The document should represent the organization or authorized contact truthfully.',
            'Admins may approve, reject, or request replacement files.',
            'Provider access may be limited if documents are misleading or unauthorized.',
        ],
    },
    scholarship: {
        title: 'I confirm this scholarship information is accurate and authorized.',
        summary: 'Scholarship details may be reviewed by admins, shown to applicants after approval, and used for eligibility matching.',
        details: [
            'The provider is responsible for truthful program details, deadlines, requirements, and contact information.',
            'Applicant data received through the portal should only be used for scholarship review and related communication.',
            'Admin review is required before new or resubmitted programs become visible to students.',
        ],
    },
    acceptance: {
        title: 'I understand and confirm my scholarship response.',
        summary: 'If you accept, the provider may proceed with the next award, release, or verification steps. If you decline, the provider will be notified.',
        details: [
            'Final release of support still depends on the provider requirements and schedule.',
            'For younger applicants, a parent or guardian should help confirm the response.',
            'Keep your contact details and documents updated for any next steps.',
        ],
    },
};

const selectedContent = computed(() => content[props.context] ?? content.account);

function updateValue(event) {
    emit('update:modelValue', event.target.checked);
}
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
        <label class="flex cursor-pointer items-start gap-3">
            <input
                type="checkbox"
                class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-200"
                :checked="modelValue"
                :required="required"
                @change="updateValue"
            >
            <span>
                <span class="block font-bold text-slate-950">
                    {{ selectedContent.title }}
                    <a
                        href="/terms"
                        target="_blank"
                        rel="noreferrer"
                        class="ml-1 text-amber-700 underline decoration-amber-300 underline-offset-2 hover:text-amber-800"
                        @click.stop
                    >
                        Read terms
                    </a>
                </span>
                <span class="mt-1 block leading-5 text-slate-600">
                    {{ selectedContent.summary }}
                </span>
            </span>
        </label>

        <details class="mt-2 pl-7">
            <summary class="cursor-pointer text-xs font-bold text-slate-600">
                What this means
            </summary>
            <ul class="mt-2 space-y-1 text-xs leading-5 text-slate-500">
                <li v-for="detail in selectedContent.details" :key="detail">
                    {{ detail }}
                </li>
            </ul>
        </details>
    </div>
</template>
