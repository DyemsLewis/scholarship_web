import { hasLocationPin } from './locationPins.js';

export function organizationProgramLocation(user = {}) {
    const locationAddress = String(user.provider_address ?? '').trim();
    const hasPin = locationAddress && hasLocationPin(user.provider_latitude, user.provider_longitude);
    return {
        locationName: String(user.provider_name ?? '').trim() || 'Organization office',
        locationAddress,
        latitude: hasPin ? String(user.provider_latitude) : '',
        longitude: hasPin ? String(user.provider_longitude) : '',
    };
}

export function organizationLocationWouldReplace(current, location) {
    return ['locationName', 'locationAddress'].some(key => String(current[key] ?? '').trim()
        && String(current[key]).trim() !== location[key])
        || (hasLocationPin(current.latitude, current.longitude)
            && (!hasLocationPin(location.latitude, location.longitude)
                || Number(current.latitude) !== Number(location.latitude)
                || Number(current.longitude) !== Number(location.longitude)));
}
