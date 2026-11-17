import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { earliestDate, latestDate, nextProviderMinute, providerDate, providerDateOffset, providerDateTime, providerDateTimeIso, providerYearOffset, providerTimestamp } from '../../resources/js/support/providerDates.js';
import { monitoringPlanDateError } from '../../resources/js/support/monitoringPlanDates.js';
import { sessionScheduleError } from '../../resources/js/support/selectionSessionValidation.js';

test('date and time inputs use Philippine time rather than the computer timezone', () => {
    const instant = '2026-11-16T16:05:00.000Z';
    assert.equal(providerDate(instant), '2026-11-17');
    assert.equal(providerDateTime(instant), '2026-11-17T00:05');
    assert.equal(providerDateTime('2026-11-17T00:05'), '2026-11-17T00:05');
    assert.equal(providerDateTimeIso('2026-11-17T00:05'), instant);
    assert.equal(providerDateTimeIso(instant), instant);
    assert.equal(providerDateTime(null), '');
    assert.ok(Number.isNaN(providerTimestamp('invalid')));
    assert.match(sessionScheduleError({ starts_at: '2026-11-17T00:04' }, 0, Date.parse(instant)), /future date/);
});

test('calendar bounds handle minute midnight year and leap-day boundaries', () => {
    assert.equal(nextProviderMinute(Date.parse('2026-11-16T15:59:59Z')), '2026-11-17T00:00');
    assert.equal(providerDateOffset('2026-12-31', 1), '2027-01-01');
    assert.equal(providerDateOffset('2028-02-28', 1), '2028-02-29');
    assert.equal(providerYearOffset('2027-11-16', 1), '2028-11-16');
    assert.equal(earliestDate('', '2026-12-10', '2026-12-01'), '2026-12-01');
    assert.equal(latestDate('', '2026-12-10', '2026-12-01'), '2026-12-10');
});

test('monitoring may cover an ongoing period but cannot create an expired plan or escape its support period', () => {
    const program = { support_starts_on: '2026-09-01', support_ends_on: '2027-06-30' };
    const values = { starts_on: '2026-10-01', ends_on: '2027-01-01' };
    const error = (changes, saved = null) => monitoringPlanDateError({ ...values, ...changes }, program, saved, '2026-11-16');
    assert.equal(error({}), '');
    assert.match(error({ ends_on: '2026-11-15' }), /today or a future date/);
    assert.match(error({ starts_on: '2026-08-01' }), /program support period/);
    assert.match(error({ ends_on: '2027-07-01' }), /program support period/);
    assert.equal(error({ ends_on: '2026-11-15' }, { status: 'active', ends_on: '2026-11-15' }), '');
});

const files = [
    'pages/provider/programs/ProgramFormPage.vue', 'pages/provider/monitoring/MonitoringPlanPage.vue',
    'pages/provider/monitoring/MonitoringCheckInRecordPage.vue', 'pages/provider/selection/SelectionSessionFormPage.vue',
    'pages/provider/selection/components/SelectionSessionFormModal.vue', 'pages/provider/selection/SelectionSessionRecordPage.vue',
    'pages/provider/recipients/RecipientRecordPage.vue', 'pages/provider/releases/BenefitReleaseSchedulePage.vue',
    'pages/provider/billing/BillingServiceMeetingPage.vue', 'pages/provider/organization/OrganizationVerificationPage.vue',
    'components/RecipientCheckInAppointment.vue', 'components/benefit-delivery/BenefitDeliveryReportDialog.vue',
    'components/case-reviews/RecipientCasePrivacySection.vue',
];
for (const file of files) test(file + ' compiles with the date safeguards', () => {
    const { descriptor, errors } = parse(readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8'));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
});
