import { readFile } from 'node:fs/promises';
const origin=process.env.ALERT_TEST_ORIGIN || 'http://127.0.0.1:8000';
const username=process.env.HOSTED_AUDIT_USERNAME || 'nuvareya.programs';
const folder='C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/provider-feedback-alerts-20261115-'+(origin.startsWith('https:')?'hosted':'local');
let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("const origin = 'https://findscholarship.online';",`const origin = ${JSON.stringify(origin)};`).replace(/^const folder = .*;$/m,`const folder = ${JSON.stringify(folder)};`).replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json',folder+'/browser.json');
source=source.replace("d.querySelector('footer').getBoundingClientRect()", "(d.querySelector('footer') || d.lastElementChild).getBoundingClientRect()");
const start=source.indexOf("    const response = await fetch(origin + '/login');"),end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+`await login(${JSON.stringify(username)});`+String.raw`
    const list=await get('/provider/programs/data?filter=all');let program;
    for(const row of list.body.programs){const r=await get('/provider/programs/'+row.id+'/data');if(r.body.program.actions.edit_url){program=r.body.program;break;}}
    assert.ok(program);
    const before=(await get('/provider/scholarships/'+program.id)).body.scholarship;
    await navigate(program.actions.edit_url.replace(new RegExp('/basics$'),'/eligibility'),"!!document.querySelector('#program-eligible-courses')");
    const scope="document.querySelector('[data-eligibility-picker=program-eligible-courses]')";
    await client.evaluate("document.getElementById('program-eligible-courses').click()");
    await client.evaluate(scope+".querySelector('details summary').click()");
    await client.evaluate("(()=>{const e=document.getElementById('program-eligible-courses-custom');e.value='Invalid, option';e.dispatchEvent(new Event('input',{bubbles:true}));e.focus();e.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));})()");
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'option error dialog');
    await audit('[role=alertdialog]');await shot('eligibility-error-modal');
    await click('Got it',"document.querySelector('[role=alertdialog]')");
    await wait(()=>client.evaluate("!document.querySelector('[role=alertdialog]')"),'dismiss option error');
    assert.equal(await client.evaluate("document.getElementById('program-eligible-courses-custom').value"),'Invalid, option');
    await client.evaluate("(()=>{const e=document.getElementById('program-eligible-courses-custom');e.value='Test course';e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));})()");
    for(let count=0;count<30;count++){
        const exists=await client.evaluate(scope+".querySelectorAll('li button').length");if(!exists)break;
        await client.evaluate(scope+".querySelector('li button').click()");await delay(40);
    }
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Review this eligibility rule')"),'replacement warning');
    await audit('[role=alertdialog]');await click('Got it',"document.querySelector('[role=alertdialog]')");
    assert.equal(await client.evaluate(scope+".innerText.includes('Choose replacement options')"),true);
    await client.evaluate("(()=>{window.__alertGet=window.axios.get.bind(window.axios);window.__notificationRetries=0;window.axios.get=(url,...args)=>url==='/notifications'?(window.__notificationRetries++,Promise.reject(new Error('Simulated read-only notification failure'))):window.__alertGet(url,...args);})()");
    await client.evaluate("[...document.querySelectorAll('button')].find(b=>b.getAttribute('aria-label')==='Notifications').click()");
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Notification action unavailable')"),'notification dialog');
    await audit('[role=alertdialog]');
    await click('Refresh notifications',"document.querySelector('[role=alertdialog]')");
    await wait(()=>client.evaluate("window.__notificationRetries>=2 && !!document.querySelector('[role=alertdialog]')"),'notification retry');
    assert.equal(await client.evaluate("document.querySelectorAll('[role=alertdialog]').length"),1);
    await click('Close',"document.querySelector('[role=alertdialog]')");
    await wait(()=>client.evaluate("!document.querySelector('[role=alertdialog]')"),'close notification dialog');
    await client.evaluate('window.axios.get=window.__alertGet');
    const after=(await get('/provider/scholarships/'+program.id)).body.scholarship;
    assert.deepEqual(after.eligible_courses,before.eligible_courses);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS Visible modal errors, focus and scroll locking, preserved input, explicit restriction warning and notification retry. No business changes saved.');
`+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}catch(error){console.error(error.message);process.exitCode=1;}
