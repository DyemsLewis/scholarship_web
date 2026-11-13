import { readFile } from 'node:fs/promises';

const output = process.env.PAYOUT_DETAILS_OUTPUT || 'storage/qa';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8032';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('accountability-hosted-part6', 'payout-details-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `${output}/payout-details-local.json`)
    .replace("const blocked = !['GET'", "const permittedPayout = request.method === 'POST' && /^\\/dashboard\\/benefit-release-records\\/[0-9]+\\/payout-details$/.test(route);\n            const blocked = !permittedPayout && !['GET'");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    // Run only against the isolated local database with sample digital releases.
    async function openRequiredRelease(user, method) {
        await login(user);
        const { body: { support } } = await get('/dashboard/monitoring/data');
        const base = support.focus.href;
        const { status, body } = await get(base + '/data?view=benefits');
        assert.equal(status, 200);
        const record = body.detail.content.directory.payout_required[0];
        assert.equal(record.method_label, method);
        await navigate(record.href, '!!document.querySelector("[data-benefit-next-step]")');
        assert.match(await client.evaluate('document.querySelector("[data-benefit-next-step]").innerText'), /Enter your payout details/);
        return { base, record };
    }

    const form = 'document.querySelector("[data-payout-details-dialog]")';
    const nextStep = 'document.querySelector("[data-benefit-next-step]")';
    const bank = await openRequiredRelease('learner.onetime.plan', 'Bank transfer');
    await click('Enter payout details', nextStep);
    await wait(() => client.evaluate('!!' + form), 'bank details form');
    await audit('[data-payout-details-dialog]');
    assert.match(await client.evaluate(form + '.innerText'), /Bank name/);
    assert.doesNotMatch(await client.evaluate(form + '.innerText'), /Registered mobile number/);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('applicant-payout-part-1-bank');

    const number = '000087654321';
    const fields = { institution: 'Example Savings Bank', account_holder: 'Example Recipient', account_number: number };
    await client.evaluate('(() => {const values=' + JSON.stringify(fields) + ';for (const [name,value] of Object.entries(values)) { const e=' + form + '.elements.namedItem(name); e.value=value; e.dispatchEvent(new Event("input",{bubbles:true})); }})()');
    await click('Review and submit', form);
    await wait(() => client.evaluate('!!document.querySelector("[data-outcome-confirmation]")'), 'submission confirmation');
    await click('Go back', 'document.querySelector("[data-outcome-confirmation]")');
    assert.equal(await client.evaluate(form + '.elements.namedItem("account_number").value'), number);
    await click('Review and submit', form);
    await click('Submit details', 'document.querySelector("[data-outcome-confirmation]")');
    await wait(() => client.evaluate(nextStep + '?.innerText.includes("Payout details submitted")'), 'submission saved');
    const saved = await get(bank.base + '/data?view=benefits&record=' + bank.record.record_key);
    assert.equal(saved.body.detail.content.selected.payout.status, 'submitted');
    assert.equal(saved.body.detail.content.selected.can_respond, false);
    assert.ok(!JSON.stringify(saved.body).includes(number));
    await shot('applicant-payout-part-1-submitted');
    await click('Replace details', nextStep);
    await wait(() => client.evaluate('!!' + form), 'replacement form');
    assert.equal(await client.evaluate(form + '.elements.namedItem("account_number").value'), '');
    await click('Cancel', form);

    await openRequiredRelease('learner.onetime.record', 'E-wallet');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await click('Enter payout details', nextStep);
    await wait(() => client.evaluate('!!' + form), 'e-wallet details form');
    await audit('[data-payout-details-dialog]');
    assert.match(await client.evaluate(form + '.innerText'), /Registered mobile number/);
    assert.doesNotMatch(await client.evaluate(form + '.innerText'), /Bank account number/);
    await shot('applicant-payout-part-1-wallet-mobile');

    await login('learner.onetime.confirm');
    const { body: { support } } = await get('/dashboard/monitoring/data');
    const data = (await get(support.focus.href + '/data?view=benefits')).body.detail.content;
    assert.equal(data.directory.payout_required.length, 0);
    await navigate(data.directory.action_required[0].href, '!!document.querySelector("[data-benefit-next-step]")');
    assert.match(await client.evaluate(nextStep + '.innerText'), /Confirm receipt/);
    assert.doesNotMatch(await client.evaluate(nextStep + '.innerText'), /Enter payout details/);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Private bank submission, clean replacement form, mobile wallet form, and unchanged in-person receipt.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
