import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/applicantProfileGroupingHostedSmoke.mjs', 'utf8');
source = source.replace('profile-grouping-hosted-20261114', 'profile-color-hosted-20261114')
    .replace("    await shot('hosted-profile-desktop');", String.raw`
    await wait(()=>client.evaluate("document.querySelector('[data-profile-cover] img')?.naturalWidth>0"),'decorative header image');
    report.theme=await client.evaluate("(()=>{const header=document.querySelector('[data-profile-identity]');return {header:getComputedStyle(header).backgroundColor,title:getComputedStyle(document.querySelector('#applicant-record-name')).color,heading:getComputedStyle(document.querySelector('[data-profile-section=personal] > header')).backgroundColor,height:header.getBoundingClientRect().height,overflow:header.scrollWidth>header.clientWidth+2}})()");
    assert.equal(report.theme.header,'rgb(8, 20, 38)');
    assert.equal(report.theme.title,'rgb(255, 255, 255)');
    assert.match(report.theme.heading,/^(rgb\(248, 250, 252\)|oklch\(0\.984 0\.003 247\.858\))$/);
    assert.equal(report.theme.overflow,false);assert.ok(report.theme.height<240);
    await shot('hosted-profile-desktop');
    `)
    .replace("    await shot('hosted-household-mobile');", String.raw`
    await shot('hosted-household-mobile');
    await client.evaluate('window.scrollTo(0,0)');
    assert.equal(await client.evaluate("getComputedStyle(document.querySelector('[data-profile-cover]')).display"),'none');
    await shot('hosted-profile-mobile');
    `);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
