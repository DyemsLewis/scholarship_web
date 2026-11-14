import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { programReviewDate, programReviewSummary } from '../../resources/js/support/programReviewSummary.js';

const form = () => ({
    title: 'Campus support', category: 'Financial assistance', programCycle: '2026-2027', description: 'Support for continuing learners.', officialProgramUrl: 'https://example.test/grant',
    benefits: [{ type: 'allowance', title: 'Learning allowance', amount: '9000.00', frequency: 'per_term', duration: 'Two terms', coverage: 'fixed', description: 'Books and materials.' }],
    slotsAvailable: 25, applicationLimit: 250, supportStartsAt: '2027-01-01', supportEndsAt: '2027-12-01',
    applicationOpensAt: '2026-11-01', deadline: '2026-12-15', expectedResultsAt: '2026-12-30',
    locationName: 'Learning center', locationAddress: 'Antipolo City', latitude: '14.6', longitude: '121.1',
    contactEmail: 'office@example.test', contactNumber: '09175550000', contactPerson: 'Program office', contactDepartment: 'Scholarships',
    requirements: 'Certificate of enrollment\nLatest grades', optionalRequirements: 'Reference letter', postQualificationRequirements: 'Original school ID\nOriginal enrollment record',
    applicationMode: 'online', handoffMode: 'onsite', handoffDeadline: '2026-12-20', handoffInstructions: 'Present the originals.',
    handoffLocationName: 'Records desk', handoffLocationAddress: 'Antipolo City', handoffLatitude: '14.6', handoffLongitude: '121.1', handoffUrl: 'https://example.test/unused',
    selectionStages: ['screening', 'formal_application', 'interview', 'exam', 'decision'],
    recipientAgreement: { commitment_type: 'reporting', release_conditions: 'After agreement acceptance.', responsibilities: 'Attend check-ins.', required_evidence: 'Current enrollment.', duration: 'One school year', noncompliance_consequence: 'Support may be paused after review.', exit_or_exception_process: 'Contact the program office.' },
});
const options = () => ({
    eligibilitySummary: 'Verified senior high school learners.',
    rules: [{ key: 'education_level', mode: 'required', value: 'Senior High School' }, { key: 'academic', mode: 'required', value: 'Minimum average: 85.00%' }, { key: 'course', mode: 'preferred', value: 'STEM' }],
    scoring: { available: true, fixed: true, rows: [{ key: 'screening', weight: 30 }], formula: 'Pre-screening score x 30% + Exam score x 40% + Interview score x 30%' },
    catalogs: { benefitTypes: [['allowance', 'Allowance']], frequencyOptions: [['per_term', 'Per term or semester']], stages: [['screening', 'Pre-screening'], ['formal_application', 'Formal application'], ['interview', 'Interview'], ['exam', 'Exam'], ['decision', 'Final decision']] },
    hasLogo: true, coverLabel: 'Uploaded image',
});
const value = (section, label) => section.facts.find(item => item.label === label)?.value;

test('all six review summaries show actual configured values without mutating the form', () => {
    const input = form(), config = options();
    const before = JSON.stringify({ input, config });
    const sections = programReviewSummary(input, config);
    assert.deepEqual(Object.keys(sections), ['basics', 'support', 'dates-location', 'eligibility', 'application', 'selection']);
    assert.equal(value(sections.basics, 'Program title'), input.title);
    assert.equal(value(sections.basics, 'Scholarship logo'), 'Added');
    assert.equal(value(sections.support, 'Recipient slots'), '25');
    assert.equal(value(sections['dates-location'], 'Public venue'), 'Learning center\nAntipolo City');
    assert.equal(value(sections['dates-location'], 'Public map pin'), 'Selected');
    assert.equal(value(sections.eligibility, 'Required rules'), '2');
    assert.equal(value(sections.eligibility, 'Provider preferences'), '1');
    assert.equal(value(sections.eligibility, 'Target education'), 'Senior High School');
    assert.equal(sections.eligibility.details[0].value, config.eligibilitySummary);
    assert.equal(JSON.stringify({ input, config }), before);
});

test('benefit summaries include real value, coverage, frequency and duration, including a zero value', () => {
    const input = form();
    const sections = programReviewSummary(input, options());
    assert.equal(sections.support.benefits[0].title, 'Learning allowance');
    for (const expected of ['9,000', 'Fixed value', 'Per term or semester', 'Two terms']) assert.ok(sections.support.benefits[0].summary.includes(expected));
    assert.equal(sections.support.details[0].value, 'Books and materials.');
    input.benefits[0].amount = 0;
    assert.match(programReviewSummary(input, options()).support.benefits[0].summary, /0/);
});

