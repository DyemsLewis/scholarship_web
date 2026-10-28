import assert from 'node:assert/strict';
import test from 'node:test';
import { sessionScheduleError } from '../../resources/js/support/selectionSessionValidation.js';

const now = Date.parse('2027-06-01T00:00:00Z');
const form = { starts_at: '2027-06-02T01:00:00Z', ends_at: '2027-06-02T03:00:00Z', capacity: 30 };

test('session schedule rejects past, missing, and invalid start times', () => {
    for (const starts_at of ['', 'invalid', '2027-05-31T23:59:00Z']) {
        assert.match(sessionScheduleError({ ...form, starts_at }, 0, now), /future date/);
    }
    assert.equal(sessionScheduleError(form, 0, now), '');
});

test('session end is optional but must be strictly after start when set', () => {
    for (const ends_at of ['', null, '2027-06-02T03:00:00Z']) {
        assert.equal(sessionScheduleError({ ...form, ends_at }, 0, now), '');
    }
    for (const ends_at of [form.starts_at, 'invalid', '2027-06-02T00:00:00Z']) {
        assert.match(sessionScheduleError({ ...form, ends_at }, 0, now), /later than/);
    }
});

test('session capacity accounts for the existing roster and allows an unlimited room', () => {
    assert.match(sessionScheduleError({ ...form, capacity: 1 }, 2, now), /at 2 or higher/);
    for (const capacity of [2, 3, '', null]) {
        assert.equal(sessionScheduleError({ ...form, capacity }, 2, now), '');
    }
    for (const capacity of [0, -1, 1.5, 10_001, 'abc']) {
        assert.match(sessionScheduleError({ ...form, capacity }, 0, now), /whole number/);
    }
});
