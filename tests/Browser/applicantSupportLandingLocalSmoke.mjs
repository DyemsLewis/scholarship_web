import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8024';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'support-landing-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/support-landing-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('learner.release.confirm');
    const landing = await get('/dashboard/monitoring/data');
    assert.equal(landing.status, 200);
    const support = landing.body.support;
    assert.equal(support.focus.decision.state, 'benefit_confirmation_required');
    assert.equal(support.schedule.length, 2);
    assert.equal(support.past.length, 1);
    assert.deepEqual((await get(support.focus.href + '/data')).body.detail.decision, support.focus.decision);
    await navigate('/dashboard/monitoring', '!!document.querySelector("[data-support-landing]")');
    assert.equal(await client.evaluate('document.querySelector("[data-support-priority-link]").getAttribute("href")'), support.focus.decision.action.href);
    assert.equal(await client.evaluate('document.querySelector("[data-support-past]").open'), false);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    assert.ok(await client.evaluate('[...document.querySelectorAll("[data-support-active] img,[data-support-schedule] img")].every(img=>img.naturalWidth>0)'));
    await shot('applicant-support-part-1-landing');

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    assert.ok(await client.evaluate('document.querySelector("[data-support-priority]").getBoundingClientRect().bottom <= document.querySelector("[data-support-schedule]").getBoundingClientRect().top'));
    await client.evaluate('document.querySelector("[data-support-past] summary").click()');
    assert.ok(await client.evaluate('document.querySelector("[data-support-past]").open'));
    await navigate(support.focus.decision.action.href, '!!document.querySelector("[data-benefit-release-record]")');

    await login('learner.agreement');
    const pending = (await get('/dashboard/monitoring/data')).body.support.focus.decision;
    assert.equal(pending.state, 'agreement_required');
    assert.match(pending.action.href, /\/outcome\?action=agreement$/);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Shared next action, exact record link, logo, past support, and mobile layout.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
