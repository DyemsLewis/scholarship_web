import { readFile } from 'node:fs/promises';

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
harness = harness.replaceAll('accountability-hosted-part6', 'applicant-action-confirmations-hosted')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'applicant-action-confirmations-hosted-20261108.json')
    .replace('const route = new URL(request.url).pathname;', String.raw`
            const route = new URL(request.url).pathname;
            if (request.method === 'POST' && /^\/dashboard\/scholarships\/\d+\/application-start$/.test(route)) {
                void client.send('Fetch.fulfillRequest', { requestId, responseCode: 204 }).catch(() => {});
                return;
            }
    `);
const start = harness.indexOf("    const response = await fetch(origin + '/login');");
const end = harness.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Hosted browser harness structure changed.');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const assets = [manifest['resources/js/app.js'].file, manifest['resources/css/app.css'].file];
harness = harness.slice(0, start) + 'const expectedAssets = ' + JSON.stringify(assets) + ';\n' + String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);
    const html = await response.text();
    for (const asset of expectedAssets) {
        assert.ok(html.includes(asset), 'Live login uses ' + asset);
        assert.equal((await fetch(origin + '/build/' + asset, { method: 'HEAD' })).status, 200);
    }
    report.latestBuild = true;

    const prompt = "document.querySelector('.confirmation-dialog[role=alertdialog]')";
    async function dismiss() {
        await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
        await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
        await wait(() => client.evaluate('!' + prompt), 'dismissed confirmation');
    }
    async function requirePrompt(title) {
        await wait(() => client.evaluate('!!' + prompt), title);
        assert.match(await client.evaluate(prompt + '.innerText'), title);
    }

    await login('learner.apply');
    const applicationData = await get('/dashboard/applications/data');
    assert.equal(applicationData.status, 200);
    const beforeCount = applicationData.body.applications.length;
    const names = new Set((applicationData.body.prepared_documents ?? []).map(item => item.document_name));
    const program = applicationData.body.scholarships.find(item => item.can_start_application
        && (item.application_mode === 'provider_review' || String(item.requirements ?? '').split(/\r?\n|,/).map(value => value.trim()).filter(Boolean).every(name => names.has(name))));
    assert.ok(program, 'Demo applicant has an eligible program and the required files');
    await navigate('/dashboard/applications?scholarship=' + program.id, "Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Continue to Eligibility' && !b.disabled)");
    await click('Continue to Eligibility');
    await wait(() => client.evaluate("Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Continue to Requirements' && !b.disabled)"), 'eligibility verified');
    await click('Continue to Requirements');
    await client.evaluate("for (const box of document.querySelectorAll('textarea[id^=application-answer-]')) { box.value='Browser verification only, not submitted.'; box.dispatchEvent(new Event('input',{bubbles:true})); }");
    await wait(() => client.evaluate("Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Continue to Confirm' && !b.disabled)"), 'requirements met');
    await click('Continue to Confirm');
    await wait(() => client.evaluate("Array.from(document.querySelectorAll('label')).some(l=>l.innerText.includes('I agree to the application terms.'))"), 'final application review');
    await client.evaluate("Array.from(document.querySelectorAll('label')).find(l=>l.innerText.includes('I agree to the application terms.')).querySelector('input').click()");
    await click('Submit application');
    await requirePrompt(/Submit this application/);
    assert.ok((await client.evaluate(prompt + '.innerText')).includes(program.title));
    await click('Keep reviewing', prompt);
    await wait(() => client.evaluate('!' + prompt), 'application submission canceled');
    assert.ok(await client.evaluate("Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Submit application' && !b.disabled)"));
    assert.equal((await get('/dashboard/applications/data')).body.applications.length, beforeCount);
    report.applicationPrompt = true;
    console.log('PASS Live application submit prompt and cancel.');

    await login('learner.exam.confirm');
    const records = await get('/dashboard/applications/data');
    assert.equal(records.status, 200);
    const application = records.body.applications.find(item => item.selection_activities?.some(activity => activity.requires_applicant_acknowledgment));
    assert.ok(application, 'Demo applicant has a schedule that needs acknowledgment');
    const activitiesUrl = '/dashboard/applications/' + application.id + '/activities';
    const hasScheduleButton = "Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Confirm schedule' && !b.disabled)";
    await navigate(activitiesUrl, hasScheduleButton);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await click('Confirm schedule');
    await requirePrompt(/Confirm you saw this schedule/);
    assert.match(await client.evaluate(prompt + '.innerText'), /does not mark you as having attended/);
    assert.ok(await client.evaluate(prompt + '.getBoundingClientRect().width <= innerWidth'));
    await dismiss();
    assert.ok(await client.evaluate(hasScheduleButton));
    report.schedulePrompt = true;
    console.log('PASS Live schedule confirmation, mobile layout, and Escape without saving.');

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
` + harness.slice(end);

try { await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
