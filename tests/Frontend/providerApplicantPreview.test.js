import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const files = [
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
    assert.match(source, /Desktop preview/);
    assert.match(source, /Mobile preview/);
    assert.match(source, /Back to editor/);
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

test('provider profile and program workspaces link to the applicant preview', () => {
    assert.match(sources['OrganizationPublicProfilePage.vue'], /href="\/provider\/previews\/organization"/);
    assert.match(sources['ProgramFormPage.vue'], /`\/provider\/previews\/programs\/\$\{programId\}`/);
    assert.match(sources['ProgramRecordPage.vue'], /`\/provider\/previews\/programs\/\$\{programId\}`/);
});
