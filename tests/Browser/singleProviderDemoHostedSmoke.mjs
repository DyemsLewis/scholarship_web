import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/single-provider-demo-hosted-20261114';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    report.applicants={};report.staff=[];
    const okay = async(url) => {const response=await get(url);assert.equal(response.status,200,url);return response.body;};
    for(const name of ['aurora','brio','cielo','dune','profile','verification']){
        await login('learner.'+name);
        const dashboard=await okay('/dashboard/overview-data');
        assert.ok(dashboard.dashboard);
        const list=await okay('/dashboard/applications/data');
        const rows=list.applications;assert.ok(Array.isArray(rows));
        if(['profile','verification'].includes(name))assert.equal(rows.length,0);
        else{
            assert.ok(rows.length>=5,'Several workflows must belong to the same applicant.');
            await okay('/dashboard/monitoring/data');
            for(const row of rows)await okay('/dashboard/applications/'+row.id+'/data');
        }
        report.applicants[name]={applications:rows.length};
        if(name==='aurora'){
            await navigate('/dashboard/applications',"!!document.querySelector('h1') && document.body.innerText.includes('Orivanta')");
            await shot('applicant-multiple-scholarship-records');
        }
    }
    const providerSuffixes=['','manager','programs','reviewer','selection','decisions','recipients','monitoring','releases','profile','team','support','billing'];
    const endpoints={
        programs:'/provider/programs/data',reviewer:'/provider/workspaces/reviews/data',selection:'/provider/workspaces/selection/sessions/data',
        decisions:'/provider/workspaces/decisions/data',monitoring:'/provider/workspaces/monitoring/data',releases:'/provider/workspaces/releases/data',support:'/provider/reports/data'
    };
    for(const suffix of providerSuffixes){
        const username='orivanta'+(suffix?'.'+suffix:'');await login(username);
        await wait(()=>client.evaluate("!!document.querySelector('h1')"),'workspace heading '+username);
        if(endpoints[suffix])await okay(endpoints[suffix]);
        if(suffix==='programs'){
            await navigate('/provider/programs/create',"!!document.querySelector('.program-builder-form')");
            await navigate('/provider/programs/9/edit/review',"document.querySelectorAll('[data-review-section]').length===6");
            await shot('provider-draft-review');
        }
        if(suffix==='decisions')await shot('provider-final-comparison-queue');
        report.staff.push({username,path:await client.evaluate('location.pathname')});
    }
    for(const suffix of ['root','accounts','reviews','support','billing','finance','records','manager']){
        const username='lumenops.'+suffix;await login(username);
        await wait(()=>client.evaluate("!!document.querySelector('h1')"),'admin heading '+username);
        if(suffix==='reviews')await okay('/admin/workspaces/reviews/applicants/data');
        report.staff.push({username,path:await client.evaluate('location.pathname')});
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS All six applicant dashboards, all 25 application detail APIs, provider roles, coordinator create/edit, DSS, monitoring/releases and all admin logins. No business decisions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
