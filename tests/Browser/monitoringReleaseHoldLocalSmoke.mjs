import { readFile } from 'node:fs/promises';

const origin = process.env.MONITORING_SMOKE_ORIGIN ?? 'http://127.0.0.1:8019';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(origin)) throw new Error('Use a local test server.');

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", 'const origin = ' + JSON.stringify(origin) + ';')
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'monitoring-release-hold-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/monitoring-release-hold-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    await login('marilag.manager');
    const queue = await get('/provider/workspaces/releases/data?queue=record');
    assert.equal(queue.status, 200);
    let blocked;
    for (const entry of queue.body.releases) {
        const data = await get('/provider/workspaces/releases/distributions/' + entry.id + '/data');
        assert.equal(data.status, 200);
        const next = data.body.release.records.find(record => ['scheduled', 'prepared'].includes(record.status));
        if (next?.release_readiness?.blocked_cycle_id && !next.has_open_delivery_report) {
            blocked = entry;
            break;
        }
    }
    assert.ok(blocked, 'The local demo needs a scheduled release with a due, unconfirmed check-in.');
    const panel = 'document.querySelector(\'aside[aria-label="Next step"]\')';
    await navigate(blocked.action_url, panel + '?.innerText.includes("Release on hold")');
    assert.equal(await client.evaluate('document.querySelector(\'option[value="released"]\')?.disabled'), true);
    assert.equal(await client.evaluate(panel + '.querySelector("a")?.innerText.trim()'), 'Open check-in');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('monitoring-part-1-release-hold');
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Release hold explains the blocker and disables the released option.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
