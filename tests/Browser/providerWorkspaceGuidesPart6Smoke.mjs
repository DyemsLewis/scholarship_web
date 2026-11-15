import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guides-${label}-part6`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('url: origin + url', 'url: new URL(url, origin).href')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guides-part6-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/support/progress', '/provider/workspace-guides/billing/progress', '/provider/workspace-guides/manager/progress'].includes(route)");
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
    const ready = heading => "!!document.querySelector("+JSON.stringify(heading)+") && !/Loading/.test(document.querySelector('.student-page').innerText)";
    const track = async (key,status) => wait(async () => (await get('/provider/workspace-guides/'+key)).body.status===status,'save '+key+' '+status);
    const checkDraft = async () => {
        const before=await fields(); await openGuide(); await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
        await wait(async()=>!await visible(),'Escape'); assert.deepEqual(await fields(),before,'Guide preserves unsaved values');
        assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"),'Workspace guide');
    };
    const configs = [
        {key:'support',account:'support',title:'Support workspace',queue:'/provider/workspaces/support/needs-response',heading:'#support-directory-title'},
        {key:'billing',account:'billing',title:'Platform Services workspace',queue:'/provider/workspaces/billing/requests',heading:'#billing-directory-title'},
        {key:'manager',account:'manager',title:'Provider dashboard guide',queue:'/provider',heading:'#provider-dashboard-title'}
    ];
    for(const config of configs) {
        await login('${prefix}.'+config.account);
        await wait(() => client.evaluate("!!document.querySelector('.workspace-dashboard-header h1') && !/Loading/.test(document.querySelector('.student-page').innerText)"),'dashboard loaded');
        const initial=await get('/provider/workspace-guides/'+config.key); assert.equal(initial.status,200);
        assert.equal(initial.body.guide.steps.length,4); await openGuide();
        assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText"),config.title);
        await shot(config.account+'-guide-start'); await closeGuide(); if(initial.body.status!=='completed')await track(config.key,'dismissed');
        await navigate(config.queue,ready(config.heading)); await delay(250); assert.equal(await visible(),false,'No repeated popup');
        const before=await geometry(); await openGuide(); assert.deepEqual(await geometry(),before,'Background stays stable');
        await guideClick('Next'); await guideClick('Back'); await guideClick('Next'); await guideClick('Next'); await guideClick('Next');
        await audit('.workspace-guide-dialog'); await shot(config.account+'-guide-handoff'); await guideClick('Got it'); await track(config.key,'completed');
        await openGuide(); await closeGuide();
        if(config.key==='support') {
            assert.equal((await get('/provider/workspace-guides/billing')).status,403);
            assert.equal((await get('/provider/workspace-guides/manager')).status,403);
            const data=await get('/provider/workspaces/support/data?queue=needs_action');assert.equal(data.status,200);
            const row=data.body.reports.find(row=>row.action_url);
            if(row){await navigate(row.action_url,ready('#support-case-title'));assert.equal(await visible(),false,'Case is manual');
                await client.evaluate("(()=>{const e=document.querySelector('textarea');if(e){e.value='Unsaved guide response';e.dispatchEvent(new Event('input',{bubbles:true}));}})()");await checkDraft();report.supportCaseChecked=true;}
            await navigate('/provider/workspaces/support/report-platform-issue',ready('#platform-report-title'));
            assert.equal(await visible(),false,'Report form is manual');
            await client.evaluate("(()=>{const e=document.querySelector('textarea');e.value='Unsaved platform report';e.dispatchEvent(new Event('input',{bubbles:true}));})()");await checkDraft();
        } else if(config.key==='billing') {
            assert.equal((await get('/provider/workspace-guides/support')).status,403);
            const catalog=await get('/provider/workspaces/billing/services/data');assert.equal(catalog.status,200);assert.ok(catalog.body.plans.length);
            await navigate('/provider/workspaces/billing/services',ready('#services-title'));assert.equal(await visible(),false);await checkDraft();
            const code=catalog.body.plans[0].code;
            await navigate('/provider/workspaces/billing/services/'+code,ready('#service-title'));assert.equal(await visible(),false);await checkDraft();
            await navigate('/provider/workspaces/billing/services/'+code+'/request',ready('#intake-title'));assert.equal(await visible(),false);
            await client.evaluate("(()=>{const e=[...document.querySelectorAll('form input')].find(e=>!e.disabled&&e.type!=='hidden');if(e){e.value='Unsaved service request';e.dispatchEvent(new Event('input',{bubbles:true}));}})()");await checkDraft();
            let reviewed=false,workspaceChecked=false;
            for(const queue of ['requests','needs_action','active','waiting','completed']) {
                const data=await get('/provider/workspaces/billing/data?queue='+queue);assert.equal(data.status,200);
                for(const row of data.body.entries){
                    const urls=[row.action_url,row.workspace_url,row.review_url].filter(Boolean);
                    for(const url of urls){
                        if(!reviewed&&url.includes('/review')){await navigate(url,ready('#review-title'));assert.equal(await visible(),false);await checkDraft();reviewed=true;}
                        if(!workspaceChecked&&/\\/provider\\/billing\\/[^/]+/.test(url)){
                            await navigate(url,ready('#service-record-title'));assert.equal(await visible(),false);await checkDraft();
                            const base=url.split('?')[0].replace(/\\/(meeting|files|updates|closeout|history)$/,'');
                            for(const section of ['meeting','files','updates','closeout','history']){await navigate(base+'/'+section,ready('#service-record-title'));assert.equal(await visible(),false);await checkDraft();}
                            workspaceChecked=true;
                        }
                    }
                }
            }
            report.billingReviewChecked=reviewed;report.billingWorkspaceChecked=workspaceChecked;
        } else {
            for(const key of ['programs','reviews','selection','decisions','recipients','monitoring','releases','organization-profile','team','support','billing']){
                const data=await get('/provider/workspace-guides/'+key);assert.equal(data.status,200);assert.equal(data.body.auto_offer,false);assert.equal(data.body.guide.steps.length,4);
                for(const step of data.body.guide.steps){assert.equal(step.items.length,3);assert.ok(step.title);}
            }
            for(const config of configs.filter(c=>c.key!=='manager')){await navigate(config.queue,ready(config.heading));await delay(250);assert.equal(await visible(),false,'Manager workspaces stay manual');await openGuide();await closeGuide();}
            await navigate('/provider',ready('#provider-dashboard-title'));assert.equal(await visible(),false,'Manager intro stays dismissed');
        }
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Support, Platform Services, and Manager guides: tracking, permissions, modal layout/focus, unsaved drafts, and all 12 guide contracts. Only guide progress written.');
`;
source=source.slice(0,start)+cases+source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
