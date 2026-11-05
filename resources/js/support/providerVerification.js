const proofTypes = ['organization_registration', 'authorization_letter', 'valid_id', 'school_or_office_proof', 'other'];

export function providerProofUploadType(type) {
    const normalized = { authority_letter: 'authorization_letter', representative_id: 'valid_id' }[type] || type;
    return proofTypes.includes(normalized) ? normalized : 'other';
}
