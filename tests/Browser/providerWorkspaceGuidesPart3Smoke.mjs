import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guides-${label}-part3`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guides-part3-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/decisions/progress', '/provider/workspace-guides/recipients/progress'].includes(route)");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = `
    const visible = () => client.evaluate("!!document.querySelector('.workspace-guide-dialog')");
    const guideClick = label => click(label, "document.querySelector('.workspace-guide-dialog')");
    const openGuide = async () => {
        if (!await visible()) await click('Workspace guide');
        await wait(() => client.evaluate("!!document.querySelector('.workspace-guide-dialog h3')"), 'loaded guide');
        await audit('.workspace-guide-dialog');
    };
    const fields = () => client.evaluate("[...document.querySelectorAll('input,textarea,select')].map(e=>[e.name,e.id,e.value,e.checked,e.disabled])");
    const geometry = () => client.evaluate("(()=>{const r=document.querySelector('header h1').getBoundingClientRect();return {x:r.x,y:r.y,w:r.width}})()");
    const track = async (key,status) => wait(async () => (await get('/provider/workspace-guides/'+key)).body.status===status,'save '+key+' '+status);
    const configs = [
        { key:'decisions', title:'Final Decisions workspace', first:'Open a decision-ready record', last:'Hand off and follow up', queue:'/provider/workspaces/decisions/pending', heading:'#decision-directory-title', data:'/provider/workspaces/decisions/data?queue=pending', recordHeading:'#final-decision-title' },
        { key:'recipients', title:'Recipients workspace', first:'Check the agreement response', last:'Record a supported outcome', queue:'/provider/workspaces/recipients/active', heading:'#recipient-directory-title', data:'/provider/workspaces/recipients/data?queue=active', recordHeading:'#recipient-record-title' }
    ];
    for (const config of configs) {
        await login('${prefix}.'+config.key);
        await wait(() => client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Workspace guide')"),'guide action');
        const initial=await get('/provider/workspace-guides/'+config.key); assert.equal(initial.status,200);
        await openGuide(); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText"),config.title);
        await shot(config.key+'-guide-start');
        await guideClick('Skip guide'); await wait(async()=>!await visible(),'skip');
        if(initial.body.status!=='completed') await track(config.key,'dismissed');
        await navigate(config.queue,"!!document.querySelector("+JSON.stringify(config.heading)+") && !!document.querySelector('tbody') && !document.querySelector('tbody').innerText.includes('Loading')");
        await delay(400); assert.equal(await visible(),false,'No repeated popup');
        const before=await geometry(); await openGuide(); assert.deepEqual(await geometry(),before,'Background stays in place');
        await guideClick('Next'); await guideClick('Back'); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.first)+")"),true);
        await guideClick('Next'); await guideClick('Next'); await guideClick('Next');
        assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.last)+")"),true);
        await audit('.workspace-guide-dialog'); await shot(config.key+'-guide-handoff'); await guideClick('Got it'); await track(config.key,'completed');
        const rows=await get(config.data); assert.equal(rows.status,200);
        const records=config.key==='decisions'?rows.body.applications:rows.body.recipients; assert.ok(records.length,'Existing demo record required');
        const recordUrl=config.key==='decisions'?records[0].detail_url:'/provider/applications/'+records[0].id;
        await navigate(recordUrl,"!!document.querySelector("+JSON.stringify(config.recordHeading)+")");
        await delay(300); assert.equal(await visible(),false,'Working records never auto-open guides');
        if(config.key==='decisions') {
            await client.evaluate("(()=>{const e=document.querySelector('input[name=final-outcome][value=waitlisted]');if(e){e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}));}})()");
        } else await click('Support status');
        await delay(150);
        await client.evaluate("(()=>{const e=[...document.querySelectorAll('textarea')].find(e=>e.offsetParent!==null&&!e.disabled);if(e){e.value='Unsaved guide check';e.dispatchEvent(new Event('input',{bubbles:true}));}})()");
        const unsaved=await fields(); await openGuide(); await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
        await wait(async()=>!await visible(),'Escape'); assert.deepEqual(await fields(),unsaved,'Unsaved decision must not change');
        assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"),'Workspace guide');
        const other=config.key==='decisions'?'recipients':'decisions';
        assert.equal((await get('/provider/workspace-guides/'+other)).status,403);
        if(config.key==='recipients') {
            for(const [route,heading] of [['concerns','#concern-queue-title'],['withdrawals','#withdrawal-queue-title']]) {
                await navigate('/provider/workspaces/recipients/'+route,"!!document.querySelector("+JSON.stringify(heading)+") && !!document.querySelector('.staff-table-viewport[aria-busy=false]')");
                await delay(250); assert.equal(await visible(),false); await openGuide(); await guideClick('Skip guide');
                assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),true);
            }
        }
    }
    await login('${prefix}.manager');
    for(const config of configs) {
        await navigate(config.queue,"!!document.querySelector("+JSON.stringify(config.heading)+")");
        await delay(400); assert.equal(await visible(),false,'Managers get guides on demand');
        assert.equal((await get('/provider/workspace-guides/'+config.key)).body.auto_offer,false);
        await openGuide(); await guideClick('Skip guide');
    }
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true; console.log('PASS Final Decisions and Recipients guides: independent tracking, permission boundaries, stable layout, unchanged unsaved forms, and concern/withdrawal entrypoints. Only guide progress was written.');
`;
source=source.slice(0,start)+cases+source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode=1; }
