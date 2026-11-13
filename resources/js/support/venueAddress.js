export const emptyVenueAddress = () => ({ venue: '', region: '', province: '', city: '', barangay: '', street: '', latitude: null, longitude: null });

export function venueAddressText(details, includeVenue = true) {
    const keys = includeVenue ? ['venue', 'street', 'barangay', 'city', 'province', 'region'] : ['street', 'barangay', 'city', 'province', 'region'];
    return [...new Set(keys.map(key => typeof details?.[key] === 'string' ? details[key].trim().replace(/\s+/g, ' ') : '').filter(Boolean))].join(', ');
}
