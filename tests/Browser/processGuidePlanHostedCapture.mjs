import { readFile } from 'node:fs/promises';

const result = JSON.parse(await readFile('storage/deployment/process-data-repair-20261113/result.json', 'utf8'));
const missedRelease = result.accounts.find(a=>a.username==='demo.process.award.followup').releases[0].recipient_benefit_release_id;
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const output = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/process-guide-update-20261113';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser-plan.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `    const missedRelease = ${JSON.stringify(missedRelease)};\n` + String.raw`
    if (!process.env.PROCESS_GUIDE_RELEASE_ONLY) {
    await login('talunaw.programs');
    await navigate('/provider/monitoring/15/plan', 'document.body.innerText.includes("Monitoring plan") && document.body.innerText.includes("Review timing")');
    assert.equal(await client.evaluate('document.body.innerText.includes("This plan is read-only for your role")'), false);
    await shot('monitoring-plan');
    await login('talunaw.monitoring');
    await navigate('/provider/monitoring/15/plan', 'document.body.innerText.includes("New check-in")');
    await click('New check-in');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog]")'), 'new check-in modal');
    await shot('monitoring-new-check-in');
    await login('talunaw.selection');
    await navigate('/provider/applications/29', 'document.body.innerText.includes("Sorevi") && document.body.innerText.includes("Interview")');
    const buttons = await client.evaluate('[...document.querySelectorAll("button")].map(b=>b.innerText.trim())');
    const score = buttons.find(b=>b==='Add score'||b==='Record score'||b==='Add evaluation');
    if(score) { await click(score); await wait(() => client.evaluate('!!document.querySelector("[data-stage-evaluation-dialog]")'), 'missing evaluation dialog'); }
    await shot('dss-missing-evaluation');
    }
    await login('talunaw.releases');
    await navigate('/provider/workspaces/releases/distributions/' + missedRelease, 'document.body.innerText.includes("Oriven") && document.body.innerText.toLowerCase().includes("follow-up")');
    await shot('provider-benefit-follow-up');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Editable plan, unsaved published-check-in form and missing evaluation captured.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
