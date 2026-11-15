import { readFile } from 'node:fs/promises';

const origin = process.env.PROGRAM_REVIEW_ORIGIN || 'http://127.0.0.1:8000';
const account = process.env.PROGRAM_REVIEW_ACCOUNT || 'novacore.reviews';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `admin-program-review-modal-${label}-20261115`)
    .replace('url: origin + url', 'url: new URL(url, origin).href')
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/admin-program-review-modal-${label}-browser-20261115.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = `
    await login(${JSON.stringify(account)});
    const queue=await get('/admin/workspaces/reviews/programs/data?status=pending_review');assert.equal(queue.status,200);assert.ok(queue.body.items.length,'A pending program is required');
    const row=queue.body.items[0];await navigate(row.review_url,"!!document.querySelector('[data-program-review-summary]') && !document.querySelector('.admin-page').innerText.includes('Loading program record')");
    await wait(()=>client.evaluate("!document.body.innerText.includes('Finding the next matching record')"),'navigation settled');await delay(200);
    const id=row.id;
    const original=await get('/admin/scholarships/'+id+'/review/data');assert.equal(original.status,200);
    const originalStatus=original.body.scholarship.status;
    const size=(await client.send('Page.getLayoutMetrics')).cssContentSize;
    const capture=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:size.width,height:size.height,scale:1}});
    const pageImage=path.join(folder,'program-review-page.png');await writeFile(pageImage,Buffer.from(capture.data,'base64'));report.screenshots.push(pageImage);
    const draft=()=>client.evaluate("({outcome:document.querySelector('#publication-decision select')?.value,note:document.querySelector('#publication-decision textarea')?.value})");
    await client.evaluate("(()=>{const s=document.querySelector('#publication-decision select');s.value='rejected';s.dispatchEvent(new Event('change',{bubbles:true}));const t=document.querySelector('#publication-decision textarea');t.value='Unsaved review note retained while inspecting the program.';t.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const before=await draft();
    const geometry=()=>client.evaluate("(()=>{const r=document.querySelector('[data-program-review-summary]').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,scroll:scrollY}})()");
    const position=await geometry();
    const modal=()=>client.evaluate("!!document.querySelector('[data-program-details-modal]')");
    const ready=()=>client.evaluate("!!document.querySelector('[data-program-details-modal] [role=tabpanel] h3')");
    const clickTab=async label=>{await click(label,"document.querySelector('[role=tablist]')");await delay(80);await audit('[data-program-details-modal]');};
    await click('Open details');await wait(ready,'details modal');await audit('[data-program-details-modal]');
    assert.deepEqual(await geometry(),position,'Opening details must not move the background');
    await shot('program-details-overview');
    for(const [tab,heading] of [['Eligibility','Eligibility rules'],['Benefits & terms','Benefits and recipient terms'],['Application','Application requirements'],['Selection','Selection and schedule']]){
        await clickTab(tab);assert.equal(await client.evaluate("document.querySelector('[role=tabpanel] h3').innerText"),heading);
        assert.deepEqual(await draft(),before,'Review draft must remain unchanged');
        await shot('program-details-'+tab.split(' ')[0].toLowerCase());
    }
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Home',code:'Home'});await delay(100);
    assert.equal(await client.evaluate("document.querySelector('[role=tab][aria-selected=true]').innerText.trim()"),'Overview');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowLeft',code:'ArrowLeft'});await delay(100);
    assert.equal(await client.evaluate("document.querySelector('[role=tab][aria-selected=true]').innerText.trim()"),'Selection');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});await wait(async()=>!await modal(),'close details');
    assert.deepEqual(await geometry(),position);assert.deepEqual(await draft(),before);
    assert.equal(await client.evaluate("document.activeElement.innerText.trim()"),'Open details');
    assert.notEqual(await client.evaluate("document.documentElement.style.overflowY"),'hidden');
    for(const [label,heading] of [['Open eligibility rules','Eligibility rules'],['Open benefits and recipient terms','Benefits and recipient terms']]){
        await client.evaluate("document.querySelector('button[aria-label='+JSON.stringify("+JSON.stringify(label)+")+']').click()");
        await wait(ready,'direct section');assert.equal(await client.evaluate("document.querySelector('[role=tabpanel] h3').innerText"),heading);
        await click('Close details',"document.querySelector('[data-program-details-modal]')");await wait(async()=>!await modal(),'close section');
    }
    await click('Save decision');await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'outcome confirmation');
    assert.equal(await modal(),false,'Details do not overlap the decision confirmation');
    await click('Go back',"document.querySelector('[role=alertdialog]')");
    assert.deepEqual(await draft(),before);
    assert.equal((await get('/admin/scholarships/'+id+'/review/data')).body.scholarship.status,originalStatus);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Admin program review: compact page, all five modal sections, keyboard/focus, stable background, preserved decision draft, and cancelled confirmation. No business writes.');
`;
source=source.slice(0,start)+cases+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
