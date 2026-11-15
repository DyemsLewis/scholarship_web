import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/login-cooldown-hosted-20261114';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json')
    .replace('const route = new URL(request.url).pathname;', String.raw`
            const route = new URL(request.url).pathname;
            if (route === '/login' && request.method === 'POST') {
                report.mockAttempts = (report.mockAttempts || 0) + 1;
                void client.send('Fetch.fulfillRequest', {
                    requestId, responseCode: 429,
                    responseHeaders: [{name:'Content-Type',value:'application/json'}, {name:'Retry-After',value:'65'}],
                    body: Buffer.from(JSON.stringify({message:'Too many sign-in attempts. Please wait before trying again.',retry_after:65})).toString('base64'),
                });
                return;
            }
    `);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    contextId = (await control.send('Target.createBrowserContext')).browserContextId;
    const target = await control.send('Target.createTarget', {url:'about:blank',browserContextId:contextId});
    client = new Cdp('ws://127.0.0.1:'+port+'/devtools/page/'+target.targetId);
    await configure();
    await navigate('/login',"!!document.querySelector('#login') && !!window.axios && document.readyState==='complete'");
    await client.evaluate("(()=>{for(const [selector,value] of [['#login','cooldown-ui-test'],['#password','not-a-real-password']]){const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event('input',{bubbles:true}));}document.querySelector('form').requestSubmit()})()");
    await wait(()=>client.evaluate("document.querySelector('button[type=submit]')?.innerText.includes('Try again in')"),'lockout timer');
    assert.equal(await client.evaluate("document.querySelector('button[type=submit]').disabled"),true);
    await shot('login-button-countdown');
    await client.evaluate("document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))");
    await delay(150);assert.equal(report.mockAttempts,1);
    await client.send('Emulation.setVirtualTimePolicy',{policy:'advance',budget:66000});
    await wait(()=>client.evaluate("document.querySelector('button[type=submit]')?.innerText.trim()==='Log in to portal' && !document.querySelector('button[type=submit]').disabled"),'automatic unlock');
    await shot('login-button-ready-again');
    assert.equal(report.mockAttempts,1);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Hosted login countdown, disabled retry and automatic unlock. Login response mocked; no real account attempts.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
