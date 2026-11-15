import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { checkInCardFacts, checkInCardGuidance } from '../../resources/js/support/checkInPresentation.js';

const record = (extra = {}) => ({
    record_key: 'check-in-1', title: 'Academic records review', period_label: 'First semester',
    state: 'open', state_label: 'Awaiting provider review', state_tone: 'neutral',
    next_actor_label: 'Scholarship provider', progress_label: '2 of 3 items verified',
    href: '/dashboard/monitoring/1/check-ins/check-in-1', ...extra,
});
const handoff = { scheduled_label: 'Nov 20, 2026 09:00 AM', location: 'Learning center, Antipolo City', rescheduled: false, started: false };

test('appointment facts use the server time and current venue, including rescheduled and past visits', () => {
    const row = record({ handoff });
    assert.deepEqual(checkInCardFacts(row), { dateCaption: 'Appointment date', dateLabel: handoff.scheduled_label, contextCaption: 'Venue', contextLabel: handoff.location });
    assert.equal(checkInCardFacts(record({ handoff: { ...handoff, rescheduled: true } })).dateCaption, 'New date and time');
    assert.equal(checkInCardFacts(record({ handoff: { ...handoff, rescheduled: true, started: true } })).dateCaption, 'Appointment date');
    assert.equal(checkInCardFacts(record({ handoff: { ...handoff, rescheduled: true } }), true).dateCaption, 'Appointment date');
});

test('legacy online checklists retain effective deadlines and do not invent a venue or appointment', () => {
    const facts = checkInCardFacts(record({ due_label: 'Nov 15, 2026', effective_due_label: 'Nov 22, 2026' }));
    assert.equal(facts.dateLabel, 'Nov 22, 2026');
    assert.equal(facts.contextCaption, 'Checklist');
    assert.equal(facts.contextLabel, '2 of 3 items verified');
    assert.equal(checkInCardFacts(record({ closed_at: 'Nov 24, 2026' }), true).dateCaption, 'Closed on');
    assert.equal(checkInCardFacts().dateLabel, 'No deadline posted');
    assert.equal(checkInCardFacts(record({ handoff: {} })).contextLabel, 'Awaiting provider details');
});

test('guidance distinguishes visits, legacy submissions, provider review and past outcomes', () => {
    assert.match(checkInCardGuidance(record({ state: 'upcoming', handoff })), /appointment.*documents/);
    assert.match(checkInCardGuidance(record({ state: 'upcoming' })), /opening date/);
    assert.equal(checkInCardGuidance(record()), 'Next step: Scholarship provider');
    for (const state of ['cancelled', 'incomplete', 'waived', 'completed']) {
        const text = checkInCardGuidance(record({ state }), true);
        assert.match(text, /provider outcome/);
        assert.doesNotMatch(text, /successfully completed|upload/i);
    }
});

const read = (name) => readFileSync(new URL(`../../resources/js/components/monitoring-detail/${name}.vue`, import.meta.url), 'utf8');
const { descriptor } = parse(read('CheckInSummaryCard'));
let code = compileScript(descriptor, { id: 'check-in-card', inlineTemplate: true }).content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
for (const name of ['checkInPresentation', 'checkInStatus']) code = code.replaceAll(`from '../../support/${name}.js'`, `from ${JSON.stringify(new URL(`../../resources/js/support/${name}.js`, import.meta.url).href)}`);
const { default: Card } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

test('cards retain their server state, period, date, venue and existing record link', async () => {
    const row = record({ handoff });
    const html = await renderToString(createSSRApp(Card, { record: row }));
    for (const text of [row.title, row.period_label, row.state_label, handoff.scheduled_label, handoff.location, row.href, 'Open check-in']) assert.ok(html.includes(text), text);
    assert.doesNotMatch(html, /type="file"|Upload record/);
    const past = await renderToString(createSSRApp(Card, { record: record({ state_label: 'Cancelled' }), past: true }));
    assert.match(past, /Cancelled/);
    assert.match(past, /Open record/);
    assert.doesNotMatch(past, /successfully completed/i);
});

test('the directory owns its framed heading and does not duplicate the featured appointment', () => {
    const source = read('MonitoringCheckIns');
    assert.match(source, /data-check-in-directory-header/);
    assert.match(source, /content\.empty\.title/);
    assert.match(source, /content\.empty\.description/);
    assert.equal((source.match(/records: props\.content\.directory\.(active|upcoming)\.filter\(record => record\.record_key !== appointment\.value\?\.record_key\)/g) || []).length, 2);
    assert.match(source, /<CheckInSummaryCard[^>]+past/);
});

test('next-step guidance precedes the schedule while in-person, closed-record and legacy submission guards remain', () => {
    const source = read('MonitoringCheckInRecord');
    assert.ok(source.indexOf('data-check-in-next-step') < source.indexOf('data-check-in-appointment'));
    assert.match(source, /canUpload = computed\(\(\) => !props\.record\.handoff && !isPast\.value\)/);
    assert.match(source, /if \(!file \|\| !target \|\| !canUpload\.value \|\| !target\.requirement\.can_submit\) return/);
    assert.match(source, /No online upload is needed/);
    for (const flow of ['MonitoringAdjustmentModal', 'MonitoringCaseHistoryModal', 'saveManualGrade', 'confirmOutcome', 'record.instructions', 'LocationMapLink']) assert.ok(source.includes(flow), flow);
});
