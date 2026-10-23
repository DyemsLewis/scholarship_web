import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const files = [
    ['ApplicantPagePreviewModal.vue', '../../resources/js/components/ApplicantPagePreviewModal.vue'],
    ['ApplicantPreviewViewport.vue', '../../resources/js/components/ApplicantPreviewViewport.vue'],
    ['ProviderApplicantPreviewToolbar.vue', '../../resources/js/components/ProviderApplicantPreviewToolbar.vue'],
    ['UserScholarshipDetailPage.vue', '../../resources/js/pages/UserScholarshipDetailPage.vue'],
    ['UserProvidersPage.vue', '../../resources/js/pages/UserProvidersPage.vue'],
    ['OrganizationPublicProfilePage.vue', '../../resources/js/pages/provider/organization/OrganizationPublicProfilePage.vue'],
    ['ProgramFormPage.vue', '../../resources/js/pages/provider/programs/ProgramFormPage.vue'],
    ['ProgramRecordPage.vue', '../../resources/js/pages/provider/programs/ProgramRecordPage.vue'],
];

const sources = Object.fromEntries(files.map(([name, path]) => [
    name,
    readFileSync(new URL(path, import.meta.url), 'utf8'),
]));

for (const [name] of files) {
    test(`${name} compiles with applicant preview support`, () => {
        const { descriptor, errors } = parse(sources[name], { filename: name });

        assert.deepEqual(errors, []);

        const script = compileScript(descriptor, { id: `provider-preview-${name}` });
        const template = compileTemplate({
            id: `provider-preview-${name}`,
            filename: name,
            source: descriptor.template?.content ?? '',
            compilerOptions: { bindingMetadata: script.bindings },
        });

        assert.deepEqual(template.errors, []);
    });
}

test('preview toolbar clearly separates preview controls from applicant content', () => {
    const source = sources['ProviderApplicantPreviewToolbar.vue'];

    assert.match(source, /data-provider-applicant-preview-toolbar/);
    assert.match(source, /Actions are disabled and this view is not public/);
    assert.match(source, /Applicant state/);
    assert.doesNotMatch(source, /Mobile preview/);
    assert.doesNotMatch(source, /update:width/);
    assert.match(source, /Back to editor/);
});

test('shared preview modal provides accessible controls and a reusable viewport', () => {
    const modal = sources['ApplicantPagePreviewModal.vue'];
    const viewport = sources['ApplicantPreviewViewport.vue'];

    assert.match(modal, /data-applicant-page-preview-modal/);
    assert.match(modal, /role="dialog"/);
    assert.match(modal, /aria-modal="true"/);
    assert.match(modal, /ApplicantPreviewViewport/);
    assert.match(modal, /Applicant state/);
    assert.doesNotMatch(modal, /Mobile preview/);
    assert.doesNotMatch(modal, /update:width/);
    assert.match(modal, /Open full-page preview/);
    assert.match(modal, /event\.key === 'Escape'/);
    assert.match(modal, /event\.key !== 'Tab'/);
    assert.match(modal, /modalEnvironmentActive/);
    assert.match(modal, /document\.body\.classList\.add\('overflow-hidden'\)/);
    assert.match(viewport, /data-applicant-preview-viewport/);
    assert.doesNotMatch(viewport, /max-w-\[30rem\]/);
    assert.match(viewport, /min-h-full w-full bg-slate-100/);
});

test('applicant pages switch to protected preview endpoints and disable applicant actions', () => {
    const scholarship = sources['UserScholarshipDetailPage.vue'];
    const provider = sources['UserProvidersPage.vue'];

    assert.match(scholarship, /dataset\.providerPreview === 'true'/);
    assert.match(scholarship, /isPreview \? previewDataUrl/);
    assert.match(scholarship, /isSaving \|\| isPreview/);
    assert.match(scholarship, /ProviderApplicantPreviewToolbar/);
    assert.match(provider, /dataset\.providerPreview === 'true'/);
    assert.match(provider, /isPreview\s*\? previewDataUrl/);
    assert.match(provider, /ProviderApplicantPreviewToolbar/);
});

test('program workspaces use the shared applicant preview modal', () => {
    assert.match(sources['ProgramFormPage.vue'], /ApplicantPagePreviewModal/);
    assert.match(sources['ProgramFormPage.vue'], /UserScholarshipDetailPage/);
    assert.match(sources['ProgramFormPage.vue'], /previewOpen = true/);
    assert.match(sources['ProgramRecordPage.vue'], /ApplicantPagePreviewModal/);
    assert.match(sources['ProgramRecordPage.vue'], /UserScholarshipDetailPage/);
    assert.match(sources['ProgramRecordPage.vue'], /previewOpen = true/);
});

test('organization workspace uses the shared applicant preview modal', () => {
    const organization = sources['OrganizationPublicProfilePage.vue'];
    const provider = sources['UserProvidersPage.vue'];

    assert.match(organization, /ApplicantPagePreviewModal/);
    assert.match(organization, /UserProvidersPage/);
    assert.match(organization, /previewOpen = true/);
    assert.match(provider, /embedded: \{ type: Boolean/);
    assert.match(provider, /data-embedded-preview/);
    assert.match(provider, /blockPreviewAction/);
});

test('scholarship detail supports embedded modal rendering and section navigation', () => {
    const scholarship = sources['UserScholarshipDetailPage.vue'];

    assert.match(scholarship, /embedded: \{ type: Boolean/);
    assert.match(scholarship, /data-embedded-preview/);
    assert.match(scholarship, /openPreviewSection/);
    assert.match(scholarship, /readinessSection/);
    assert.match(scholarship, /section: currentView\.value/);
});
