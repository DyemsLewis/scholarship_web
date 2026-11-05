import { readFile } from 'node:fs/promises';

let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("accountability-hosted-part6';","program-edit-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json','program-edit-hosted.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');");
const end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    report.edits=[];
    for(const name of ['marilag.manager','marilag.programs','silayan.manager','silayan.programs']) {
        await login(name);
        const list=await get('/provider/programs/data');assert.equal(list.status,200);
        let program;
        for(const row of list.body.programs) {
            const record=await get(row.record_url+'/data');assert.equal(record.status,200);
            if(record.body.program.actions.edit_url) {program=record.body.program;break;}
        }
        assert.ok(program?.actions.edit_url,name+' editable assigned program');
        await navigate(program.actions.edit_url,"!!document.querySelector('#program-builder-title') && [...document.querySelectorAll('input')].some(e=>e.value==="+JSON.stringify(program.title)+")");
        assert.equal(await client.evaluate("document.querySelector('#app').dataset.page"),'providerProgramForm');
        assert.equal(await client.evaluate("!!document.querySelector('[data-program-access-notice]')"),false);
        report.edits.push({account:name,title:program.title});
        await shot(name.replaceAll('.','-')+'-edit');console.log('PASS_EDIT '+name);
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Hosted edit forms load saved program data for manager and coordinator accounts in both organizations. No changes saved.');
`+source.slice(end);
try {await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
