export function copyProgramLocation(value = {}) {
    return {
        locationName: value.locationName ?? '',
        locationAddress: value.locationAddress ?? '',
        locationAddressDetails: value.locationAddressDetails ? { ...value.locationAddressDetails } : null,
        latitude: value.latitude ?? null,
        longitude: value.longitude ?? null,
    };
}

export function copyProgramPublicContact(value = {}) {
    return {
        contactEmail: value.contactEmail ?? '',
        contactNumber: value.contactNumber ?? '',
        contactPerson: value.contactPerson ?? '',
        contactDepartment: value.contactDepartment ?? '',
        officialProgramUrl: value.officialProgramUrl ?? '',
    };
}
