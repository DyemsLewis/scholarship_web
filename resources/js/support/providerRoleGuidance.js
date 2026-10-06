export const providerRoleGuidance = {
    governance: {
        label: 'Organization owner',
        purpose: 'Keep every provider workflow accountable by assigning clear owners and protecting access to organization data.',
        responsibilities: [
            'Assign workflow ownership and close responsibility gaps.',
            'Review staff access, verification, and account status.',
            'Choose the operating model that matches the organization.',
        ],
        boundaries: 'This workspace governs people and permissions. Day-to-day application, recipient, and payment work stays in the assigned role workspaces.',
        handoff: 'Changes made here determine which workspace and program records each staff member can access.',
    },
    programs: {
        label: 'Program coordinator',
        purpose: 'Move scholarship programs from draft to publication while keeping requirements, dates, and capacity complete.',
        responsibilities: [
            'Create and maintain program requirements, schedules, and award capacity.',
            'Resolve admin review feedback before publication.',
            'Close completed cycles and keep published information accurate.',
        ],
        boundaries: 'This role owns the program lifecycle, not individual application reviews or award decisions.',
        handoff: 'Published programs become available to application reviewers and the later selection workflow.',
    },
    reviews: {
        label: 'Application reviewer',
        purpose: 'Confirm that each assigned application is complete, credible, and ready for selection activities.',
        responsibilities: [
            'Validate applicant details, eligibility, and submitted evidence.',
            'Document review findings consistently and flag missing proof.',
            'Move only review-ready applications into the next stage.',
        ],
        boundaries: 'Reviewers verify facts and eligibility; they do not schedule selection activities or make final award decisions.',
        handoff: 'Completed reviews move to the selection officer with the review record and evidence attached.',
    },
    selection: {
        label: 'Selection officer',
        purpose: 'Coordinate interviews, exams, and other selection activities, then record defensible results.',
        responsibilities: [
            'Schedule activities and communicate the correct time and venue.',
            'Track attendance and record results with supporting notes.',
            'Identify completed activities that are still missing results.',
        ],
        boundaries: 'This role records assessment outcomes but does not approve the final scholarship decision.',
        handoff: 'Candidates with complete activity results become available to the decision officer.',
    },
    decisions: {
        label: 'Decision officer',
        purpose: 'Record final outcomes fairly while respecting award capacity and an auditable waitlist order.',
        responsibilities: [
            'Compare completed candidate records before recording an outcome.',
            'Keep selected, waitlisted, and declined decisions within program capacity.',
            'Maintain waitlist order and decision notes for auditability.',
        ],
        boundaries: 'This role decides outcomes; recipient agreements and ongoing support are handled after selection.',
        handoff: 'Selected applicants move to recipient onboarding, while waitlisted candidates remain available when capacity changes.',
    },
    recipients: {
        label: 'Recipient officer',
        purpose: 'Turn selected applicants into active recipients with complete agreements and dependable support records.',
        responsibilities: [
            'Track agreement responses and resolve incomplete onboarding.',
            'Maintain accurate recipient status and contact records.',
            'Prepare active recipients for monitoring and benefit delivery.',
        ],
        boundaries: 'This role owns onboarding and recipient records, not academic check-in decisions or payment proof.',
        handoff: 'Fully onboarded recipients become available to monitoring and benefit release staff.',
    },
    monitoring: {
        label: 'Monitoring officer',
        purpose: 'Protect recipient continuity by reviewing check-ins, deadlines, risks, and support requests.',
        responsibilities: [
            'Review submitted progress records and decide recipient requests.',
            'Prioritize overdue, at-risk, and follow-up cases.',
            'Record interventions so the support history stays clear.',
        ],
        boundaries: 'Monitoring evaluates continued compliance; it does not record final application decisions or payment proof.',
        handoff: 'Approved recipient status and resolved requirements inform benefit release readiness.',
    },
    releases: {
        label: 'Benefit release officer',
        purpose: 'Deliver approved support on schedule and preserve a complete record of every distribution.',
        responsibilities: [
            'Schedule releases only for eligible, ready recipients.',
            'Record amounts, dates, channels, and proof of distribution.',
            'Resolve receipt concerns and incomplete release records.',
        ],
        boundaries: 'This role executes approved benefits; it does not decide recipient eligibility or monitoring outcomes.',
        handoff: 'Completed proof updates the recipient record and provides an auditable distribution history.',
    },
    profile: {
        label: 'Organization profile manager',
        purpose: 'Keep the provider identity, public details, and verification proof accurate and trustworthy.',
        responsibilities: [
            'Maintain applicant-facing organization information.',
            'Keep representative and verification documents current.',
            'Resolve profile completeness issues that affect publishing.',
        ],
        boundaries: 'This role maintains organization identity, not staff permissions or scholarship operations.',
        handoff: 'Accurate verified details appear across published programs and provider communications.',
    },
    team: {
        label: 'Team administrator',
        purpose: 'Give each staff member the minimum access needed to perform a clearly defined provider role.',
        responsibilities: [
            'Create individual staff accounts instead of sharing credentials.',
            'Assign role presets and program scope deliberately.',
            'Suspend outdated access and resolve unsafe permission combinations.',
        ],
        boundaries: 'This role manages access configuration; workflow records remain owned by operational staff.',
        handoff: 'Assigned permissions determine each staff member’s navigation, queues, and available actions.',
    },
    support: {
        label: 'Support staff',
        purpose: 'Resolve applicant concerns and platform reports with clear ownership, communication, and closure.',
        responsibilities: [
            'Triage new cases by urgency, category, and related program.',
            'Record useful updates and keep the requester informed.',
            'Escalate platform defects while retaining case ownership.',
        ],
        boundaries: 'Support explains and coordinates; it does not bypass program rules or alter formal review decisions.',
        handoff: 'Escalated technical reports retain their case timeline so the requester receives a complete resolution.',
    },
    billing: {
        label: 'Billing staff',
        purpose: 'Track provider service requests from payment through fulfillment without losing their financial context.',
        responsibilities: [
            'Verify payment state and identify requests that need action.',
            'Distinguish payment progress from service fulfillment progress.',
            'Keep references and request records ready for reconciliation.',
        ],
        boundaries: 'This role manages paid provider services, not scholarship benefit distributions to recipients.',
        handoff: 'Confirmed payments allow the requested provider service to enter its fulfillment workflow.',
    },
};

export const getProviderRoleGuidance = (key) => providerRoleGuidance[key] ?? null;
