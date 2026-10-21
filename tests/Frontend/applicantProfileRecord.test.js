import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
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

    assert.match(source, /<ApplicantProfileRecord/);
    assert.match(source, /<ApplicantProfileRecord[\s\S]*?primary/);
    assert.match(source, /profile_section: activeSection\.value/);
    assert.match(source, /aria-modal="true"/);
    assert.match(source, /aria-labelledby="profile-editor-title"/);
    assert.match(source, /aria-describedby="profile-editor-description"/);
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
