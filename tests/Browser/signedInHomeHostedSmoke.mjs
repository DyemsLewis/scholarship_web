import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/signed-in-home-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    const guest=await fetch(origin+'/');
    assert.equal(guest.status,200);assert.equal(new URL(guest.url).pathname,'/');
    await login('demo.student.monitoring.appointment');
    assert.equal(await client.evaluate('location.pathname'),'/dashboard');
    const firstTab=client;
    const target=await control.send('Target.createTarget',{url:'about:blank',browserContextId:contextId});
    client=new Cdp('ws://127.0.0.1:'+port+'/devtools/page/'+target.targetId);await configure();
    await navigate('/','location.pathname==="/dashboard" && document.readyState==="complete" && !!window.axios');
    assert.equal(await firstTab.evaluate('location.pathname'),'/dashboard');firstTab.socket.close();
    const dashboard=await get('/dashboard/data');assert.equal(dashboard.status,200);assert.equal(dashboard.body.user.role,'applicant');
    await shot('signed-in-new-tab-dashboard');
    await navigate('/login','location.pathname==="/dashboard" && document.readyState==="complete" && !!window.axios');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Guest landing retained; signed-in applicant opens root in a second tab and reaches dashboard without login. Original tab/session and login redirect preserved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