test('document lists and handoff instructions stay separate from pre-screening', () => {
    const sections = programReviewSummary(form(), options());
    assert.equal(value(sections.application, 'Pre-screening files'), '2 required / 1 optional');
    assert.deepEqual(sections.application.details.find(item => item.label === 'Required pre-screening files').items, ['Certificate of enrollment', 'Latest grades']);
    assert.deepEqual(sections.application.details.find(item => item.label === 'After-qualification documents').items, ['Original school ID', 'Original enrollment record']);
    assert.equal(sections.application.details.find(item => item.label === 'Instructions').value, 'Present the originals.');
    assert.ok(!sections.application.details.some(item => item.label === 'Official link'));
});

test('inactive handoff fields and profile-only uploads are not presented as requirements', () => {
    const input = { ...form(), applicationMode: 'provider_review', handoffMode: 'online', handoffUrl: 'https://example.test/apply' };
    let section = programReviewSummary(input, options()).application;
    assert.equal(value(section, 'Pre-screening files'), 'No uploads required');
    assert.equal(value(section, 'Pre-screening method'), 'Profile review only');
    assert.ok(!section.details.some(item => item.label === 'Required pre-screening files' || item.label === 'Formal application venue'));
    assert.equal(section.details.find(item => item.label === 'Official link').value, input.handoffUrl);
    input.handoffMode = 'provider_contact';
    section = programReviewSummary(input, options()).application;
    assert.equal(value(section, 'Formal application method'), 'Wait for provider contact');
    assert.ok(!section.details.some(item => item.label === 'Official link'));
});

test('selection preserves real stage order, frozen DSS basis and complete agreement terms', () => {
    const section = programReviewSummary(form(), options()).selection;
    assert.equal(value(section, 'Stage order'), 'Pre-screening > Formal application > Interview > Exam > Final decision');
    assert.match(value(section, 'DSS scoring basis'), /^Fixed intake: /);
    assert.equal(value(section, 'Recipient commitment'), 'Progress or utilization reports');
    assert.equal(section.details.length, 6);
    assert.equal(section.details.at(-1).value, 'Contact the program office.');
    const input = form(); input.recipientAgreement.commitment_type = 'none';
    assert.deepEqual(programReviewSummary(input, options()).selection.details.map(item => item.label), ['Conditions for receiving benefits']);
    const config = options(); config.scoring = { legacy: true };
    assert.match(value(programReviewSummary(input, config).selection, 'DSS scoring basis'), /without a numeric ranking score/);
});

test('unsaved changes and blank values are represented rather than replaced with saved defaults', () => {
    const input = form(); input.title = 'Unsaved new title'; input.applicationLimit = ''; input.latitude = null;
    const sections = programReviewSummary(input, options());
    assert.equal(value(sections.basics, 'Program title'), 'Unsaved new title');
    assert.equal(value(sections.support, 'Application limit'), 'Not set');
    assert.equal(value(sections['dates-location'], 'Public map pin'), 'Not set');
    assert.equal(value(programReviewSummary({}, {}).basics, 'Program title'), 'Not set');
    assert.doesNotMatch(JSON.stringify(programReviewSummary({}, {})), /NaN|Invalid Date/);
});

test('date-only values are formatted without UTC date shifts and optional blanks stay explicit', () => {
    assert.equal(programReviewDate('2027-01-01'), 'Jan 1, 2027');
    assert.equal(programReviewDate('2027-01-01T00:00:00.000Z'), 'Jan 1, 2027');
    assert.equal(programReviewDate('', 'Not specified'), 'Not specified');
    assert.equal(programReviewDate('2027-02-31'), '2027-02-31');
});

test('summary component compiles, uses existing readiness and edit events, and adds no save or submission path', () => {
    const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
    const filename = 'pages/provider/programs/components/ProgramReviewSummary.vue';
    const source = read(filename);
    const { descriptor, errors } = parse(source, { filename });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'program-review' });
    assert.deepEqual(compileTemplate({ id: 'program-review', filename, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /\$emit\('edit', step.id\)/);
    assert.match(source, /checks\[step.id\]/);
    assert.match(source, /issues\[step.id\]/);
    assert.match(source, /<summary/);
    assert.doesNotMatch(source, /axios|fetch\(|v-html|v-model|<form|type="submit"/);
    const parent = read('pages/provider/programs/ProgramFormPage.vue');
    assert.match(parent, /:summaries="reviewSummaries"[\s\S]*@edit="openStep"/);
    assert.match(parent, /v-model="form.termsAccepted"/);
    assert.match(parent, /@click="submitProgram"/);
});
