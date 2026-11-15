import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url), 'utf8');
const start = source.lastIndexOf('<header', source.indexOf('data-scholarship-detail-header'));
const template = source.slice(start, source.indexOf('</header>', start) + 9);
const fixture = `<script setup>
defineProps({ identity: Object, snapshot: Array, scholarshipImage: String, scholarshipVisual: Object, isOverview: { type: Boolean, default: true }, isPreview: Boolean, imageFailed: Boolean });
function blockPreviewAction(event) { event.preventDefault(); }
</script><template>${template}</template>`;
const { descriptor, errors } = parse(fixture); assert.deepEqual(errors, []);
const code = compileScript(descriptor, { id: 'scholarship-header', inlineTemplate: true }).content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const { default: Header } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const props = {
    identity: { title: 'Continuing Studies Scholarship', category: 'Financial assistance', program_cycle: '2026-2027', provider: { name: 'Education foundation', href: '/dashboard/providers/1', verified: true } },
    scholarshipImage: '/scholarship-logo.png', scholarshipVisual: { hero: '/program-cover.jpg', heroAlt: 'Program cover' },
    snapshot: [{ key: 'benefit', label: 'Benefit', value: 'PHP 9,000 per semester' }, { key: 'deadline', label: 'Deadline', value: 'Nov 30, 2026' }, { key: 'capacity', label: 'Application intake', value: '20 applications remaining', note: '5 award places planned.' }],
};
const render = extra => renderToString(createSSRApp(Header, { ...props, ...extra }));

test('the new masthead preserves program branding, provider attribution and every published header fact', async () => {
    const html = await render();
    for (const text of [props.identity.title, props.identity.category, props.identity.program_cycle, props.identity.provider.name, 'Verified provider', '/scholarship-logo.png', '/program-cover.jpg', ...props.snapshot.flatMap(item => [item.label, item.value, item.note].filter(Boolean))]) assert.ok(html.includes(text), text);
    assert.match(html, /bg-portal-navy/);
    assert.match(html, /data-scholarship-cover/);
    assert.match(html, /object-contain/);
    assert.doesNotMatch(html, /Ready to begin|Start pre-screening|Save scholarship/);
});

test('non-overview tabs retain a compact branded header without duplicating the cover or snapshot', async () => {
    const html = await render({ isOverview: false });
    assert.match(html, /Continuing Studies Scholarship/);
    assert.match(html, /Education foundation/);
    assert.doesNotMatch(html, /data-scholarship-cover|data-scholarship-key-facts/);
});

test('an unverified provider never acquires a verified badge and broken logos retain their icon fallback', async () => {
    const html = await render({ identity: { ...props.identity, provider: { name: 'Provider awaiting review', verified: false } }, imageFailed: true });
    assert.doesNotMatch(html, /Verified provider|data-scholarship-logo|href="\/dashboard\/providers/);
    assert.match(html, /fa-graduation-cap/);
    assert.match(html, /Provider awaiting review/);
});

test('long titles and provider names stay readable, while missing metadata or facts do not create empty rows', async () => {
    const html = await render({ identity: { title: 'A long scholarship title '.repeat(8), provider: { name: 'A long provider name '.repeat(8) } }, snapshot: [] });
    assert.match(html, /break-words/);
    assert.doesNotMatch(html, /\btruncate\b|line-clamp|data-scholarship-key-facts|fa-calendar/);
});

test('application-state guidance remains outside the header and provider previews keep their navigation guard', () => {
    assert.doesNotMatch(template, /ScholarshipDetailAction|decision\./);
    assert.match(source, /<\/header>\s+<ScholarshipDetailAction v-if="!isApplicationView"/);
    assert.match(template, /:aria-disabled="isPreview \|\| undefined" @click="blockPreviewAction"/);
    assert.match(source, /function blockPreviewAction\(event\) \{\s+if \(isPreview\) event.preventDefault\(\)/);
});
