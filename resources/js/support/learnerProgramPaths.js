export const juniorHighPathOptions = [
    'General curriculum',
    'STE',
    'SPA',
    'Sports program',
    'Special science class',
    'Other',
];

export const seniorHighPathOptions = [
    'STEM',
    'ABM',
    'HUMSS',
    'GAS',
    'TVL',
    'Arts and Design',
    'Sports Track',
    'Other',
];

export const collegePathOptions = [
    'Any course',
    'BS Information Technology',
    'BS Education',
    'BS Nursing',
    'BS Accountancy',
    'BS Business Administration',
    'Engineering',
    'Criminology',
    'Agriculture',
    'Other',
];

export const tvetPathOptions = [
    'Cookery NC II',
    'ICT / Computer Systems Servicing',
    'Automotive Servicing',
    'Electrical Installation and Maintenance',
    'Caregiving',
    'Shielded Metal Arc Welding',
    'Other',
];

export const alsPathOptions = [
    'Basic Literacy',
    'A&E Elementary',
    'A&E Junior High School',
    'Other',
];

const aliases = new Map([
    ['bsit', 'BS Information Technology'],
    ['bs it', 'BS Information Technology'],
    ['b s it', 'BS Information Technology'],
    ['bs information technology', 'BS Information Technology'],
    ['bachelor of science in information technology', 'BS Information Technology'],
    ['bachelor of science information technology', 'BS Information Technology'],
    ['information technology', 'BS Information Technology'],
    ['bsed', 'BS Education'],
    ['bs ed', 'BS Education'],
    ['bs education', 'BS Education'],
    ['bachelor of science in education', 'BS Education'],
    ['bachelor of secondary education', 'BS Education'],
    ['bsn', 'BS Nursing'],
    ['bs nursing', 'BS Nursing'],
    ['bachelor of science in nursing', 'BS Nursing'],
    ['bsa', 'BS Accountancy'],
    ['bs accountancy', 'BS Accountancy'],
    ['bachelor of science in accountancy', 'BS Accountancy'],
    ['bsba', 'BS Business Administration'],
    ['bs business administration', 'BS Business Administration'],
    ['bachelor of science in business administration', 'BS Business Administration'],
    ['ict', 'ICT / Computer Systems Servicing'],
    ['computer systems servicing', 'ICT / Computer Systems Servicing'],
    ['ict computer systems servicing', 'ICT / Computer Systems Servicing'],
    ['automotive', 'Automotive Servicing'],
    ['automotive servicing', 'Automotive Servicing'],
    ['electrical installation', 'Electrical Installation and Maintenance'],
    ['electrical installation and maintenance', 'Electrical Installation and Maintenance'],
]);

export function normalizeProgramPath(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

export function canonicalProgramPath(value) {
    const original = String(value ?? '').trim();

    return aliases.get(normalizeProgramPath(original)) ?? original;
}

export function splitProgramPaths(value) {
    if (!value) {
        return [];
    }

    return String(value)
        .split(/\r?\n|,/)
        .map(canonicalProgramPath)
        .filter(Boolean)
        .filter((path, index, paths) => paths.findIndex((item) => (
            normalizeProgramPath(item) === normalizeProgramPath(path)
        )) === index);
}

export function canonicalizeProgramPathList(value) {
    return splitProgramPaths(value).join('\n');
}

export function isOpenProgramPath(value) {
    const normalized = normalizeProgramPath(value);

    return normalized === 'any'
        || normalized === 'all'
        || normalized === 'n a'
        || normalized.startsWith('any ')
        || normalized.startsWith('all ')
        || normalized.includes('open to all')
        || normalized.includes('no restriction')
        || normalized.includes('not applicable');
}

export function providerProgramPathOptionsForTarget(targetKey) {
    const optionsByTarget = {
        junior_high: ['Any', ...juniorHighPathOptions],
        senior_high: ['Any strand', ...seniorHighPathOptions],
        college: collegePathOptions,
        tvet: ['Any', ...tvetPathOptions],
        als: ['Any', ...alsPathOptions],
    };
    const broadOptions = [
        'Any',
        ...juniorHighPathOptions,
        ...seniorHighPathOptions,
        ...collegePathOptions.filter((option) => option !== 'Any course'),
        ...tvetPathOptions,
        ...alsPathOptions,
    ];

    return [...new Set(optionsByTarget[targetKey] ?? broadOptions)];
}

export function programPathListMatches(value, filter) {
    const normalizedFilter = normalizeProgramPath(canonicalProgramPath(filter));

    if (!normalizedFilter) {
        return true;
    }

    const paths = splitProgramPaths(value);

    if (paths.length === 0 || paths.some(isOpenProgramPath)) {
        return true;
    }

    return paths.some((path) => {
        const normalizedPath = normalizeProgramPath(canonicalProgramPath(path));

        return normalizedPath === normalizedFilter
            || normalizedPath.includes(normalizedFilter)
            || normalizedFilter.includes(normalizedPath);
    });
}
