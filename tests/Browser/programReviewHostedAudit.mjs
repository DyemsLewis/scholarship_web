import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-review-hosted-20261114';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json')
    .replace('response.status >= 500', 'response.status >= 400')
    .replace('report.serverErrors.push({ path:', "new URL(response.url).pathname === '/login' && response.status === 403 ? (report.edgeChallenges ??= []).push('/login') : report.serverErrors.push({ path:");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    report.checks=[];
    const check = async(url) => {
        const started=Date.now();const r=await get(url);
        report.checks.push({url,status:r.status,ms:Date.now()-started});
        console.log(JSON.stringify(report.checks.at(-1)));
        assert.equal(r.status,200,url);
        return r.body;
    };
    await login('lumenops.reviews');
    const pending=await check('/admin/workspaces/reviews/programs/data?status=pending_review');
    assert.ok(pending.items.some(p=>p.id===10));
    await navigate('/admin/workspaces/reviews/programs',"document.querySelector('table')?.innerText.includes('Orivanta College Pathways Award')");
    await shot('review-programs-queue');
    const href=await client.evaluate("document.querySelector('tbody a[title=\"Open record\"]').getAttribute('href')");
    report.rowHref=href;
    await navigate(new URL(href,origin).pathname+new URL(href,origin).search,"document.body.innerText.includes('Orivanta College Pathways Award') && !!document.querySelector('#publication-decision')");
    await shot('review-program-record');
    for(const status of ['pending_review','published','rejected','all']){
        const result=await check('/admin/workspaces/reviews/programs/data?status='+status);
        report.checks.at(-1).records=result.pagination.total;
        if(status==='all'){
            const programs=[...result.items];
            for(let page=2;page<=result.pagination.last_page;page++){
                const next=await check('/admin/workspaces/reviews/programs/data?status=all&page='+page);
                programs.push(...next.items);
            }
            for(const program of programs){
                const data=await check('/admin/scholarships/'+program.id+'/review/data');
                assert.ok(data.scholarship);
                await navigate('/admin/workspaces/reviews/programs/'+program.id,"document.body.innerText.includes("+JSON.stringify(program.title)+") && !!document.querySelector('.admin-panel h2')");
                report.checks.push({record:program.title,rendered:true});
            }
        }
    }
    report.reviewLinks=await client.evaluate("[...document.querySelectorAll('a[href]')].map(a=>({text:a.innerText.trim(),href:a.getAttribute('href')})).filter(a=>a.href.includes('review'))");
    await login('lumenops.manager');
    await navigate('/admin/workspaces/portal/reviews?type=programs',"!!document.querySelector('h1') && document.readyState==='complete'");
    await shot('manager-program-oversight');
    report.manager=await client.evaluate("({text:document.body.innerText.slice(0,6000),links:[...document.querySelectorAll('main a[href]')].map(a=>({text:a.innerText.trim(),href:a.getAttribute('href')}))})");
    await login('orivanta.programs');
    await check('/provider/programs/data?status=pending_review');
    await navigate('/provider/programs/review',"!!document.querySelector('h1') && document.body.innerText.includes('Orivanta College Pathways Award')");
    await shot('provider-programs-in-review');
    report.provider=await client.evaluate("({text:document.body.innerText.slice(-5000),links:[...document.querySelectorAll('main a[href]')].map(a=>({text:a.innerText.trim(),href:a.getAttribute('href')}))})");
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Hosted program review audit. No business decisions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
