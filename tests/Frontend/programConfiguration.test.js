import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { programConfigurationErrors } from '../../resources/js/support/programConfiguration.js';

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

test('dates remain ordered without requiring unfilled optional draft fields', () => {
    const cases = [
        ['deadline', 'application_opens_at', 'application_opens_at'],
        ['expected_results_at', 'deadline', 'expected_results_at'],
        ['handoff_deadline', 'deadline', 'handoff_deadline'],
        ['support_ends_at', 'support_starts_at', 'support_ends_at'],
    ];
    for (const [later, earlier, field] of cases) {
        assert.ok(programConfigurationErrors({ [later]: '2027-06-01', [earlier]: '2027-06-02' })[field]);
        assert.deepEqual(programConfigurationErrors({ [later]: '2027-06-02', [earlier]: '2027-06-02' }), {});
        assert.deepEqual(programConfigurationErrors({ [later]: '', [earlier]: '2027-06-02' }), {});
    }
    assert.deepEqual(programConfigurationErrors({}), {});
});

test('ongoing support is allowed to overlap an intake instead of imposing a new business workflow', () => {
    assert.deepEqual(programConfigurationErrors({
        application_opens_at: '2027-06-01', deadline: '2027-09-01',
        support_starts_at: '2027-06-15', support_ends_at: '2028-06-15',
    }), {});
});

test('program editor applies conflict checks before saving and uses the existing alert dialog', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /programConfigurationErrors\(/);
    assert.match(source, /async function save\([^]*?configurationErrors\.value[^]*?showAlert\('Check the program details'/);
    assert.match(source, /:min="Number\(form\.slotsAvailable\) \|\| 1"/);
    assert.match(source, /:min="form\.supportStartsAt \|\| undefined"/);
    assert.match(source, /:min="form\.deadline \|\| undefined"/);
    assert.match(source, /<form novalidate[^>]*@submit\.prevent="nextStep"/);
});
