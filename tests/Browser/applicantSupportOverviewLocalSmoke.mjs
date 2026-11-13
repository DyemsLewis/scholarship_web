import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8026';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'support-overview-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/support-overview-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('learner.release.confirm');
    const landing = (await get('/dashboard/monitoring/data')).body.support;
    const recordUrl = landing.focus.href;
    const { status, body } = await get(recordUrl + '/data');
    assert.equal(status, 200);
    const detail = body.detail;
    assert.deepEqual(detail.content.next_action, landing.focus.decision);
    assert.equal(detail.content.next_action.state, 'benefit_confirmation_required');
    assert.equal(detail.content.schedule.length, 2);
    assert.ok(!detail.content.schedule.some(item => item.href === detail.content.next_action.action.href));
    await navigate(recordUrl, '!!document.querySelector("[data-monitoring-overview]")');
    assert.equal(await client.evaluate('document.querySelector("[data-monitoring-next-action] a").getAttribute("href")'), detail.content.next_action.action.href);
    assert.equal(await client.evaluate('document.querySelector("[data-overview-plan]").open'), false);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    assert.equal(await client.evaluate('document.querySelector("[data-monitoring-detail-header] img").src'), detail.identity.program.logo_url);
    assert.ok(await client.evaluate('document.querySelector("[data-monitoring-detail-header]").getBoundingClientRect().height < 160'));
    await shot('applicant-support-part-2-overview');

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    assert.ok(await client.evaluate('document.querySelector("[data-monitoring-next-action]").getBoundingClientRect().bottom <= document.querySelector("[data-overview-schedule]").getBoundingClientRect().top'));
    await client.evaluate('document.querySelector("[data-overview-plan] summary").click()');
    assert.ok(await client.evaluate('document.querySelector("[data-overview-plan]").open'));
    assert.equal(await client.evaluate('document.querySelector("[data-overview-plan] a").getAttribute("href")'), detail.content.plan.agreement.href);
    await navigate(detail.content.plan.agreement.href, '!!document.querySelector("#recipient-agreement-title")');
    assert.doesNotMatch(await client.evaluate('document.querySelector("[aria-labelledby=recipient-agreement-title]").innerText'), /Accept agreement|Decline agreement/);

    await login('learner.onetime.confirm');
    const oneTime = (await get('/dashboard/monitoring/data')).body.support.focus;
    await navigate(oneTime.href, '!!document.querySelector("[data-monitoring-overview]")');
    assert.equal(await client.evaluate('!!document.querySelector("[data-overview-schedule]")'), false);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Compact header, exact next action, genuine dates, saved agreement, one-time empty state, and mobile layout.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
