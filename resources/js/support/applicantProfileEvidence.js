export function evidenceStatus(document) {
    const neutral = 'border-slate-200 bg-slate-50 text-slate-600';
    if (!document) return { key: 'missing', label: 'Not uploaded', className: neutral };
    if (document.file_available === false) return { key: 'unavailable', label: 'Upload needed', className: 'border-amber-200 bg-amber-50 text-amber-900' };
    if (document.has_current_acceptance === true) return { key: 'accepted', label: 'Accepted', className: 'border-emerald-200 bg-emerald-50 text-emerald-800' };
    if (document.status === 'rejected') return { key: 'replacement', label: 'Needs replacement', className: 'border-rose-200 bg-rose-50 text-rose-800' };
    if (document.status === 'approved') return { key: 'legacy', label: 'Earlier approval', className: neutral };
    if (['claims_changed', 'review_reopened'].includes(document.review_context?.action)) return { key: 'recheck', label: 'Review needed', className: neutral };
    return { key: 'pending', label: 'Awaiting review', className: 'border-amber-200 bg-amber-50 text-amber-800' };
}

export function evidenceFilePolicy(type, ocr = {}) {
    const scanned = type === 'academic_record' && Boolean(ocr.configured);
    const imageOrPdf = scanned || type === 'recent_school_id';
    const configuredLimit = Number(ocr.max_file_size_mb);
    const maxMb = scanned && Number.isFinite(configuredLimit) && configuredLimit > 0 ? configuredLimit : scanned ? 1 : 5;
    return {
        accept: imageOrPdf ? '.pdf,.jpg,.jpeg,.png' : '.pdf,.jpg,.jpeg,.png,.doc,.docx',
        maxMb,
        label: `${imageOrPdf ? 'PDF, JPG or PNG' : 'PDF, JPG, PNG or Word'}; up to ${maxMb} MB`,
    };
}

export function evidenceProgress(rows = []) {
    const required = rows.filter((row) => row.required);
    return {
        total: required.length,
        saved: required.filter((row) => row.document && row.document.file_available !== false).length,
        accepted: required.filter((row) => evidenceStatus(row.document).key === 'accepted').length,
        needsReplacement: required.filter((row) => ['replacement', 'unavailable'].includes(evidenceStatus(row.document).key)).length,
    };
}

export function nextEvidenceAction(rows = []) {
    const required = rows.filter((row) => row.required);
    return required.find((row) => ['replacement', 'unavailable'].includes(evidenceStatus(row.document).key))
        || required.find((row) => !row.document)
        || rows.find((row) => !row.required && ['replacement', 'unavailable'].includes(evidenceStatus(row.document).key))
        || null;
}

export function evidenceSummary(rows = [], verificationStatus = 'unsubmitted', scanNeedsAttention = false) {
    const progress = evidenceProgress(rows);
    const next = nextEvidenceAction(rows);
    if (verificationStatus === 'approved' && !next?.required) return {
        title: 'Your profile is verified', detail: 'Keep your records current. Replace a file only when it changes or a reviewer asks.', next: next?.value ?? null,
    };
    if (next) return {
        title: `${next.document ? 'Replace' : 'Upload'} ${next.label.toLowerCase()}`,
        detail: next.document ? 'Read the feedback beside this record. Other accepted documents do not need uploading again.' : 'See What to upload for examples. Upload one clear record for each required item.',
        next: next.value,
    };
    if (verificationStatus === 'rejected') return {
        title: 'A profile correction is needed', detail: 'Read the profile reviewer note below. Replace a document only if it was requested or no longer matches your details.', next: null,
    };
    if (progress.total > 0 && progress.accepted === progress.total) return {
        title: 'Documents accepted; final verification pending', detail: 'No more uploads are needed. A reviewer must finish profile verification before you can apply.', next: null,
    };
    if (scanNeedsAttention) return {
        title: 'Check your academic result', detail: 'The scan needs attention. You can retry it or enter the result for an authorized reviewer to confirm.', next: 'academic_record',
    };
    return { title: 'Records awaiting review', detail: 'Your files are saved. Keep accepted records; you only need to act if a reviewer requests a correction.', next: null };
}

export function evidenceReviewDetail(document) {
    if (document?.file_available === false) return 'The saved file is unavailable. Upload a replacement so it can be reviewed.';
    return {
        file_replaced: 'Replacement saved. It is awaiting a new review.',
        claims_changed: 'Related profile details changed. This record needs review again; replace it only if it is no longer current.',
        review_reopened: 'Your profile review was reopened. This record needs another review.',
    }[document?.review_context?.action] || '';
}

export function evidenceDecisionDate(value) {
    const date = value ? new Date(value) : null;
    return date && Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(date) : '';
}

export function evidenceFileError(file, type, ocr = {}) {
    const policy = evidenceFilePolicy(type, ocr);
    const extension = String(file?.name || '').match(/\.[^.]+$/)?.[0]?.toLowerCase();
    if (!extension || !policy.accept.split(',').includes(extension)) return `Choose a supported file: ${policy.label}.`;
    if (!Number.isFinite(file?.size) || file.size <= 0) return 'This file is empty. Choose a readable document.';
    if (file.size > policy.maxMb * 1024 * 1024) return `Choose a file up to ${policy.maxMb} MB.`;
    return '';
}
