export function hasLocationPin(latitude, longitude) {
    if ([latitude, longitude].some((value) => value === null || value === undefined || String(value).trim() === '')) return false;
    const lat = Number(latitude);
    const lng = Number(longitude);
    return Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}
