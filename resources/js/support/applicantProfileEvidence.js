export function evidenceStatus(document) {
    if (!document) return { label: 'Not uploaded', className: 'border-slate-200 bg-slate-50 text-slate-600' };
    return {
        approved: { label: 'Accepted', className: 'border-emerald-200 bg-emerald-50 text-emerald-800' },
        rejected: { label: 'Needs replacement', className: 'border-rose-200 bg-rose-50 text-rose-800' },
    }[document.status] ?? { label: 'Awaiting review', className: 'border-amber-200 bg-amber-50 text-amber-800' };
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
        saved: required.filter((row) => row.document).length,
        accepted: required.filter((row) => row.document?.status === 'approved').length,
        needsReplacement: required.filter((row) => row.document?.status === 'rejected').length,
    };
}
