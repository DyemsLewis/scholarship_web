import { readFile } from 'node:fs/promises';

const ids = Object.fromEntries(['APPLICATION', 'INCOMPLETE', 'EXCUSED', 'AWAITING'].map(key => [key, Number(process.env['MONITORING_SMOKE_' + key])]));
if (Object.values(ids).some(id => !Number.isInteger(id) || id < 1)) throw new Error('Set the application and check-in IDs in the isolated local demo.');

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8022';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'monitoring-outcomes-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/monitoring-outcomes-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    const ids = ${JSON.stringify(ids)};
    const expected = [[ids.INCOMPLETE, 'Incomplete'], [ids.EXCUSED, 'Excused'], [ids.AWAITING, 'Awaiting closure']];
    await login('marilag.manager');
    for (const [cycleId, label] of expected) {
        const response = await get('/provider/workspaces/monitoring/check-ins/' + cycleId + '/data');
        assert.equal(response.status, 200);
        assert.equal(response.body.cycle.recipients[0].monitoring_case.status_label, label);
    }
    await navigate('/provider/workspaces/monitoring/check-ins/' + ids.AWAITING, "document.body.innerText.includes('Awaiting closure') && document.body.innerText.includes('Close check-in')");
    assert.ok(await client.evaluate("document.querySelector('nav[aria-label=\"Recipient filters\"]').innerText.includes('Needs action')"));

    await login('learner.release.record');
    const base = '/dashboard/monitoring/' + ids.APPLICATION;
    const result = await get(base + '/data?view=check-ins');
    assert.equal(result.status, 200);
    const content = result.body.detail.content.directory;
    const list = [...content.active, ...content.upcoming, ...content.completed];
    for (const [cycleId, label] of expected) assert.equal(list.find(row => row.record_key === 'check-in-' + cycleId)?.state_label, label);
    assert.ok(!content.completed.some(row => row.record_key === 'check-in-' + ids.AWAITING));
    const awaiting = content.active.find(row => row.record_key === 'check-in-' + ids.AWAITING);
    assert.notEqual(result.body.detail.summary.check_ins.next_due_label, awaiting.handoff.scheduled_label);
    await navigate(base + '/check-ins', '!!document.querySelector("[data-monitoring-check-ins]")');
    await client.evaluate("document.querySelector('[data-monitoring-check-ins] details').open = true");
    assert.match(await client.evaluate('document.querySelector("[data-monitoring-check-ins] details").innerText'), /Incomplete/i);
    assert.match(await client.evaluate('document.querySelector("[data-monitoring-check-ins] details").innerText'), /Excused/i);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('monitoring-part-3-check-in-outcomes');

    await navigate(base + '/check-ins/check-in-' + ids.INCOMPLETE, "document.querySelector('[data-check-in-next-step]')?.innerText.includes('Incomplete')");
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.equal(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), true);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Provider and applicant labels agree; terminal outcomes stay out of active work; focused applicant record fits mobile.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
