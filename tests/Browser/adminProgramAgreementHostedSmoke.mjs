import { readFile } from 'node:fs/promises';

let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("accountability-hosted-part6';","admin-program-agreement-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json','admin-program-agreement-hosted.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');");
const end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    await login('platform.reviews');
    const queue=await get('/admin/workspaces/reviews/programs/data?status=all&per_page=25');assert.equal(queue.status,200);
    assert.ok(queue.body.items.length>0);report.programs=[];let captured=false;
    for(const program of queue.body.items) {
        const dataUrl='/admin/scholarships/'+program.id+'/review/data';
        const before=await get(dataUrl);assert.equal(before.status,200);
        await navigate('/admin/workspaces/reviews/programs/'+program.id,'document.body.innerText.includes('+JSON.stringify(program.title)+')');
        await client.evaluate("(()=>{const s=[...document.querySelectorAll('summary')].find(e=>e.innerText.includes('Applicant terms and support'));if(!s)throw new Error('Missing terms section');s.parentElement.open=true;s.parentElement.scrollIntoView({block:'center'})})()");
        await wait(()=>client.evaluate("!!document.querySelector('[data-recipient-agreement-details]') || document.body.innerText.includes('No recipient agreement has been provided.')"),'formatted agreement');
        const view=await client.evaluate("(()=>{const d=document.querySelector('[data-recipient-agreement-details]');return {text:d?.innerText||'',labels:[...d?.querySelectorAll('dt')||[]].map(e=>e.innerText),overflow:d?d.scrollWidth>d.clientWidth+2:false}})()");
        assert.doesNotMatch(view.text,/"commitment_type"\s*:|reporting_and_participation|"release_conditions"\s*:/);
        assert.equal(view.overflow,false);
        if(before.body.scholarship.recipient_agreement?.responsibilities) assert.ok(view.labels.includes('Recipient responsibilities'));
        if(!captured && before.body.scholarship.recipient_agreement?.commitment_type==='reporting_and_participation') {
            await shot('readable-recipient-agreement');captured=true;
        }
        const after=await get(dataUrl);assert.equal(after.status,200);
        assert.equal(after.body.scholarship.status,before.body.scholarship.status);
        assert.deepEqual(after.body.scholarship.recipient_agreement,before.body.scholarship.recipient_agreement);
        report.programs.push({id:program.id,title:program.title,labels:view.labels});console.log('PASS_PROGRAM '+program.title);
    }
    assert.equal(captured,true,'The reported reporting-and-participation agreement was inspected');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS All program review agreements use readable fields; publication status and saved terms unchanged; no business writes.');
`+source.slice(end);
try {await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
