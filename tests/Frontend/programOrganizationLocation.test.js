import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { organizationProgramLocation, organizationLocationWouldReplace } from '../../resources/js/support/programOrganizationLocation.js';

const organization = { provider_name: 'Learning Trust', provider_address: 'Office, Antipolo City', provider_latitude: '14.5861000', provider_longitude: '121.1756000' };

test('organization address and valid coordinates are copied as one independent value', () => {
    const result = organizationProgramLocation(organization);
    assert.deepEqual(result, { locationName: 'Learning Trust', locationAddress: 'Office, Antipolo City', latitude: '14.5861000', longitude: '121.1756000' });
    result.locationAddress = 'Different program venue';
    assert.equal(organization.provider_address, 'Office, Antipolo City');
});

test('missing, partial or invalid organization pins cannot leave stale program coordinates', () => {
    for (const overrides of [{ provider_latitude: null }, { provider_longitude: '' }, { provider_latitude: 91 }, { provider_address: '' }]) {
        const result = organizationProgramLocation({ ...organization, ...overrides });
        assert.equal(result.latitude, '');
        assert.equal(result.longitude, '');
    }
    assert.equal(organizationProgramLocation().locationAddress, '');
});

test('different venues and replaced pins need confirmation, matching or empty forms do not', () => {
    const source = organizationProgramLocation(organization);
    assert.equal(organizationLocationWouldReplace({}, source), false);
    assert.equal(organizationLocationWouldReplace({ ...source, latitude: 14.5861, longitude: 121.1756 }, source), false);
    assert.equal(organizationLocationWouldReplace({ ...source, locationAddress: 'Different office' }, source), true);
    assert.equal(organizationLocationWouldReplace({ ...source, locationName: 'Exam center' }, source), true);
    assert.equal(organizationLocationWouldReplace({ ...source, latitude: 14.6 }, source), true);
    assert.equal(organizationLocationWouldReplace(source, { ...source, latitude: '', longitude: '' }), true);
});

test('program form uses the existing organization payload and invalidates a pin only on manual address edits', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /organizationProgramLocation\(portalUser\)/);
    assert.match(source, /Object.assign\(form.value, organizationLocation\)/);
    assert.match(source, /cancelLabel: 'Keep current venue'/);
    assert.match(source, /@input="clearProgramAddressPin"/);
    assert.match(source, /&& programHasLocationPin.value/);
    assert.match(source, /:disabled="!organizationLocation.locationAddress"/);
});
