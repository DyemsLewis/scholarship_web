import { cleanPhilippineLocationName, findPhilippineRegion } from './philippineLocations.js';

export const emptyVenueAddress = () => ({ venue: '', region: '', province: '', city: '', barangay: '', street: '', latitude: null, longitude: null });

export function venueAddressText(details, includeVenue = true) {
    const keys = includeVenue ? ['venue', 'street', 'barangay', 'city', 'province', 'region'] : ['street', 'barangay', 'city', 'province', 'region'];
    return [...new Set(keys.map(key => typeof details?.[key] === 'string' ? details[key].trim().replace(/\s+/g, ' ') : '').filter(Boolean))].join(', ');
}

export function venueAddressFromMap(address = {}) {
    if (!address) return null;
    if (String(address.country_code ?? '').toLowerCase() !== 'ph') return null;
    const text = value => cleanPhilippineLocationName(value).trim();
    const region = [address.region, address.state_district, address.state].map(findPhilippineRegion).find(Boolean);
    if (!region) return null;
    const province = address.province || (!findPhilippineRegion(address.state) ? address.state : '') || address.county;
    return {
        region: region.value,
        province: region.value === 'NCR' ? 'Metro Manila' : text(province),
        city: text(address.city || address.municipality || address.town),
        barangay: text(address.quarter || address.village || address.suburb || address.neighbourhood),
        street: [address.house_number, address.road].map(text).filter(Boolean).join(' '),
    };
}

export async function lookupVenuePinAddress(location, signal) {
    if (location.address?.country_code) return location.address;
    if (location.addressLookupFailed) throw new Error('Map address lookup unavailable.');
    const query = new URLSearchParams({ format: 'jsonv2', addressdetails: '1', lat: String(location.latitude), lon: String(location.longitude), 'accept-language': 'en' });
    const response = await fetch(`https://nominatim.openstreetmap.org/reverse?${query}`, {
        headers: { Accept: 'application/json' }, referrerPolicy: 'strict-origin', signal,
    });
    if (!response.ok) throw new Error('Map address lookup unavailable.');
    return (await response.json()).address ?? {};
}
