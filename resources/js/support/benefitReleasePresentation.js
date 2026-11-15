export function benefitMethodIcon(method) {
    return {
        in_person: 'fa-solid fa-location-dot',
        bank_transfer: 'fa-solid fa-building-columns',
        e_wallet: 'fa-solid fa-mobile-screen-button',
    }[method] || 'fa-solid fa-gift';
}

export function benefitReleaseGroups(directory = {}) {
    const seen = new Set();
    const collect = (buckets) => buckets.flatMap(([key, action]) => (directory[key] || []).flatMap((release) => {
        const identity = release.record_key || release.href || release;
        if (seen.has(identity)) return [];
        seen.add(identity);
        return [{ ...release, priority_key: key, action_label: key === 'payout_required' && release.payout_status === 'correction_requested' ? 'Update payout details' : action }];
    }));

    return [
        { key: 'attention', title: 'Needs your attention', description: 'Complete these steps before waiting for another provider update.', attention: true,
            releases: collect([['follow_up', 'Open follow-up'], ['action_required', 'Review release'], ['payout_required', 'Enter payout details']]) },
        { key: 'planned', title: 'Scheduled and in progress', description: 'Upcoming releases and updates from your provider.',
            releases: collect([['upcoming', 'View instructions'], ['in_progress', 'View update']]) },
        { key: 'completed', title: 'Past releases', description: 'Saved delivery outcomes, receipt confirmations, and earlier schedules.',
            releases: collect([['completed', 'View record']]) },
    ];
}

export function benefitNextStep(release = {}) {
    const delivery = release.delivery || {};
    const payout = release.payout || {};
    if (release.can_respond || release.follow_up?.can_reply
        || !['scheduled', 'ready', 'waiting'].includes(delivery.key)) return delivery;
    if (payout.status === 'correction_requested') return {
        title: 'Correct your payout details',
        description: payout.correction_label || 'The provider asked for updated payout details.',
    };
    if (payout.status === 'required') return {
        title: 'Enter your payout details',
        description: payout.method === 'e_wallet'
            ? 'Provide the wallet that should receive this benefit. The provider sends the transfer outside this portal.'
            : 'Provide the bank account that should receive this benefit. The provider sends the transfer outside this portal.',
    };
    if (payout.status === 'submitted') return {
        title: 'Your payout details are with the provider',
        description: 'Wait for verification. Submitting an account does not mean a transfer has been sent.',
    };
    if (payout.status === 'approved') return {
        title: 'Your payout details are approved',
        description: 'Wait for the provider to record the transfer. Confirm receipt only after the benefit actually arrives.',
    };
    return delivery;
}

export function benefitCardGuidance(release) {
    if (release.priority_key === 'payout_required') return release.payout_status === 'correction_requested'
        ? 'The provider requested a correction to your payout details.'
        : 'Add your payout details so the provider can arrange this release.';
    if (release.priority_key === 'follow_up') return 'The provider needs your reply about this benefit.';
    if (release.priority_key === 'action_required') return 'Check what you received before confirming or reporting a problem.';
    if (!['scheduled', 'ready', 'waiting'].includes(release.delivery?.key)) return release.delivery?.title || 'Open the record for the recorded outcome.';
    if (release.payout_status === 'submitted') return 'Your payout details are awaiting provider verification.';
    if (release.payout_status === 'approved') return 'Payout details approved. Wait for the provider to record the transfer.';
    return release.delivery?.title || 'Open the release for the latest instructions.';
}
