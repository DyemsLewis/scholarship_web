import { readFile } from 'node:fs/promises';

const port = Number(process.env.SUPPORT_HISTORY_PORT || 8031);
const output = process.env.SUPPORT_HISTORY_OUTPUT || 'storage/qa';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = 'http://127.0.0.1:${port}';`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('accountability-hosted-part6', 'support-history-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `${output}/support-history-local.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('learner.release.confirm');
    const { body: { support } } = await get('/dashboard/monitoring/data');
    const base = support.focus.href;
    const { status, body } = await get(base + '/data?view=history');
    assert.equal(status, 200);
    const content = body.detail.content;
    const decision = content.action_required[0];
    assert.ok(decision, 'The isolated demo needs a decision awaiting acknowledgement.');
    assert.ok(content.timeline.some(item => item.kind === 'benefit_release' && item.status_label === 'Received'));
    assert.ok(content.timeline.some(item => item.kind === 'check_in' && item.date_caption === 'Closed'));
    const pastDecision = content.timeline.find(item => item.kind === 'support_outcome');
    assert.ok(pastDecision);
    await navigate(base + '/history', '!!document.querySelector("[data-history-month]")');
    assert.equal(await client.evaluate('document.querySelectorAll("[data-history-kind]").length'), content.timeline.length);
    assert.equal(await client.evaluate('document.querySelectorAll("[data-history-action]").length'), 1);
    assert.ok(await client.evaluate('document.querySelectorAll("[data-history-month]").length >= 2'));
    assert.equal(await client.evaluate('!!document.querySelector("[data-record-section-intro]")'), false);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('applicant-support-part-5-history');

    const filters = 'document.querySelector("[data-monitoring-history] [role=group]")';
    await click('Benefits', filters);
    assert.ok(await client.evaluate('[...document.querySelectorAll("[data-history-kind]")].every(row=>row.dataset.historyKind==="benefit_release")'));
    assert.ok(await client.evaluate('!!document.querySelector("[data-history-action]")'));
    await click('Decisions', filters);
    assert.equal(await client.evaluate('document.querySelector("[data-history-kind]").dataset.historyKind'), 'support_outcome');
    assert.ok((await client.evaluate('document.querySelector("[data-history-kind] a").getAttribute("href")')).endsWith('/' + pastDecision.record_key));
    await navigate(pastDecision.href, '!!document.querySelector("[data-support-outcome-next-step]")');
    assert.match(await client.evaluate('document.querySelector("[data-support-outcome-next-step]").innerText'), /No response needed/);
    assert.equal(await client.evaluate('!!document.querySelector("[data-support-outcome-next-step] button")'), false);

    await navigate(decision.href, '!!document.querySelector("[data-support-outcome-next-step] button")');
    await shot('applicant-support-part-5-decision');
    await click('Respond to decision', 'document.querySelector("[data-support-outcome-next-step]")');
    const modal = 'form[aria-labelledby="support-outcome-response-title"]';
    await wait(() => client.evaluate('!!document.querySelector(' + JSON.stringify(modal) + ')'), 'response modal');
    await audit(modal);
    assert.match(await client.evaluate('document.querySelector(' + JSON.stringify(modal) + ').innerText'), /Acknowledge decision/);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await audit(modal);
    await click('Cancel', 'document.querySelector(' + JSON.stringify(modal) + ')');
    await navigate(base + '/history', '!!document.querySelector("[data-history-month]")');
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    await client.evaluate('document.querySelector("[data-history-action]").scrollIntoView({block:"start"})');
    await shot('applicant-support-part-5-history-mobile');

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS History order and filters, active and older decisions, response modal, and mobile layout.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
