const PSGC_API_BASE = 'https://psgc.cloud/api';

export const philippineRegionOptions = [
    { code: '0100000000', value: 'Region I', label: 'Region I (Ilocos Region)' },
    { code: '0200000000', value: 'Region II', label: 'Region II (Cagayan Valley)' },
    { code: '0300000000', value: 'Region III', label: 'Region III (Central Luzon)' },
    { code: '0400000000', value: 'Region IV-A', label: 'Region IV-A (CALABARZON)' },
    { code: '1700000000', value: 'MIMAROPA', label: 'MIMAROPA Region' },
    { code: '0500000000', value: 'Region V', label: 'Region V (Bicol Region)' },
    { code: '0600000000', value: 'Region VI', label: 'Region VI (Western Visayas)' },
    { code: '1800000000', value: 'NIR', label: 'Negros Island Region (NIR)' },
    { code: '0700000000', value: 'Region VII', label: 'Region VII (Central Visayas)' },
    { code: '0800000000', value: 'Region VIII', label: 'Region VIII (Eastern Visayas)' },
    { code: '0900000000', value: 'Region IX', label: 'Region IX (Zamboanga Peninsula)' },
    { code: '1000000000', value: 'Region X', label: 'Region X (Northern Mindanao)' },
    { code: '1100000000', value: 'Region XI', label: 'Region XI (Davao Region)' },
    { code: '1200000000', value: 'Region XII', label: 'Region XII (SOCCSKSARGEN)' },
    { code: '1300000000', value: 'NCR', label: 'National Capital Region (NCR)' },
    { code: '1400000000', value: 'CAR', label: 'Cordillera Administrative Region (CAR)' },
    { code: '1600000000', value: 'Region XIII', label: 'Region XIII (Caraga)' },
    { code: '1900000000', value: 'BARMM', label: 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)' },
];

const negrosIslandProvinces = [
    { code: '0604500000', value: 'Negros Occidental', label: 'Negros Occidental' },
    { code: '0704600000', value: 'Negros Oriental', label: 'Negros Oriental' },
    { code: '0706100000', value: 'Siquijor', label: 'Siquijor' },
];
const requestCache = new Map();

export function cleanPhilippineLocationName(value) {
    const name = String(value ?? '');

    if (!/[ÃÂ]/.test(name)) {
        return name;
    }

    try {
        const bytes = Uint8Array.from(Array.from(name), (character) => {
            const codePoint = character.codePointAt(0);

            if (codePoint > 255) {
                throw new RangeError('Location name is not Latin-1 mojibake.');
            }

            return codePoint;
        });

        return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch (error) {
        return name
            .replaceAll('Ã±', 'ñ')
            .replaceAll('Ã‘', 'Ñ')
            .replaceAll('Â', '');
    }
}

function normalizedName(value) {
    return cleanPhilippineLocationName(value)
        .toLowerCase()
        .replace(/\bcity of\b/g, '')
        .replace(/\bcity\b/g, '')
        .replace(/\bprovince of\b/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

async function fetchPsgc(path) {
    if (!requestCache.has(path)) {
        requestCache.set(path, fetch(`${PSGC_API_BASE}${path}`, {
            headers: { Accept: 'application/json' },
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Location list is unavailable.');
            }

            return response.json();
        }).then((payload) => (Array.isArray(payload) ? payload : payload?.data ?? []))
            .catch((error) => {
                requestCache.delete(path);
                throw error;
            }));
    }

    return requestCache.get(path);
}

function toOptions(items, excludedTypes = []) {
    return items
        .filter((item) => item?.name && !excludedTypes.includes(item.type))
        .map((item) => {
            const name = cleanPhilippineLocationName(item.name);

            return {
                code: String(item.code),
                value: name,
                label: name,
            };
        })
        .sort((left, right) => left.label.localeCompare(right.label));
}

export function findPhilippineRegion(value) {
    const normalized = normalizedName(value);

    if (!normalized) {
        return null;
    }

    const aliases = new Map([
        ['metro manila', 'NCR'],
        ['national capital region ncr', 'NCR'],
        ['calabarzon', 'Region IV-A'],
        ['cordillera administrative region car', 'CAR'],
        ['negros island region nir', 'NIR'],
        ['bangsamoro autonomous region in muslim mindanao barmm', 'BARMM'],
    ]);
    const alias = aliases.get(normalized);

    return philippineRegionOptions.find((option) => option.value === alias)
        ?? philippineRegionOptions.find((option) => [option.value, option.label, option.code]
            .some((candidate) => normalizedName(candidate) === normalized))
        ?? null;
}

export function findLocationOption(options, value) {
    const normalized = normalizedName(value);

    return options.find((option) => normalizedName(option.value) === normalized) ?? null;
}

export async function provincesForRegion(regionCode) {
    if (regionCode === '1300000000') {
        return [{ code: regionCode, value: 'Metro Manila', label: 'Metro Manila' }];
    }

    if (regionCode === '1800000000') {
        return negrosIslandProvinces;
    }

    const movedToNegrosIslandRegion = {
        '0600000000': ['Negros Occidental'],
        '0700000000': ['Negros Oriental', 'Siquijor'],
    };
    const excludedProvinces = movedToNegrosIslandRegion[regionCode] ?? [];

    return toOptions(await fetchPsgc(`/regions/${regionCode}/provinces`))
        .filter((option) => !excludedProvinces.includes(option.value));
}

export async function citiesForLocation(regionCode, provinceCode = '') {
    const path = regionCode === '1300000000'
        ? `/regions/${regionCode}/cities-municipalities`
        : `/provinces/${provinceCode}/cities-municipalities`;

    if (!regionCode || (regionCode !== '1300000000' && !provinceCode)) {
        return [];
    }

    return toOptions(await fetchPsgc(path), ['SubMun']);
}
