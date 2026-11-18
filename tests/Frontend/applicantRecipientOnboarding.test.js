import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const read = file => readFileSync(`resources/js/components/${file}`, 'utf8');
const compile = (file, imports = {}) => {
    const { descriptor } = parse(read(file), { filename: file });
    const code = compileScript(descriptor, { id: file, inlineTemplate: true }).content
        .replace(/from (['"])(.*?)\1/g, (match, quote, specifier) => {
            const resolved = specifier === 'vue' ? import.meta.resolve('vue') : imports[specifier];
            return resolved ? `from ${JSON.stringify(resolved)}` : match;
        });
    return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
};
const textSummary = compile('ApplicantTextSummary.vue');
const disclosure = compile('FinalAgreementDisclosure.vue', { './ApplicantTextSummary.vue': textSummary });
const { default: Disclosure } = await import(disclosure);
const { default: Summary } = await import(compile('RecipientAgreementSummary.vue', { './FinalAgreementDisclosure.vue': disclosure }));
const { default: Requirement } = await import(compile('monitoring-detail/FinalAgreementRequirement.vue', { '../ApplicantTextSummary.vue': textSummary }));
const render = (component, props) => renderToString(createSSRApp(component, props));
const policy = { final_agreement_required: true, instructions: 'Sign at orientation or the first collection visit.' };

for (const file of [
    'FinalAgreementDisclosure.vue', 'RecipientAgreementSummary.vue', 'ScholarshipAwardDetails.vue',
    'application-detail/RecipientAgreementDialog.vue', 'monitoring-detail/FinalAgreementRequirement.vue',
    'monitoring-detail/MonitoringCheckIns.vue', 'monitoring-detail/MonitoringOverviewRecord.vue',
    '../pages/UserMonitoringDetailPage.vue',
]) {
    test(`${file} compiles with applicant onboarding`, () => {
        const { descriptor, errors } = parse(read(file), { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('the requirement is visible outside the embedded offer tabs and uses the frozen snapshot', async () => {
    const html = await render(Summary, { agreement: { snapshot: { program_title: 'Learning award', onboarding: policy } }, embedded: true });
    assert.ok(html.indexOf('data-final-agreement-disclosure') < html.indexOf('aria-label="Agreement sections"'));
    assert.match(html, /Final provider agreement required/);
    assert.match(html, /Sign at orientation/);
    assert.match(html, /Confirming intention is not signing a contract/);
    const legacy = await render(Summary, { agreement: { snapshot: {} }, embedded: true });
    assert.doesNotMatch(legacy, /data-final-agreement-disclosure/);
    assert.doesNotMatch(await render(Disclosure, { policy: { final_agreement_required: false } }), /data-final-agreement-disclosure/);
});

const record = {
    state: 'pending', can_proceed: true, status_label: 'Pending provider confirmation',
    guidance: 'Follow the instructions outside the platform.', instructions: policy.instructions,
    provider_instructions: 'Bring your school ID.', instructions_published_label: 'Nov 18, 2026',
    offer_href: '/dashboard/applications/example/outcome?action=view-agreement',
    benefits_href: '/dashboard/monitoring/example?view=benefits',
    contact: { email: 'office@example.test', email_href: 'mailto:office@example.test', number: '09171234567', phone_href: 'tel:09171234567' },
};

test('requirements show original and supplemental instructions without a signing or upload action', async () => {
    const html = await render(Requirement, { record });
    assert.match(html, /id="final-agreement"/);
    assert.match(html, /Pending provider confirmation/);
    assert.match(html, /Bring your school ID/);
    assert.match(html, /Sign at orientation/);
    assert.match(html, /mailto:office@example.test/);
    assert.match(html, /Existing events and collection dates/);
    assert.doesNotMatch(html, /<form|<input|<textarea|Mark complete|Confirm signing/);
    assert.doesNotMatch(read('monitoring-detail/FinalAgreementRequirement.vue'), /axios|fetch\(|emit\(|type="file"/);
});

test('completed and inactive requirements show status without requesting more onboarding', async () => {
    const html = await render(Requirement, { record: { ...record, state: 'completed', status_label: 'Completed', can_proceed: false, completed_label: 'Nov 18, 2026' } });
    assert.match(html, /Provider-confirmed completion/);
    assert.match(html, /Nov 18, 2026/);
    assert.doesNotMatch(html, /Existing events and collection dates/);
    const awaiting = await render(Requirement, { record: { ...record, state: 'awaiting_intention', can_proceed: false } });
    assert.match(awaiting, /Review offer first/);
});

test('public program terms and offer confirmation disclose the requirement without changing response payloads', () => {
    assert.match(read('ScholarshipAwardDetails.vue'), /<FinalAgreementDisclosure :policy="content.recipient_terms\?\.onboarding_policy"/);
    const dialog = read('application-detail/RecipientAgreementDialog.vue');
    assert.match(dialog, /props.agreement\?\.snapshot\?\.onboarding\?\.final_agreement_required/);
    assert.match(dialog, /Scholarship Support > Requirements/);
    assert.match(dialog, /confirmOutcome/);
    assert.match(dialog, /emit\('respond', outcome\)/);
    const page = read('../pages/UserMonitoringDetailPage.vue');
    assert.match(page, /await loadMonitoringRecord\(\);\s+await nextTick\(\);/);
    assert.match(page, /window.location.hash === '#final-agreement'/);
    assert.match(page, /requirement\?\.focus\(\{ preventScroll: true \}\)/);
});
