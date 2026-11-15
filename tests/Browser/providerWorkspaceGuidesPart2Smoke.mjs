import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guides-${label}-part2`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guides-part2-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/reviews/progress', '/provider/workspace-guides/selection/progress'].includes(route)");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = `
    const visible = () => client.evaluate("!!document.querySelector('.workspace-guide-dialog')");
    const guideReady = () => wait(() => client.evaluate("!!document.querySelector('.workspace-guide-dialog h3')"), 'loaded guide');
    const guideClick = label => click(label, "document.querySelector('.workspace-guide-dialog')");
    const openGuide = async () => { if (!await visible()) await click('Workspace guide'); await guideReady(); await audit('.workspace-guide-dialog'); };
    const geometry = () => client.evaluate("(()=>{const r=document.querySelector('header h1').getBoundingClientRect();return {x:r.x,y:r.y,w:r.width}})()");
    const fields = () => client.evaluate("[...document.querySelectorAll('input,textarea,select')].map(e=>[e.name,e.id,e.value,e.disabled])");
    const track = async (key, status) => wait(async () => (await get('/provider/workspace-guides/'+key)).body.status===status, 'save '+key+' '+status);
    const configs = [
        { key:'reviews', account:'reviewer', title:'Application Review workspace', first:'Find and claim a review', last:'Record the screening result', queue:'/provider/workspaces/reviews/all', heading:'#review-directory-title', data:'/provider/workspaces/reviews/data?queue=all' },
        { key:'selection', account:'selection', title:'Selection Activities workspace', first:'Choose the next activity', last:'Evaluate and record the outcome', queue:'/provider/workspaces/selection/active', heading:'#selection-directory-title', data:'/provider/workspaces/selection/sessions/data?queue=active' }
    ];
    for (const config of configs) {
        await login('${prefix}.'+config.account);
        await wait(() => client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Workspace guide')"), 'guide action');
        const initial = await get('/provider/workspace-guides/'+config.key); assert.equal(initial.status,200);
        await openGuide(); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText"),config.title);
        await shot(config.key+'-guide-start');
        await guideClick('Skip guide'); await wait(async () => !await visible(), 'skip');
        if(initial.body.status!=='completed') await track(config.key,'dismissed');
        await navigate(config.queue, "!!document.querySelector("+JSON.stringify(config.heading)+") && !!document.querySelector('tbody') && !document.querySelector('tbody').innerText.includes('Loading')");
        await delay(500); assert.equal(await visible(),false,'No repeated popup');
        const before = await geometry(); await openGuide(); assert.deepEqual(await geometry(),before,'Background stays in place');
        await guideClick('Next'); await guideClick('Back'); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.first)+")"),true);
        await guideClick('Next'); await guideClick('Next'); await guideClick('Next');
        assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.last)+")"),true);
        await audit('.workspace-guide-dialog'); await shot(config.key+'-guide-handoff'); await guideClick('Got it'); await track(config.key,'completed');
        const rows = await get(config.data); assert.equal(rows.status,200);
        let recordUrl, recordHeading;
        if(config.key==='reviews') { assert.ok(rows.body.applications.length); recordUrl=rows.body.applications[0].detail_url; recordHeading='#application-review-title'; }
        else { assert.ok(rows.body.sessions.length); recordUrl='/provider/workspaces/selection/sessions/'+rows.body.sessions[0].id; recordHeading='#session-record-title'; }
        await navigate(recordUrl,"!!document.querySelector("+JSON.stringify(recordHeading)+")");
        await delay(400); assert.equal(await visible(),false,'Records never auto-open guides');
        const prior=await fields(); await openGuide(); await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
        await wait(async () => !await visible(),'Escape'); assert.deepEqual(await fields(),prior);
        assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"),'Workspace guide');
        await shot(config.key+'-record-guide-button');
        if(config.key==='selection') {
            await navigate('/provider/workspaces/selection/results',"!!document.querySelector('#selection-directory-title') && !!document.querySelector('tbody') && !document.querySelector('tbody').innerText.includes('Loading')");
            const results=await get('/provider/workspaces/selection/sessions/data?queue=results');
            assert.equal(results.status,200); assert.ok(results.body.applications.length);
            await navigate(results.body.applications[0].action.url,"!!document.querySelector('#selection-outcome-title')");
            const outcomeFields=await fields(); await openGuide(); await guideClick('Skip guide'); await wait(async()=>!await visible(),'close outcome help'); assert.deepEqual(await fields(),outcomeFields);
            await navigate(config.queue,"!!document.querySelector('#selection-directory-title')");
            await click('Schedule activity'); await wait(()=>client.evaluate("!!document.querySelector('#session-modal-title')"),'session form');
            await delay(600); assert.equal(await visible(),false,'No tutorial over session form');
            await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
            await wait(()=>client.evaluate("!document.querySelector('#session-modal-title')"),'close unsaved session');
        }
        const other=config.key==='reviews'?'selection':'reviews';
        assert.equal((await get('/provider/workspace-guides/'+other)).status,403);
        assert.equal((await get('/provider/workspace-guides/programs')).status,403);
    }
    await login('${prefix}.manager');
    for(const config of configs) {
        await navigate(config.queue,"!!document.querySelector("+JSON.stringify(config.heading)+")");
        await delay(500); assert.equal(await visible(),false,'Managers get guides on demand');
        assert.equal((await get('/provider/workspace-guides/'+config.key)).body.auto_offer,false);
        await openGuide(); await guideClick('Skip guide');
    }
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true; console.log('PASS Review and Selection guides, role boundaries, independent tracking, stable background, untouched forms, and no tutorial over session creation. Only guide progress was written.');
`;
source = source.slice(0, start) + cases + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
