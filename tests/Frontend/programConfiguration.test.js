import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { programConfigurationErrors as errors, programTimelineMinimums } from '../../resources/js/support/programConfiguration.js';
const programConfigurationErrors = values => errors(values, { today: '2026-11-16' });

test('application capacity cannot be smaller than recipient capacity', () => {
    assert.match(programConfigurationErrors({ slots_available: '30', application_limit: '20' }).application_limit, /recipient slots/);
    for (const application_limit of [30, 100, '', null]) {
        assert.deepEqual(programConfigurationErrors({ slots_available: 30, application_limit }), {});
    }
});

test('both capacities require positive bounded whole numbers when set', () => {
    for (const field of ['slots_available', 'application_limit']) {
        for (const value of [-1, 0, 1.5, 1_000_001, 'abc']) {
            assert.ok(programConfigurationErrors({ [field]: value })[field]);
        }
    }
    assert.deepEqual(programConfigurationErrors({ slots_available: 1, application_limit: 1_000_000 }), {});
});

test('drafts may be incomplete but later dates require the application deadline', () => {
    assert.deepEqual(programConfigurationErrors({}), {});
    assert.deepEqual(programConfigurationErrors({ application_opens_at: '2027-06-02' }), {});
    assert.deepEqual(programConfigurationErrors({ deadline: '2027-06-01' }), {});
    for (const field of ['expected_results_at', 'handoff_deadline', 'support_starts_at', 'support_ends_at']) {
        assert.match(programConfigurationErrors({ [field]: '2027-06-03' }).deadline, /Set the application deadline/);
    }
    assert.match(programConfigurationErrors({ deadline: '2027-06-01', support_ends_at: '2027-06-03' }).support_starts_at, /before the support end/);
});

test('the order is enforced across the full timeline without moving other dates', () => {
    const dates = {
        application_opens_at: '2027-06-01', deadline: '2027-06-01',
        handoff_deadline: '2027-06-02', expected_results_at: '2027-06-03',
        support_starts_at: '2027-06-03', support_ends_at: '2027-06-03',
    };
    assert.deepEqual(programConfigurationErrors(dates), {});
    assert.deepEqual(programConfigurationErrors({ deadline: dates.deadline, support_starts_at: '2027-06-02' }), {});
    for (const changes of [
        { application_opens_at: '2027-06-02' }, { handoff_deadline: '2027-06-01' },
        { expected_results_at: '2027-06-02' }, { support_starts_at: '2027-06-02' },
        { support_ends_at: '2027-06-02' },
    ]) {
        assert.ok(programConfigurationErrors({ ...dates, ...changes })[Object.keys(changes)[0]]);
    }
    const movedDeadline = { ...dates, deadline: '2027-06-10' };
    const before = { ...movedDeadline };
    assert.deepEqual(Object.keys(programConfigurationErrors(movedDeadline)), ['handoff_deadline', 'expected_results_at', 'support_starts_at']);
    assert.deepEqual(movedDeadline, before);
});

test('date picker limits follow the Manila calendar across month and year boundaries', () => {
    assert.deepEqual(programTimelineMinimums({
        application_opens_at: '2027-12-01', deadline: '2027-12-30', handoff_deadline: '2027-12-31',
        expected_results_at: '2028-01-02', support_starts_at: '2028-01-03',
    }), {
        deadline: '2027-12-01', handoff_deadline: '2027-12-31', expected_results_at: '2028-01-01',
        support_starts_at: '2028-01-02', support_ends_at: '2028-01-03',
    });
});

test('program editor applies conflict checks before saving and uses the existing alert dialog', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /programConfigurationErrors\(/);
    assert.match(source, /async function save\([^]*?configurationErrors\.value[^]*?showAlert\('Check the program details'/);
    assert.match(source, /:min="Number\(form\.slotsAvailable\) \|\| 1"/);
    const schedule = source.slice(source.indexOf('<section v-else-if="activeStep === \'dates-location\'"'), source.indexOf('<section v-else-if="activeStep === \'eligibility\'"'));
    assert.match(schedule, /data-program-timeline/);
    assert.equal((source.match(/type="date"/g) ?? []).length, 1);
    assert.match(schedule, /:min="latestDate\(programDateMinimum\(field.field\), timelineMinimums\[field.field\]\)"/);
    assert.match(schedule, /:disabled="dateInputUnavailable\(field\)"/);
    assert.match(schedule, /program-date-error-/);
    assert.match(source, /<form novalidate[^>]*@submit\.prevent="nextStep"/);
});

test('new start and end dates must be today or later while unchanged published history is retained', () => {
    for (const field of ['application_opens_at', 'deadline', 'expected_results_at', 'handoff_deadline', 'support_starts_at', 'support_ends_at']) {
        assert.match(programConfigurationErrors({ [field]: '2026-11-15' })[field], /today or a future date/);
        const options = { today: '2026-11-16', savedDates: { [field]: '2026-09-01' }, preserveHistoricalDates: true };
        assert.deepEqual(errors({ [field]: '2026-09-01' }, options), {});
        assert.ok(errors({ [field]: '2026-09-02' }, options)[field]);
        assert.ok(errors({ [field]: '2026-09-01' }, { ...options, preserveHistoricalDates: false })[field]);
    }
    assert.deepEqual(programConfigurationErrors({ application_opens_at: '2026-11-16', deadline: '2026-11-16' }), {});
});

test('unchanged published timelines are kept, but a change triggers validation', () => {
    const dates = { deadline: '2027-06-01', expected_results_at: '2027-06-03', support_starts_at: '2027-06-02', support_ends_at: '2027-06-04' };
    const options = { today: '2026-11-16', savedDates: dates, preserveHistoricalDates: true };
    assert.deepEqual(errors(dates, options), {});
    assert.match(errors({ ...dates, support_ends_at: '2027-06-05' }, options).support_starts_at, /expected final results/);
    assert.deepEqual(errors({ ...dates, support_starts_at: '2027-06-03' }, options), {});
});
