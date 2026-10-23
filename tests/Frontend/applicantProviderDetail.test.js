import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/UserProvidersPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('applicant provider directory and details page compiles', () => {
    const { descriptor, errors } = parse(source, { filename: 'UserProvidersPage.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'applicant-provider-detail' });
    const template = compileTemplate({
        id: 'applicant-provider-detail',
        filename: 'UserProvidersPage.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: { bindingMetadata: script.bindings },
    });

    assert.deepEqual(template.errors, []);
});

test('provider details use an image-led desktop hierarchy without changing the directory', () => {
    assert.match(source, /v-if="!isDetail"/);
    assert.match(source, /data-provider-detail-hero/);
    assert.match(source, /data-provider-detail-record/);
    assert.match(source, /data-provider-programs/);
    assert.match(source, /providerHero\.image/);
    assert.match(source, /programVisual\(program\)\.image/);
    assert.match(source, /lg:min-h-\[390px\]/);
    assert.match(source, /Search providers/);
    assert.match(source, /filteredProviders/);
    assert.doesNotMatch(source, /sm:grid-cols-\[11rem_minmax\(0,1fr\)\]/);
});

test('provider details support embedded read-only previews', () => {
    assert.match(source, /embedded: \{ type: Boolean/);
    assert.match(source, /data-embedded-preview/);
    assert.match(source, /blockPreviewAction/);
    assert.match(source, /aria-disabled="isPreview \|\| undefined"/);
});
