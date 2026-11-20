import commonProgramDocuments from '../../data/program-documents.json' with { type: 'json' };

export { commonProgramDocuments };

const key = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
const catalog = new Map(commonProgramDocuments.flatMap((item) => [item.label, ...item.aliases].map((label) => [key(label), item.label])));
export const requirementLines = (value) => String(value ?? '').split(/\r\n|\r|\n/).map((line) => line.trim()).filter(Boolean);
export const commonDocumentLabel = (value) => catalog.get(key(value)) || null;
const documentKey = value => key(commonDocumentLabel(value) || value);

export function documentGroupConflict(required, optional) {
    const requiredKeys = new Set(requirementLines(required).map(documentKey));
    const overlap = requirementLines(optional).find(line => requiredKeys.has(documentKey(line)));
    return overlap ? `${commonDocumentLabel(overlap) || overlap} is listed as both required and optional. Keep it in only one pre-screening list.` : '';
}

export function programDocumentErrors({ applicationMode, requirements, optionalRequirements, eligibilityConditions = [] }) {
    if (applicationMode === 'provider_review') {
        return eligibilityConditions.some(condition => condition.key === 'required_documents')
            ? { application_mode: 'This program has an eligibility condition that requires documents. Choose Portal review or Portal + in-person.' }
            : {};
    }
    const conflict = documentGroupConflict(requirements, optionalRequirements);
    return conflict ? { optional_requirements: conflict } : {};
}
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
    return lines.filter((line) => { const id = documentKey(line); if (seen.has(id)) return false; seen.add(id); return true; }).join('\n');
}
