export function applicantProfileOverview({
    readiness = null,
    verificationStatus = 'unsubmitted',
    requirements = [],
    documents = [],
    photoReviewStatus = '',
    scanNeedsAttention = false,
} = {}) {
    const required = requirements.filter((item) => item.required);
    const records = new Map(documents.map((item) => [item.document_type, item]));
    const missingRecord = required.find((item) => !records.has(item.value));
    const returnedRecord = required.some((item) => records.get(item.value)?.status === 'rejected');
    const complete = readiness?.complete === true;
    const verified = readiness?.verified === true && verificationStatus === 'approved';
    const percent = Number(readiness?.percent ?? 0);
    const base = {
        complete,
        canApply: complete && verified && !missingRecord && !returnedRecord,
        percent: Number.isFinite(percent) ? Math.max(0, Math.min(100, percent)) : 0,
        uploaded: required.filter((item) => records.has(item.value)).length,
        required: required.length,
        action: null,
    };

    if (!complete) {
        return {
            ...base, key: 'details_needed', label: 'Details needed', tone: 'attention', icon: 'fa-solid fa-user-pen',
            title: 'Complete your applicant details',
            description: 'Finish the required information in your saved record before applying.',
            action: { label: 'Continue setup', field: readiness?.missing?.[0]?.key ?? null, section: 'personal' },
        };
    }
    if (photoReviewStatus === 'needs_replacement') {
        return {
            ...base, key: 'photo_requested', label: 'Photo update requested', tone: 'attention', icon: 'fa-solid fa-camera',
            title: 'A reviewer requested a clearer photo',
            description: 'Open your personal details to read the note and replace your applicant photo.',
            action: { label: 'Update photo', section: 'personal' },
        };
    }
    if (verificationStatus === 'rejected' || returnedRecord) {
        return {
            ...base, canApply: false, key: 'changes_requested', label: 'Changes requested', tone: 'attention', icon: 'fa-solid fa-file-pen',
            title: 'Update the records requested by your reviewer',
            description: 'Read the review note and replace the requested evidence before verification can continue.',
            action: { label: 'Review changes', section: 'verification' },
        };
    }
    if (missingRecord) {
        return {
            ...base, key: 'files_needed', label: 'Files needed', tone: 'attention', icon: 'fa-solid fa-file-arrow-up',
            title: 'Support your profile with the required records',
            description: 'Upload the remaining evidence. Completing your details alone does not verify your profile.',
            action: { label: 'Add supporting evidence', section: 'verification' },
        };
    }
    if (base.canApply) {
        return {
            ...base, key: 'ready', label: 'Verified', tone: 'positive', icon: 'fa-solid fa-check',
            title: 'Your profile is ready for applications',
            description: 'Required details are complete and the required supporting records are approved.',
        };
    }
    if (scanNeedsAttention) {
        return {
            ...base, key: 'academic_attention', label: 'Academic record needs attention', tone: 'attention', icon: 'fa-solid fa-file-circle-exclamation',
            title: 'Your academic result needs attention',
            description: 'Open supporting evidence to retry the scan, replace the file, or enter the result for review.',
            action: { label: 'Review academic record', section: 'verification' },
        };
    }
    if (verificationStatus === 'pending') {
        return {
            ...base, key: 'under_review', label: 'Under review', tone: 'neutral', icon: 'fa-regular fa-clock',
            title: 'Your supporting records are with a reviewer',
            description: 'No action is needed right now. Your profile must be verified before you can apply.',
        };
    }
    return {
        ...base, key: 'verification_required', label: 'Verification required', tone: 'attention', icon: 'fa-solid fa-shield-halved',
        title: 'Your profile still needs verification',
        description: 'Check your supporting records. Uploading files is not the same as having them approved.',
        action: { label: 'Review supporting evidence', section: 'verification' },
    };
}
