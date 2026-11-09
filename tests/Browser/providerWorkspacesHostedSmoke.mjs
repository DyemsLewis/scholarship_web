import { readFile } from 'node:fs/promises';

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
harness = harness
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa/provider-workspace-hosted';")
    .replaceAll('accountability-hosted-part6', 'provider-workspace-hosted')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/provider-workspace-hosted/report.json');
const start = harness.indexOf("    const response = await fetch(origin + '/login');");
const end = harness.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

harness = harness.slice(0, start) + String.raw`
    const panel = 'document.querySelector(\'aside[aria-label="Next step"]\')';
    const confirm = 'document.querySelector("[data-outcome-confirmation]")';
    const record = (id, workspace) => '/provider/applications/' + id + '?workspace=' + workspace;
    const loaded = () => wait(() => client.evaluate('[...document.images].every(img => img.complete)'), 'workspace images');
    const capture = async (name) => { await loaded(); await shot(name); };

    await login('marilag.manager');
    await navigate('/provider', '!!document.querySelector("#manager-queues a[href*=\'page=1\']")');
    if (process.env.EXPECTED_PROVIDER_CSS) {
        const styles = await client.evaluate('[...document.querySelectorAll("link[rel=stylesheet]")].map(el=>el.href)');
        assert.ok(styles.some(url => url.endsWith(process.env.EXPECTED_PROVIDER_CSS)), 'Hosted page uses the release CSS');
    }
    assert.equal(await client.evaluate('getComputedStyle([...document.querySelectorAll(".student-page a")].find(a=>a.innerText.trim()==="Open programs")).borderRadius'), '5px');
    await capture('part-1-dashboard');

    const decisions = await get('/provider/workspaces/decisions/data?queue=pending');
    assert.equal(decisions.status, 200);
    const candidate = decisions.body.applications[0];
    assert.ok(candidate, 'Pending final decision fixture');
    const decisionUrl = '/provider/applications/' + candidate.id + '/data?workspace=decision';
    const before = (await get(decisionUrl)).body.application;
    assert.equal(before.final_outcome, null);
    await navigate(record(candidate.id, 'decision'), '!!document.querySelector("input[name=final-outcome]")');
    assert.equal(await client.evaluate('document.querySelectorAll("input[name=final-outcome]:checked").length'), 0);
    await client.evaluate('document.querySelector("input[name=final-outcome][value=selected]").click()');
    await click('Review outcome');
    await wait(() => client.evaluate('!!' + confirm), 'outcome confirmation');
    const message = await client.evaluate(confirm + '.innerText');
    assert.ok(message.includes(before.applicant.name) && message.includes(before.scholarship.title));
    assert.match(message, /Outcome: Selected[\s\S]*Award:[\s\S]*applicant will be notified/);
    await capture('part-2-outcome-confirmation');
    await click('Go back', confirm);
    assert.equal((await get(decisionUrl)).body.application.final_outcome, null);

    const programs = await get('/provider/programs/data?filter=published&sort=title');
    assert.equal(programs.status, 200);
    const program = programs.body.programs[0];
    const menu = 'nav[aria-labelledby=program-workspaces-heading]';
    await navigate('/provider/programs/' + program.id, 'document.querySelectorAll("' + menu + ' a").length === 6');
    const links = await client.evaluate('[...document.querySelectorAll("' + menu + ' a")].map(a=>a.href)');
    assert.deepEqual(links.map(link => new URL(link).searchParams.get('program_id')), Array(6).fill(String(program.id)));
    await capture('part-3-program-workspaces');
    const context = 'document.querySelector("[data-provider-program-context]")';
    for (const link of links) {
        const url = new URL(link);
        await navigate(url.pathname + url.search, '!!' + context + ' && !document.body.innerText.includes("Updating results...")');
        assert.ok((await client.evaluate(context + '.innerText')).includes(program.title));
        if (url.pathname.endsWith('/reviews/all')) await capture('part-3-filtered-review-queue');
    }

    const unscheduled = await get('/provider/workspaces/selection/sessions/data?queue=unassigned');
    assert.equal(unscheduled.status, 200);
    const applicant = unscheduled.body.applications.find(row => ['exam', 'interview'].includes(row.stage.key));
    assert.ok(applicant, 'Unscheduled applicant fixture');
    await navigate(record(applicant.id, 'selection'), '!!' + panel + ' && ' + panel + '.innerText.includes("Schedule the")');
    assert.equal(await client.evaluate(panel + '.querySelector("a").getAttribute("href")'), applicant.action.url);
    await capture('part-4-scheduling-blocker');

    const active = await get('/provider/workspaces/selection/sessions/data?queue=active');
    assert.equal(active.status, 200);
    for (const row of active.body.sessions) {
        if (!['scheduled', 'ongoing'].includes(row.status)) continue;
        const { body } = await get('/provider/scholarships/' + row.program.id + '/stage-sessions/' + row.id);
        if (body.session?.next_step?.action?.section !== 'roster') continue;
        await navigate('/provider/workspaces/selection/sessions/' + row.id, '!!' + panel + ' && !!document.querySelector("#session-record-title")');
        assert.equal(await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Complete session").disabled'), true);
        await capture('part-4-attendance-blocker');
        await click('Open roster', panel);
        await wait(() => client.evaluate('document.body.innerText.includes("Assigned applicants")'), 'roster opened');
        break;
    }

    const recipients = await get('/provider/workspaces/recipients/data?queue=awaiting');
    assert.equal(recipients.status, 200);
    const awaiting = recipients.body.recipients[0];
    if (awaiting) {
        await navigate(record(awaiting.id, 'decision'), '!!' + panel + ' && ' + panel + '.innerText.includes("accept the agreement")');
        await capture('part-4-agreement-handoff');
    }
    await login('marilag.reviewer');
    const review = await get('/provider/applications/' + applicant.id + '/data?workspace=review');
    assert.equal(review.status, 200);
    assert.equal(review.body.application.provider_next_step.owner, 'Selection officer');
    assert.equal(review.body.application.provider_next_step.action, null);
    assert.equal((await get('/provider/applications/' + applicant.id + '/stage-readiness')).status, 403);

    await login('marilag.monitoring');
    const monitoring = await get('/provider/workspaces/monitoring/dashboard/data');
    assert.equal(monitoring.status, 200);
    await navigate('/provider/workspaces/monitoring', '!!document.querySelector(".workspace-dashboard-title") && !document.querySelector(".fa-spinner.fa-spin")');
    assert.doesNotMatch(await client.evaluate('document.body.innerText'), /dashboard could not be loaded/i);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted provider Parts 1-4; manager, reviewer, and monitoring officer verified. No business actions submitted.');
` + harness.slice(end);

try {
    await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64'));
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
