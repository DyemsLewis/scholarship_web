import { readFile } from 'node:fs/promises';

const support = JSON.parse(await readFile('storage/deployment/process-data-repair-20261113/result.json', 'utf8'));
const admin = JSON.parse(await readFile('storage/deployment/process-guide-update-20261113/result.json', 'utf8'));
const output = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/process-guide-update-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + `    const examples = ${JSON.stringify(support.accounts)}; const admin = ${JSON.stringify(admin)};\n` + String.raw`
    const example = key => examples.find(a => a.username === 'demo.process.' + key);
    const ok = async url => { const r = await get(url); assert.equal(r.status, 200, url); return r.body; };
    const capture = async (url, text, name) => {
        await navigate(url, 'document.readyState==="complete" && document.body.innerText.includes(' + JSON.stringify(text) + ')');
        await shot(name);
    };
    await login('talunaw.decisions');
    await capture('/provider/workspaces/decisions/pending?program_id=' + example('dss.first').program_id, 'Nerali', 'dss-ranked-candidates');
    await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.getAttribute("aria-label")?.startsWith("View evaluation breakdown")).click()');
    await wait(() => client.evaluate('!!document.querySelector("[data-selection-breakdown-dialog]")'), 'DSS breakdown');
    await shot('dss-stage-breakdown');
    await capture('/provider/applications/' + example('dss.first').application_id, 'Record final', 'dss-final-decision');
    await login('talunaw.selection');
    await capture('/provider/applications/' + example('dss.score').application_id, 'Sorevi', 'dss-missing-evaluation');
    await login('talunaw.monitoring');
    await capture('/provider/monitoring/' + example('support.review').program_id + '/plan', 'Monitoring plan', 'monitoring-plan');
    const cycle = example('support.review').check_ins[0].recipient_monitoring_cycle_id;
    await capture('/provider/workspaces/monitoring/check-ins/' + cycle, 'Nivara', 'monitoring-roster');
    await client.evaluate('(()=>{const b=[...document.querySelectorAll("button")].find(b=>b.innerText.includes("Current academic record"));if(!b)throw new Error("Academic item missing");b.click()})()');
    await wait(() => client.evaluate('document.body.innerText.includes("Record result")'), 'academic result form');
    await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Record result").closest("form").scrollIntoView({block:"center"})');
    await shot('monitoring-academic-result');
    await capture('/provider/workspaces/monitoring/check-ins/' + cycle + '?application_id=' + example('support.close').application_id, 'Elvian', 'monitoring-close-ready');
    await login('talunaw.recipients');
    await capture('/provider/applications/' + example('support.decision').application_id + '?section=support', 'Before completing support', 'support-completion-ready');
    await client.evaluate('document.querySelector("[data-support-settlement]").scrollIntoView({block:"center"})');
    await shot('support-settlement-actions');
    await capture('/provider/applications/' + example('award.complete').application_id + '?section=support', 'Before completing support', 'one-time-completion-ready');
    await login('demo.process.support.appointment');
    await capture('/dashboard/monitoring/' + example('support.appointment').application_id + '/check-ins', 'appointment', 'applicant-original-document-appointment');
    await login('demo.process.support.history');
    await capture('/dashboard/monitoring/' + example('support.history').application_id + '/history', 'renew', 'applicant-renewal-history');
    await login('demo.process.award.receipt');
    await capture('/dashboard/monitoring/' + example('award.receipt').application_id + '/benefits/release-' + example('award.receipt').releases[0].id,
        'Confirm receipt', 'applicant-one-time-receipt');
    await login('demo.process.award.followup');
    await capture('/dashboard/monitoring/' + example('award.followup').application_id + '/benefits/release-' + example('award.followup').releases[0].id,
        'collection', 'applicant-benefit-follow-up');
    await login('demoops.accounts');
    const pending = admin.handoffs.find(h=>h.status==='pending');
    await ok('/admin/workspaces/accounts/' + pending.user_id + '/data');
    await capture('/admin/workspaces/accounts/' + pending.user_id, 'Return result', 'admin-account-task');
    await client.evaluate('document.querySelector("[data-account-support-handoffs]")?.scrollIntoView({block:"center"})');
    await shot('admin-account-task');
    await login('demoops.support');
    const returned = admin.handoffs.find(h=>h.status==='completed');
    await capture('/admin/workspaces/support/cases/' + returned.report_id, 'Returned to Support', 'admin-support-returned-task');
    await login('demoops.billing');
    const billing = admin.finance.find(p=>p.state==='with_billing');
    await capture('/admin/workspaces/billing/requests/' + billing.id, 'Respond to Finance', 'admin-billing-finance-task');
    await login('demoops.finance');
    const finance = admin.finance.find(p=>p.state==='with_finance');
    await ok('/admin/workspaces/finance/receipts/' + finance.id + '/data');
    await capture('/admin/workspaces/finance/receipts/' + finance.id, 'Billing', 'admin-finance-returned-task');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Current hosted process pages and action forms captured without submitting business actions.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
