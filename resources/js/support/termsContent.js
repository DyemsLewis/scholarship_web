export const termsContent = {
    general: {
        title: 'Terms and Privacy Notice',
        summary: 'These terms explain how applicants, parents or guardians, providers, and administrators should use the scholarship portal. They also describe how information is used for scholarship matching, applications, verification, and program management.',
        details: [
            'Use accurate and authorized information when creating an account, completing a profile, submitting an application, or posting a scholarship. Update information when it changes so matching and review results are based on current details.',
            'The portal may process account, profile, document, location, scholarship activity, notification, and review data. This information is used to operate portal features, protect records, and provide relevant updates to authorized users.',
            'Decision support scores compare applicant information with the requirements entered by a provider. A score is guidance only and does not guarantee approval, because the scholarship provider makes the final decision.',
        ],
        sections: [
            {
                title: 'Who can use the platform',
                text: 'Applicants may explore scholarships, prepare documents, and submit applications. Parents or guardians may support younger learners, while verified providers and authorized administrators manage programs, reviews, and platform records.',
                icon: 'fa-solid fa-users',
            },
            {
                title: 'Accuracy of information',
                text: 'Information and documents should be complete, readable, and truthful. False, altered, incomplete, or unauthorized records may delay a review and may affect account access, application decisions, or provider approval.',
                icon: 'fa-solid fa-circle-check',
            },
            {
                title: 'Privacy and data use',
                text: 'Portal data is used for matching, notifications, verification, document handling, review workflows, and audit history. Access is limited by role, so providers review applicants connected to their programs while administrators oversee platform verification and safety.',
                icon: 'fa-solid fa-shield-halved',
            },
            {
                title: 'Provider responsibility',
                text: 'Providers are responsible for accurate program details, clear requirements, and fair review practices. Applicant information received through the portal should only be used for scholarship evaluation, communication, and related award activities.',
                icon: 'fa-solid fa-building-columns',
            },
        ],
    },
    account: {
        title: 'Account Terms and Privacy Notice',
        summary: 'Creating an account allows the portal to store the information needed for secure access and role-based features. Profile, document, location, and scholarship activity data may be used for matching, applications, notifications, verification, and review.',
        details: [
            'Account and profile information should be truthful, complete, and kept up to date. The account owner is responsible for protecting login details and for reporting unauthorized access or incorrect information.',
            'For a younger applicant, a parent or guardian confirms that they are allowed to create, manage, or support the account. They should help the learner understand application requirements and review information before it is submitted.',
            'The decision support score helps identify scholarships whose listed requirements may fit the applicant profile. It is only a guide and does not replace document review or the final decision of the scholarship provider.',
        ],
    },
    application: {
        title: 'Application Submission Terms',
        summary: 'Submitting an application allows the selected scholarship provider and authorized portal administrators to review the information needed for that program. This may include the applicant profile, verification proofs, checklist, notes, decision support result, and attached documents.',
        details: [
            'Submitted information should be accurate, current, and belong to the applicant or be supplied with proper permission. Review the application before submission because missing or incorrect details may delay the provider decision.',
            'Prepared documents may be attached when their document type matches a program requirement. Applicants should check that every attached file is the correct and most recent version before proceeding.',
            'Profile verification proofs are available to authorized administrators and to providers reviewing applications submitted to their own programs. Providers cannot use this access to review unrelated applicant accounts.',
            'Eligibility and decision support results help organize the review but do not guarantee an award. Final approval, rejection, examination, interview, and awarding decisions remain with the scholarship provider.',
        ],
    },
    document: {
        title: 'Document Upload Terms',
        summary: 'Uploaded files are stored so they can support profile verification, document preparation, scholarship matching, and application review. File activity may also be recorded in the audit history to help protect applicants and providers.',
        details: [
            'Each file should be correct, readable, and related to the applicant or an authorized organization. Do not upload unrelated files or personal information that is not needed for scholarship or verification purposes.',
            'Profile verification proofs may be reviewed by administrators and by a provider after an application is submitted to that provider program. Replacing an approved proof may require the updated file to be reviewed again.',
            'False, altered, expired, misleading, or unauthorized documents may delay processing and may affect account, verification, or application status. The portal may keep review notes and document status changes as part of the audit record.',
        ],
    },
    providerDocument: {
        title: 'Provider Verification Terms',
        summary: 'Provider verification files help administrators confirm that an organization and its representative are legitimate and authorized. The files are reviewed before the provider account is allowed to publish scholarships for applicants.',
        details: [
            'Each document should truthfully represent the organization, office, school, foundation, or authorized contact connected to the account. The person uploading it confirms that they have permission to provide the file for verification.',
            'Administrators may approve the provider, reject the verification request, or require clearer and more appropriate proof. Uploading a file does not automatically grant scholarship publishing access.',
            'Provider access may be limited, suspended, or returned for review when documents are misleading, altered, expired, or unauthorized. Updated organization details may also require another verification review.',
        ],
    },
    scholarship: {
        title: 'Scholarship Posting Terms',
        summary: 'Scholarship information is reviewed by administrators before it becomes visible to applicants. Once approved, the details may be used for applicant matching, document preparation, application processing, and provider communication.',
        details: [
            'The provider is responsible for truthful program descriptions, eligibility rules, deadlines, requirements, schedules, award details, and contact information. Important changes should be updated promptly so applicants are not misled.',
            'Applicant profiles and documents received through the portal should only be used for scholarship review, verification, communication, and award-related activities. The information should not be sold, publicly shared, or used for unrelated purposes.',
            'Administrator review is required before a new or resubmitted program becomes visible to applicants. Approval confirms that the listing passed platform review, but the provider remains responsible for operating and funding the scholarship.',
        ],
    },
    acceptance: {
        title: 'Scholarship Response Terms',
        summary: 'A scholarship response tells the provider whether the applicant intends to continue with the award process. Accepting allows the provider to proceed with verification, orientation, or distribution steps, while declining informs the provider that the applicant will not continue.',
        details: [
            'Accepting a scholarship response does not by itself guarantee immediate release of money, supplies, or other support. Final distribution still depends on the provider requirements, schedule, available funding, and any stated award conditions.',
            'For a younger applicant, a parent or guardian should help review the award details and confirm the response. They should also help the learner understand any orientation, document, or distribution requirement.',
            'Keep contact details and supporting documents current so the provider can send accurate next-step information. Contact the provider when instructions are unclear or when the applicant can no longer attend a required activity.',
        ],
    },
};

export function getTermsContent(context = 'general') {
    return termsContent[context] ?? termsContent.general;
}
