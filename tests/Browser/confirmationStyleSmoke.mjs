import { readFile } from 'node:fs/promises';

const base = process.env.CONFIRMATION_SMOKE_ORIGIN || 'http://127.0.0.1:8156';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(base)};`)
    .replace("accountability-hosted-part6';", "confirmation-style';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', `confirmation-style-browser-${base.startsWith('https:') ? 'hosted' : 'local'}.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const confirmPanel = "document.querySelector('.confirmation-dialog')";
    const check = async () => {
        await wait(()=>client.evaluate("!!document.querySelector('.confirmation-dialog')"), 'confirmation panel');
        const state = await client.evaluate("(()=>{const d=document.querySelector('.confirmation-dialog'),r=d.getBoundingClientRect();return {border:getComputedStyle(d).borderTopWidth,radius:getComputedStyle(d).borderTopLeftRadius,fits:r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1,focused:d.contains(document.activeElement),role:d.getAttribute('role'),locked:document.documentElement.style.overflowY==='hidden'}})()");
        assert.equal(state.border,'4px');assert.equal(state.fits,true);assert.equal(state.focused,true);assert.equal(state.role,'alertdialog');assert.equal(state.locked,true);
        return state;
    };
    await login('learner.profile');
    await navigate('/dashboard/profile', "!!document.querySelector('#app') && document.body.innerText.includes('Edit profile')");
    await client.evaluate("(()=>{const e=[...document.querySelectorAll('button')].find(e=>e.innerText.trim()==='Logout');e.focus();e.click()})()");
    const logout = await check(); await shot('logout-reference');
    assert.equal(await client.evaluate("document.activeElement.innerText.trim()"),'Cancel');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await client.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    assert.equal(await client.evaluate("document.activeElement.innerText.trim()"),'Log out');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await wait(()=>client.evaluate("!document.querySelector('.confirmation-dialog')"),'logout cancelled');
    assert.equal(await client.evaluate("document.activeElement.innerText.trim()"),'Logout');
    await click('Edit profile');
    await wait(()=>client.evaluate("!!document.querySelector('#profile-first-name')"),'profile editor');
    await client.evaluate("(()=>{const e=document.querySelector('#profile-first-name');e.value+=' Sample';e.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[aria-label=\"Close profile editor\"]').click()})()");
    const unsaved = await check(); assert.equal(unsaved.radius,logout.radius); await shot('unsaved-profile-confirmation');
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await check(); await shot('applicant-confirmation-mobile');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await wait(()=>client.evaluate("!document.querySelector('.confirmation-dialog')"),'unsaved confirmation cancelled');
    assert.equal(await client.evaluate("!!document.querySelector('#profile-first-name')"),true);
    await login('marilag.team');
    await navigate('/provider/workspaces/team/active', "!!document.querySelector('[aria-label=\"Suspend staff account\"]')");
    await client.evaluate("document.querySelector('[aria-label=\"Suspend staff account\"]').click()");
    const provider = await check(); assert.equal(provider.radius,logout.radius);
    assert.equal(await client.evaluate("!!document.querySelector('[data-provider-action-dialog]')"),true);
    await shot('provider-action-confirmation');await click('Cancel',confirmPanel);
    await login('marilag.programs');
    const dashboard = await get('/provider/programs/dashboard/data');
    assert.equal(dashboard.status,200);
    const draft = dashboard.body.priorities.find(p=>p.status.label.toLowerCase()==='draft');
    assert.ok(draft?.record_url,'An editable demo draft is required');
    const record = await get(draft.record_url + '/data');
    assert.equal(record.status,200);assert.ok(record.body.program.actions.edit_url);
    await client.send('Page.navigate',{url:origin+record.body.program.actions.edit_url});
    await wait(()=>client.evaluate("document.readyState==='complete' && !!window.axios"),'program form navigation');
    if (await client.evaluate("document.querySelector('#app')?.dataset.page==='providerProgramForm'")) {
    await wait(()=>client.evaluate("[...document.querySelectorAll('button')].some(e=>e.innerText.includes('Save and continue'))"),'program form controls');
    await client.evaluate("(()=>{const e=document.querySelector('input[type=text][maxlength=\"255\"]');e.value='';e.dispatchEvent(new Event('input',{bubbles:true}))})()");
    await client.evaluate("[...document.querySelectorAll('button')].find(e=>e.innerText.includes('Save and continue')).click()");
    const alert = await check(); assert.equal(alert.radius,logout.radius);
    assert.equal(await client.evaluate("document.querySelector('.confirmation-dialog').querySelectorAll('button').length"),1);
    assert.equal(await client.evaluate("document.querySelector('.confirmation-dialog').getAttribute('aria-labelledby')"),'form-alert-title');
    await shot('program-validation-alert');
    report.validationAlert=true;
    } else {
        assert.equal(origin.startsWith('https:'),true,'Local validation alert must be tested');
        report.validationAlertSkipped='Hosted demo account is redirected away from program editing; validation alert verified locally and in component tests.';
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS Shared confirmation styles; focus, Escape, nested modal, and mobile checks. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
