import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'provider-next-steps-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/provider-next-steps-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    const panel = 'document.querySelector(\'aside[aria-label="Next step"]\')';
    const record = (id, workspace) => '/provider/applications/' + id + '?workspace=' + workspace;
    const links = () => client.evaluate('[...'+panel+'.querySelectorAll("a")].map(a=>a.getAttribute("href"))');
    const loaded = () => wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    const clickLink = () => client.evaluate(panel+'.querySelector("a").click()');

    await login('marilag');
    const unassigned = await get('/provider/workspaces/selection/sessions/data?queue=unassigned');
    assert.equal(unassigned.status, 200);
    const candidate = unassigned.body.applications.find(row => ['exam', 'interview'].includes(row.stage.key));
    assert.ok(candidate, 'An applicant awaiting a required session exists in the local demo.');
    await navigate(record(candidate.id, 'selection'), '!!'+panel+' && '+panel+'.innerText.includes("Schedule the")');
    assert.match(await client.evaluate(panel+'.innerText'), /Selection officer/);
    assert.equal((await links())[0], candidate.action.url);
    assert.doesNotMatch(await client.evaluate('document.body.innerText'), /outcome is already recorded|No selection outcome to record/);
    await loaded(); await shot('provider-part-4-scheduling-blocker');
    await clickLink();
    await wait(() => client.evaluate('!!document.querySelector("#session-modal-title")'), 'schedule modal opens');
    assert.equal(await client.evaluate('new URLSearchParams(location.search).get("program_id")'), String(candidate.program.id));

    const sessions = await get('/provider/workspaces/selection/sessions/data?queue=active');
    assert.equal(sessions.status, 200);
    assert.ok(sessions.body.sessions.length, 'An active session exists in the local demo.');
    for (const row of sessions.body.sessions) {
        const { body } = await get('/provider/scholarships/' + row.program.id + '/stage-sessions/' + row.id);
        if (!body.session?.next_step || !['scheduled', 'ongoing'].includes(row.status)) continue;
        await navigate('/provider/workspaces/selection/sessions/' + row.id, '!!'+panel+' && !!document.querySelector("#session-record-title")');
        assert.equal(await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Complete session").disabled'), true);
        await loaded(); await shot('provider-part-4-session-blocker');
        if (body.session.next_step.action?.section === 'roster') {
            await click('Open roster', panel);
            await wait(() => client.evaluate('document.body.innerText.includes("Assigned applicants")'), 'roster tab');
        }
        break;
    }

    const recipients = await get('/provider/workspaces/recipients/data?queue=awaiting');
    assert.equal(recipients.status, 200);
    const awaiting = recipients.body.recipients?.[0];
    assert.ok(awaiting, 'A selected applicant awaiting agreement exists in the local demo.');
    await navigate(record(awaiting.id, 'decision'), '!!'+panel+' && '+panel+'.innerText.includes("accept the agreement")');
    assert.match(await client.evaluate(panel+'.innerText'), /Waiting on\s+Applicant/i);
    assert.deepEqual(await links(), [record(awaiting.id, 'recipient')]);
    await loaded(); await shot('provider-part-4-agreement-handoff');
    await clickLink();
    await wait(() => client.evaluate('!!document.querySelector("#recipient-record-title") && !!'+panel), 'recipient record opens');
    assert.deepEqual(await links(), []);

    await login('marilag.reviewer');
    const review = await get('/provider/applications/'+candidate.id+'/data?workspace=review');
    assert.equal(review.status, 200);
    assert.equal(review.body.application.provider_next_step.owner, 'Selection officer');
    assert.equal(review.body.application.provider_next_step.action, null);

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Session blockers, schedule shortcut, agreement handoff, and reviewer permissions. No records changed.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
