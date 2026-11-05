import { readFile } from 'node:fs/promises';

const guide = await readFile('docs/fresh-workflow-demo.md', 'utf8');
const accounts = [...new Set([...guide.matchAll(/^\| `(marilag(?:\.[a-z]+)?|silayan(?:\.[a-z]+)?|dalisay)` \|/gm)].map(match => match[1]))];
if (accounts.length !== 29) throw new Error('Review the expected provider demo account list.');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "provider-role-access-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'provider-role-access-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + '    const accounts='+JSON.stringify(accounts)+';\n' + String.raw`
    const roles={
        programs:{queue:'/provider/programs',apis:['/provider/programs/dashboard/data','/provider/programs/data']},
        reviewer:{queue:'/provider/workspaces/reviews/assigned',apis:['/provider/workspaces/reviews/dashboard/data','/provider/workspaces/reviews/data']},
        selection:{queue:'/provider/workspaces/selection/active',apis:['/provider/workspaces/selection/dashboard/data','/provider/workspaces/selection/data']},
        decisions:{queue:'/provider/workspaces/decisions/pending',apis:['/provider/workspaces/decisions/dashboard/data','/provider/workspaces/decisions/data']},
        recipients:{queue:'/provider/workspaces/recipients/active',apis:['/provider/workspaces/recipients/dashboard/data','/provider/workspaces/recipients/data']},
        monitoring:{queue:'/provider/workspaces/monitoring/review',apis:['/provider/workspaces/monitoring/dashboard/data','/provider/workspaces/monitoring/data']},
        releases:{queue:'/provider/workspaces/releases/upcoming',apis:['/provider/workspaces/releases/dashboard/data','/provider/workspaces/releases/data']},
        profile:{queue:'/provider/workspaces/organization-profile/verification',apis:['/provider/workspaces/organization-profile/dashboard/data','/provider/workspaces/organization-profile/data']},
        team:{queue:'/provider/workspaces/team/active',apis:['/provider/workspaces/team/dashboard/data','/provider/workspaces/team/data']},
        support:{queue:'/provider/workspaces/support/needs-response',apis:['/provider/workspaces/support/dashboard/data','/provider/workspaces/support/data']},
        billing:{queue:'/provider/workspaces/billing/requests',apis:['/provider/workspaces/billing/dashboard/data','/provider/workspaces/billing/data']},
    };
    report.results=[];
    for(const name of accounts) {
        await login(name);
        if(name.endsWith('.newstaff')) {
            assert.equal(await client.evaluate("location.pathname"),'/account/setup');
            report.results.push({name,state:'expected_first_login_setup'});console.log('PASS_SETUP '+name);continue;
        }
        if(name==='dalisay') {
            const profile=await get('/provider/workspaces/organization-profile/data');assert.equal(profile.status,200);
            const restricted=await get('/provider/workspaces/reviews/data');assert.equal(restricted.status,403);
            report.results.push({name,state:'expected_pending_organization_verification'});console.log('PASS_PENDING '+name);continue;
        }
        const role=name.split('.')[1];const definition=roles[role] || {queue:'/provider',apis:['/provider/dashboard/data']};
        const permissions=await client.evaluate('window.portalUser.permissions');
        for(const api of definition.apis) {
            const response=await get(api);assert.equal(response.status,200,name+' '+api);
            assert.equal(typeof response.body,'object',name+' JSON data');
        }
        await navigate(definition.queue,"document.readyState==='complete' && !!window.axios && !!document.querySelector('#app')?.dataset.page");
        await delay(250);
        assert.equal(await client.evaluate('location.pathname'),definition.queue);
        assert.equal(await client.evaluate("document.body.innerText.includes('Provider approval is required')"),false);
        if(!permissions.includes('verify_applications')) assert.equal((await get('/provider/workspaces/reviews/data')).status,403,name+' reviewer permission boundary');
        if(!permissions.includes('manage_programs')) assert.equal((await get('/provider/programs/data')).status,403,name+' programs permission boundary');
        if(name==='marilag.reviewer'||name==='silayan.reviewer') await shot(name.replaceAll('.','-')+'-review-queue');
        report.results.push({name,state:'workspace_and_queues_ok',queue:definition.queue,apiChecks:definition.apis.length});
        console.log('PASS_WORKSPACE '+name);
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS_PROVIDER_ROLE_AUDIT='+report.results.length+'; no business writes');
` + source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
