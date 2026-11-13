import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const output = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/process-guide-update-20261113';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser-action-forms.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    await login('talunaw.selection');
    await navigate('/provider/applications/29', '!!document.querySelector("[data-recorded-evaluations]")');
    await client.evaluate('document.querySelector("[data-recorded-evaluations]").open = true');
    await wait(() => client.evaluate('[...document.querySelectorAll("button")].some(b=>b.innerText.trim()==="Add score")'), 'earlier-stage score control');
    await click('Add score');
    await wait(() => client.evaluate('!!document.querySelector("[data-stage-evaluation-dialog]")'), 'add interview evaluation');
    await shot('dss-missing-evaluation');
    await login('demoops.accounts');
    await navigate('/admin/workspaces/accounts/72', '!!document.querySelector("[data-account-handoffs]")');
    await client.evaluate('document.querySelector("[data-account-handoffs]").scrollIntoView({block:"center"})');
    await shot('admin-account-task');
    await click('Return result');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog]")'), 'return account task form');
    await shot('admin-account-return-form');
    await login('demoops.billing');
    await navigate('/admin/workspaces/billing/requests/5', 'document.body.innerText.includes("Respond to Finance")');
    await click('Respond to Finance');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog]")'), 'Billing response form');
    await shot('admin-billing-finance-task');
    await login('demoops.finance');
    await navigate('/admin/workspaces/finance/receipts/6', 'document.body.innerText.includes("Review record")');
    await click('Review record');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog]") && document.body.innerText.includes("Billing response")'), 'Finance check form');
    await shot('admin-finance-returned-task');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Detailed score, account return, Billing response and Finance check forms captured without saving.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
