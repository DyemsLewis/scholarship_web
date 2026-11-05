import { applicantProfileDetails } from './applicantProfileDetails.js';
import { labelFromKey } from './display.js';

export function evidenceState(document) {
    if (!document) return { key: 'missing', label: 'Not uploaded', class: 'bg-slate-100 text-slate-600' };
    if (document.file_available === false) return { key: 'unavailable', label: 'File unavailable', class: 'bg-amber-100 text-amber-900' };
    if (document.has_current_acceptance === true) return { key: 'accepted', label: 'Accepted', class: 'bg-emerald-100 text-emerald-800' };
    if (document.status === 'rejected') return { key: 'replacement', label: 'Replacement requested', class: 'bg-amber-100 text-amber-900' };
    return { key: 'pending', label: document.legacy_approval ? 'Earlier approval - review needed' : 'Needs review', class: 'bg-slate-100 text-slate-700' };
}

export function evidenceLabel(applicant, type) {
    return applicant?.profile_evidence_requirements?.find((item) => item.value === type)?.label
        || applicant?.verification_documents?.find((item) => item.document_type === type)?.label
        || labelFromKey(type);
}

export function evidenceRows(applicant) {
    const documents = applicant?.verification_documents ?? [];
    const requirements = applicant?.profile_evidence_requirements ?? [];
    const rows = requirements.filter((item) => item.required || documents.some((document) => document.document_type === item.value))
        .map((item) => ({ type: item.value, label: item.label, required: item.required,
            document: documents.find((document) => document.document_type === item.value) }));
    for (const document of documents) {
        if (!rows.some((row) => row.type === document.document_type)) {
            rows.push({ type: document.document_type, label: evidenceLabel(applicant, document.document_type), required: false, document });
        }
    }
    return rows.map((row) => ({ ...row, state: evidenceState(row.document), retired: row.type === 'no_current_scholarship_proof' }));
}

export function evidenceProfileClaims(applicant, document) {
    const fields = document?.claim_fields ?? [];
    const sections = applicantProfileDetails(applicant, { omitUnavailable: true });
    const claims = sections.flatMap((section) => section.groups.flatMap((group) => group.items))
        .filter((item) => fields.includes(item.key));
    if (fields.includes('first_name') && applicant?.name) claims.unshift({ key: 'official_name', label: 'Official name', value: applicant.name });
    if (fields.includes('achievements') && applicant?.achievements) claims.push({ key: 'achievements', label: 'Saved achievements', value: applicant.achievements });
    return claims;
}

export function evidenceEventLabel(action) {
    return { uploaded: 'Document uploaded', accepted: 'Document accepted', replacement_requested: 'Replacement requested',
        file_replaced: 'File replaced', claims_changed: 'Profile details changed', review_reopened: 'Review reopened', removed: 'Document removed' }[action]
        || labelFromKey(action);
}

export function evidenceReviewDate(value) {
    const date = value ? new Date(value) : null;
    return date && Number.isFinite(date.getTime())
        ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(date) : 'Not recorded';
}
