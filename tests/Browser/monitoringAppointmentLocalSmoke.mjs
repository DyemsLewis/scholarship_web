import { readFile } from 'node:fs/promises';

const origin = 'http://127.0.0.1:8020';
const cycleId = Number(process.env.MONITORING_SMOKE_CYCLE);
if (!Number.isInteger(cycleId) || cycleId < 1) throw new Error('Set the cycle ID in the isolated local demo.');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", 'const origin = ' + JSON.stringify(origin) + ';')
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'monitoring-appointment-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/monitoring-appointment-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('marilag.manager');
    const recordPath = '/provider/workspaces/monitoring/check-ins/' + ${cycleId};
    const data = await get(recordPath + '/data');
    assert.equal(data.status, 200);
    assert.equal(data.body.cycle.recipients.length, 3);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate(recordPath, 'document.body.innerText.includes("Check-in roster")');
    await click('Record attendance');
    await wait(() => client.evaluate("document.querySelector('#check-in-appointment-title')?.innerText === 'Record attendance'"), 'attendance dialog');
    await audit('[aria-labelledby="check-in-appointment-title"]');
    await click('Cancel', 'document.querySelector(\'[aria-labelledby="check-in-appointment-title"]\')');
    const rescheduled = data.body.cycle.recipients.find(row => row.handoff.rescheduled);
    assert.ok(rescheduled);
    await client.evaluate("([...document.querySelectorAll('[aria-label=\"Choose a recipient\"] button')].find(button => button.innerText.includes(" + JSON.stringify(rescheduled.name) + "))).click()");
    await wait(() => client.evaluate("document.querySelector('[aria-label=\"Recipient appointment\"]')?.innerText.includes('Rescheduled')"), 'rescheduled recipient');
    assert.equal(await client.evaluate("document.querySelector('[aria-label=\"Recipient appointment\"]')?.innerText.includes('Record attendance')"), false);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('monitoring-part-2-check-in-roster');
    await click('Reschedule');
    await wait(() => client.evaluate("document.querySelector('#check-in-appointment-title')?.innerText === 'Reschedule appointment'"), 'reschedule dialog');
    await audit('[aria-labelledby="check-in-appointment-title"]');
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Fixed roster and accessible per-recipient attendance and reschedule dialogs.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
