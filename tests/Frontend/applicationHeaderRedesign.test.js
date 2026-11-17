import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/application-detail/ApplicantApplicationShell.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source); assert.deepEqual(errors, []);
const code = compileScript(descriptor, { id: 'application-header', inlineTemplate: true }).content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace(/import (\w+) from ['"][^'"]+\.vue['"];?/g, (_, name) => `const ${name} = { render: () => null };`);
const { default: Shell } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const identity = {
    program: { title: 'Continuing Studies Scholarship', logo_url: '/program-logo.png', image_url: '/legacy-mark.png', cover_image_url: '/program-cover.jpg' },
    provider: { name: 'Education foundation', href: '/provider-profile', verified: true, logo_url: '/organization-logo.png' },
    status: { label: 'Under review', tone: 'active' }, submitted_at: 'Nov 15, 2026 09:00 AM',
};
const snapshot = [
    { key: 'stage', label: 'Current stage', value: 'Pre-screening review' },
    { key: 'deadline', label: 'Application deadline', value: 'Nov 30, 2026' },
    { key: 'files', label: 'Required files', value: '3 of 4 accepted' },
    { key: 'review', label: 'Review method', value: 'Online application' },
];
const detail = { view: 'summary', identity, snapshot, navigation: [], decision: {} };
const render = extra => renderToString(createSSRApp(Shell, { detail: { ...detail, ...extra } }));
const header = html => html.slice(html.indexOf('<header'), html.indexOf('</header>') + 9);

test('application masthead preserves the status, submission date, provider link and four current facts', async () => {
    const html = header(await render());
    for (const text of [identity.program.title, identity.provider.name, identity.status.label, identity.submitted_at, 'Verified provider', 'href="/provider-profile"', ...snapshot.flatMap(item => [item.label, item.value])]) assert.ok(html.includes(text), text);
    assert.equal((html.match(/Education foundation/g) || []).length, 1);
    assert.equal((html.match(/<dt\b/g) || []).length, 4);
    assert.match(html, /bg-portal-navy/);
    assert.doesNotMatch(html, /<figcaption|Next step|What happens next|<button|<input/);
});

test('program branding uses its own logo and cover rather than an organization mark or decorative image', async () => {
    const html = header(await render());
    assert.match(html, /src="\/program-logo.png"/);
    assert.match(html, /src="\/program-cover.jpg"/);
    assert.doesNotMatch(html, /legacy-mark|organization-logo|filipino-students/);
    assert.ok(html.indexOf('data-application-program-logo') < html.indexOf('<h1'));
    assert.match(html, /hidden min-h-48[^>]+lg:block/);
    assert.match(html, /object-contain/);
});

test('legacy and missing logos retain their fallbacks without using the cover as a logo', async () => {
    const legacy = header(await render({ identity: { ...identity, program: { ...identity.program, logo_url: null } } }));
    assert.match(legacy, /src="\/legacy-mark.png"/);
    const missing = header(await render({ identity: { ...identity, program: { title: 'Scholarship application' }, provider: { name: 'Provider', verified: false } } }));
    assert.match(missing, /src="\/uploads\/scholarship-default.jpg"/);
    assert.match(missing, /fa-graduation-cap/);
    assert.doesNotMatch(missing, /Verified provider|href="\/provider-profile"/);
    assert.match(source, /function hideBrokenImage\(event\)\s*\{\s*event.currentTarget.classList.add\('hidden'\)/);
});

test('every application section keeps the same record identity, status and facts', async () => {
    for (const view of ['summary', 'submission', 'activities', 'outcome', 'history']) {
        const html = header(await render({ view, page: { title: 'Section title', description: 'Section instructions' } }));
        for (const text of [identity.status.label, identity.program.title, identity.submitted_at, ...snapshot.map(item => item.value)]) assert.ok(html.includes(text), view + ': ' + text);
        assert.match(html, /src="\/program-cover.jpg"/);
        assert.doesNotMatch(html, /Section title|Section instructions/);
    }
});

test('status badges retain distinct outcomes and neutral fallback without hard-coded selected states', async () => {
    for (const [tone, badge] of [['positive', 'positive'], ['negative', 'negative'], ['attention', 'attention'], ['active', 'info'], ['unknown', null]]) {
        const html = header(await render({ identity: { ...identity, status: { label: 'Actual application result', tone } } }));
        assert.match(html, /Actual application result/);
        if (badge) assert.ok(html.includes('student-badge-' + badge));
        else assert.doesNotMatch(html, /student-badge-(positive|negative|attention|info)/);
        assert.doesNotMatch(html, />Selected</);
    }
});

test('long content stays readable, absent dates and facts leave no blank rows, and guidance stays outside', async () => {
    const html = header(await render({ identity: { ...identity, program: { title: 'Long scholarship name '.repeat(8) }, provider: { name: 'Long provider name '.repeat(8) }, submitted_at: null }, snapshot: [] }));
    assert.match(html, /break-words/);
    assert.doesNotMatch(html, /truncate|line-clamp|Submitted|data-record-snapshot/);
    assert.match(source, /<\/header>\s+<ApplicationDecisionBanner\s+v-if="detail.view === 'summary'"/);
    assert.match(source, /<ApplicantRecordTabs :items="detail.navigation"/);
    assert.match(source, /<slot><\/slot>/);
});
