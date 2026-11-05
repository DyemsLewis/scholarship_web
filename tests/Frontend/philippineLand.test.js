import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { isPhilippineLandPoint, loadPhilippineLand } from '../../resources/js/support/philippineLand.js';

const data = JSON.parse(readFileSync(new URL('../../public/maps/philippines-land-v1.json', import.meta.url)));
const fixtures = JSON.parse(readFileSync(new URL('../Fixtures/philippine-location-pins.json', import.meta.url)));
for (const pin of fixtures) {
    test(`map land check: ${pin.name}`, () => assert.equal(isPhilippineLandPoint(data, pin.latitude, pin.longitude), pin.allowed));
}

test('map land checks reject incomplete and invalid values', () => {
    for (const pair of [[null, 121], ['', 121], [14, ''], [Infinity, 121], [NaN, 121], ['wrong', 121]]) {
        assert.equal(isPhilippineLandPoint(data, ...pair), false);
    }
});

test('unavailable boundary checks fail closed and can be retried', async () => {
    const previous = globalThis.fetch;
    let requests = 0;
    globalThis.fetch = async () => {
        requests++;
        return requests === 1 ? { ok: false } : { ok: true, json: async () => data };
    };
    try {
        await assert.rejects(loadPhilippineLand(), /unavailable/);
        assert.equal(await loadPhilippineLand(), data);
        assert.equal(await loadPhilippineLand(), data);
        assert.equal(requests, 2);
    } finally { globalThis.fetch = previous; }
});

test('map preview compiles with shallow Leaflet references and land validation', () => {
    const filename = 'LeafletMapPreview.vue';
    const source = readFileSync(new URL(`../../resources/js/components/${filename}`, import.meta.url), 'utf8');
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: filename });
    assert.deepEqual(compileTemplate({ id: filename, filename, source: descriptor.template.content,
        compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /mapInstance = shallowRef\(null\)/);
    assert.match(source, /mapElement.value\?\.isConnected/);
    assert.match(source, /revision !== selectionRevision/);
    assert.match(source, /reverseController\?\.abort\(\)/);
});

test('local map assets avoid protected vendor URLs and include marker images', () => {
    const loader = readFileSync(new URL('../../resources/js/support/leafletLoader.js', import.meta.url), 'utf8');
    assert.doesNotMatch(loader, /\/vendor\//);
    for (const asset of ['leaflet.js', 'leaflet.css', 'images/marker-icon.png', 'images/marker-icon-2x.png', 'images/marker-shadow.png']) {
        assert.ok(readFileSync(new URL(`../../public/maps/leaflet/${asset}`, import.meta.url)).length > 0);
    }
    assert.match(loader, /\/maps\/leaflet\/leaflet.js/);
    const rules = readFileSync(new URL('../../.htaccess', import.meta.url), 'utf8');
    assert.match(rules, /vendor\)\(\?:\/\|\$\).*\[F,L,NC\]/);
});

test('map requests identify only the origin while general page privacy stays strict', () => {
    const map = readFileSync(new URL('../../resources/js/components/LeafletMapPreview.vue', import.meta.url), 'utf8');
    assert.match(map, /tileLayer\('https:\/\/tile\.openstreetmap\.org\/\{z\}\/\{x\}\/\{y\}\.png'/);
    assert.doesNotMatch(map, /https:\/\/\{s\}\.tile\.openstreetmap\.org/);
    assert.match(map, /tileLayer\([^]*?referrerPolicy: 'strict-origin'/);
    assert.match(map, /nominatim.openstreetmap.org\/search[^]*?referrerPolicy: 'strict-origin'/);
    assert.match(map, /nominatim.openstreetmap.org\/reverse[^]*?referrerPolicy: 'strict-origin'/);
    assert.match(map, /https:\/\/www.openstreetmap.org\/copyright/);
    assert.doesNotMatch(map, /cache:\s*['"](?:no-store|reload|no-cache)['"]|['"]Cache-Control['"]/);
    const headers = readFileSync(new URL('../../app/Http/Middleware/ApplySecurityHeaders.php', import.meta.url), 'utf8');
    assert.match(headers, /'Referrer-Policy', 'no-referrer'/);
});
