import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import { createSSRApp } from 'vue';
import { scholarshipBenefitFacts } from '../../resources/js/support/scholarshipBenefits.js';

const name = 'ScholarshipAwardDetails.vue';
const source = readFileSync(new URL(`../../resources/js/components/${name}`, import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: name });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'scholarship-award-details', inlineTemplate: true });
const code = script.content.replace(/from (['"])(vue|\.\.\/support\/scholarshipBenefits)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL('../../resources/js/support/scholarshipBenefits.js', import.meta.url).href)}`);
const { default: Award } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const render = (content) => renderToString(createSSRApp(Award, { content }));

test('benefit facts preserve fractional amounts, zero, and one-time or recurring frequency', () => {
    assert.deepEqual(scholarshipBenefitFacts({ amount: '9000.50', coverage_label: 'Partial coverage', frequency_label: 'Per term or semester', duration: 'Two semesters' }), [
        { label: 'Amount', value: '\u20b19,000.50' },
        { label: 'Coverage', value: 'Partial coverage' },
        { label: 'Frequency', value: 'Per term or semester' },
        { label: 'Duration', value: 'Two semesters' },
    ]);
    assert.deepEqual(scholarshipBenefitFacts({ amount: 0, frequency_label: 'One-time' }), [
        { label: 'Amount', value: '\u20b10' }, { label: 'Frequency', value: 'One-time' },
    ]);
    assert.deepEqual(scholarshipBenefitFacts({ amount: null }), []);
    assert.deepEqual(scholarshipBenefitFacts({ amount: 'invalid' }), []);
});

test('award details surface published commitments and keep secondary terms available without repeating them', async () => {
    const term = (key, label, value) => ({ key, label, value });
    const html = await render({ benefits: [
        { title: 'Learning allowance', type: 'allowance', amount: 9000, frequency_label: 'Per term or semester', duration: 'Two semesters' },
        { title: 'Mentoring', type: 'mentorship', frequency_label: 'Monthly' },
    ], support_period: 'Aug 1 to May 1', terms: [
        term('renewal_policy', 'Renewal policy', 'Reviewed yearly.'),
        term('release_conditions', 'Benefit release conditions', 'Bring proof of enrollment before release.'),
        term('responsibilities', 'Recipient responsibilities', 'Attend the scheduled check-in.'),
        term('noncompliance_consequence', 'If a requirement is not completed', 'The provider may pause later releases.'),
    ] });
    assert.match(html, /<dt[^>]*>Amount<\/dt>[\s\S]*\u20b19,000/);
    assert.match(html, /<dt[^>]*>Frequency<\/dt>[\s\S]*Per term or semester/);
    assert.match(html, /<dt[^>]*>Duration<\/dt>[\s\S]*Two semesters/);
    assert.match(html, /Aug 1 to May 1/);
    const keyTerms = html.slice(html.indexOf('data-scholarship-key-terms'), html.indexOf('<details'));
    assert.match(keyTerms, /Attend the scheduled check-in[\s\S]*Bring proof of enrollment before release[\s\S]*may pause later releases/);
    assert.doesNotMatch(keyTerms, /Renewal policy/);
    assert.match(html, /<details[^>]*data-scholarship-additional-terms(?![^>]*open)/);
    assert.equal((html.match(/Renewal policy/g) ?? []).length, 1);
});

test('unpublished amounts and terms do not invent any payment schedule or responsibilities', async () => {
    const html = await render({ benefits: [{ title: 'Mentoring', type: 'mentorship', amount: null }], support_period: 'Not specified', terms: [] });
    assert.match(html, /Mentoring/);
    assert.match(html, /No additional recipient terms were published/);
    assert.doesNotMatch(html, /Support period|>Amount<|>Frequency<|>Duration<|data-scholarship-key-terms/);
});
