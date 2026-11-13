import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/application-detail/FormalApplicationHandoff.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'FormalApplicationHandoff.vue' });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'formal-handoff', inlineTemplate: true });
const code = script.content.replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace(/from (['"])\.\.\/\.\.\/support\/locationPins\1/g, `from ${JSON.stringify(new URL('../../resources/js/support/locationPins.js', import.meta.url).href)}`);
const Component = (await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'))).default;
const render = handoff => renderToString(createSSRApp({ render: () => h(Component, { handoff }) }));

test('the application handoff keeps its record anchor and map event', () => {
    assert.match(source, /id="formal-application-handoff"/);
    assert.match(source, /data-application-formal-schedule/);
    assert.match(source, /emit\('map', handoff\)/);
    const page = readFileSync(new URL('../../resources/js/pages/UserApplicationActivitiesPage.vue', import.meta.url), 'utf8');
    assert.match(page, /<FormalApplicationHandoff/);
    assert.match(page, /@map="openHandoffMap"/);
});

test('onsite application records show the real deadline venue instructions and preparation', async () => {
    const html = await render({ mode: 'onsite', deadline: 'Nov 14, 2026', location_name: 'Scholarship Desk', location_address: 'Antipolo City',
        instructions: 'Present your original school records.', requirements: ['School ID'], contact: { email: 'desk@example.test' }, notice: 'This is not a final award.' });
    for (const text of ['Nov 14, 2026', 'Scholarship Desk', 'Antipolo City', 'Present your original school records.', 'School ID', 'View map', 'not a final award']) assert.ok(html.includes(text), text);
    assert.ok(html.includes('mailto:desk@example.test'));
    assert.ok(html.includes('data-formal-provider-contact'));
    assert.ok(html.includes('completion deadline is not an appointment time'));
});

test('online instructions retain the official link and do not invent a venue', async () => {
    const html = await render({ mode: 'online', url: 'https://example.org/official-form', instructions: 'Complete the provider form.' });
    assert.ok(html.includes('href="https://example.org/official-form"'));
    assert.ok(html.includes('Continue online'));
    assert.ok(!html.includes('data-formal-handoff-venue'));
    assert.ok(!html.includes('data-formal-preparation'));
    assert.ok(!html.includes('data-formal-provider-contact'));
});

test('unpublished details use honest fallbacks and large requirement lists stay collapsed', async () => {
    const html = await render({ requirements: ['A', 'B', 'C', 'D'] });
    assert.ok(html.includes('Provider will confirm'));
    assert.ok(html.includes('Contact the provider for the instructions'));
    assert.ok(!/\<details[^>]+open/.test(html));
});

test('a saved pin remains accessible even when no address text was supplied', async () => {
    const html = await render({ mode: 'onsite', latitude: 14.58, longitude: 121.17 });
    assert.ok(html.includes('View map'));
    assert.ok(html.includes('Provider map location'));
});
