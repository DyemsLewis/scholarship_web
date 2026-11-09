import { readFile } from 'node:fs/promises';

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
harness = harness.replaceAll('accountability-hosted-part6', 'applicant-document-removal-hosted')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'applicant-document-removal-hosted-20261108.json');
const start = harness.indexOf("    const response = await fetch(origin + '/login');");
const end = harness.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Hosted browser harness structure changed.');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
harness = harness.slice(0, start) + 'const expectedScript = ' + JSON.stringify(manifest['resources/js/app.js'].file) + ';\n' + String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);
    assert.ok((await response.text()).includes(expectedScript), 'Live login uses the new production build');
    assert.equal((await fetch(origin + '/build/' + expectedScript, { method: 'HEAD' })).status, 200);
    report.latestBuild = true;

    const library = '/dashboard/documents';
    const dataUrl = library + '/data';
    let before;
    for (const account of ['learner.screening', 'learner.apply', 'learner.interview.confirm', 'learner.profile']) {
        await login(account);
        const data = await get(dataUrl);
        assert.equal(data.status, 200);
        const options = data.body.document_options || [];
        if (data.body.prepared_documents.some(file => !options.includes(file.document_name) || options.slice(0, 7).includes(file.document_name))) {
            before = data.body.prepared_documents;
            break;
        }
    }
    assert.ok(before?.length, 'Demo account has a prepared file visible in the library');
    const remove = "document.querySelector('[data-library-document-row] button.student-button-danger')";
    const prompt = "document.querySelector('[data-document-removal-confirmation]')";
    await navigate(library, '!!' + remove);
    await client.evaluate(remove + ".closest('[data-library-document-row]').querySelector('button.student-button-secondary').click()");
    await wait(() => client.evaluate("!!document.querySelector('[role=dialog]')"), 'file preview opens directly');
    assert.equal(await client.evaluate('!!' + prompt), false);
    const escape = async () => {
        await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
        await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
    };
    await client.evaluate("document.querySelector('button[aria-label=\"Close file preview\"]').click()");
    await wait(() => client.evaluate("!document.querySelector('[role=dialog]')"), 'closed file preview');
    const removeLabel = await client.evaluate(remove + ".getAttribute('aria-label')");
    await client.evaluate(remove + '.focus();' + remove + '.click()');
    await wait(() => client.evaluate('!!' + prompt), 'document removal confirmation');
    assert.match(await client.evaluate(prompt + '.innerText'), /Remove file from My Documents.*stay attached/is);
    await wait(() => client.evaluate("document.activeElement?.innerText.trim() === 'Keep file'"), 'safe button is focused');
    await click('Keep file', prompt);
    await wait(() => client.evaluate('!' + prompt), 'cancelled removal');
    await wait(() => client.evaluate('document.activeElement?.getAttribute("aria-label") === ' + JSON.stringify(removeLabel)), 'focus restored to remove button');

    await client.evaluate(remove + '.focus();' + remove + '.click()');
    await wait(() => client.evaluate('!!' + prompt), 'reopened removal prompt');
    await escape();
    await wait(() => client.evaluate('!' + prompt), 'Escape cancels removal');
    const after = await get(dataUrl);
    assert.equal(after.status, 200);
    assert.deepEqual(after.body.prepared_documents, before);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Live file preview and removal confirmation. Keep file and Escape preserve all documents; focus returns to the remove button. No delete requests sent.');
` + harness.slice(end);

try { await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
