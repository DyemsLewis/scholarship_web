import { readFile } from 'node:fs/promises';

let source=await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source=source.replace("accountability-hosted-part6';","provider-review-notes-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json','provider-review-notes-hosted.json');
const start=source.indexOf("    const response = await fetch(origin + '/login');");
const end=source.indexOf('} catch (error) { report.failure',start);
source=source.slice(0,start)+String.raw`
    await login('marilag.profile');
    const result=await get('/provider/workspaces/organization-profile/data');assert.equal(result.status,200);
    assert.equal(result.body.profile.verification_status,'approved');
    assert.equal(result.body.verification_documents.length,3);
    for(const document of result.body.verification_documents) {
        assert.equal(document.review_notes,'Reviewed and accepted.');assert.equal(document.status,'approved');
    }
    await navigate('/provider/workspaces/organization-profile/verification',"document.body.innerText.includes('Reviewed and accepted.')");
    assert.equal(await client.evaluate("document.querySelector('tbody').innerText.includes('Fictional demonstration proof')"),false);
    await client.evaluate("document.querySelector('tbody').scrollIntoView({block:'center'})");
    await shot('concise-provider-review-notes');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Neutral provider review notes are visible; approval unchanged; no business writes.');
`+source.slice(end);
try {await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
