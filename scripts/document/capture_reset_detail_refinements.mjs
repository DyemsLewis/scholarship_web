import {readFile} from 'node:fs/promises';
const folder='C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/fresh-demo-20261113/document-actions';
let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace(/^const folder = .*;$/m,`const folder = ${JSON.stringify(folder)};`).replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json',folder+'/detail-browser.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');"),end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    await login('cohort.learner.apply');await navigate('/dashboard/profile','document.body.innerText.includes("Personal and contact")');await delay(400);await shot('applicant-profile-loaded');
    await login('novacore.reviews');await navigate('/admin/workspaces/reviews/monitoring','document.readyState==="complete" && !/Loading/i.test(document.body.innerText) && document.body.innerText.includes("Monitoring oversight")');await delay(400);await shot('admin-monitoring-loaded');
    await login('nuvareya.decisions');await navigate('/provider/workspaces/decisions/pending?program_id=13','!!document.querySelector("[data-selection-comparison]")');
    await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.getAttribute("aria-label")?.startsWith("View evaluation breakdown")).click()');
    await wait(()=>client.evaluate('!!document.querySelector("[data-selection-breakdown-dialog]")'),'DSS breakdown');await shot('decision-stage-breakdown');
    await login('cohort.learner.payout.details');await navigate('/dashboard/monitoring/23/benefits/release-6','!!document.querySelector("[data-benefit-release-record]")');await click('Enter payout details');
    await wait(()=>client.evaluate('!!document.querySelector("[role=dialog]")'),'payout form');await shot('applicant-payout-form');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
`+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}catch(e){console.error(e.message);process.exitCode=1;}
