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

test('provider detail accents stay scoped to detail content and preserve public actions', () => {
    const { descriptor } = parse(source, { filename: 'UserProvidersPage.vue' });
    assert.ok(descriptor.styles.length > 0);
    assert.ok(descriptor.styles.every(style => style.scoped));
    const css = descriptor.styles.map(style => style.content).join('\n');
    const detail = descriptor.template.content.split('<template v-else-if="provider">')[1];
    const directory = descriptor.template.content.split('<template v-else-if="!isDetail">')[1].split('<template v-else-if="provider">')[0];

    for (const name of ['provider-detail-intro', 'provider-detail-facts', 'provider-detail-record-heading', 'provider-detail-description', 'provider-detail-contact', 'provider-program-body', 'provider-program-benefits']) {
        assert.ok(detail.includes(name), name);
        assert.ok(css.includes(`.${name}`), name);
        assert.ok(!directory.includes(name), `directory should not inherit ${name}`);
    }
    assert.match(css, /--provider-soft-blue: #edf3fa/);
    assert.match(css, /--provider-warm: #fff5d6/);
    assert.match(css, /var\(--color-portal-gold\)/);
    assert.doesNotMatch(css, /grid-template-columns|display:\s*none|position:\s*fixed/);
    assert.match(detail, /<LocationMapLink :address="provider\.address/);
    assert.match(detail, /:href="`mailto:\$\{provider\.contact_email\}`"/);
    assert.match(detail, /:href="websiteUrl\(provider\.website\)"/);
    assert.match(detail, /data-program-logo/);
    assert.match(detail, /data-program-cover/);
});

test('the provider map action is outside the compact four-field contact row', () => {
    const heading = source.match(/<header class="provider-detail-record-heading[\s\S]*?<\/header>/)?.[0];
    const contact = source.match(/<dl class="provider-detail-contact[\s\S]*?<\/dl>/)?.[0];
    assert.ok(heading);
    assert.ok(contact);
    assert.match(heading, /provider-detail-map-action/);
    assert.match(heading, /<LocationMapLink :address="provider\.address \|\| ''" :latitude="provider\.latitude" :longitude="provider\.longitude" :title="provider\.name"/);
    assert.doesNotMatch(contact, /LocationMapLink|<button/);
    assert.equal((contact.match(/<dt /g) || []).length, 4);
    assert.equal((contact.match(/px-5 py-3/g) || []).length, 4);
});
