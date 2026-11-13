import { readFile } from 'node:fs/promises';
const folder='C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/fresh-demo-20261113/document-actions';
let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace(/^const folder = .*;$/m,`const folder = ${JSON.stringify(folder)};`).replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json',folder+'/browser.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');"),end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    const capture=async(url,name)=>{await navigate(url,'document.readyState==="complete" && !!window.axios && document.body.innerText.length>200');await delay(700);await shot(name);};
    if (!process.env.DOCUMENT_CAPTURE_REMAINING) {
    await login('cohort.learner.screening');
    await capture('/dashboard/documents','applicant-documents');await capture('/dashboard/applications/1','applicant-application');
    await login('cohort.learner.formal');await capture('/dashboard/applications/2','applicant-formal');
    await login('cohort.learner.exam.confirm');await capture('/dashboard/applications/4/activities','applicant-exam');
    await login('cohort.learner.interview.confirm');await capture('/dashboard/applications/8/activities','applicant-interview');
    await login('cohort.learner.agreement');await capture('/dashboard/applications/11/outcome','applicant-agreement');
    await login('cohort.process.support.history');await capture('/dashboard/monitoring/34/history','applicant-support-history');
    }
    await login('nuvareya.releases');await capture('/provider/workspaces/releases/schedule','provider-release-schedule');await capture('/provider/workspaces/releases/distributions/2','provider-release-detail');
    await login('nuvareya.selection');await capture('/provider/applications/2','provider-formal');
    await login('novacore.reviews');await capture('/admin/workspaces/reviews/monitoring','admin-monitoring');
    await login('novacore.billing');await capture('/admin/workspaces/billing/requests/5','admin-billing-detail');
    await login('novacore.finance');await capture('/admin/workspaces/finance/receipts/6','admin-finance-detail');
    await login('novacore.records');await capture('/admin/workspaces/records','admin-records');
    await login('novacore.manager');await capture('/admin','admin-manager');
    const target=await control.send('Target.createTarget',{url:'about:blank',browserContextId:(await control.send('Target.createBrowserContext')).browserContextId});client.socket.close();client=new Cdp('ws://127.0.0.1:'+port+'/devtools/page/'+target.targetId);await configure();
    await capture('/register','registration');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS Current action pages captured without saving.');
`+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}catch(error){console.error(error.message);process.exitCode=1;}
