import { readFile } from 'node:fs/promises';

const origin = process.env.VERIFICATION_ORIGIN || 'http://127.0.0.1:8000';
const account = process.env.VERIFICATION_ACCOUNT || 'novacore.reviews';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `applicant-verification-redesign-${label}-20261115`)
    .replace('url: origin + url', 'url: new URL(url, origin).href')
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/applicant-verification-redesign-${label}-browser-20261115.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = String.raw`
    await login(__REVIEW_ACCOUNT__);
    const queue=await get('/admin/workspaces/reviews/applicants/data?status=pending');assert.equal(queue.status,200);
    let row,original;
    for(const candidate of queue.body.items){
        const data=await get('/admin/applicants/'+candidate.id+'/review/data');assert.equal(data.status,200);
        if(data.body.applicant.verification_documents?.some(document=>document.document_type==='academic_record'&&document.file_available!==false)&&data.body.applicant.profile_photo_url){row=candidate;original=data.body.applicant;break;}
    }
    assert.ok(row,'An applicant with academic evidence and a photo is required');
    await navigate(row.review_url,"!!document.querySelector('[data-applicant-verification-summary]')");
    await wait(()=>client.evaluate("!document.body.innerText.includes('Finding the next matching record')"),'queue navigation settled');
    assert.equal(await client.evaluate("(()=>{const evidence=document.querySelector('#supporting-evidence').getBoundingClientRect(),decision=document.querySelector('#final-profile-verification').getBoundingClientRect();return decision.top>=evidence.bottom&&Math.abs(decision.left-evidence.left)<2&&Math.abs(decision.width-evidence.width)<2})()"),true,'Evidence and final decision are stacked at the same full width');
    const size=(await client.send('Page.getLayoutMetrics')).cssContentSize;
    const capture=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:size.width,height:size.height,scale:1}});
    const pageImage=path.join(folder,'applicant-verification-page.png');await writeFile(pageImage,Buffer.from(capture.data,'base64'));report.screenshots.push(pageImage);
    assert.equal(await client.evaluate("(()=>{const e=document.querySelector('#supporting-evidence .staff-table-viewport');return !e||e.scrollWidth<=e.clientWidth+2})()"),true,'Evidence table fits its panel');
    const draft=()=>client.evaluate("({outcome:document.querySelector('#final-profile-verification select').value,note:document.querySelector('#final-profile-verification textarea').value})");
    await client.evaluate("(()=>{const s=document.querySelector('#final-profile-verification select');s.value='rejected';s.dispatchEvent(new Event('change',{bubbles:true}));const t=document.querySelector('#final-profile-verification textarea');t.value='Unsaved final verification note';t.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const before=await draft();
    const geometry=()=>client.evaluate("(()=>{const r=document.querySelector('[data-applicant-verification-summary]').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,scroll:scrollY}})()");
    const position=await geometry();
    const profile=()=>client.evaluate("!!document.querySelector('[data-applicant-verification-profile]')");
    const profileReady=()=>client.evaluate("!!document.querySelector('[data-applicant-verification-profile] [data-profile-details]')");
    await click('View profile');await wait(profileReady,'profile modal');await audit('[data-applicant-verification-profile]');
    assert.deepEqual(await geometry(),position,'Profile modal keeps the background stable');await shot('applicant-profile-personal');
    const tabs=await client.evaluate("[...document.querySelectorAll('[data-applicant-verification-profile] [role=tab]')].map(tab=>tab.innerText.trim())");
    for(const tab of tabs.filter(tab=>tab!=='Photo review')){
        await click(tab,"document.querySelector('[data-applicant-verification-profile] [role=tablist]')");await delay(80);await audit('[data-applicant-verification-profile]');assert.deepEqual(await draft(),before);
        if(tab==='Learning record')await shot('applicant-profile-learning');
        if(tab==='Home address'&&await client.evaluate("!![...document.querySelectorAll('[data-applicant-verification-profile] button')].find(button=>button.innerText.trim()==='View map')")){
            await click('View map',"document.querySelector('[data-applicant-verification-profile]')");await wait(()=>client.evaluate("!!document.querySelector('#location-map-modal-title')"),'nested map');
            assert.equal(await profile(),true);assert.equal(await client.evaluate("document.documentElement.style.overflowY"),'hidden');
            await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await wait(()=>client.evaluate("!document.querySelector('#location-map-modal-title')"),'close map');
            assert.equal(await profile(),true);await audit('[data-applicant-verification-profile]');report.nestedMapChecked=true;
        }
    }
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await wait(async()=>!await profile(),'close profile');assert.deepEqual(await geometry(),position);assert.deepEqual(await draft(),before);
    assert.equal(await client.evaluate("document.activeElement.innerText.trim()"),'View profile');
    console.log('PROFILE_MODAL_PASS');
    await click('Review photo');await wait(()=>client.evaluate("!!document.querySelector('[data-applicant-verification-profile] textarea')"),'photo review');await audit('[data-applicant-verification-profile]');
    assert.equal(await client.evaluate("document.querySelector('[data-applicant-verification-profile] [role=tab][aria-selected=true]').innerText.trim()"),'Photo review');
    await client.evaluate("(()=>{const e=document.querySelector('[data-applicant-verification-profile] textarea');e.value='Unsaved photo replacement reason';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    await shot('applicant-photo-review');
    await client.evaluate("document.querySelector('[data-applicant-verification-profile] a[data-file-preview=true]').click()");await wait(()=>client.evaluate("!!document.querySelector('[aria-label=\"Close file preview\"]')"),'photo file preview');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await wait(()=>client.evaluate("!document.querySelector('[aria-label=\"Close file preview\"]')"),'close photo preview');
    assert.equal(await profile(),true);await audit('[data-applicant-verification-profile]');
    await click('Approve photo',"document.querySelector('[data-applicant-verification-profile]')");await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'photo confirmation');await click('Go back',"document.querySelector('[role=alertdialog]')");
    assert.equal(await client.evaluate("document.querySelector('[data-applicant-verification-profile] textarea').value"),'Unsaved photo replacement reason');
    await click('Close profile',"document.querySelector('[data-applicant-verification-profile]')");await wait(async()=>!await profile(),'close photo review');assert.deepEqual(await draft(),before);
    console.log('PHOTO_REVIEW_PASS');
    await client.evaluate("[...document.querySelectorAll('#supporting-evidence button')].find(button=>button.innerText.startsWith('All evidence')).click()");
    await client.evaluate("(()=>{const row=[...document.querySelectorAll('#supporting-evidence tbody tr')].find(row=>/academic|grade/i.test(row.querySelector('td').innerText));if(!row)throw new Error('Academic evidence missing');row.querySelector('button').focus();row.querySelector('button').click()})()");
    await wait(()=>client.evaluate("!!document.querySelector('.evidence-review-dialog')"),'academic evidence dialog');await audit('.evidence-review-dialog');
    await client.evaluate("document.querySelector('.evidence-review-dialog input[value=approved]').click()");await wait(()=>client.evaluate("!!document.querySelector('[data-academic-results]')"),'academic result controls');
    await client.evaluate("(()=>{const e=document.querySelector('[data-academic-results] select');e.value='percentage';e.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('.evidence-review-dialog input[type=checkbox]').click()})()");
    await client.evaluate("(()=>{const e=document.querySelector('[data-academic-results] input');e.value='101';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    assert.equal(await client.evaluate("document.querySelector('button[form=evidence-decision]').disabled"),true,'Invalid grades cannot be accepted');
    await client.evaluate("(()=>{const e=document.querySelector('[data-academic-results] input');e.value='92';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    assert.equal(await client.evaluate("document.querySelector('button[form=evidence-decision]').disabled"),false,'Valid academic review stays available');await shot('academic-evidence-review');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await wait(()=>client.evaluate("!document.querySelector('.evidence-review-dialog')"),'close evidence');assert.deepEqual(await draft(),before);
    assert.equal(await client.evaluate("!!document.querySelector('#final-profile-verification input[type=number]')"),false);
    console.log('ACADEMIC_EVIDENCE_PASS');
    await click('Save decision');await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'final profile confirmation');await click('Go back',"document.querySelector('[role=alertdialog]')");assert.deepEqual(await draft(),before);
    const after=(await get('/admin/applicants/'+row.id+'/review/data')).body.applicant;
    assert.equal(after.applicant_verification_status,original.applicant_verification_status);assert.equal(after.gwa,original.gwa);assert.equal(after.profile_photo_review_status,original.profile_photo_review_status);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS Applicant verification redesign: profile/photo modals, nested previews, stable layout, academic validation, preserved drafts and cancelled confirmations. No records changed.');
`.replace('__REVIEW_ACCOUNT__', JSON.stringify(account));
source=source.slice(0,start)+cases+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
