import { readFile } from 'node:fs/promises';

const port = Number(process.env.SUPPORT_BENEFITS_PORT || 8030);
const output = process.env.SUPPORT_BENEFITS_OUTPUT || 'storage/qa';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = 'http://127.0.0.1:${port}';`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('accountability-hosted-part6', 'support-benefits-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `${output}/support-benefits-local.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('learner.onetime.confirm');
    const landing = (await get('/dashboard/monitoring/data')).body.support;
    const recordUrl = landing.focus.href;
    const { status, body } = await get(recordUrl + '/data?view=benefits');
    assert.equal(status, 200);
    assert.equal(body.detail.content.one_time, true);
    assert.equal(body.detail.navigation.some(tab => tab.key === 'check-ins'), false);
    const release = body.detail.content.directory.action_required[0];
    assert.equal(release.delivery.label, 'Confirmation needed');
    await navigate(recordUrl + '/benefits', '!!document.querySelector("[data-monitoring-benefits] a")');
    assert.match(await client.evaluate('document.querySelector("[data-monitoring-benefits]").innerText'), /Confirm what you received/);
    await shot('applicant-support-part-4-benefits');

    await navigate(release.href, '!!document.querySelector("[data-benefit-next-step]") && document.querySelector("[data-benefit-next-step]").innerText.includes("Report a problem")');
    const nextStep = 'document.querySelector("[data-benefit-next-step]")';
    const delivery = await client.evaluate('document.querySelector("[data-benefit-delivery-details]").innerText');
    assert.ok(delivery.includes(release.delivery.date_label) && delivery.includes(release.method_label));
    assert.match(delivery, /Provider recorded release/);
    assert.match(await client.evaluate(nextStep + '.innerText'), /Confirm receipt/);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('applicant-support-part-4-release');

    await click('Confirm receipt', nextStep);
    const receipt = 'form[aria-labelledby="benefit-response-title"]';
    await wait(() => client.evaluate('!!document.querySelector(' + JSON.stringify(receipt) + ')'), 'receipt confirmation form');
    await audit(receipt);
    assert.match(await client.evaluate('document.querySelector(' + JSON.stringify(receipt) + ').innerText'), /Date received/);
    await click('Cancel', 'document.querySelector(' + JSON.stringify(receipt) + ')');
    await click('Report a problem', nextStep);
    await wait(() => client.evaluate('!!document.querySelector("[data-delivery-dialog]")'), 'release issue form');
    await audit('[data-delivery-dialog]');
    assert.match(await client.evaluate('document.querySelector("[data-delivery-dialog]").innerText'), /Affected release/i);
    assert.ok((await client.evaluate('document.querySelector("[data-delivery-dialog]").innerText')).includes(release.title));
    assert.doesNotMatch(await client.evaluate('document.querySelector("[data-delivery-dialog]").innerText'), /Choose a benefit or published release/);

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await audit('[data-delivery-dialog]');
    await click('Cancel', 'document.querySelector("[data-delivery-dialog]")');
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    assert.ok(await client.evaluate(nextStep + '.getBoundingClientRect().bottom <= document.querySelector("[data-benefit-delivery-details]").getBoundingClientRect().top'));
    assert.equal(await client.evaluate('!!document.querySelector("[data-delivery-dialog]")'), false);
    await shot('applicant-support-part-4-release-mobile');
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS One-time navigation, delivery facts, separate actions, preselected report source, and mobile layout.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
