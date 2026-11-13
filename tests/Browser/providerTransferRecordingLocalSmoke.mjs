import { readFile } from 'node:fs/promises';

const releaseId = Number(process.env.PAYOUT_TRANSFER_RELEASE_ID);
if (!Number.isSafeInteger(releaseId) || releaseId < 1) throw new Error('Set the approved sample release ID in the isolated preview database.');
const output = process.env.PAYOUT_TRANSFER_OUTPUT || 'storage/qa';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8034';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('accountability-hosted-part6', 'payout-transfer-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `${output}/payout-transfer-local.json`)
    .replace("const blocked = !['GET'", "const transferWrite = request.method === 'POST' && /^\\/(?:provider\\/benefit-release-records\\/[0-9]+\\/result|dashboard\\/benefit-release-records\\/[0-9]+\\/response)$/.test(route);\n            const blocked = !transferWrite && !['GET'");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    // Use only fictional proof and the isolated preview database.
    const url = '/provider/workspaces/releases/distributions/' + ${releaseId};
    const evidence = 'document.querySelector("[data-transfer-evidence]")';
    const nextStep = 'document.querySelector("[data-benefit-next-step]")';
    const outcome = 'document.querySelector("[data-outcome-confirmation]")';
    const staffConfirmation = 'document.querySelector(".confirmation-dialog[role=alertdialog]")';
    async function waitForText(scope, text) {
        await wait(() => client.evaluate(scope + '?.innerText.includes(' + JSON.stringify(text) + ')'), text);
    }
    await login('marilag.releases');
    await navigate(url, '!!document.querySelector("[data-distribution-result]")');
    assert.equal(await client.evaluate('document.querySelector("option[value=released]").textContent'), 'Sent');
    assert.equal(await client.evaluate('document.querySelector("option[value=released]").disabled'), false);
    await client.evaluate('(()=>{const select=document.querySelector("[data-distribution-result]");select.value="released";select.dispatchEvent(new Event("change",{bubbles:true}));})()');
    await waitForText(evidence, 'Transfer proof');
    assert.equal(await client.evaluate(evidence + '.closest("form").checkValidity()'), false);
    const sentOn = await client.evaluate('new Intl.DateTimeFormat("sv-SE",{timeZone:"Asia/Manila"}).format()');
    await client.evaluate('(async()=>{const date=' + evidence + '.querySelector("input[type=date]");date.value=' + JSON.stringify(sentOn) + ';date.dispatchEvent(new Event("input",{bubbles:true}));'
        + 'const canvas=document.createElement("canvas");canvas.width=480;canvas.height=100;const ctx=canvas.getContext("2d");ctx.fillStyle="#ffffff";ctx.fillRect(0,0,480,100);ctx.fillStyle="#0f172a";ctx.font="bold 22px sans-serif";ctx.fillText("Sample transfer proof - not a receipt",20,55);'
        + 'const blob=await new Promise(resolve=>canvas.toBlob(resolve,"image/png"));const files=new DataTransfer();files.items.add(new File([blob],"sample-transfer-proof.png",{type:"image/png"}));'
        + 'const input=' + evidence + '.querySelector("input[type=file]");input.files=files.files;input.dispatchEvent(new Event("change",{bubbles:true}));})()');
    assert.equal(await client.evaluate(evidence + '.closest("form").checkValidity()'), true);
    await client.evaluate(evidence + '.closest("form").scrollIntoView({block:"center"})');
    await shot('provider-payout-part-3-record-transfer');
    await click('Record transfer as sent', evidence + '.closest("form")');
    await waitForText(staffConfirmation, 'Record this transfer as sent?');
    await click('Record as sent', staffConfirmation);
    await wait(() => client.evaluate('document.body.innerText.includes("Transfer sent.")'), 'provider saved transfer');
    const transfer = (await get(url + '/data')).body.release.records[0];
    assert.equal(transfer.status, 'released');
    assert.equal(transfer.status_label, 'Sent - awaiting receipt');
    assert.equal(transfer.receipt_response, null);

    await login('learner.onetime.plan');
    const { body: { support } } = await get('/dashboard/monitoring/data');
    const applicantData = (await get(support.focus.href + '/data?view=benefits')).body.detail.content;
    const record = applicantData.directory.action_required[0];
    assert.equal(record.delivery.date_caption, 'Sent on');
    await navigate(record.href, '!!document.querySelector("[data-benefit-next-step]")');
    await waitForText(nextStep, 'Report a problem');
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('applicant-payout-part-3-awaiting-receipt');
    await click('Report a problem', nextStep);
    await waitForText('document.querySelector("[data-delivery-dialog]")', 'Affected release'.toUpperCase());
    await click('Cancel', 'document.querySelector("[data-delivery-dialog]")');
    await click('Confirm receipt', nextStep);
    const form = 'document.querySelector("form[aria-labelledby=benefit-response-title]")';
    await wait(() => client.evaluate('!!' + form), 'receipt confirmation form');
    assert.equal(await client.evaluate(form + '.querySelector("input[type=date]").min'), sentOn);
    await client.evaluate(form + '.querySelector("input[type=checkbox]").click()');
    await click('Continue', form);
    await waitForText(outcome, 'Received As Listed');
    await click('Confirm receipt', outcome);
    await wait(() => client.evaluate('document.querySelector("[data-benefit-release-record]").innerText.includes("Receipt has been confirmed")'), 'receipt recorded');

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Required proof, recorded transfer, applicant report entry, and separate receipt confirmation.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
