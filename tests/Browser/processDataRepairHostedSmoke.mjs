import { readFile } from 'node:fs/promises';

const result = JSON.parse(await readFile('storage/deployment/process-data-repair-20261113/result.json', 'utf8'));
const output = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/process-data-repair-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + `    const examples = ${JSON.stringify(result.accounts.map(({ username, application_id, program_id, check_ins, releases }) => ({ username, application_id, program_id, check_ins, releases })))};\n` + String.raw`
    const example = key => examples.find(a => a.username === 'demo.process.' + key);
    const ok = async url => { const r = await get(url); assert.equal(r.status, 200, url); return r.body; };
    const ready = example('dss.first');
    await login('talunaw.decisions');
    const comparison = await ok('/provider/workspaces/decisions/data?queue=pending&program_id=' + ready.program_id);
    assert.equal(comparison.selection_comparison.ranked, 3);
    assert.equal(comparison.selection_comparison.provisional, false);
    assert.equal(comparison.applications.length, 3);
    assert.deepEqual(comparison.applications.map(a => a.selection_comparison.rank), [1, 2, 3]);
    await navigate('/provider/workspaces/decisions/pending?program_id=' + ready.program_id,
        '!!document.querySelector("[data-selection-comparison]") && document.body.innerText.includes("Nerali")');
    await shot('hosted-dss-three-candidates');
    await login('talunaw.selection');
    const missing = await ok('/provider/applications/' + example('dss.score').application_id + '/data');
    assert.ok(JSON.stringify(missing).includes('Evaluation incomplete'));
    assert.ok(missing.application.selection_evaluation.stages.find(s => s.key === 'interview').can_edit);
    await login('talunaw.monitoring');
    const attended = example('support.review');
    await ok('/provider/workspaces/monitoring/data?program_id=' + attended.program_id);
    await navigate('/provider/workspaces/monitoring/check-ins/' + attended.check_ins[0].recipient_monitoring_cycle_id,
        'document.querySelector("#monitoring-record-title") && document.body.innerText.includes("Nivara")');
    await shot('hosted-monitoring-original-document-roster');
    await login('talunaw.recipients');
    await ok('/provider/workspaces/recipients/data?program_id=' + example('support.decision').program_id);
    await ok('/provider/applications/' + example('support.decision').application_id + '/data');
    await ok('/provider/applications/' + example('award.complete').application_id + '/data');
    await login('talunaw.releases');
    await ok('/provider/workspaces/releases/data?program_id=' + example('award.receipt').program_id);
    for (const key of ['support.appointment', 'support.history', 'award.receipt', 'award.followup']) {
        const row = example(key);
        await login(row.username);
        const payload = await ok('/dashboard/monitoring/' + row.application_id + '/data');
        assert.ok(payload.detail, 'Applicant support detail: ' + key);
        await navigate('/dashboard/monitoring/' + row.application_id + (key.startsWith('award.') ? '/benefits' : ''),
            '!!document.querySelector("[data-monitoring-detail-header]") && document.readyState==="complete"');
        if (key.startsWith('award.')) {
            await navigate('/dashboard/monitoring/' + row.application_id + '/benefits/release-' + row.releases[0].id,
                '!!document.querySelector("[data-monitoring-detail-header]") && document.readyState==="complete"');
        }
        if (key === 'award.receipt') {
            await wait(() => client.evaluate('document.body.innerText.includes("Confirm receipt")'), 'recipient confirmation action');
            await shot('hosted-one-time-award-receipt');
        }
        if (key === 'award.followup') {
            await wait(() => client.evaluate('document.body.innerText.includes("collection") || document.body.innerText.includes("Follow-up")'), 'benefit follow-up');
            await shot('hosted-one-time-benefit-follow-up');
        }
    }
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted DSS comparison, evaluation handoff, monitoring roster, closeout records, receipt confirmation, renewal history and follow-up. No business actions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
