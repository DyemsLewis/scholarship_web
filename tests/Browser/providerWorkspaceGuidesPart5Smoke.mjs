import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guides-${label}-part5`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guides-part5-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/organization-profile/progress', '/provider/workspace-guides/team/progress'].includes(route)");
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
    const ready = heading => "!!document.querySelector("+JSON.stringify(heading)+") && !/(Loading|Checking profile readiness)/.test(document.querySelector('.student-page').innerText)";
    const checkDraft = async () => {
        const before=await fields(); await openGuide(); await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
        await wait(async()=>!await visible(),'Escape'); assert.deepEqual(await fields(),before,'Guide must not alter unsaved form values');
        assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"),'Workspace guide');
    };
    const configs = [
        { key:'organization-profile', account:'profile', title:'Organization Profile workspace', first:'Start with profile readiness', last:'Follow the review and hand off', queue:'/provider/workspaces/organization-profile/readiness', heading:'#profile-readiness-title' },
        { key:'team', account:'team', title:'Team Access workspace', first:'Review the current team', last:'Finish setup and maintain access', queue:'/provider/workspaces/team/active', heading:'#team-directory-title' }
    ];
    for(const config of configs) {
        await login('${prefix}.'+config.account);
        await wait(() => client.evaluate("!!document.querySelector('.workspace-dashboard-header .workspace-dashboard-action')"),'loaded dashboard');
        assert.equal(await client.evaluate("(()=>{const h=document.querySelector('.workspace-dashboard-header'),r=h.getBoundingClientRect();return [...h.querySelectorAll('button,a')].every(e=>{const b=e.getBoundingClientRect();return b.left>=r.left&&b.right<=r.right+2;})})()"),true,'Dashboard actions remain inside header');
        const initial=await get('/provider/workspace-guides/'+config.key); assert.equal(initial.status,200);
        await openGuide(); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText"),config.title);
        await shot(config.account+'-guide-start'); await closeGuide(); if(initial.body.status!=='completed') await track(config.key,'dismissed');
        await navigate(config.queue,ready(config.heading)); await delay(200); assert.equal(await visible(),false,'No repeated popup');
        const before=await geometry(); await openGuide(); assert.deepEqual(await geometry(),before,'Background remains stable');
        await guideClick('Next'); await guideClick('Back'); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.first)+")"),true);
        await guideClick('Next'); await guideClick('Next'); await guideClick('Next');
        assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes("+JSON.stringify(config.last)+")"),true);
        await audit('.workspace-guide-dialog'); await shot(config.account+'-guide-handoff'); await guideClick('Got it'); await track(config.key,'completed');
        const other=config.key==='team'?'organization-profile':'team'; assert.equal((await get('/provider/workspace-guides/'+other)).status,403);
        if(config.key==='organization-profile') {
            for(const [route,heading] of [['public','#public-profile-title'],['contact','#organization-contact-title'],['verification','#organization-verification-title']]) {
                await navigate('/provider/workspaces/organization-profile/'+route,ready(heading)); await delay(150);
                assert.equal(await visible(),false,'Profile edit pages stay manual');
                await client.evaluate("(()=>{const e=[...document.querySelectorAll('form input:not([type]),form input[type=text]')].find(e=>e.offsetParent!==null&&!e.disabled);if(!e)throw new Error('Missing editable profile field');e.value='Unsaved guide check';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
                await checkDraft();
                if(route==='public') {
                    const saved=await get('/provider/previews/organization/data'); assert.equal(saved.status,200);
                    const draft=await fields(); await click('Preview as applicant');
                    await wait(()=>client.evaluate("!!document.querySelector('[aria-label=\\\"Close applicant preview\\\"]')"),'applicant preview');
                    assert.equal(await visible(),false,'Guide cannot appear over preview');
                    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
                    await wait(()=>client.evaluate("!document.querySelector('[aria-label=\\\"Close applicant preview\\\"]')"),'close preview');
                    assert.deepEqual(await fields(),draft,'Preview must preserve unsaved text');
                }
            }
        } else {
            const directory=await get('/provider/workspaces/team/data?queue=active'); assert.equal(directory.status,200);
            const account=directory.body.accounts.find(row=>row.can_manage&&!row.is_current_account&&row.action_url); assert.ok(account,'Manageable existing staff account required');
            await navigate(account.action_url,ready('#team-account-title')); assert.equal(await visible(),false,'Account edit stays manual');
            await client.evaluate("(()=>{const values={'First name':'Guide','Middle initial':'X','Last name':'Check','Email':'guide-check@example.test','Username':'guide.check','Contact number':'09175550000'};for(const label of document.querySelectorAll('form label')){const input=label.querySelector('input:not([type=password])');const key=Object.keys(values).find(key=>label.textContent.trim().startsWith(key));if(input&&key&&!input.value.trim()){input.value=values[key];input.dispatchEvent(new Event('input',{bubbles:true}));}}})()");
            await checkDraft(); await click('Continue');
            await wait(()=>client.evaluate("document.querySelector('#team-account-title').closest('header').textContent.includes('Step 2 of 3')"),'workspace role step');
            await checkDraft(); await click('Continue');
            await wait(()=>client.evaluate("document.querySelector('#team-account-title').closest('header').textContent.includes('Step 3 of 3')"),'program scope step');
            await checkDraft();
            await navigate('/provider/team/accounts/create',ready('#team-account-title')); assert.equal(await visible(),false,'Account create stays manual');
            await client.evaluate("(()=>{const e=document.querySelector('form input:not([type]),form input[type=text]');if(!e)throw new Error('Missing staff name input');e.value='Unsaved guide check';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
            await checkDraft();
        }
    }
    await login('${prefix}.manager');
    for(const config of configs) {
        await navigate(config.queue,ready(config.heading)); await delay(200); assert.equal(await visible(),false,'Managers get guides on demand');
        assert.equal((await get('/provider/workspace-guides/'+config.key)).body.auto_offer,false); await openGuide(); await closeGuide();
    }
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true; console.log('PASS Organization Profile and Team Access guides: tracking, permissions, stable layout, unsaved profile/account forms, saved preview, and role/program-scope steps. Only guide progress was written.');
`;
source=source.slice(0,start)+cases+source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode=1; }
