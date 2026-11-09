import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'provider-decision-safety-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/provider-decision-safety-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    await login('marilag');
    const confirmation = 'document.querySelector("[data-outcome-confirmation]")';
    const prompt = () => wait(() => client.evaluate('!!' + confirmation), 'confirmation');
    const dismiss = () => click('Go back', confirmation);
    const queue = await get('/provider/workspaces/decisions/data?queue=pending');
    assert.equal(queue.status, 200);
    const candidate = queue.body.applications[0];
    assert.ok(candidate, 'A pending decision exists in the local demo.');
    const decisionUrl = '/provider/applications/' + candidate.id + '/data?workspace=decision';
    const before = (await get(decisionUrl)).body.application;
    assert.equal(before.final_outcome, null);
    await navigate('/provider/applications/' + candidate.id + '?workspace=decision',
        '!!document.querySelector("input[name=final-outcome]")');
    assert.equal(await client.evaluate('document.querySelectorAll("input[name=final-outcome]:checked").length'), 0);
    assert.equal(await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Review outcome").disabled'), true);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'applicant avatar');
    await shot('provider-part-2-decision-form');
    await client.evaluate('document.querySelector("input[name=final-outcome][value=selected]").click()');
    await click('Review outcome'); await prompt();
    const message = await client.evaluate(confirmation + '.innerText');
    assert.ok(message.includes(before.applicant.name));
    assert.ok(message.includes(before.scholarship.title));
    assert.match(message, /Outcome: Selected[\s\S]*Award:[\s\S]*applicant will be notified/);
    await shot('provider-part-2-final-confirmation');
    await dismiss();
    assert.equal(await client.evaluate('document.querySelector("input[name=final-outcome]:checked").value'), 'selected');
    assert.equal((await get(decisionUrl)).body.application.final_outcome, null);

    const results = await get('/provider/workspaces/selection/sessions/data?queue=results');
    assert.equal(results.status, 200);
    for (const row of results.body.applications) {
        const { body } = await get('/provider/applications/' + row.id + '/data?workspace=selection');
        const decision = body.application?.decision_reopen;
        if (!decision?.can_reopen || !decision?.decision_reference) continue;
        await navigate('/provider/applications/' + row.id + '?workspace=selection', '!!document.querySelector("#selection-outcome-title")');
        await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'applicant avatar');
        await click('Reopen ' + decision.stage_label);
        await wait(() => client.evaluate('!!document.querySelector("#decision-reopen-title")'), 'reopen dialog');
        assert.match(await client.evaluate('document.querySelector("[aria-labelledby=decision-reopen-title]").innerText'), /Current result/);
        assert.equal(await client.evaluate('document.querySelector("[aria-labelledby=decision-reopen-title] button[type=submit]").disabled'), true);
        await shot('provider-part-2-reopen-dialog');
        await click('Cancel', 'document.querySelector("[aria-labelledby=decision-reopen-title]")');
        report.selectionReopenDialog = true;
        break;
    }
    assert.equal(report.selectionReopenDialog, true);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Local decision safety screens and cancellation. No business actions submitted.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
