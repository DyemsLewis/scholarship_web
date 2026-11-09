import { readFile } from 'node:fs/promises';

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
harness = harness.replaceAll('accountability-hosted-part6', 'in-person-check-ins-hosted')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'in-person-check-ins-hosted-20261108.json')
    .replace('let control, client, contextId;', 'let control, client, contextId, fixture;')
    .replace('const route = new URL(request.url).pathname;', String.raw`
            const url = new URL(request.url);
            const route = url.pathname;
            if (request.method === 'GET' && fixture?.path === route && fixture.record === url.searchParams.get('record')) {
                const body = Buffer.from(JSON.stringify(fixture.data)).toString('base64');
                void client.send('Fetch.fulfillRequest', {
                    requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body,
                }).catch(() => {});
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

    await login('marilag.monitoring');
    const programs = await get('/provider/programs/data?workspace=monitoring');
    assert.equal(programs.status, 200);
    const program = programs.body.programs.find(item => item.monitoring?.can_publish_check_in
        && item.monitoring?.status === 'active');
    assert.ok(program?.monitoring.plan_url, 'Demo provider can schedule an active monitoring plan');
    const plan = new URL(program.monitoring.plan_url, origin);
    assert.equal(plan.origin, origin);
    const hasNewCheckIn = "Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='New check-in' && !b.disabled)";
    await navigate(plan.pathname + plan.search, hasNewCheckIn);
    await click('New check-in');
    const dialog = "document.querySelector('[aria-labelledby=check-in-editor-title]')";
    await wait(() => client.evaluate('!!' + dialog), 'appointment form');
    assert.match(await client.evaluate(dialog + '.innerText'), /Schedule an in-person check-in.*Venue and address/s);
    assert.equal(await client.evaluate(dialog + ".querySelector('input[type=datetime-local]').required"), true);
    assert.match(await client.evaluate(dialog + '.innerText'), /cannot upload files/);
    await click('Cancel', dialog);
    await wait(() => client.evaluate('!' + dialog), 'canceled appointment form');
    report.providerAppointmentForm = true;
    console.log('PASS Live provider appointment form and required date and time.');

    await login('learner.monitoring.upload');
    const applications = await get('/dashboard/applications/data');
    assert.equal(applications.status, 200);
    let existing;
    for (const application of applications.body.applications) {
        const base = '/dashboard/monitoring/' + application.id;
        const response = await get(base + '/data?view=check-ins');
        if (response.status !== 200) continue;
        const content = response.body.detail.content;
        const record = [...content.active, ...content.upcoming, ...content.completed].find(item => /^check-in-\d+$/.test(item.record_key));
        if (!record) continue;
        const data = await get(base + '/data?view=check-ins&record=' + record.record_key);
        if (data.status === 200) { existing = { base, record, data: data.body }; break; }
    }
    assert.ok(existing, 'Demo applicant has an existing check-in');
    const { base, record, data } = existing;
    const url = base + '/check-ins/' + record.record_key;
    const checkIn = "document.querySelector('[data-monitoring-check-in-record]')";
    assert.equal(data.detail.content.selected.handoff, null);
    await navigate(url, '!!' + checkIn);
    assert.ok((await client.evaluate(checkIn + '.innerText')).includes(record.title));
    report.legacyCheckIn = true;

    // Check the new screen without creating a real appointment or changing the old one.
    const selected = data.detail.content.selected;
    const location = 'Learning Center, Room 2';
    selected.handoff = { scheduled_label: 'Nov 20, 2026 9:30 AM (Philippine time)', location };
    selected.state = 'upcoming';
    selected.state_label = 'Upcoming';
    for (const requirement of selected.requirements) {
        requirement.can_submit = false;
        requirement.submission = null;
        requirement.status = 'bring_document';
        requirement.status_label = 'Bring to check-in';
    }
    fixture = { path: base + '/data', record: record.record_key, data };
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate(url, '!!' + checkIn + ' && ' + checkIn + '.innerText.includes(' + JSON.stringify(location) + ')');
    assert.match(await client.evaluate(checkIn + '.innerText'), /What to bring.*Bring the listed documents in person/s);
    assert.equal(await client.evaluate("!!document.querySelector('.student-page input[type=file]')"), false);
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    report.applicantAppointmentFixture = true;
    console.log('PASS Live existing check-in and mobile appointment preview with no upload control.');

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
` + harness.slice(end);

try { await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
