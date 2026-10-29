import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/components/profile/ApplicantProfileEditModal.vue',
    '../../resources/js/components/profile/ApplicantProfileRecord.vue',
    '../../resources/js/pages/UserProfilePage.vue',
    '../../resources/js/pages/AdminApplicantReviewPage.vue',
    '../../resources/js/pages/admin/reviews/ApplicantReviewRecordPage.vue',
    '../../resources/js/pages/provider/reviews/ApplicationReviewRecordPage.vue',
    '../../resources/js/pages/provider/decisions/FinalDecisionRecordPage.vue',
];

for (const relativePath of components) {
    const component = relativePath.split('/').at(-1);

    test(`${component} compiles with the shared applicant profile record`, () => {
        const filename = new URL(relativePath, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });

        assert.deepEqual(errors, []);

        const script = descriptor.scriptSetup
            ? compileScript(descriptor, { id: `${component}-${relativePath}` })
            : null;
        const template = compileTemplate({
            id: `${component}-${relativePath}`,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: { bindingMetadata: script?.bindings ?? {} },
        });

        assert.deepEqual(template.errors, []);
    });
}

test('the applicant profile has one record and an accessible section editor', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const modalSource = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEditModal.vue', import.meta.url), 'utf8');

    assert.match(source, /<ApplicantProfileRecord/);
    assert.match(source, /<ApplicantProfileRecord[\s\S]*?primary/);
    assert.match(source, /profile_section: activeSection\.value/);
    assert.match(source, /<ApplicantProfileEditModal/);
    assert.match(source, /:size="profileEditorSize"/);
    assert.match(source, /@save="finishProfileEditor"/);
    assert.match(modalSource, /aria-modal="true"/);
    assert.match(modalSource, /aria-labelledby="profile-editor-title"/);
    assert.match(modalSource, /aria-describedby="profile-editor-description"/);
    assert.match(modalSource, /event\.key === 'Escape'/);
    assert.match(modalSource, /event\.key !== 'Tab'/);
    assert.match(modalSource, /releaseScrollLock \?\?= lockPageScroll\(\)/);
    assert.doesNotMatch(modalSource, /document\.body\.style\.overflow\s*=/);
    assert.match(modalSource, /previouslyFocused\?\.focus\?\.\(\{ preventScroll: true \}\)/);
    assert.doesNotMatch(
        source,
        /watch\(showProfileEditorModal,[\s\S]*?document\.body\.style\.overflow/,
        'The modal component must be the only owner of the page scroll lock.',
    );
    assert.match(source, /<ApplicantPersonalEditor/);
    assert.match(source, /<ApplicantLearningEditor/);
    assert.match(source, /<ApplicantHouseholdEditor/);
    assert.match(source, /<ApplicantAddressEditor/);
    assert.match(source, /<ApplicantEvidenceEditor/);
    assert.doesNotMatch(source, /sectionHeaderClass/);
    assert.equal(
        (source.match(/@save="finishProfileEditor"/g) ?? []).length,
        1,
        'The profile editor should expose one save action in its footer.',
    );
    assert.doesNotMatch(source, /showProfileGuidance|Show guidance|Hide guidance/);
    assert.match(source, /handleUnsavedNavigation/);
    assert.match(source, /title: 'Leave without saving\?'/);
    assert.match(source, /confirmLabel: 'Discard and leave'/);
    assert.match(source, /cancelLabel: 'Keep editing'/);
    assert.match(source, /document\.addEventListener\('click', handleUnsavedNavigation\)/);
    assert.match(source, /<ApplicantPageHeader[\s\S]*?compact/);
    assert.doesNotMatch(source, /showProviderPreview|profileNavigationSteps|reviewGroups|v-if="false"/);

    assert.ok(
        source.indexOf('<ApplicantPageHeader') < source.indexOf('<ApplicantProfileRecord'),
        'The compact page header should appear immediately before the applicant record.',
    );
    assert.ok(
        source.indexOf('<ApplicantProfileRecord') < source.indexOf('<PrivacyNoticeCard'),
        'The applicant record should appear before secondary privacy guidance.',
    );
});

