import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { benefitReleaseGroups, benefitNextStep, benefitCardGuidance, benefitMethodIcon } from '../../resources/js/support/benefitReleasePresentation.js';

const release = (key, extra = {}) => ({ record_key: key, title: 'Learning allowance', href: `/dashboard/monitoring/1/benefits/${key}`, delivery: { key: 'scheduled', tone: 'neutral', label: 'Scheduled', title: 'Prepare for collection', date_caption: 'Scheduled for', date_label: 'Nov 20, 2026 09:00 AM' }, ...extra });

test('six server buckets become three ordered groups without duplicating releases or mutating data', () => {
    const record = release('release-1');
    const directory = { follow_up: [record], action_required: [record, release('release-2')], payout_required: [release('release-3', { payout_status: 'correction_requested' })], upcoming: [record, release('release-4')], in_progress: [release('release-5')], completed: [release('release-6')] };
    const before = JSON.stringify(directory);
    const groups = benefitReleaseGroups(directory);
    assert.deepEqual(groups.map((group) => group.key), ['attention', 'planned', 'completed']);
    assert.deepEqual(groups.map((group) => group.releases.length), [3, 2, 1]);
    assert.equal(groups[0].releases[0].action_label, 'Open follow-up');
    assert.equal(groups[0].releases[2].action_label, 'Update payout details');
    assert.equal(groups[1].releases[0].href, directory.upcoming[1].href);
    assert.equal(JSON.stringify(directory), before);
    assert.deepEqual(benefitReleaseGroups().map((group) => group.releases.length), [0, 0, 0]);
});

test('payout entry, corrections, submitted details and approval never imply a transfer or receipt', () => {
    for (const status of ['required', 'correction_requested', 'submitted', 'approved']) {
        const item = release('release-1', { payout: { status, method: 'e_wallet', correction_label: 'Check the wallet number.' } });
        const step = benefitNextStep(item);
        assert.ok(step.title);
        assert.doesNotMatch(step.title, /received|transfer sent/i);
        if (status === 'required') assert.match(step.description, /wallet/);
        if (status === 'correction_requested') assert.equal(step.description, 'Check the wallet number.');
        if (status === 'approved') assert.match(step.description, /actually arrives/);
    }
    assert.match(benefitNextStep(release('release-1', { payout: { status: 'required', method: 'bank_transfer' } })).description, /bank account/);
});

test('confirmation, follow-up and terminal outcomes take precedence over outdated payout metadata', () => {
    for (const key of ['fulfilled', 'resolved', 'issue_open', 'inactive', 'missed', 'withheld', 'unclaimed', 'declined', 'rescheduled', 'confirmation_needed']) {
        const delivery = { key, title: 'Recorded delivery result', description: 'Latest provider update.' };
        const item = release('release-1', { delivery, payout: { status: 'approved' } });
        assert.equal(benefitNextStep(item), delivery);
        assert.equal(benefitCardGuidance(item), delivery.title);
    }
    const item = release('release-1', { can_respond: true, payout: { status: 'submitted' } });
    assert.equal(benefitNextStep(item), item.delivery);
    item.can_respond = false; item.follow_up = { can_reply: true };
    assert.equal(benefitNextStep(item), item.delivery);
});

test('card actions are specific to the actual applicant task', () => {
    assert.match(benefitCardGuidance(release('r1', { priority_key: 'follow_up' })), /reply/);
    assert.match(benefitCardGuidance(release('r2', { priority_key: 'action_required' })), /before confirming/);
    assert.match(benefitCardGuidance(release('r3', { priority_key: 'payout_required', payout_status: 'required' })), /Add your payout/);
    assert.match(benefitCardGuidance(release('r4', { priority_key: 'payout_required', payout_status: 'correction_requested' })), /correction/);
    assert.match(benefitMethodIcon('bank_transfer'), /building-columns/);
    assert.match(benefitMethodIcon('e_wallet'), /mobile/);
    assert.equal(benefitMethodIcon('unknown'), 'fa-solid fa-gift');
});

const componentSource = readFileSync(new URL('../../resources/js/components/monitoring-detail/BenefitReleaseCard.vue', import.meta.url), 'utf8');
const { descriptor } = parse(componentSource);
let code = compileScript(descriptor, { id: 'benefit-card', inlineTemplate: true }).content;
for (const quote of ['"', "'"]) code = code.replaceAll(`from ${quote}vue${quote}`, `from ${JSON.stringify(import.meta.resolve('vue'))}`);
for (const name of ['benefitReleasePresentation', 'checkInStatus']) code = code.replaceAll(`from '../../support/${name}.js'`, `from ${JSON.stringify(new URL(`../../resources/js/support/${name}.js`, import.meta.url).href)}`);
const { default: Card } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

test('a release card renders readable dates, delivery method, amount and its existing link', async () => {
    const row = { ...release('release-1'), release_method: 'bank_transfer', method_label: 'Bank transfer', amount_label: 'PHP 5,000.00', action_label: 'View instructions' };
    const html = await renderToString(createSSRApp(Card, { release: row }));
    for (const text of ['Learning allowance', 'Bank transfer', 'PHP 5,000.00', 'Scheduled for', 'Nov 20, 2026', 'View instructions', row.href]) assert.ok(html.includes(text), text);
    assert.doesNotMatch(html, /Receipt confirmed|Received on/);
});

test('non-cash and unscheduled records do not acquire invented money or dates', async () => {
    const html = await renderToString(createSSRApp(Card, { release: release('release-2', { amount_label: null, delivery: { key: 'waiting', label: 'Awaiting release update', title: 'Wait for a release update' }, action_label: 'View update' }) }));
    assert.doesNotMatch(html, /Release amount|PHP|Nov 20/);
    assert.match(html, /Not provided yet/);
});
