export const termsContent = {
    general: {
        title: 'Terms and Privacy Notice',
        summary: 'Practical rules for using the scholarship portal responsibly.',
        details: [
            'Use accurate and authorized information when creating accounts, profiles, applications, and scholarship programs.',
            'The portal may process account, profile, document, location, scholarship activity, notification, and review data.',
            'Decision support scores are guidance only. Scholarship providers still make final review decisions.',
        ],
        sections: [
            {
                title: 'Who can use the platform',
                text: 'Applicants, parents or guardians supporting younger learners, verified scholarship providers, and portal admins may use the system for scholarship discovery, matching, application review, and scholarship management.',
                icon: 'fa-solid fa-users',
            },
            {
                title: 'Accuracy of information',
                text: 'False, altered, incomplete, or unauthorized information may affect account access, application review, or provider approval.',
                icon: 'fa-solid fa-circle-check',
            },
            {
                title: 'Privacy and data use',
                text: 'Portal data is used for matching, notifications, review workflows, document handling, and audit history.',
                icon: 'fa-solid fa-shield-halved',
            },
            {
                title: 'Provider responsibility',
                text: 'Providers are responsible for accurate program details, fair review practices, and proper use of applicant data.',
                icon: 'fa-solid fa-building-columns',
            },
        ],
    },
    account: {
        title: 'Account Terms and Privacy Notice',
        summary: 'The portal may process account, profile, document, location, and scholarship activity data for matching, applications, notifications, and review.',
        details: [
            'Information should be truthful and kept up to date.',
            'For younger applicants, a parent or guardian confirms they are allowed to manage or support the account.',
            'The decision support score is only a guide; scholarship providers still make final review decisions.',
        ],
    },
    application: {
        title: 'Application Submission Terms',
        summary: 'Your profile, profile verification proofs, checklist, notes, DSS result, and attached documents may be shared with the scholarship provider and portal admins for review.',
        details: [
            'Submitted information should be accurate and belong to the applicant.',
            'Prepared documents may be attached automatically when they match the program requirements.',
            'Profile verification proofs are visible only to admins and providers reviewing applications submitted to their own programs.',
            'Final approval, rejection, or awarding remains with the scholarship provider.',
        ],
    },
    document: {
        title: 'Document Upload Terms',
        summary: 'Uploaded files may be stored and used for scholarship matching, application review, verification, and audit history.',
        details: [
            'The file should be correct, readable, and related to the applicant or authorized organization.',
            'Profile verification proofs may be reviewed by admins and by providers after you submit an application to their program.',
            'False, altered, or unauthorized documents may affect account or application status.',
        ],
    },
    providerDocument: {
        title: 'Provider Verification Terms',
        summary: 'Provider verification files may be reviewed by admins to decide whether the account can publish scholarships.',
        details: [
            'The document should represent the organization or authorized contact truthfully.',
            'Admins may approve, reject, or request replacement files.',
            'Provider access may be limited if documents are misleading or unauthorized.',
        ],
    },
    scholarship: {
        title: 'Scholarship Posting Terms',
        summary: 'Scholarship details may be reviewed by admins, shown to applicants after approval, and used for eligibility matching.',
        details: [
            'The provider is responsible for truthful program details, deadlines, requirements, and contact information.',
            'Applicant data received through the portal should only be used for scholarship review and related communication.',
            'Admin review is required before new or resubmitted programs become visible to students.',
        ],
    },
    acceptance: {
        title: 'Scholarship Response Terms',
        summary: 'If you accept, the provider may proceed with the next award, release, or verification steps. If you decline, the provider will be notified.',
        details: [
            'Final release of support still depends on the provider requirements and schedule.',
            'For younger applicants, a parent or guardian should help confirm the response.',
            'Keep your contact details and documents updated for any next steps.',
        ],
    },
};

export function getTermsContent(context = 'general') {
    return termsContent[context] ?? termsContent.general;
}
