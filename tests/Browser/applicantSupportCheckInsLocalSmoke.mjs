import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8028';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'support-check-ins-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/support-check-ins-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

source = source.slice(0, start) + String.raw`
    await login('learner.release.confirm');
    const landing = (await get('/dashboard/monitoring/data')).body.support;
    const recordUrl = landing.focus.href;
    const { status, body } = await get(recordUrl + '/data?view=check-ins');
    assert.equal(status, 200);
    const directory = body.detail.content.directory;
    const appointment = directory.next_appointment;
    assert.equal(appointment.state_label, 'Rescheduled');
    assert.equal(appointment.preparation.document_count, 2);
    const venue = appointment.handoff.location;
    await navigate(recordUrl + '/check-ins', '!!document.querySelector("[data-next-check-in-appointment]")');
    const featured = await client.evaluate('document.querySelector("[data-next-check-in-appointment]").innerText');
    assert.ok(featured.includes(venue) && featured.includes(appointment.handoff.scheduled_label));
    assert.equal(await client.evaluate('Array.from(document.querySelectorAll("[data-monitoring-check-ins] a")).filter(a=>a.getAttribute("href")===' + JSON.stringify(appointment.href) + ').length'), 1);

    await navigate(appointment.href, '!!document.querySelector("[data-check-in-preparation]")');
    assert.match(await client.evaluate('document.querySelector("[data-check-in-appointment]").innerText'), /New date and time/);
    const checklist = await client.evaluate('document.querySelector("[data-check-in-preparation]").innerText');
    for (const document of appointment.preparation.preview_titles) assert.ok(checklist.includes(document));
    assert.doesNotMatch(checklist, /orientation participation/i);
    assert.match(await client.evaluate('document.querySelector("[data-monitoring-check-in-record]").innerText'), /Recorded by the provider/);
    assert.match(await client.evaluate('document.querySelector("[data-check-in-next-step]").innerText'), /Next step: You/i);
    assert.equal(await client.evaluate('!!document.querySelector("input[type=file]")'), false);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'workspace images');
    await shot('applicant-support-part-3-check-in');

    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    assert.ok(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'));
    assert.ok(await client.evaluate('document.querySelector("[data-check-in-appointment]").getBoundingClientRect().bottom <= document.querySelector("[data-check-in-next-step]").getBoundingClientRect().top'));

    const missed = directory.active.find(record => record.state_label === 'Missed appointment');
    assert.ok(missed);
    await navigate(missed.href, '!!document.querySelector("[data-check-in-next-step]")');
    assert.match(await client.evaluate('document.querySelector("[data-check-in-next-step]").innerText'), /Contact the provider/i);
    assert.equal(await client.evaluate('!!document.querySelector("[data-check-in-preparation]")'), false);
    assert.doesNotMatch(await client.evaluate('document.querySelector("[data-monitoring-check-in-record]").innerText'), /Arrival instructions/);

    const legacy = directory.active.find(record => !record.handoff && record.pending_items > 0);
    assert.ok(legacy, 'A legacy online record in the isolated demo is accepting files.');
    await navigate(legacy.href, '!!document.querySelector("[data-monitoring-check-in-record]") && !!document.querySelector("input[type=file]")');
    assert.ok(await client.evaluate('!!document.querySelector("input[type=file]")'));
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Current appointment, preparation list, missed guidance, online upload controls, and mobile layout.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
