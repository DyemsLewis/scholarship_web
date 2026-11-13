import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-eligibility-summary-20261114';
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const summary = () => client.evaluate("document.querySelector('#program-eligibility-summary').value");
    const needsReview = () => client.evaluate("!!document.querySelector('[data-eligibility-summary-review]')");
    const field = (selector, value, event = 'input') => client.evaluate("(()=>{const e=document.querySelector("+JSON.stringify(selector)+");e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event("+JSON.stringify(event)+",{bubbles:true}));})()");
    await login('nuvareya.programs');
    await navigate('/provider/programs/8/edit/eligibility', "!!document.querySelector('#program-eligibility-summary')");
    const existing = (await get('/provider/scholarships/8')).body.scholarship;
    assert.equal(await summary(), existing.eligibility, 'Existing custom text is not overwritten.');
    assert.equal(await needsReview(), true);
    await click('I checked my summary');
    assert.equal(await needsReview(), false);
    await field('#program-eligible-courses', 'ABM', 'change');
    await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-courses] [data-add-option]').click()");
    await field('[data-eligibility-mode=course]', 'preferred', 'change');
    assert.equal(await needsReview(), true);
    await click('Use rule-based summary');
    assert.equal(await client.evaluate("document.querySelector('#program-eligibility-summary').readOnly"), true);
    assert.equal(await needsReview(), false);
    assert.match(await summary(), /Preferred, not required to apply:[\s\S]*STEM/);
    await field('#program-academic-rule', 'percentage', 'change');
    await field('.program-builder-form input[type=number]', '88');
    assert.match(await summary(), /Minimum average: 88.00%/);
    await field('[data-eligibility-mode=academic]', 'preferred', 'change');
    assert.match(await summary(), /Preferred, not required to apply:[\s\S]*Minimum average: 88.00%/);
    await click('Write custom summary');
    await field('#program-eligibility-summary', 'Custom conditions for this program.');
    assert.equal(await needsReview(), true);
    await click('Save and continue');
    await wait(() => client.evaluate("!!document.querySelector('[role=alertdialog]')"), 'custom summary review guard');
    assert.match(await client.evaluate("document.querySelector('[role=alertdialog]').innerText"), /custom eligibility summary/);
    await client.evaluate("[...document.querySelector('[role=alertdialog]').querySelectorAll('button')].at(-1).click()");
    await click('I checked my summary');
    assert.equal(await needsReview(), false);
    await field('.program-builder-form input[type=number]', '89');
    assert.equal(await needsReview(), true);
    assert.equal(await summary(), 'Custom conditions for this program.');
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.blockedWrites, []);
    assert.deepEqual(report.serverErrors, []);
    report.passed = true;
    console.log('PASS Existing summary preservation, live required/preferred summaries, review invalidation and step protection. No program records changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
