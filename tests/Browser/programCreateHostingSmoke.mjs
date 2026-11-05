import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "program-create-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'program-create-hosted-browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    report.programAccess=[];
    for (const name of (process.env.PROGRAM_ACCESS_ACCOUNTS || 'marilag,marilag.manager,marilag.programs,silayan,silayan.manager,silayan.programs,dalisay').split(',')) {
        await login(name);
        await navigate('/provider/programs',"document.body.innerText.includes('Create program')");
        const directory = await client.evaluate("({createLink:!!document.querySelector('a[href=\"/provider/programs/create\"]'),permissions:window.portalUser?.permissions,status:window.portalUser?.verification_status,canPost:window.portalUser?.can_post_scholarships})");
        await client.send('Page.navigate',{url:origin+'/provider/programs/create'});
        await wait(()=>client.evaluate("document.readyState==='complete' && !!window.axios"),'create page navigation');
        await delay(1800);
        const create = await client.evaluate("({path:location.pathname,page:document.querySelector('#app')?.dataset.page,text:document.body.innerText.slice(-5000),hasBuilder:!!document.querySelector('#program-builder-title'),hasAccessNotice:!!document.querySelector('[data-program-access-notice]'),hasVerificationLink:!!document.querySelector('[data-program-access-notice] a[href=\"/provider/workspaces/organization-profile/verification\"]')})");
        report.programAccess.push({name,directory,create});
        await shot(name.replaceAll('.','-')+'-create-program');
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    if (process.env.EXPECT_PROGRAM_ACCESS==='1') for (const row of report.programAccess) {
        assert.equal(row.create.hasBuilder,row.directory.canPost,row.name+' should see the correct program access state');
        assert.equal(row.create.hasAccessNotice,!row.directory.canPost,row.name+' should not be silently redirected');
        if (row.create.hasAccessNotice) assert.equal(row.create.hasVerificationLink,row.directory.permissions.includes('manage_profile'));
    }
    report.passed=true;console.log(JSON.stringify(report.programAccess.map(row=>({name:row.name,path:row.create.path,page:row.create.page,builder:row.create.hasBuilder}))));
` + source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