test('the personal profile editor presents the applicant information in review order', () => {
    const modal = readFileSync(new URL('../../resources/js/components/profile/ApplicantPersonalEditor.vue', import.meta.url), 'utf8');
    const photo = modal.indexOf('>Applicant photo<');
    const officialName = modal.indexOf('>Official name<');
    const identity = modal.indexOf('>Personal details<');
    const contact = modal.indexOf('>Contact and account<');

    assert.ok(photo > -1, 'The personal editor should include the required applicant photo.');
    assert.ok(photo < officialName, 'The applicant photo should be the first personal profile task.');
    assert.ok(officialName < identity, 'Official identity should precede eligibility details.');
    assert.ok(identity < contact, 'Contact and account responsibility should finish the personal form.');
    assert.equal((modal.match(/Open camera/g) ?? []).length, 1);
    assert.equal((modal.match(/>Contact and account</g) ?? []).length, 1);
    assert.doesNotMatch(modal, />Basic information<|>Account responsibility</);
});

test('learning and household editors use a focused review hierarchy', () => {
    const learning = readFileSync(new URL('../../resources/js/components/profile/ApplicantLearningEditor.vue', import.meta.url), 'utf8');
    const household = readFileSync(new URL('../../resources/js/components/profile/ApplicantHouseholdEditor.vue', import.meta.url), 'utf8');
    const modal = learning + household;
    const currentStudies = modal.indexOf('>Current studies<');
    const recordResult = modal.indexOf('>Record period and result<');
    const schoolIdentifiers = modal.indexOf('>School identifiers ');

    assert.ok(currentStudies > -1, 'The learning editor should begin with the current study context.');
    assert.ok(currentStudies < recordResult, 'The academic result should follow the current studies.');
    assert.ok(recordResult < schoolIdentifiers, 'Optional school identifiers should come last.');
    assert.doesNotMatch(modal, />Current learning stage<|>Academic record period<|>Program and grades<|>Additional school details/);
    assert.match(modal, />Household eligibility</);
    assert.match(modal, />Other scholarship support</);
    assert.match(household, /guardianRequired \? 'section' : 'details'/);
    assert.match(household, /!guardianRequired && hasGuardianDetails/);
    assert.doesNotMatch(modal, />Household context<|>Other scholarship status</);
});

test('the location editor separates required residence from optional address precision', () => {
    const locationEditor = readFileSync(new URL('../../resources/js/components/profile/ApplicantAddressEditor.vue', import.meta.url), 'utf8');
    const generalLocation = locationEditor.indexOf('>General location<');
    const addressDetails = locationEditor.indexOf('>Address details ');
    const mapPin = locationEditor.indexOf('>Approximate map pin ');

    assert.ok(generalLocation > -1, 'The location editor should start with the required general location.');
    assert.ok(generalLocation < addressDetails, 'Optional address precision should follow the general location.');
    assert.ok(addressDetails < mapPin, 'The map pin should be the final location task.');
    assert.match(locationEditor, /id="profile-region"[\s\S]*?required/);
    assert.match(locationEditor, /id="profile-province"[\s\S]*?required/);
    assert.match(locationEditor, /id="profile-city"[\s\S]*?required/);
    assert.match(locationEditor, /does not need to identify your exact home/);
    assert.match(locationEditor, /not shown on your public profile/);
    assert.doesNotMatch(locationEditor, /fa-house|>Home address</);
});

test('supporting evidence distinguishes required completion from optional records', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const evidenceEditor = readFileSync(new URL('../../resources/js/components/profile/ApplicantEvidenceEditor.vue', import.meta.url), 'utf8');

    assert.match(source, /const requiredVerificationDocumentRows = computed/);
    assert.match(source, /const optionalVerificationDocumentRows = computed/);
    assert.match(source, /const verificationDocumentGroups = computed/);
    assert.match(source, /label: 'Required files'/);
    assert.match(source, /label: 'Optional records'/);
    assert.match(evidenceEditor, /<ApplicantProfileEvidenceList/);
    assert.match(source, /:groups="verificationDocumentGroups"/);
    const list = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEvidenceList.vue', import.meta.url), 'utf8');
    assert.match(list, /v-for="group in groups"/);
    assert.match(list, /progress\.saved.*progress\.total/);
    assert.match(evidenceEditor, /Only authorized reviewers can access these files/);
    assert.doesNotMatch(evidenceEditor, /verificationDocumentRows\.filter\(\(row\) => row\.document\)\.length \}\} of \{\{ verificationDocumentRows\.length/);
    assert.doesNotMatch(evidenceEditor, /No file uploaded/);
});

