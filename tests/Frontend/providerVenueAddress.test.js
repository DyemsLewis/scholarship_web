import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { emptyVenueAddress, venueAddressText } from '../../resources/js/support/venueAddress.js';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
const files = ['components/ProviderVenueAddress.vue', 'components/RecipientCheckInAppointment.vue', 'pages/provider/selection/SelectionSessionFormPage.vue', 'pages/provider/selection/components/SelectionSessionFormModal.vue', 'pages/provider/monitoring/MonitoringPlanPage.vue', 'pages/provider/releases/BenefitReleaseSchedulePage.vue'];
for (const file of files) test(file + ' compiles with structured addresses', () => {
    const { descriptor, errors } = parse(read(file), { filename: file }); assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
});

test('formatting preserves address order and never duplicates venue or municipality labels', () => {
    const address = { ...emptyVenueAddress(), venue: ' Office ', street: 'School  Road', barangay: 'Village', city: 'City', province: 'Province', region: 'NCR' };
    assert.equal(venueAddressText(address), 'Office, School Road, Village, City, Province, NCR');
    assert.equal(venueAddressText(address, false), 'School Road, Village, City, Province, NCR');
    assert.equal(venueAddressText({ venue: 'City', city: 'City' }), 'City');
    assert.equal(venueAddressText(null), '');
});

test('one shared editor covers creation editing check-ins rescheduling and benefits', () => {
    for (const file of files.slice(1)) {
        assert.match(read(file), /<ProviderVenueAddress/);
        assert.match(read(file), /address_details/);
    }
    const source = read(files[0]);
    for (const label of ['Region', 'Province', 'City / municipality', 'Barangay', 'Street / address details', 'Venue / building / room']) assert.ok(source.includes(label));
    assert.match(source, /data-legacy-venue/);
    assert.match(source, /current !== version/);
    assert.match(source, /pinValues.value = \{ \.\.\.pinValues.value, \[key\]: value \}/);
    assert.match(source, /latitude: null, longitude: null/);
    assert.match(source, /Enter the province and city/);
});

test('check-in records expose the saved map and in-person releases can include instructions', () => {
    for (const file of ['components/RecipientCheckInAppointment.vue', 'pages/provider/monitoring/MonitoringCheckInRecordPage.vue']) {
        const source = read(file);
        assert.match(source, /<LocationMapLink/);
        assert.match(source, /:latitude="(?:appointment|cycle.handoff).latitude"/);
        assert.match(source, /:longitude="(?:appointment|cycle.handoff).longitude"/);
    }
    const source = read('pages/provider/releases/BenefitReleaseSchedulePage.vue');
    assert.match(source, /<textarea v-model="form.instructions"/);
    assert.doesNotMatch(source, /<label v-else[^>]*>[^<]*Instructions/);
    assert.match(source, /Shown to recipients when they open this release/);
});
