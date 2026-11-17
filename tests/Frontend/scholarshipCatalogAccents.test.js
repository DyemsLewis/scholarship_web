import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const source = readFileSync('resources/js/pages/UserScholarshipsPage.vue', 'utf8');
const { descriptor, errors } = parse(source, { filename: 'UserScholarshipsPage.vue' });

test('the accented scholarship catalog compiles without changing its existing actions', () => {
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'scholarship-catalog-accents' });
    const template = compileTemplate({
        id: 'scholarship-catalog-accents',
        filename: 'UserScholarshipsPage.vue',
        source: descriptor.template.content,
        compilerOptions: { bindingMetadata: script.bindings },
    });
    assert.deepEqual(template.errors, []);
    for (const action of ['resetFilters', 'toggleSave(scholarship)', 'openComparisonModal', 'canStartApplication(scholarship)']) {
        assert.ok(descriptor.template.content.includes(action), action);
    }
    assert.match(descriptor.template.content, /:src="scholarshipLogo\(scholarship\)"/);
    assert.match(descriptor.template.content, /v-for="scholarship in filteredScholarships"/);
});

test('muted catalog accents stay scoped to filters and result cards', () => {
    assert.ok(descriptor.styles.every(style => style.scoped));
    const css = descriptor.styles.map(style => style.content).join('\n');
    for (const name of ['scholarship-filter-fields', 'scholarship-card-body', 'scholarship-card-facts', 'scholarship-card-benefit', 'scholarship-card-actions']) {
        assert.ok(descriptor.template.content.includes(name), name);
        assert.ok(css.includes(`.${name}`), name);
    }
    assert.match(css, /--catalog-soft-blue:/);
    assert.match(css, /--catalog-warm:/);
    assert.match(css, /var\(--color-portal-gold\)/);
    assert.doesNotMatch(css, /@media|grid-template-columns|position:\s*fixed/);
    assert.match(descriptor.template.content, /sm:grid-cols-2 xl:grid-cols-4/);
});
