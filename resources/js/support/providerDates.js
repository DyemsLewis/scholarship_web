const options = { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' };

export function providerDate(value = new Date()) {
    return new Intl.DateTimeFormat('sv-SE', options).format(new Date(value));
}

export function providerDateTime(value = new Date()) {
    if (!value) return '';
    return new Intl.DateTimeFormat('sv-SE', { ...options, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(providerDateTimeIso(value))).replace(' ', 'T');
}

export function providerTimestamp(value) {
    const text = String(value);
    const zoned = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(text) ? text + '+08:00' : value;
    return new Date(zoned).getTime();
}

export const providerDateTimeIso = value => new Date(providerTimestamp(value)).toISOString();

export function nextProviderMinute(now = Date.now()) {
    return providerDateTime(new Date((Math.floor(now / 60_000) + 1) * 60_000));
}

export function providerDateOffset(value, days) {
    if (!value) return '';
    const date = new Date(String(value).slice(0, 10) + 'T00:00:00+08:00');
    return providerDate(new Date(date.getTime() + days * 86_400_000));
}

export function providerYearOffset(value, years) {
    const date = new Date(value + 'T00:00:00+08:00');
    date.setUTCFullYear(date.getUTCFullYear() + years);
    return providerDate(date);
}

export const latestDate = (...dates) => dates.filter(Boolean).sort().at(-1) || '';
export const earliestDate = (...dates) => dates.filter(Boolean).sort()[0] || '';
