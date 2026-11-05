import { readFile } from 'node:fs/promises';

let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("accountability-hosted-part6';","reviewer-records-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json','reviewer-records-hosted.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');");
const end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    report.records=[];
    for(const name of ['marilag.reviewer','silayan.reviewer']) {
        await login(name);
        const queue=await get('/provider/workspaces/reviews/data?queue=mine');assert.equal(queue.status,200);
        const application=queue.body.applications[0];assert.ok(application?.detail_url,name+' prepared review record');
        const url=new URL(application.detail_url,origin);assert.equal(url.origin,origin);
        await navigate(url.pathname+url.search,"document.readyState==='complete' && !!window.axios && !!document.querySelector('#app')?.dataset.page");
        await delay(1500);
        assert.equal(await client.evaluate("document.body.innerText.includes('Provider approval is required')"),false);
        assert.equal(await client.evaluate("document.body.innerText.length>400"),true);
        await shot(name.replaceAll('.','-')+'-applicant-record');
        report.records.push({account:name,applicationId:application.id,page:await client.evaluate("document.querySelector('#app').dataset.page")});
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Reviewer applicant records open for both organizations; no business writes.');
`+source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
