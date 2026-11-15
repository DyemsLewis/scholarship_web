import { readFile } from 'node:fs/promises';

const username = process.env.HOSTED_AUDIT_USERNAME;
if (!username) throw new Error('Set HOSTED_AUDIT_USERNAME and ACCOUNTABILITY_DEMO_PASSWORD privately.');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/account-program-access-20261114';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json')
    .replace('response.status >= 500', 'response.status >= 400');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `
    await login(${JSON.stringify(username)});
` + String.raw`
    report.landing=await client.evaluate("({path:location.pathname,title:document.querySelector('h1')?.innerText,links:[...document.querySelectorAll('aside a[href]')].map(e=>({text:e.innerText.trim(),href:e.getAttribute('href')}))})");
    const dashboard=await get('/provider/dashboard/data');
    assert.equal(dashboard.status,200);
    report.primaryAction=dashboard.body.manager_overview.primary_action;
    assert.ok(report.primaryAction?.href);
    await wait(()=>client.evaluate("[...document.querySelectorAll('a')].some(e=>e.innerText.trim()==="+JSON.stringify(report.primaryAction.label)+" && e.getAttribute('href')==="+JSON.stringify(report.primaryAction.href)+")"),'permitted dashboard action');
    report.directPages=[];
    for(const url of ['/provider/programs?page=1','/provider/programs','/provider/programs?page=2','/provider/programs?page=1&sort=updated']){
        await navigate(url,"document.readyState==='complete' && (!!document.querySelector('h1') || /403|Forbidden|505|504/.test(document.body.innerText))");
        await delay(650);
        const page=await client.evaluate("({path:location.pathname+location.search,title:document.title,text:document.body.innerText.slice(-2400),actor:window.portalUser?{id:window.portalUser.id,role:window.portalUser.role,permissions:window.portalUser.permissions}:null})");
        report.directPages.push(page);
        console.log(JSON.stringify({page:page.path,title:page.title,actor:page.actor?.id,forbidden:/403|Forbidden/.test(page.text)}));
        if(url==='/provider/programs?page=1')await shot('exact-program-list-page-1');
    }
    await navigate('/provider',"!!window.axios && !!document.querySelector('h1')");
    report.checks=[];
    let programs=[];
    for(const url of ['/provider/programs/data?filter=review','/provider/programs/data?filter=all']){
        const started=Date.now(), r=await get(url);
        const row={path:url,status:r.status,ms:Date.now()-started};
        if(r.status===200){row.context=r.body.context;row.programs=r.body.programs.map(p=>({id:p.id,title:p.title,status:p.status,record_url:p.record_url}));if(url.endsWith('all'))programs=r.body.programs;}
        else row.message=typeof r.body==='string'?r.body.slice(0,1600):r.body;
        report.checks.push(row);console.log(JSON.stringify(row));
    }
    await navigate('/provider/programs/review',"document.readyState==='complete' && (!!document.querySelector('h1') || document.body.innerText.includes('Forbidden'))");
    await delay(1200);
    report.review=await client.evaluate("({path:location.pathname,text:document.body.innerText.slice(-9000),links:[...document.querySelectorAll('main a[href]')].map(e=>({text:e.innerText.trim(),title:e.title,href:e.getAttribute('href')}))})");
    await shot('account-programs-review');
    for(const url of ['/provider/workspaces/reviews','/provider/workspaces/reviews/data']){
        const started=Date.now(),r=await get(url);
        report.checks.push({path:url,status:r.status,ms:Date.now()-started,message:r.status>=400?(typeof r.body==='string'?r.body.slice(0,1600):r.body):null});
        console.log(JSON.stringify(report.checks.at(-1)));
    }
    for(const program of programs){
        for(const url of ['/provider/programs/'+program.id+'/data','/provider/scholarships/'+program.id,'/provider/previews/programs/'+program.id+'/data']){
            const started=Date.now(),r=await get(url);
            report.checks.push({path:url,status:r.status,ms:Date.now()-started,message:r.status>=400?(typeof r.body==='string'?r.body.slice(0,1600):r.body):null});
            console.log(JSON.stringify(report.checks.at(-1)));
        }
        const href=new URL(program.record_url,origin);
        await navigate(href.pathname+href.search,"!!document.querySelector('h1') && document.readyState==='complete'");
        await delay(800);
        report.record=await client.evaluate("({path:location.pathname,text:document.body.innerText.slice(-7000),links:[...document.querySelectorAll('main a[href]')].map(e=>({text:e.innerText.trim(),href:e.getAttribute('href')}))})");
        await shot('account-program-record');
        await navigate('/provider/programs/'+program.id+'/edit',"!!document.querySelector('h1') && document.readyState==='complete'");
        await wait(()=>client.evaluate("!!document.querySelector('input[name=title]') || !!document.querySelector('[data-program-form]') || document.body.innerText.includes('Program name') || document.body.innerText.includes('Program title')"),'program editor');
        await delay(800);
        report.editor=await client.evaluate("({path:location.pathname,text:document.body.innerText.slice(-6500)})");
        await shot('account-program-editor');
    }
    report.realErrors=report.serverErrors.filter(e=>!(e.path==='/login'&&e.status===403));
    assert.deepEqual(report.realErrors,[]);
    assert.deepEqual(report.errors,[]);
    assert.deepEqual(report.blockedWrites,[]);
    report.completed=true;
    console.log('COMPLETE Read-only program access audit; no business actions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
