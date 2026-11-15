import { readFile } from 'node:fs/promises';

const origin = process.env.CHECK_IN_REDESIGN_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/check-in-redesign-' + (hosted ? 'hosted' : 'local') + '-20261114';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `
    const accounts=${JSON.stringify(hosted ? ['learner.cielo', 'learner.dune'] : ['cohort.learner.monitoring.appointment', 'cohort.learner.monitoring.review'])};
` + String.raw`
    async function openCase(username, match) {
        await login(username);
        const list=await get('/dashboard/applications/data'); assert.equal(list.status,200);
        const inventory=[];
        for(const application of list.body.applications.filter(row=>row.recipient_monitoring?.eligible)){
            const result=await get('/dashboard/monitoring/'+application.id+'/data?view=check-ins'); assert.equal(result.status,200);
            const content=result.body.detail.content, row=match(content);
            inventory.push({application:application.id,states:[...content.active,...content.upcoming,...content.completed].map(r=>({key:r.record_key,state:r.state,guidance:r.guidance?.key}))});
            if(row)return {application:application.id,content,row};
        }
        throw new Error('Missing case for '+username+': '+JSON.stringify(inventory));
    }
    const fit=()=>client.evaluate("(()=>{const nodes=[...document.querySelectorAll('[data-check-in-directory-header], [data-next-check-in-appointment], [data-monitoring-check-in-record], [data-check-in-card]')];return {overflow:document.documentElement.scrollWidth>innerWidth+2,clipped:nodes.some(e=>e.scrollWidth>e.clientWidth+2)}})()");
    const screenshot=async(name)=>{await client.evaluate('document.fonts.ready');await wait(()=>client.evaluate("[...document.querySelectorAll('[data-check-in-directory-header] img')].every(e=>e.complete && e.naturalWidth>0)"),'header image');await client.evaluate('window.scrollTo(0,0)');await shot(name);};
    const visit=await openCase(accounts[0],c=>c.directory.next_appointment);
    await navigate('/dashboard/monitoring/'+visit.application+'/check-ins',"!!document.querySelector('[data-next-check-in-appointment]')");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-record-section-intro]').length"),0);
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-monitoring-check-ins] a')].filter(e=>e.getAttribute('href')==="+JSON.stringify(visit.row.href)+").length"),1);
    const text=await client.evaluate("document.querySelector('[data-next-check-in-appointment]').innerText");
    for(const title of visit.row.preparation.preview_titles)assert.ok(text.includes(title));
    assert.ok(text.includes(visit.row.handoff.scheduled_label));
    assert.ok(text.includes(visit.row.handoff.location));
    assert.deepEqual(await fit(),{overflow:false,clipped:false});
    await screenshot('check-in-directory-desktop');
    await click('View map',"document.querySelector('[data-next-check-in-appointment]')");
    await wait(()=>client.evaluate("!!document.querySelector('#location-map-modal-title')"),'directory venue map');
    await client.evaluate("document.querySelector('[aria-label=\"Close map\"]').click()");
    await navigate(visit.row.href,"!!document.querySelector('[data-check-in-next-step]')");
    assert.equal(await client.evaluate("document.querySelectorAll('input[type=file]').length"),0);
    assert.equal(await client.evaluate("document.querySelector('[data-check-in-next-step]').getBoundingClientRect().bottom<=document.querySelector('[data-check-in-appointment]').getBoundingClientRect().top+1"),true);
    assert.match(await client.evaluate("document.querySelector('[data-check-in-preparation]').innerText"),/No online upload is needed/);
    await screenshot('check-in-detail-desktop');
    await click('View map',"document.querySelector('[data-check-in-appointment]')");
    await wait(()=>client.evaluate("!!document.querySelector('#location-map-modal-title')"),'detail venue map');
    await client.evaluate("document.activeElement.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
    await wait(()=>client.evaluate("!document.querySelector('#location-map-modal-title')"),'close venue map');
    await client.evaluate("document.querySelector('[data-check-in-preparation] article button').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-check-in-preparation] article button').getAttribute('aria-expanded')"),'true');
    assert.equal(await client.evaluate("document.querySelectorAll('input[type=file]').length"),0);
    await client.evaluate("document.querySelector('[data-check-in-preparation] article button').click()");
    for(const width of [1024,390,320]){
        await client.send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<640});
        assert.deepEqual(await fit(),{overflow:false,clipped:false});
        assert.equal(await client.evaluate("document.querySelector('[data-check-in-next-step]').getBoundingClientRect().bottom<=document.querySelector('[data-check-in-appointment]').getBoundingClientRect().top+1"),true);
        if(width===390)await screenshot('check-in-detail-mobile');
    }
    await navigate('/dashboard/monitoring/'+visit.application+'/check-ins',"!!document.querySelector('[data-next-check-in-appointment]')");
    assert.deepEqual(await fit(),{overflow:false,clipped:false});
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await screenshot('check-in-directory-mobile');
    const review=await openCase(accounts[1],c=>[...c.directory.active,...c.directory.upcoming].find(r=>r.handoff?.started));
    await navigate(review.row.href,"!!document.querySelector('[data-check-in-next-step]')");
    const reviewText=await client.evaluate("document.querySelector('[data-check-in-next-step]').innerText");
    assert.match(reviewText,/provider/i);
    assert.equal(await client.evaluate("document.querySelectorAll('input[type=file]').length"),0);
    const history=await client.evaluate("[...document.querySelectorAll('button')].some(e=>e.innerText.trim()==='Case history')");
    if(history){
        await click('Case history');
        await wait(()=>client.evaluate("!!document.querySelector('#monitoring-history-title')"),'case history');
        await audit('[aria-labelledby="monitoring-history-title"]');
        await click('Close',"document.querySelector('[aria-labelledby=\"monitoring-history-title\"]')");
    }
    assert.deepEqual(await fit(),{overflow:false,clipped:false});
    await screenshot('check-in-provider-review-desktop');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Check-in directory/detail, appointment uniqueness, next-step order, original-document rules, maps, case history and responsive layout. No business records changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
