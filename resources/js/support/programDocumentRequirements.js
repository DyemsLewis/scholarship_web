export const commonProgramDocuments = [
    { group: 'School records', label: 'Certificate of enrollment', aliases: ['Proof of enrollment', 'Certificate of registration', 'Enrollment certificate'] },
    { group: 'School records', label: 'Latest report card or grades', aliases: ['Latest report card', 'Report card', 'Latest grades', 'Copy of grades', 'Grade report', 'Report card / transcript', 'Latest grade report'] },
    { group: 'School records', label: 'Transcript of records', aliases: ['Transcript', 'Official transcript of records', 'TOR'] },
    { group: 'School records', label: 'Recent school ID', aliases: ['School ID', 'Student ID', 'Current school ID', 'Recent school identification'] },
    { group: 'School records', label: 'Certificate of good moral character', aliases: ['Good moral certificate', 'Certificate of good moral', 'Good moral character certificate'] },
    { group: 'School records', label: 'Certificate of graduation', aliases: ['Graduation certificate'] },
    { group: 'School records', label: 'Diploma', aliases: [] },
    { group: 'Identity', label: 'PSA birth certificate', aliases: ['Birth certificate', 'PSA-issued birth certificate'] },
    { group: 'Identity', label: 'Valid government-issued ID', aliases: ['Government ID', 'Valid government ID'] },
    { group: 'Identity', label: 'Recent 2x2 photo', aliases: ['2x2 photo', '2x2 ID photo', 'Recent ID photo'] },
    { group: 'Income and residency', label: 'Certificate of indigency', aliases: ['Barangay certificate of indigency', 'Indigency certificate'] },
    { group: 'Income and residency', label: 'Proof of income', aliases: ['Household income proof', 'Income certificate', 'Family income certificate'] },
    { group: 'Income and residency', label: 'Income tax return', aliases: ['ITR', 'Parent or guardian income tax return'] },
    { group: 'Income and residency', label: 'Certificate of tax exemption', aliases: ['Tax exemption certificate', 'BIR certificate of tax exemption'] },
    { group: 'Income and residency', label: 'Recent payslip', aliases: ['Payslip', 'Parent or guardian payslip'] },
    { group: 'Income and residency', label: 'Certificate of employment', aliases: ['Employment certificate', 'Parent or guardian certificate of employment'] },
    { group: 'Income and residency', label: 'Certificate of residency', aliases: ['Barangay certificate of residency', 'Residency certificate', 'Proof of residency'] },
    { group: 'Other supporting records', label: 'Recommendation letter', aliases: ['Letter of recommendation'] },
    { group: 'Other supporting records', label: 'Achievement certificate', aliases: ['Award certificate', 'Certificate of achievement', 'Achievement certificates'] },
    { group: 'Other supporting records', label: 'Parent or guardian consent', aliases: ['Parental consent', 'Parent consent form', 'Guardian consent'] },
];

const key = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
const catalog = new Map(commonProgramDocuments.flatMap((item) => [item.label, ...item.aliases].map((label) => [key(label), item.label])));
export const requirementLines = (value) => String(value ?? '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
export const commonDocumentLabel = (value) => catalog.get(key(value)) || null;
export function splitDocumentRequirements(value) {
    const lines = requirementLines(value);
    return { common: lines.filter((line) => commonDocumentLabel(line)), specific: lines.filter((line) => !commonDocumentLabel(line)) };
}
export function addCommonDocument(value, label) {
    const canonical = commonDocumentLabel(label);
    const lines = requirementLines(value);
    if (!canonical || lines.some((line) => commonDocumentLabel(line) === canonical)) return value;
    return [...lines, canonical].join('\n');
}
export function removeCommonDocument(value, label) {
    const canonical = commonDocumentLabel(label);
    if (!canonical) return value;
    return requirementLines(value).filter((line) => commonDocumentLabel(line) !== canonical).join('\n');
}
export function setSpecificDocuments(value, specific) {
    // Preserve saved common-document wording so existing applicant file labels still match.
    const lines = [...splitDocumentRequirements(value).common, ...requirementLines(specific)];
    const seen = new Set();
    return lines.filter((line) => { const id = key(commonDocumentLabel(line) || line); if (seen.has(id)) return false; seen.add(id); return true; }).join('\n');
}
