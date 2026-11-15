import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guides-${label}-part4`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guides-part4-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/monitoring/progress', '/provider/workspace-guides/releases/progress'].includes(route)");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = `
    const visible = () => client.evaluate("!!document.querySelector('.workspace-guide-dialog')");
    const guideClick = label => click(label, "document.querySelector('.workspace-guide-dialog')");
    const openGuide = async () => {
        if (!await visible()) await click('Workspace guide');
        await wait(() => client.evaluate("!!document.querySelector('.workspace-guide-dialog h3')"),'loaded guide');
        await audit('.workspace-guide-dialog');
    };
    const closeGuide = async () => { await guideClick('Skip guide'); await wait(async()=>!await visible(),'close guide'); };
    const fields = () => client.evaluate("[...document.querySelectorAll('input,textarea,select')].map(e=>[e.name,e.id,e.value,e.checked,e.disabled])");
    const geometry = () => client.evaluate("(()=>{const r=document.querySelector('header h1').getBoundingClientRect();return {x:r.x,y:r.y,w:r.width}})()");
    const track = async (key,status) => wait(async () => (await get('/provider/workspace-guides/'+key)).body.status===status,'save '+key+' '+status);
    const queueReady = heading => "!!document.querySelector("+JSON.stringify(heading)+") && !document.querySelector('.student-page').innerText.includes('Loading')";
    const configs = [
        { key:'monitoring', title:'Monitoring workspace', first:'Start with the plan and priorities', last:'Resolve follow-ups and close carefully', queue:'/provider/workspaces/monitoring/review', heading:'#monitoring-directory-title', endpoint:'/provider/workspaces/monitoring/data', queues:['review','awaiting','followup','history'], collection:'check_ins', recordHeading:'#monitoring-record-title' },
        { key:'releases', title:'Benefit Releases workspace', first:'Check readiness and schedule', last:'Follow receipt and resolve issues', queue:'/provider/workspaces/releases/record', heading:'#release-directory-title', endpoint:'/provider/workspaces/releases/data', queues:['record','upcoming','history','issues'], collection:'releases', recordHeading:'#release-record-title' }
    ];
    for (const config of configs) {
        await login('${prefix}.'+config.key);
        await wait(() => client.evaluate("!!document.querySelector('.workspace-dashboard-header .workspace-dashboard-action')"),'loaded dashboard');
        assert.equal(await client.evaluate("(()=>{const h=document.querySelector('.workspace-dashboard-header'),r=h.getBoundingClientRect();return [...h.querySelectorAll('button,a')].every(e=>{const b=e.getBoundingClientRect();return b.left>=r.left&&b.right<=r.right+2;})})()"),true,'Dashboard actions stay inside the header');
        const initial=await get('/provider/workspace-guides/'+config.key); assert.equal(initial.status,200);
        await openGuide(); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText"),config.title);
        await shot(config.key+'-guide-start'); await closeGuide();
        if(initial.body.status!=='completed') await track(config.key,'dismissed');
        await navigate(config.queue,queueReady(config.heading));
        await delay(250); assert.equal(await visible(),false,'No repeated popup');
        const before=await geometry(); await openGuide(); assert.deepEqual(await geometry(),before,'Background stays in place');
        await guideClick('Next'); await guideClick('Back'); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.first)+")"),true);
        await guideClick('Next'); await guideClick('Next'); await guideClick('Next');
        assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.last)+")"),true);
        await audit('.workspace-guide-dialog'); await shot(config.key+'-guide-handoff'); await guideClick('Got it'); await track(config.key,'completed');
        let records=[];
        for(const queue of config.queues){const data=await get(config.endpoint+'?queue='+queue);assert.equal(data.status,200);records=data.body[config.collection];if(records.length)break;}
        assert.ok(records.length,'Existing demo record required');
        await navigate(records[0].action_url,"!!document.querySelector("+JSON.stringify(config.recordHeading)+")");
        await delay(200); assert.equal(await visible(),false,'Working records never auto-open guides');
        await client.evaluate("(()=>{const e=[...document.querySelectorAll('textarea')].find(e=>e.offsetParent!==null&&!e.disabled);if(e){e.value='Unsaved guide check';e.dispatchEvent(new Event('input',{bubbles:true}));}})()");
        const unsaved=await fields(); await openGuide(); await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
        await wait(async()=>!await visible(),'Escape'); assert.deepEqual(await fields(),unsaved,'Unsaved working form stays unchanged');
        assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"),'Workspace guide');
        const other=config.key==='monitoring'?'releases':'monitoring'; assert.equal((await get('/provider/workspace-guides/'+other)).status,403);
        if(config.key==='monitoring') {
            const cycleId=await client.evaluate("document.querySelector('#app').dataset.cycleId");
            const cycle=await get('/provider/workspaces/monitoring/check-ins/'+cycleId+'/data'); assert.equal(cycle.status,200);
            await navigate('/provider/monitoring/plans',queueReady('#program-directory-title'));
            await delay(200); assert.equal(await visible(),false); await openGuide(); await closeGuide();
            await navigate('/provider/monitoring/'+cycle.body.program.id+'/plan',"!!document.querySelector('#monitoring-plan-title')");
            await delay(200); assert.equal(await visible(),false,'Plans stay manual');
            const planFields=await fields(); await openGuide(); await closeGuide(); assert.deepEqual(await fields(),planFields);
            if(await client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='New check-in')")) {
                await click('New check-in'); await wait(()=>client.evaluate("!!document.querySelector('#check-in-editor-title')"),'check-in scheduling form');
                assert.equal(await visible(),false,'No guide over check-in form');
                await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
                await wait(()=>client.evaluate("!document.querySelector('#check-in-editor-title')"),'close unsaved check-in');
            }
        } else {
            await navigate('/provider/workspaces/releases/schedule',"!!document.querySelector('#schedule-release-title') && !document.querySelector('.student-page').innerText.includes('Checking recipient eligibility')");
            await delay(200); assert.equal(await visible(),false,'Release schedule stays manual');
            await client.evaluate("(()=>{const e=document.querySelector('form input:not([type]),form input[type=text]');if(!e)throw new Error('Missing release title');e.value='Unsaved guide check';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
            const scheduleFields=await fields(); await openGuide(); await closeGuide(); assert.deepEqual(await fields(),scheduleFields);
            await navigate('/provider/workspaces/releases/delivery-reports',queueReady('#delivery-queue-title'));
            await delay(200); assert.equal(await visible(),false); await openGuide(); await closeGuide();
        }
    }
    await login('${prefix}.manager');
    for(const config of configs) {
        await navigate(config.queue,queueReady(config.heading)); await delay(200); assert.equal(await visible(),false,'Managers get guides on demand');
        assert.equal((await get('/provider/workspace-guides/'+config.key)).body.auto_offer,false); await openGuide(); await closeGuide();
    }
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true; console.log('PASS Monitoring and Benefit Releases guides: independent tracking, permissions, stable layout, unchanged forms, plan and schedule entrypoints, and delivery-report help. Only guide progress was written.');
`;
source=source.slice(0,start)+cases+source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode=1; }
