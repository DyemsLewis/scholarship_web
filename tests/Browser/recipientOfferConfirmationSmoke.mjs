import { readFile } from 'node:fs/promises';

const origin = process.env.OFFER_ORIGIN || 'http://127.0.0.1:8000';
const applicant = process.env.OFFER_APPLICANT || 'cohort.learner.agreement';
const provider = process.env.OFFER_PROVIDER || 'nuvareya.manager';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `offer-confirmation-${label}-20261116`)
    .replace('url: origin + url', 'url: new URL(url, origin).href')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/offer-confirmation-${label}-browser-20261116.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = String.raw`
    await login(__APPLICANT__);
    const list=await get('/dashboard/applications/data');assert.equal(list.status,200);
    const row=list.body.applications.find(application=>application.recipient_agreement?.can_respond);assert.ok(row,'A selected applicant with a pending offer is required');
    const id=row.id;
    const before=await get('/dashboard/applications/'+id+'/data?view=outcome');assert.equal(before.status,200);
    assert.equal(before.body.detail.content.agreement.response_scope,'offer_confirmation');assert.equal(before.body.detail.content.agreement.final_contract_recorded,false);
    assert.equal(before.body.detail.content.result.label,'Selected');
    await navigate('/dashboard/applications/'+id+'/outcome?action=agreement',"!!document.querySelector('[data-offer-confirmation-notice]')");
    assert.equal(await client.evaluate("document.querySelector('#recipient-agreement-title').innerText"),'Review your offer summary');
    const confirmDisabled=()=>client.evaluate("[...document.querySelectorAll('[role=dialog] button')].find(button=>button.innerText.trim()==='Confirm intention').disabled");
    assert.equal(await confirmDisabled(),true);await shot('applicant-offer-confirmation-desktop');
    await client.evaluate("document.querySelector('[role=dialog] input[type=checkbox]').click()");assert.equal(await confirmDisabled(),false);
    await click('Confirm intention',"document.querySelector('[role=dialog]')");
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'intent confirmation');
    assert.equal(await client.evaluate("document.querySelector('[role=alertdialog]').innerText.includes('does not sign a final contract')"),true);
    await click('Go back',"document.querySelector('[role=alertdialog]')");
    await wait(()=>client.evaluate("!document.querySelector('[role=alertdialog]')"),'cancel intent confirmation');
    assert.equal(await confirmDisabled(),false);
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.equal(await client.evaluate("(()=>{const dialog=document.querySelector('[role=dialog]'),box=dialog.getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth+2&&box.top>=0&&box.bottom<=innerHeight+2&&dialog.querySelector('footer').getBoundingClientRect().bottom<=innerHeight+2})()"),true);
    await shot('applicant-offer-confirmation-mobile');await click('Close',"document.querySelector('[role=dialog]')");
    const after=await get('/dashboard/applications/'+id+'/data?view=outcome');assert.equal(after.body.detail.content.agreement.status,before.body.detail.content.agreement.status);assert.equal(after.body.detail.content.result.label,'Selected');
    await login(__PROVIDER__);
    await navigate('/provider/applications/'+id+'?workspace=recipient',"!!document.querySelector('[data-provider-offer-notice]')");
    assert.equal(await client.evaluate("document.querySelector('[data-provider-offer-notice]').innerText.includes('not proof of a signed final contract')"),true);
    await click('Offer response');await shot('provider-offer-response');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS Offer summary, already-selected guidance, required intention checkbox, cancelled confirmation, mobile layout, provider disclaimer, and unchanged response. No business actions submitted.');
`.replace('__APPLICANT__', JSON.stringify(applicant)).replace('__PROVIDER__', JSON.stringify(provider));
source = source.slice(0, start) + cases + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message);process.exitCode = 1; }
