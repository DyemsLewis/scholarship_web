import { readFile } from 'node:fs/promises';

const origin = process.env.BENEFIT_REDESIGN_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/benefit-release-redesign-' + (hosted ? 'hosted' : 'local') + '-20261114';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `
    const accounts=${JSON.stringify(hosted ? ['learner.aurora', 'learner.brio', 'learner.dune'] : ['cohort.learner.release.confirm', 'cohort.learner.onetime.record', 'cohort.learner.payout.details'])};
` + String.raw`
    async function openCase(username, match) {
        await login(username);
        const list=await get('/dashboard/applications/data');assert.equal(list.status,200);
        const inventory=[];
        for(const application of list.body.applications.filter(row=>row.recipient_monitoring?.eligible)){
            const result=await get('/dashboard/monitoring/'+application.id+'/data?view=benefits');assert.equal(result.status,200);
            const content=result.body.detail.content;
            const row=content.all.find(match);
            inventory.push({application:application.id,states:content.all.map(r=>({key:r.delivery.key,method:r.release_method,payout:r.payout?.status}))});
            if(row)return {application:application.id,content,row};
        }
        throw new Error('Missing case for '+username+': '+JSON.stringify(inventory));
    }
    function auditWidth(){return client.evaluate("(()=>{const elements=[...document.querySelectorAll('[data-benefit-card], [data-benefit-release-record] article, [data-benefit-next-step], [data-benefit-directory-header]')];return {overflow:document.documentElement.scrollWidth>innerWidth+2,clipped:elements.some(e=>e.scrollWidth>e.clientWidth+2)}})()");}
    const receipt=await openCase(accounts[0],r=>r.can_respond);
    await navigate('/dashboard/monitoring/'+receipt.application+'/benefits',"!!document.querySelector('[data-benefit-card]')");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-record-section-intro]').length"),0);
    const keys=await client.evaluate("[...document.querySelectorAll('[data-benefit-card]')].map(e=>e.dataset.releaseKey)");
    assert.equal(new Set(keys).size,keys.length);
    await shot('benefit-release-directory-desktop');
    await navigate(receipt.row.href,"!!document.querySelector('[data-benefit-next-step]') && document.querySelector('[data-benefit-next-step]').innerText.includes('Confirm receipt')");
    await wait(()=>client.evaluate("document.querySelector('[data-benefit-next-step]').innerText.includes('Report a problem')"),'report action');
    assert.deepEqual(await auditWidth(),{overflow:false,clipped:false});
    await client.evaluate('window.scrollTo(0,0)');
    await shot('benefit-release-detail-desktop');
    await click('Confirm receipt',"document.querySelector('[data-benefit-next-step]')");
    await wait(()=>client.evaluate("!!document.querySelector('#benefit-response-title')"),'receipt dialog');
    await audit('form[aria-labelledby="benefit-response-title"]');
    await click('Cancel',"document.querySelector('form[aria-labelledby=\"benefit-response-title\"]')");
    await click('Report a problem',"document.querySelector('[data-benefit-next-step]')");
    await wait(()=>client.evaluate("!!document.querySelector('[data-delivery-dialog]')"),'report dialog');
    await audit('[data-delivery-dialog]');
    await click('Cancel',"document.querySelector('[data-delivery-dialog]')");
    for(const width of [1024,390,320]){
        await client.send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<640});
        assert.deepEqual(await auditWidth(),{overflow:false,clipped:false});
        if(width===390)await shot('benefit-release-detail-mobile');
    }
    await navigate('/dashboard/monitoring/'+receipt.application+'/benefits',"!!document.querySelector('[data-benefit-card]')");
    assert.deepEqual(await auditWidth(),{overflow:false,clipped:false});
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await shot('benefit-release-directory-mobile');
    const scheduled=await openCase(accounts[1],r=>['scheduled','ready','waiting'].includes(r.delivery.key) && r.release_method==='in_person');
    await navigate(scheduled.row.href,"!!document.querySelector('[data-benefit-delivery-details]')");
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-benefit-next-step] button')].some(b=>b.innerText.trim()==='Confirm receipt')"),false);
    if(scheduled.row.release_method==='in_person' && scheduled.row.location){
        await client.evaluate("document.querySelector('[data-benefit-delivery-details] button').click()");
        await wait(()=>client.evaluate("!!document.querySelector('#location-map-modal-title')"),'venue map');
        await client.evaluate("document.activeElement.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
    }
    const payout=await openCase(accounts[2],r=>r.payout?.submit_url);
    await navigate(payout.row.href,"!!document.querySelector('[data-benefit-next-step]')");
    await client.evaluate("[...document.querySelectorAll('[data-benefit-next-step] button')].find(b=>/details/.test(b.innerText)).click()");
    await wait(()=>client.evaluate("!!document.querySelector('[data-payout-details-dialog]')"),'payout dialog');
    await audit('[data-payout-details-dialog]');
    await click('Cancel',"document.querySelector('[data-payout-details-dialog]')");
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Benefit directory/detail, receipt and report dialogs, scheduled-release guards, venue map, private payout form and responsive layout. No business records changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
