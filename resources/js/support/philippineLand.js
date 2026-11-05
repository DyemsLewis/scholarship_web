import { hasLocationPin } from './locationPins.js';

export const PHILIPPINE_LAND_MESSAGE = 'Choose a location on land within the Philippines, not on water or in another country.';
let boundaryPromise;

function inPolygons(polygons, x, y) {
    return polygons.some(({ bounds: [west, south, east, north], rings }) => {
        if (x < west || x > east || y < south || y > north) return false;
        let inside = false;
        for (const encoded of rings) {
            const ring = decodeRing(encoded);
            for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                const [xi, yi] = ring[i];
                const [xj, yj] = ring[j];
                if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) inside = !inside;
            }
        }
        return inside;
    });
}

function decodeRing(encoded) {
    const result = [], point = [0, 0];
    let i = 0;
    while (i < encoded.length) {
        for (let axis = 0; axis < 2; axis++) {
            let value = 0, shift = 0, byte;
            do {
                byte = encoded.charCodeAt(i++) - 63;
                value |= (byte & 31) << shift;
                shift += 5;
            } while (byte >= 32);
            point[axis] += value & 1 ? ~(value >> 1) : value >> 1;
        }
        result.push([point[0] / 1e6, point[1] / 1e6]);
    }
    return result;
}

export function isPhilippineLandPoint(data, latitude, longitude) {
    if (!hasLocationPin(latitude, longitude)) return false;
    const x = Number(longitude), y = Number(latitude);
    if (y < 4 || y > 22 || x < 116 || x > 128) return false;
    return inPolygons(data.land, x, y) && !inPolygons(data.ponds, x, y)
        && (!inPolygons(data.water, x, y) || inPolygons(data.lakeIslands, x, y));
}

export function loadPhilippineLand() {
    if (!boundaryPromise) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        boundaryPromise = fetch('/maps/philippines-land-v1.json', { signal: controller.signal })
            .then(async (response) => {
                if (!response.ok) throw new Error('Boundary data unavailable.');
                const data = await response.json();
                if (data.version !== 1 || data.encoding !== 'polyline6-xy' || !['land', 'water', 'lakeIslands', 'ponds'].every((key) => Array.isArray(data[key]))) {
                    throw new Error('Invalid boundary data.');
                }
                return data;
            }).catch((error) => { boundaryPromise = undefined; throw error; })
            .finally(() => clearTimeout(timer));
    }
    return boundaryPromise;
}
