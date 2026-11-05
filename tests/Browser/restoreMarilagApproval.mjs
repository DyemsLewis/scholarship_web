import { readFile } from 'node:fs/promises';

const mode = process.env.MARILAG_RESTORE_MODE || 'prepare';
if (!['prepare', 'approve'].includes(mode)) throw new Error('Unexpected restoration mode');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "marilag-approval-restore';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', `marilag-approval-${mode}.json`)
    .replace('const report = {', 'const allowedWrites = new Set();\nconst report = {')
    .replace("const blocked = !['GET', 'HEAD', 'OPTIONS'].includes(request.method) && !['/login', '/hcdn-cgi/jschallenge-validate'].includes(route);",
        "const blocked = !['GET', 'HEAD', 'OPTIONS'].includes(request.method) && !['/login', '/hcdn-cgi/jschallenge-validate'].includes(route) && !allowedWrites.has(request.method + ' ' + route);");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `    const mode=${JSON.stringify(mode)};\n` + String.raw`
    await login('platform.reviews');
    const queue = await get('/admin/workspaces/reviews/providers/data?status=all&search=Marilag');
    assert.equal(queue.status,200);
    const item = queue.body.items.find(row=>/marilag/i.test(row.name));assert.ok(item);
    const dataUrl='/admin/providers/'+item.id+'/review/data';
    let record=(await get(dataUrl)).body.provider;
    assert.equal(record.username,'marilag');assert.equal(record.verification_documents.length,3);
    report.before=record;
    if (mode==='prepare') {
        await navigate('/admin/workspaces/reviews/providers/'+item.id,"!!document.querySelector('[data-review-provider-document]')");
        await shot('organization-before');
        for (const doc of record.verification_documents) {
            assert.equal(doc.file_available,true);
            const file=await client.evaluate("(async()=>{const r=await fetch("+JSON.stringify(doc.view_url)+");if(!r.ok)throw new Error('Proof unavailable');const b=new Uint8Array(await r.arrayBuffer());let s='';for(const v of b)s+=String.fromCharCode(v);return btoa(s)})()");
            const filename=path.join(folder,'proof-'+doc.id+(doc.mime_type==='application/pdf'?'.pdf':'.image'));
            await writeFile(filename,Buffer.from(file,'base64'));
            (report.proofFiles??=[]).push({id:doc.id,file:filename,type:doc.document_type,name:doc.original_name});
        }
    } else {
        const prepared=JSON.parse(await (await import('node:fs/promises')).readFile('storage/deployment/marilag-approval-prepare.json','utf8'));
        for(const doc of record.verification_documents) {
            const inspected=prepared.before.verification_documents.find(row=>row.id===doc.id);
            assert.equal(doc.revision,inspected?.revision,'Only the inspected document revision may be accepted');
        }
        const note='Fictional demonstration proof inspected to restore the prepared Marilag demo workflow. Not independently authenticated with an issuing authority.';
        for (const doc of record.verification_documents) {
            allowedWrites.add('PATCH '+new URL(doc.review_url,origin).pathname);
            const result=await client.evaluate("(async()=>{const r=await window.axios.patch("+JSON.stringify(doc.review_url)+","+JSON.stringify({status:'approved',expected_revision:doc.revision,compared:true,review_notes:note})+",{portalToast:false});return {status:r.status}})()");
            assert.equal(result.status,200);allowedWrites.clear();
        }
        record=(await get(dataUrl)).body.provider;assert.equal(record.document_review.can_approve,true);
        const verificationUrl='/admin/providers/'+item.id+'/verification';
        allowedWrites.add('PATCH '+verificationUrl);
        const result=await client.evaluate("(async()=>{const r=await window.axios.patch("+JSON.stringify(verificationUrl)+","+JSON.stringify({verification_status:'approved',verification_notes:'Marilag fictional demo organization restored to its prepared approved state at the tester request. Individual proof reviews completed; Dalisay remains the pending provider-verification demonstration.',expected_review_token:record.document_review.token})+",{portalToast:false});return {status:r.status,provider:r.data.provider}})()");
        allowedWrites.clear();assert.equal(result.status,200);assert.equal(result.provider.verification_status,'approved');report.after=result.provider;
        await navigate('/admin/workspaces/reviews/providers/'+item.id,"document.body.innerText.includes('3 of 3 accepted')");
        await shot('organization-approved');
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('MARILAG_'+mode.toUpperCase()+'=passed');
` + source.slice(end);
try { await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
