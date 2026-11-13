import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('applicant application start flow compiles', () => {
    const { descriptor, errors } = parse(source, { filename: 'UserApplicationsPage.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'applicant-application-start' });
    const template = compileTemplate({
        id: 'applicant-application-start',
        filename: 'UserApplicationsPage.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: { bindingMetadata: script.bindings },
    });

    assert.deepEqual(template.errors, []);
});

test('eligibility cards use distinct status colors without repeating profile values', () => {
    assert.match(source, /eligibilityCriterionCardClass/);
    assert.match(source, /pass: 'border-emerald-200 bg-emerald-50\/50'/);
    assert.match(source, /fail: 'border-rose-300 bg-rose-50\/60'/);
    assert.match(source, /missing: 'border-amber-300 bg-amber-50\/60'/);
    assert.match(source, /info: 'border-sky-200 bg-sky-50\/60'/);
    assert.doesNotMatch(source, /Your profile:/);
    assert.doesNotMatch(source, /profileValue:/);
});

test('eligibility summary links to the scholarship eligibility details', () => {
    assert.match(source, /`\/dashboard\/scholarships\/\$\{selectedScholarship\.value\.id\}\/eligibility`/);
    assert.match(source, /View full eligibility/);
});

test('accepted awards retain application access separately from scholarship support', () => {
    const start = source.indexOf('function applicationActionUrl(');
    const end = source.indexOf('\nfunction applicationStageLabel(', start);
    assert.ok(start > 0 && end > start);
    const actionUrl = new Function('window', 'primarySchedule', source.slice(start, end) + '\nreturn applicationActionUrl;')(
        { location: { origin: 'https://findscholarship.test' } },
        application => application.schedules?.[0],
    );
    for (const pending of [0, 2]) {
        const application = { id: 7, detail_url: '/dashboard/applications/7', workflow: { is_closed: true },
            recipient_agreement: { status: 'accepted' }, recipient_monitoring: { eligible: true, pending_count: pending, support_status: 'active' } };
        assert.equal(actionUrl(application), '/dashboard/applications/7');
    }
    assert.equal(actionUrl({ id: 8, detail_url: '/r/opaque-record', workflow: { is_closed: true }, recipient_agreement: { status: 'accepted' } }), '/r/opaque-record');
    assert.equal(actionUrl({ id: 9, recipient_agreement: { can_respond: true } }), '/dashboard/applications/9?action=agreement');
    assert.equal(actionUrl({ id: 10, workflow: { is_closed: false }, schedules: [{ status: 'scheduled' }] }), '/dashboard/applications/10?section=schedule');
    assert.match(source, /data-application-record-link/);
    assert.match(source, /data-application-support-link/);
    assert.match(source, /v-if="application\.recipient_agreement\?\.status === 'accepted' && application\.recipient_monitoring\?\.eligible"/);
    assert.match(source, /return 'View application'/);
});
