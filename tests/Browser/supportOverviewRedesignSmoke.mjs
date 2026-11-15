import { readFile } from 'node:fs/promises';

const origin=process.env.SUPPORT_OVERVIEW_ORIGIN || 'http://127.0.0.1:8000';
const hosted=origin.startsWith('https:');
const folder='C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/support-overview-'+(hosted?'hosted':'local')+'-20261115';
let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("const origin = 'https://findscholarship.online';",`const origin = ${JSON.stringify(origin)};`).replace(/^const folder = .*;$/m,`const folder = ${JSON.stringify(folder)};`).replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json',folder+'/browser.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');"),end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+`
    const accounts=${JSON.stringify(hosted?['learner.cielo','learner.aurora','learner.aurora','learner.aurora']:['cohort.learner.monitoring.appointment','cohort.learner.release.confirm','cohort.learner.onetime.plan','cohort.learner.agreement'])};
`+String.raw`
    async function openCase(username,match){
        await login(username);
        const list=await get('/dashboard/applications/data');assert.equal(list.status,200);
        const inventory=[];
        for(const row of list.body.applications.filter(r=>r.recipient_monitoring?.eligible)){
            const result=await get('/dashboard/monitoring/'+row.id+'/data');assert.equal(result.status,200);
            const detail=result.body.detail;inventory.push({id:row.id,state:detail.decision.state,mode:detail.content.plan.mode});
            if(match(detail))return {id:row.id,detail};
        }
        throw new Error('No overview case for '+username+': '+JSON.stringify(inventory));
    }
    const fits=()=>client.evaluate("(()=>{const nodes=[...document.querySelectorAll('[data-monitoring-next-action], [data-overview-summary], [data-overview-schedule], [data-overview-plan]')];return {overflow:document.documentElement.scrollWidth>innerWidth+2,clipped:nodes.some(e=>e.scrollWidth>e.clientWidth+2)}})()");
    const screenshot=async(name)=>{await client.evaluate('document.fonts.ready');await wait(()=>client.evaluate("[...document.querySelectorAll('[data-monitoring-next-action] img')].every(e=>e.complete&&e.naturalWidth>0)"),'overview image');await client.evaluate('window.scrollTo(0,0)');await shot(name);};
    const appointment=await openCase(accounts[0],d=>d.decision.state==='check_in_scheduled');
    await navigate('/dashboard/monitoring/'+appointment.id,"!!document.querySelector('[data-overview-summary]')");
    assert.equal(await client.evaluate("document.querySelector('#monitoring-next-step-title').innerText"),appointment.detail.decision.title);
    const rendered=await client.evaluate("document.querySelector('[data-monitoring-overview]').innerText");
    for(const item of appointment.detail.content.schedule){assert.ok(rendered.includes(item.title));assert.ok(rendered.includes(item.date_label));}
    assert.ok(rendered.includes(appointment.detail.content.plan.agreement.label));
    assert.deepEqual(await fits(),{overflow:false,clipped:false});
    await screenshot('support-overview-desktop');
    await client.evaluate("document.querySelector('[data-overview-plan] summary').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-overview-plan]').open"),true);
    assert.equal(await client.evaluate("!!document.querySelector('[data-overview-plan] a[href]')"),!!appointment.detail.content.plan.agreement.href);
    await client.evaluate("document.querySelector('[data-overview-plan] summary').click()");
    for(const width of [1024,390,320]){
        await client.send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<640});
        assert.deepEqual(await fits(),{overflow:false,clipped:false});
        if(width===390)await screenshot('support-overview-mobile');
    }
    const receipt=await openCase(accounts[1],d=>d.decision.state==='benefit_confirmation_required');
    await navigate('/dashboard/monitoring/'+receipt.id,"!!document.querySelector('[data-overview-summary]')");
    assert.match(await client.evaluate("document.querySelector('[data-monitoring-next-action]').innerText"),/confirm/i);
    const action=await client.evaluate("document.querySelector('[data-monitoring-next-action] footer a').getAttribute('href')");
    assert.equal(action,receipt.detail.decision.action.href);
    await screenshot('support-overview-benefit-action');
    await navigate(action,"!!document.querySelector('[data-benefit-release-record]')");
    const oneTime=await openCase(accounts[2],d=>d.content.plan.mode==='one_time'&&d.content.plan.agreement_accepted&&!d.content.closeout);
    await navigate('/dashboard/monitoring/'+oneTime.id,"!!document.querySelector('[data-overview-summary]')");
    assert.match(await client.evaluate("document.querySelector('[data-overview-summary]').innerText"),/One-time benefit only/);
    if(!oneTime.detail.content.schedule.length)assert.match(await client.evaluate("document.querySelector('[data-overview-schedule-empty]').innerText"),/Delivery instructions/);
    assert.deepEqual(await fits(),{overflow:false,clipped:false});
    const agreement=await openCase(accounts[3],d=>d.decision.state==='agreement_required');
    await navigate('/dashboard/monitoring/'+agreement.id,"!!document.querySelector('[data-overview-summary]')");
    assert.equal(await client.evaluate("!!document.querySelector('[data-overview-schedule]')"),false);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Illustrated support overview, current action, schedule, agreement, one-time award, existing benefit link and desktop/mobile layout. No business records changed.');
`+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}catch(error){console.error(error.message);process.exitCode=1;}
