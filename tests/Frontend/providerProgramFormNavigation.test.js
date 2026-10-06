import assert from 'node:assert/strict';
import test from 'node:test';

import {
    providerProgramFormStepUrl,
    sectionFromProviderProgramFormPath,
} from '../../resources/js/support/providerProgramFormNavigation.js';

test('program form paths resolve to their canonical form section', () => {
    assert.equal(sectionFromProviderProgramFormPath('/provider/programs/42/edit/basics'), 'details');
    assert.equal(sectionFromProviderProgramFormPath('/provider/programs/42/edit/dates-location'), 'logistics');
    assert.equal(sectionFromProviderProgramFormPath('/provider/programs/42/edit/review/'), 'review');
});

test('unknown and create paths safely open the basics section', () => {
    assert.equal(sectionFromProviderProgramFormPath('/provider/programs/create'), 'details');
    assert.equal(sectionFromProviderProgramFormPath('/provider/programs/42/edit/unknown'), 'details');
});

test('program form step urls are generated only for known saved-program sections', () => {
    assert.equal(providerProgramFormStepUrl(42, 'logistics'), '/provider/programs/42/edit/dates-location');
    assert.equal(providerProgramFormStepUrl(null, 'logistics'), '');
    assert.equal(providerProgramFormStepUrl(42, 'unknown'), '');
});