test('profile save errors are announced and move focus to the affected field', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');

    assert.match(source, /const profileFieldControlIds = \{/);
    assert.match(source, /async function focusProfileField\(field\)/);
    assert.match(source, /openSection\(sectionForField\(field\), \{ clearError: false \}\)/);
    assert.match(source, /error\.response\?\.data\?\.message \?\? 'Unable to save this profile section/);
    assert.match(source, /ref="profileValidationAlert"/);
    assert.match(source, /aria-live="assertive"/);
    assert.match(source, /@click="focusProfileField\(error\.key\)"/);
    const household = readFileSync(new URL('../../resources/js/components/profile/ApplicantHouseholdEditor.vue', import.meta.url), 'utf8');
    assert.match(household, /id="profile-guardian-account-owner"/);
    assert.match(source, /ancestor.tagName === 'DETAILS'[^]*?ancestor.open = true/);
});

test('staff review surfaces reuse the same applicant record without duplicate profile panels', () => {
    const staffPages = components.slice(2);

    for (const relativePath of staffPages) {
        const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');

        assert.match(source, /ApplicantProfileRecord/);
    }

    const superAdminReview = readFileSync(new URL('../../resources/js/pages/AdminApplicantReviewPage.vue', import.meta.url), 'utf8');
    assert.doesNotMatch(superAdminReview, /id="applicant-details"|Profile used for verification/);
    assert.match(superAdminReview, /trigger-only/);
});

test('provider review records identify applicant profile details as self-declared', () => {
    const record = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileRecord.vue', import.meta.url), 'utf8');
    const providerPages = [
        '../../resources/js/pages/provider/reviews/ApplicationReviewRecordPage.vue',
        '../../resources/js/pages/provider/decisions/FinalDecisionRecordPage.vue',
    ];

    assert.match(record, /showDataSourceNotice/);
    assert.match(record, /Self-declared profile information/);
    assert.match(record, /does not automatically confirm every profile field/);

    for (const relativePath of providerPages) {
        const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
        assert.match(source, /show-data-source-notice/);
    }
});

test('applicant page headers use one centered icon treatment without a font baseline gap', () => {
    const header = readFileSync(new URL('../../resources/js/components/TaskPageHeader.vue', import.meta.url), 'utf8');
    const styles = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    assert.match(header, /isApplicant \? 'items-center' : 'items-start'/);
    assert.match(header, /inline-flex h-11 w-11 shrink-0 items-center justify-center/);
    assert.match(header, /block w-4 text-center text-base leading-none/);
    assert.doesNotMatch(header, /compact \? 'h-9 w-9' : 'h-11 w-11'/);
    assert.match(styles, /\.student-hero-icon > i,[\s\S]*?line-height: 1;/);
});

test('confirmation dialogs present unsaved changes as a designed alert', () => {
    const dialog = readFileSync(new URL('../../resources/js/components/ConfirmationDialog.vue', import.meta.url), 'utf8');
    const composable = readFileSync(new URL('../../resources/js/composables/useConfirmationDialog.js', import.meta.url), 'utf8');

    assert.match(dialog, /role="alertdialog"/);
    assert.match(dialog, /border-t-4/);
    assert.match(dialog, /\{\{ eyebrow \}\}/);
    assert.match(dialog, /\{\{ cancelLabel \}\}/);
    assert.match(composable, /cancelLabel: options\.cancelLabel/);
    assert.match(composable, /eyebrow: options\.eyebrow/);
});
