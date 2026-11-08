import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { recipientAgreementReviewRows } from '../../resources/js/support/recipientAgreement.js';

const agreement = {
    commitment_type: 'reporting_and_participation', responsibilities: 'Remain enrolled and submit check-ins.',
    required_evidence: 'Current grades and enrollment certificate.', release_conditions: 'Accept the agreement before release.',
    duration: 'Current school year', noncompliance_consequence: 'A provider review is required.', exit_or_exception_process: 'Contact the provider.',
};
const component = readFileSync('resources/js/components/RecipientAgreementDetails.vue', 'utf8');
const descriptor = parse(component).descriptor;
const code = compileScript(descriptor, { id: 'agreement-details', inlineTemplate: true }).content
    .replace(/from (['"])(.*?)\1/g, (match, quote, name) => 'from '+JSON.stringify(name === 'vue'
        ? import.meta.resolve('vue') : new URL('../../resources/js/support/recipientAgreement.js', import.meta.url).href));
const { default: Details } = await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'));
const render = scholarship => renderToString(createSSRApp({ render: () => h(Details, { scholarship }) }));

test('structured agreement fields have readable labels and do not mutate saved terms', () => {
    const before = JSON.stringify(agreement);
    const rows = recipientAgreementReviewRows({ recipient_agreement: agreement });
    assert.equal(rows.length, 7);
    assert.equal(rows[0].label, 'Commitment type');
    assert.equal(rows[0].value, 'Reporting and participation');
    assert.equal(rows[1].value, agreement.responsibilities);
    assert.equal(JSON.stringify(agreement), before);
});

test('serialized and double-encoded objects display the same terms as structured records', () => {
    const expected = recipientAgreementReviewRows({ recipient_agreement: agreement });
    assert.deepEqual(recipientAgreementReviewRows({ recipient_agreement: JSON.stringify(agreement) }), expected);
    assert.deepEqual(recipientAgreementReviewRows({ recipient_agreement: JSON.stringify(JSON.stringify(agreement)) }), expected);
});

test('plain text and legacy contracts retain their text and paragraph breaks', () => {
    assert.equal(recipientAgreementReviewRows({ recipient_agreement: 'Remain enrolled.\nAttend orientation.' })[0].value, 'Remain enrolled.\nAttend orientation.');
    assert.equal(recipientAgreementReviewRows({ return_service_contract: 'Attend activities.', renewal_policy: 'Maintain grades.' })[0].value, 'Attend activities.\n\nMaintain grades.');
    assert.equal(recipientAgreementReviewRows({ recipient_agreement: '"Quoted opening without a closing quote' })[0].value, '"Quoted opening without a closing quote');
});

test('absent agreements are distinct from unreadable or unsupported agreement data', () => {
    for (const value of [null, undefined, '', {}, '   ']) assert.deepEqual(recipientAgreementReviewRows({ recipient_agreement: value }), []);
    for (const value of ['{bad json', [], true, { unsupported: { deep: 'value' } }]) {
        const rows = recipientAgreementReviewRows({ recipient_agreement: value });
        assert.equal(rows[0].issue, true);
        assert.doesNotMatch(rows[0].value, /\[object Object\]|bad json|deep/);
    }
});

test('unknown enum labels and string lists are formatted without raw keys or objects', () => {
    const rows = recipientAgreementReviewRows({ recipient_agreement: { commitment_type: 'receipt_confirmation', required_evidence: [' Receipt ', { bad: true }, 'Enrollment'], duration: 2 } });
    assert.equal(rows[0].value, 'Receipt Confirmation');
    assert.equal(rows[1].value, 'Receipt\nEnrollment');
    assert.equal(rows[2].value, '2');
});

test('the review component renders labelled text and escapes provider input', async () => {
    const html = await render({ recipient_agreement: { ...agreement, responsibilities: '<script>alert(1)</script>' } });
    assert.match(html, /data-recipient-agreement-details/);
    assert.match(html, /Recipient responsibilities/);
    assert.match(html, /Reporting and participation/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /<script>|commitment_type|reporting_and_participation|release_conditions/);
    assert.match(await render({}), /No recipient agreement has been provided/);
});

test('admin program review uses the formatted component instead of interpolating the raw agreement', () => {
    const page = readFileSync('resources/js/pages/admin/reviews/ProgramReviewRecordPage.vue', 'utf8');
    assert.match(page, /<RecipientAgreementDetails :scholarship="program"/);
    assert.doesNotMatch(page, /\{\{\s*program\.recipient_agreement/);
});
