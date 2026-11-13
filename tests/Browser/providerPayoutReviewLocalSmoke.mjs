import { readFile } from 'node:fs/promises';

const releaseId = Number(process.env.PAYOUT_REVIEW_RELEASE_ID);
if (!Number.isSafeInteger(releaseId) || releaseId < 1) throw new Error('Set the sample release ID in the isolated preview database.');
const output = process.env.PAYOUT_REVIEW_OUTPUT || 'storage/qa';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8033';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('accountability-hosted-part6', 'payout-review-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `${output}/payout-review-local.json`)
    .replace("const blocked = !['GET'", "const payoutWrite = /^(PATCH \\/provider|POST \\/dashboard)\\/benefit-release-records\\/[0-9]+\\/payout-details$/.test(request.method + ' ' + route);\n            const blocked = !payoutWrite && !['GET'");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    const url = '/provider/workspaces/releases/distributions/' + ${releaseId};
    const payout = 'document.querySelector("[data-provider-payout-status]")';
    const dialog = 'document.querySelector("[data-provider-payout-dialog]")';
    const outcome = 'document.querySelector("[data-outcome-confirmation]")';
    const option = 'document.querySelector("option[value=released]")';
    const number = '000000000123';
    const correctedNumber = '000000000456';
    async function openProvider(status) {
        await login('marilag.releases');
        await navigate(url, '!!' + payout);
        assert.match(await client.evaluate(payout + '.innerText'), status);
    }
    async function waitForText(scope, text) {
        await wait(() => client.evaluate(scope + '?.innerText.includes(' + JSON.stringify(text) + ')'), text);
    }

    await openProvider(/Ready for payout review/);
    const before = await get(url + '/data');
    assert.equal(before.body.release.records[0].payout.status, 'submitted');
    assert.ok(!JSON.stringify(before.body).includes(number));
    assert.equal(await client.evaluate(option + '.disabled'), true);
    await click('Review payout details', payout);
    await waitForText(dialog, number);
    await audit('[data-provider-payout-dialog]');
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('provider-payout-part-2-review');

    await click('Request correction', dialog);
    await client.evaluate('(()=>{const select=' + dialog + '.querySelector("select");select.value="destination";select.dispatchEvent(new Event("change",{bubbles:true}));})()');
    await click('Notify applicant', dialog);
    await waitForText(outcome, 'Request a payout correction?');
    await click('Request correction', outcome);
    await waitForText(payout, 'Correction requested');
    assert.equal(await client.evaluate(option + '.disabled'), true);
    assert.equal(await client.evaluate('!!' + dialog), false);

    await login('learner.onetime.plan');
    const { body: { support } } = await get('/dashboard/monitoring/data');
    const applicantData = (await get(support.focus.href + '/data?view=benefits')).body.detail.content;
    assert.equal(applicantData.directory.payout_required[0].payout_status, 'correction_requested');
    await navigate(applicantData.directory.payout_required[0].href, '!!document.querySelector("[data-benefit-next-step]")');
    const nextStep = 'document.querySelector("[data-benefit-next-step]")';
    assert.match(await client.evaluate(nextStep + '.innerText'), /Check the account or registered mobile number/);
    await click('Update payout details', nextStep);
    const form = 'document.querySelector("[data-payout-details-dialog]")';
    await wait(() => client.evaluate('!!' + form), 'applicant replacement form');
    assert.equal(await client.evaluate(form + '.elements.namedItem("account_number").value'), '');
    const fields = { institution: 'Example Savings Bank', account_holder: 'Xavier De Vera', account_number: correctedNumber };
    await client.evaluate('(() => {const values=' + JSON.stringify(fields) + ';for (const [name,value] of Object.entries(values)) { const e=' + form + '.elements.namedItem(name); e.value=value; e.dispatchEvent(new Event("input",{bubbles:true})); }})()');
    await click('Review and submit', form);
    await waitForText(outcome, 'Replace payout details?');
    await click('Replace details', outcome);
    await waitForText(nextStep, 'Payout details submitted');

    await openProvider(/Ready for payout review/);
    await click('Review payout details', payout);
    await waitForText(dialog, correctedNumber);
    assert.doesNotMatch(await client.evaluate(dialog + '.innerText'), new RegExp(number));
    await click('Approve details', dialog);
    await waitForText(outcome, 'Approve these payout details?');
    await click('Approve details', outcome);
    await waitForText(payout, 'Payout details approved');
    assert.equal(await client.evaluate(option + '.disabled'), false);
    const approved = (await get(url + '/data')).body.release.records[0];
    assert.equal(approved.payout.status, 'approved');
    assert.equal(approved.status, 'scheduled');
    assert.ok(!JSON.stringify(approved).includes(correctedNumber));
    await shot('provider-payout-part-2-approved');

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Private review, correction, applicant resubmission, approval gate, and no transfer recorded.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
