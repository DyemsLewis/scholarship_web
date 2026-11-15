<?php

namespace App\Support;

use App\Models\User;

class ProviderWorkspaceGuides
{
    public static function for(User $user, string $workspace): array
    {
        $permission = match ($workspace) {
            'programs' => 'manage_programs',
            'reviews' => 'verify_applications',
            'selection' => 'manage_selection_activities',
            'decisions' => 'record_final_decisions',
            'recipients' => 'manage_recipients',
            'monitoring' => 'manage_monitoring',
            'releases' => 'manage_benefit_releases',
            'organization-profile' => 'manage_profile',
            'team' => 'manage_team',
            'support' => 'manage_reports',
            'billing' => 'manage_billing',
            'manager' => 'dashboard',
            default => null,
        };
        abort_if($permission === null, 404);
        abort_unless($user->isProvider() && ($workspace === 'manager'
            ? ProviderWorkspace::preferredRouteName($user) === null
            : $user->hasPortalPermission($permission)), 403);

        $owner = $user->providerOrganizationOwner();
        $ready = $owner->hasVerifiedEmail() && $owner->providerProfile?->isVerified();

        $guide = match ($workspace) {
            'programs' => self::programs(),
            'reviews' => self::reviews(),
            'selection' => self::selection(),
            'decisions' => self::decisions(),
            'recipients' => self::recipients(),
            'monitoring' => self::monitoring(),
            'releases' => self::releases(),
            'organization-profile' => self::organizationProfile(),
            'team' => self::team(),
            'support' => self::support(),
            'billing' => self::billing(),
            'manager' => self::manager(),
        };

        return [
            ...$guide,
            'key' => $workspace,
            'version' => 1,
            'prerequisite' => match ($workspace) {
                'organization-profile' => $ready
                    ? 'Your organization is verified. Changes to legal details or proof can return it to review; check the resulting status before handing off.'
                    : 'You can complete this profile while approval is pending. Check the organization email and submit the required proof; an administrator decides verification.',
                'team' => $ready
                    ? 'Your organization is verified. Give staff only the role and program access needed for their work.'
                    : 'You can prepare staff access while organization approval is pending. Approval-dependent work remains unavailable until the organization is verified.',
                'manager' => $ready
                    ? 'Your organization is verified. The dashboard shows only work your account is allowed to access.'
                    : 'Organization approval is still needed for operational work. Use Organization profile or Team Access if available to prepare the organization and staff.',
                default => $ready
                    ? ($workspace === 'programs'
                        ? 'Your organization is verified. You can create and edit programs within your access scope.'
                        : 'Your organization is verified. Work only on the applications and programs available to your account.')
                    : 'The organization needs verified email and an approved provider profile before this work can proceed. Ask the organization profile manager to complete these.',
            },
            'ready' => (bool) $ready,
            'scope_note' => match ($workspace) {
                'organization-profile' => 'This workspace maintains the shared organization profile. My profile contains your own staff details, not the public organization record.',
                'team' => 'Staff accounts belong to this organization. Use the available role and program choices; ask an authorized manager if the needed access is unavailable.',
                'billing' => 'Service requests belong to your organization. Service fees are separate from scholarship benefit releases and do not grant publication or selection approval.',
                'manager' => 'Only available workspaces appear. A dashboard guide does not grant new permissions or access to unassigned scholarships.',
                default => $user->hasLimitedProviderProgramAccess()
                    ? 'You only see programs assigned to your account. Ask your manager if a program is missing.'
                    : 'You can work on programs available to your organization.',
            },
        ];
    }

    private static function programs(): array
    {
        return [
            'title' => 'Programs workspace',
            'purpose' => 'Prepare a clear scholarship offer and send it for administrator review.',
            'steps' => [
                [
                    'title' => 'Start with a draft', 'icon' => 'fa-solid fa-pen-to-square',
                    'items' => [
                        ['label' => 'Open Programs', 'text' => 'Choose Create program, or open a draft in Drafts & changes.'],
                        ['label' => 'Complete Basics', 'text' => 'Name the scholarship, choose its cycle, and add a logo and description.'],
                        ['label' => 'Save as draft', 'text' => 'Keep unfinished work private. Saving a draft does not publish the scholarship.'],
                    ],
                ],
                [
                    'title' => 'Build the offer and rules', 'icon' => 'fa-solid fa-list-check',
                    'items' => [
                        ['label' => 'Support and Schedule', 'text' => 'Set benefits, available slots, intake limits, dates, and the program location.'],
                        ['label' => 'Eligibility and Application', 'text' => 'Choose required or preferred rules, document requirements, and applicant instructions.'],
                        ['label' => 'Selection', 'text' => 'Choose the stages and evaluation plan. Confirm them before applications begin.'],
                    ],
                ],
                [
                    'title' => 'Preview, then submit', 'icon' => 'fa-solid fa-eye',
                    'items' => [
                        ['label' => 'Preview as applicant', 'text' => 'After saving, check the page applicants will see. Previewing does not publish it.'],
                        ['label' => 'Open Review', 'text' => 'Check the summary, resolve missing details, and confirm the declaration.'],
                        ['label' => 'Submit for review', 'text' => 'Send the program to the administrator. Submission is not publication.'],
                    ],
                ],
                [
                    'title' => 'Follow the review result', 'icon' => 'fa-solid fa-circle-check',
                    'items' => [
                        ['label' => 'Under review', 'text' => 'Track programs awaiting an administrator decision.'],
                        ['label' => 'Drafts & changes', 'text' => 'Read requested corrections, update the program, and submit it again.'],
                        ['label' => 'Published programs', 'text' => 'Check the approved offer. Application reviewers and selection staff handle their assigned stages in their own workspaces.'],
                    ],
                ],
            ],
        ];
    }

    private static function reviews(): array
    {
        return [
            'title' => 'Application Review workspace',
            'purpose' => 'Check the submitted evidence, resolve corrections, and record a screening result.',
            'steps' => [
                [
                    'title' => 'Find and claim a review', 'icon' => 'fa-solid fa-user-check',
                    'items' => [
                        ['label' => 'My reviews', 'text' => 'Start with applications assigned to you. Use Returned for applicants who submitted requested updates.'],
                        ['label' => 'Unassigned', 'text' => 'Find an available record and choose Claim review before making changes.'],
                        ['label' => 'Check the assignment', 'text' => 'Another reviewer\'s record is read-only. Ask your manager if an assignment needs attention.'],
                    ],
                ],
                [
                    'title' => 'Check the evidence', 'icon' => 'fa-solid fa-file-circle-check',
                    'items' => [
                        ['label' => 'Eligibility', 'text' => 'Compare required and preferred rules separately. A profile comparison does not approve an application.'],
                        ['label' => 'Evidence', 'text' => 'View each required file, then use Review to record its status. Explain what needs replacing when a file is unclear or incorrect.'],
                        ['label' => 'Profile', 'text' => 'Check the photo, supporting evidence, and academic result. Distinguish self-declared details from reviewed evidence.'],
                    ],
                ],
                [
                    'title' => 'Resolve requested corrections', 'icon' => 'fa-solid fa-pen-to-square',
                    'items' => [
                        ['label' => 'Request correction', 'text' => 'In Result, identify what the applicant must fix and give a clear instruction. Do not pass an incomplete record.'],
                        ['label' => 'Returned updates', 'text' => 'Reopen the updated application and review the changed information and replacement files.'],
                        ['label' => 'Accept update', 'text' => 'Resolve the correction only after checking it. Required files must be accepted and open corrections resolved before passing screening.'],
                    ],
                ],
                [
                    'title' => 'Record the screening result', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Complete the evaluation', 'text' => 'In Result, score the screening rubric when configured and add a useful review note.'],
                        ['label' => 'Review result', 'text' => 'Choose Pass screening or Do not pass, provide any required reason, then check the confirmation before saving.'],
                        ['label' => 'Hand off to the next stage', 'text' => 'Passing advances to the next configured stage; it is not a scholarship award. Selection staff handle later activities, and final-decision staff make the final selection.'],
                    ],
                ],
            ],
        ];
    }

    private static function selection(): array
    {
        return [
            'title' => 'Selection Activities workspace',
            'purpose' => 'Organize sessions, record participation and proof, then review each applicant\'s stage outcome.',
            'steps' => [
                [
                    'title' => 'Choose the next activity', 'icon' => 'fa-solid fa-calendar-check',
                    'items' => [
                        ['label' => 'Sessions and Unassigned', 'text' => 'Open existing sessions or find exam and interview applicants who still need a schedule. Filter by program and stage.'],
                        ['label' => 'Formal applications', 'text' => 'Use Results to review formal applications directly when no appointment was assigned. Do not create a session just to unlock a decision.'],
                        ['label' => 'Follow the current stage', 'text' => 'Work on applicants who reached the relevant stage. An activity schedule is not a passed outcome.'],
                    ],
                ],
                [
                    'title' => 'Set up the session and roster', 'icon' => 'fa-solid fa-users',
                    'items' => [
                        ['label' => 'Schedule activity', 'text' => 'Choose the program, stage, date, time, and capacity. Set the venue and map pin for in-person sessions, or a meeting link for online sessions.'],
                        ['label' => 'Publish', 'text' => 'Check the instructions and publish the draft session. Use separate sessions for different dates or locations.'],
                        ['label' => 'Roster', 'text' => 'Use Add applicants to assign eligible applicants. Move an assignment before recording its outcome if another schedule is needed.'],
                    ],
                ],
                [
                    'title' => 'Record what happened', 'icon' => 'fa-solid fa-file-shield',
                    'items' => [
                        ['label' => 'Attendance', 'text' => 'After the session starts, record attendance for every assigned applicant. Attendance alone does not pass anyone.'],
                        ['label' => 'Proof', 'text' => 'Upload the attendance sheet, score sheet, or other appropriate evidence. Proof is required when the session says so.'],
                        ['label' => 'Complete session', 'text' => 'Resolve the completion blockers first. Completing the session makes its records ready for review; individual outcomes remain separate.'],
                    ],
                ],
                [
                    'title' => 'Evaluate and record the outcome', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Results', 'text' => 'Open an applicant ready for review. Check the activity record and resolve any displayed blocker before recording a result.'],
                        ['label' => 'Stage evaluation', 'text' => 'For a scored exam or interview, enter the evaluation requested by the fixed scoring plan. Existing intakes without a plan do not add ranking scores.'],
                        ['label' => 'Review outcome', 'text' => 'Choose Passed or Not passed and confirm the result. Passing advances to the next stage; final-decision staff use the collected evaluations to support, not automate, their decision.'],
                    ],
                ],
            ],
        ];
    }

    private static function decisions(): array
    {
        return [
            'title' => 'Final Decisions workspace',
            'purpose' => 'Review the completed evaluations and available slots, then make an informed final decision.',
            'steps' => [
                [
                    'title' => 'Open a decision-ready record', 'icon' => 'fa-solid fa-folder-open',
                    'items' => [
                        ['label' => 'Pending decisions', 'text' => 'Start with applicants who completed the configured selection stages. Filter by program to keep the current intake in view.'],
                        ['label' => 'Review the completed record', 'text' => 'Check earlier stage results, eligibility, and accepted evidence. Resolve open corrections before recording a final outcome.'],
                        ['label' => 'Program capacity', 'text' => 'Check awarded and remaining slots. A high evaluation score does not create an additional scholarship slot.'],
                    ],
                ],
                [
                    'title' => 'Use the DSS as decision support', 'icon' => 'fa-solid fa-scale-balanced',
                    'items' => [
                        ['label' => 'Compare within the program', 'text' => 'Choose a program in the queue and inspect the comparison and score breakdown, not just the suggestion.'],
                        ['label' => 'Check evaluation readiness', 'text' => 'Review stage scores, weights, and any missing evaluations. Ask the relevant reviewer to resolve gaps; do not invent scores for an intake without a fixed plan.'],
                        ['label' => 'Keep the decision with the provider', 'text' => 'Suggestions do not award scholarships automatically. Review ties and other context, and explain a different outcome when the system requests an internal reason.'],
                    ],
                ],
                [
                    'title' => 'Review and confirm the outcome', 'icon' => 'fa-solid fa-gavel',
                    'items' => [
                        ['label' => 'Choose an outcome', 'text' => 'Selected offers an award; Waitlisted keeps the applicant in consideration; Not selected closes without an award and requires a reason. Selection is blocked when slots are full.'],
                        ['label' => 'Check the award and notes', 'text' => 'Leave Awarded amount blank to use the program terms. The Decision note is visible to the applicant; the Internal decision reason is not.'],
                        ['label' => 'Review outcome', 'text' => 'Check the confirmation before saving. If the comparison changed, use Reload comparison before confirming the result.'],
                    ],
                ],
                [
                    'title' => 'Hand off and follow up', 'icon' => 'fa-solid fa-arrow-right-arrow-left',
                    'items' => [
                        ['label' => 'Selected applicants', 'text' => 'Recipient staff track agreement responses next. Selected does not mean the applicant has accepted the terms or received a benefit.'],
                        ['label' => 'Waitlist', 'text' => 'When a slot opens, review the current comparison and use Promote to selected. Explain a decision ahead of the official waitlist when requested.'],
                        ['label' => 'History and corrections', 'text' => 'Find saved outcomes in History. Use Reopen only when the record offers it; downstream agreements or support activity may prevent reversal.'],
                    ],
                ],
            ],
        ];
    }

    private static function recipients(): array
    {
        return [
            'title' => 'Recipients workspace',
            'purpose' => 'Track agreement responses, coordinate accepted recipients, and record supported changes to scholarship support.',
            'steps' => [
                [
                    'title' => 'Check the agreement response', 'icon' => 'fa-solid fa-file-signature',
                    'items' => [
                        ['label' => 'Awaiting response', 'text' => 'Open a selected applicant and review Agreement for the terms saved with the award and the current response status.'],
                        ['label' => 'Let the applicant respond', 'text' => 'The applicant accepts or declines the terms. Do not treat silence as acceptance or assume a selected applicant is already receiving support.'],
                        ['label' => 'Confirm acceptance first', 'text' => 'Acceptance is required for support outcomes and benefit release. Use Declined to review declined responses rather than treating them as active recipients.'],
                    ],
                ],
                [
                    'title' => 'Coordinate active support', 'icon' => 'fa-solid fa-hand-holding-heart',
                    'items' => [
                        ['label' => 'Active recipients', 'text' => 'Open an accepted recipient and check Support status for the current status, requirements, releases, and renewal readiness.'],
                        ['label' => 'Support activity', 'text' => 'Review recorded check-ins and benefit releases. A scheduled release is not proof that the benefit was delivered.'],
                        ['label' => 'Hand off the operational work', 'text' => 'Monitoring staff arrange document check-ins; release staff handle benefit delivery and follow-up. Recipient access does not grant those roles automatically.'],
                    ],
                ],
                [
                    'title' => 'Handle concerns and withdrawals fairly', 'icon' => 'fa-solid fa-comments',
                    'items' => [
                        ['label' => 'Agreement concerns', 'text' => 'Record the concern, supporting evidence, and applicant response. A concern or a missed response is not a confirmed breach.'],
                        ['label' => 'Withdrawal requests', 'text' => 'Review the applicant\'s request and the support record before deciding. A pending withdrawal request does not pause support by itself.'],
                        ['label' => 'Respect review safeguards', 'text' => 'Use the available review and response process before a noncompliance-based termination. Do not treat a concern as a public blacklist entry.'],
                    ],
                ],
                [
                    'title' => 'Record a supported outcome', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Support status', 'text' => 'Choose renewal, completion, or early termination only when appropriate. Supply the requested reason, dates, terms, and supporting document.'],
                        ['label' => 'Clear the displayed blockers', 'text' => 'Check outstanding requirements, releases, and settlement issues before completion. Early termination may require notice, an independent review, and a completed appeal opportunity.'],
                        ['label' => 'Record support outcome', 'text' => 'Confirm the supporting records, review the confirmation, and save. Use History and Closed to review the recorded result; recording an outcome does not transfer money.'],
                    ],
                ],
            ],
        ];
    }

    private static function monitoring(): array
    {
        return [
            'title' => 'Monitoring workspace',
            'purpose' => 'Schedule document check-ins, verify the records presented, and resolve each recipient\'s follow-ups.',
            'steps' => [
                [
                    'title' => 'Start with the plan and priorities', 'icon' => 'fa-solid fa-clipboard-list',
                    'items' => [
                        ['label' => 'Plans & schedules', 'text' => 'Open the program\'s monitoring plan. Program staff configure and activate it; ask them to resolve missing plan details if you cannot edit them.'],
                        ['label' => 'Choose the right queue', 'text' => 'Use Needs review for records requiring action, Follow-ups for unresolved work, and Scheduled or waiting for upcoming appointments.'],
                        ['label' => 'Check the recipient and program', 'text' => 'Work within your assigned programs and check the current support status. Completing a check-in does not renew or end the scholarship automatically.'],
                    ],
                ],
                [
                    'title' => 'Schedule the document handoff', 'icon' => 'fa-solid fa-calendar-plus',
                    'items' => [
                        ['label' => 'New check-in', 'text' => 'From an active plan, set the appointment, response deadline, venue, map pin, and instructions for the documents to bring.'],
                        ['label' => 'Choose the recipients', 'text' => 'Select eligible recipients who should attend. Check the date and location before using Publish check-in.'],
                        ['label' => 'Explain the handoff', 'text' => 'For these in-person check-ins, applicants bring their documents to the appointment. They do not satisfy the check-in by uploading files to the portal.'],
                    ],
                ],
                [
                    'title' => 'Verify attendance and requirements', 'icon' => 'fa-solid fa-user-check',
                    'items' => [
                        ['label' => 'Open the check-in', 'text' => 'Select the recipient and record attendance when the appointment allows it. Attendance alone does not confirm that every requirement was met.'],
                        ['label' => 'Record result', 'text' => 'Inspect each document presented and record the requirement result. Enter a verified academic result and confirm the document check when requested.'],
                        ['label' => 'Keep the reason clear', 'text' => 'Explain unmet requirements or an exception. Follow the displayed blockers instead of marking an unchecked document as met.'],
                    ],
                ],
                [
                    'title' => 'Resolve follow-ups and close carefully', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Follow-ups and requests', 'text' => 'Review adjustment requests, arrange another appointment where allowed, and record the action needed. Rescheduling does not confirm a requirement.'],
                        ['label' => 'Close the check-in', 'text' => 'Confirm all required items and resolve the displayed closure blockers. Use an incomplete or excused outcome only when available and supported by the record.'],
                        ['label' => 'History and handoff', 'text' => 'Review the saved outcome in History. Release staff use the current monitoring results for release readiness; recipient staff handle renewal or scholarship closure.'],
                    ],
                ],
            ],
        ];
    }

    private static function releases(): array
    {
        return [
            'title' => 'Benefit Releases workspace',
            'purpose' => 'Schedule eligible benefits, verify delivery details, and record proof without confusing sent with received.',
            'steps' => [
                [
                    'title' => 'Check readiness and schedule', 'icon' => 'fa-solid fa-calendar-check',
                    'items' => [
                        ['label' => 'Schedule release', 'text' => 'Choose the program, benefit details, and release date. Review the eligible recipients and the reasons anyone is blocked.'],
                        ['label' => 'Check the conditions', 'text' => 'Agreement acceptance, support status, and monitoring requirements due before the release date can affect readiness. Do not bypass a blocked recipient.'],
                        ['label' => 'One-time and continuing benefits', 'text' => 'Use the configured benefit and its remaining entitlement. A one-time benefit does not automatically require recurring check-ins; follow the program\'s actual conditions.'],
                    ],
                ],
                [
                    'title' => 'Prepare the delivery details', 'icon' => 'fa-solid fa-address-card',
                    'items' => [
                        ['label' => 'Choose the release method', 'text' => 'For in-person delivery, set the venue, map pin, and collection instructions. For another arrangement, explain clearly how the recipient will receive the benefit.'],
                        ['label' => 'Verify payout details', 'text' => 'For bank or e-wallet transfers, review the destination supplied by the applicant. Approve details only after checking them, or request a correction.'],
                        ['label' => 'Keep payment separate', 'text' => 'Approving payout details does not move money. The provider sends the benefit through its bank or e-wallet outside the portal.'],
                    ],
                ],
                [
                    'title' => 'Record actual delivery and proof', 'icon' => 'fa-solid fa-receipt',
                    'items' => [
                        ['label' => 'Ready to record', 'text' => 'Open the release and select a recipient. Record the actual distribution on or after the scheduled time, not simply because a release was scheduled.'],
                        ['label' => 'Record transfer as sent', 'text' => 'For a digital transfer, enter the date sent and upload the required proof after sending it. Hide full account numbers and unrelated transactions in the proof.'],
                        ['label' => 'Other distribution results', 'text' => 'For in-person delivery, record the result and receipt or acknowledgement as requested. Explain a missed or withheld release and confirm any required original-document check.'],
                    ],
                ],
                [
                    'title' => 'Follow receipt and resolve issues', 'icon' => 'fa-solid fa-comments',
                    'items' => [
                        ['label' => 'Sent is not confirmed received', 'text' => 'The applicant confirms receipt. Do not treat a provider\'s sent record or an unanswered notice as the applicant\'s confirmation.'],
                        ['label' => 'Issues & follow-up and Delivery reports', 'text' => 'Review missed releases and reported problems. Respond with a correction plan or delivery proof; only the applicant can confirm receipt or accept a clarification.'],
                        ['label' => 'History and completion', 'text' => 'Check recorded results and receipt responses in History. Resolve open delivery issues before handing off support completion to recipient staff.'],
                    ],
                ],
            ],
        ];
    }

    private static function organizationProfile(): array
    {
        return [
            'title' => 'Organization Profile workspace',
            'purpose' => 'Keep the public organization record accurate and provide the proof administrators need to verify it.',
            'steps' => [
                [
                    'title' => 'Start with profile readiness', 'icon' => 'fa-solid fa-building-circle-check',
                    'items' => [
                        ['label' => 'Profile readiness', 'text' => 'Check the next task and incomplete sections before editing. Profile completeness is not the same as administrator approval.'],
                        ['label' => 'Check verification status', 'text' => 'Read any review notes and confirm the organization email status. An uploaded file is not automatically an approved document.'],
                        ['label' => 'Use the organization record', 'text' => 'Make shared organization changes here. Keep personal staff information in My profile.'],
                    ],
                ],
                [
                    'title' => 'Prepare the public profile and location', 'icon' => 'fa-solid fa-address-book',
                    'items' => [
                        ['label' => 'Public profile', 'text' => 'Add the organization name, type, description, and mission. Use the official logo for identity and a separate background image for the public page.'],
                        ['label' => 'Contact and location', 'text' => 'Keep public contact details, office hours, service area, address, and map pin accurate. Check existing session locations separately after an office move.'],
                        ['label' => 'Save and preview', 'text' => 'Save text edits before using Preview as applicant. Logo and background changes save separately; a preview does not approve the organization.'],
                    ],
                ],
                [
                    'title' => 'Provide legal details and proof', 'icon' => 'fa-solid fa-file-shield',
                    'items' => [
                        ['label' => 'Legal verification', 'text' => 'Match the legal name, registration authority, number, and date to the supporting records, then use Save legal details.'],
                        ['label' => 'Upload proof', 'text' => 'Choose the requested document type, such as organization registration, authorization letter, or authorized representative ID. Upload a readable file and confirm the declaration.'],
                        ['label' => 'Replace only what needs changing', 'text' => 'Review the file status and notes before replacing or removing proof. Changes can return an approved organization to review and pause approval-dependent work.'],
                    ],
                ],
                [
                    'title' => 'Follow the review and hand off', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Respond to requested changes', 'text' => 'Correct the named issue and submit the affected proof. Do not replace unrelated approved documents unnecessarily.'],
                        ['label' => 'Check the resulting status', 'text' => 'An administrator reviews the organization and its proof. Saving details, uploading files, or opening a preview does not grant approval.'],
                        ['label' => 'Confirm applicant-facing details', 'text' => 'Recheck the saved public preview and contact information. Tell the manager when verification or profile changes affect other workspaces.'],
                    ],
                ],
            ],
        ];
    }

    private static function team(): array
    {
        return [
            'title' => 'Team Access workspace',
            'purpose' => 'Give each staff member a separate login, a clear responsibility, and the right scholarship access.',
            'steps' => [
                [
                    'title' => 'Review the current team', 'icon' => 'fa-solid fa-users-gear',
                    'items' => [
                        ['label' => 'Setup required', 'text' => 'Check accounts still waiting for email verification or a password change before staff begin work.'],
                        ['label' => 'Active and Suspended', 'text' => 'Review existing accounts before adding another. A staff account belongs to this organization, not to an applicant.'],
                        ['label' => 'Add team member', 'text' => 'Create a separate login rather than sharing the representative or manager account. Use the staff member\'s own contact details.'],
                    ],
                ],
                [
                    'title' => 'Choose the workspace responsibility', 'icon' => 'fa-solid fa-user-gear',
                    'items' => [
                        ['label' => 'Account details', 'text' => 'Enter the name, email, username, and contact details. Share any temporary password privately; new staff must complete their password setup.'],
                        ['label' => 'Workspace role', 'text' => 'Choose the appropriate role and review its assigned responsibilities. Use custom permissions only when available and necessary.'],
                        ['label' => 'Avoid unnecessary access', 'text' => 'Give only the work the staff member needs. Team administration does not automatically mean the person should review applicants or record awards.'],
                    ],
                ],
                [
                    'title' => 'Set the scholarship scope', 'icon' => 'fa-solid fa-folder-tree',
                    'items' => [
                        ['label' => 'Program access', 'text' => 'Choose all organization programs only when that breadth is needed and available. Otherwise choose the specific programs the staff member will handle.'],
                        ['label' => 'Check the selected programs', 'text' => 'Select at least one program when using selected-program access. An empty list does not mean access to every program.'],
                        ['label' => 'Keep role and scope separate', 'text' => 'A role determines the actions available; program assignments determine which scholarship records those actions apply to. Ask an authorized manager about unavailable choices.'],
                    ],
                ],
                [
                    'title' => 'Finish setup and maintain access', 'icon' => 'fa-solid fa-user-shield',
                    'items' => [
                        ['label' => 'Complete account setup', 'text' => 'After saving, check Setup required until email verification and password setup are complete. Organization approval may still gate operational work.'],
                        ['label' => 'Review changes carefully', 'text' => 'Edit the account when duties or assigned scholarships change. Removing responsibilities or programs can remove access to existing queues.'],
                        ['label' => 'Suspend or reactivate', 'text' => 'Use the confirmation when suspending a departing staff member or reactivating an account. You cannot suspend your own account; do not reuse another person\'s login.'],
                    ],
                ],
            ],
        ];
    }

    private static function support(): array
    {
        return [
            'title' => 'Support workspace',
            'purpose' => 'Read concerns, give a clear response, and follow the platform review without changing scholarship decisions.',
            'steps' => [
                [
                    'title' => 'Choose the case to handle', 'icon' => 'fa-solid fa-headset',
                    'items' => [
                        ['label' => 'Needs response', 'text' => 'Start with applicant concerns waiting for your organization. Search or filter by program to find a specific case.'],
                        ['label' => 'Waiting for platform', 'text' => 'Track cases awaiting platform follow-up. Platform reports contains issues your organization sent.'],
                        ['label' => 'Read the full case', 'text' => 'Open the original concern, related program, attachment, and case progress before responding.'],
                    ],
                ],
                [
                    'title' => 'Send a useful response', 'icon' => 'fa-solid fa-message',
                    'items' => [
                        ['label' => 'Explain the next action', 'text' => 'Give a factual answer and a practical next step. The applicant can read your response; avoid unnecessary private information.'],
                        ['label' => 'Send response', 'text' => 'Check the confirmation before sending. Your response completes the provider side, not necessarily the whole case.'],
                        ['label' => 'Correct an answer', 'text' => 'Use Reopen response when available. Confirm carefully: the current provider response is cleared so you can replace it.'],
                    ],
                ],
                [
                    'title' => 'Report a platform problem', 'icon' => 'fa-solid fa-triangle-exclamation',
                    'items' => [
                        ['label' => 'Report platform issue', 'text' => 'Choose the problem type and the related program when relevant. Describe one issue per report.'],
                        ['label' => 'Give enough context', 'text' => 'Name the affected page, what happened, and what you expected. Attach useful evidence without passwords or unnecessary applicant details.'],
                        ['label' => 'Check before sending', 'text' => 'Use a clear subject and review your description. Follow the submitted report rather than sending duplicate cases.'],
                    ],
                ],
                [
                    'title' => 'Follow through and hand off', 'icon' => 'fa-solid fa-clipboard-check',
                    'items' => [
                        ['label' => 'Track both sides', 'text' => 'Check provider response, platform review, and overall case status separately. A sent response is not always a closed case.'],
                        ['label' => 'Use Resolved cases', 'text' => 'Review the recorded response and platform result when checking how a concern was handled.'],
                        ['label' => 'Keep responsibilities separate', 'text' => 'Support replies do not approve applications, change grades, or release benefits. Hand operational issues to the authorized workspace staff.'],
                    ],
                ],
            ],
        ];
    }

    private static function billing(): array
    {
        return [
            'title' => 'Platform Services workspace',
            'purpose' => 'Request optional platform assistance, track payment and delivery, and review the finished work.',
            'steps' => [
                [
                    'title' => 'Choose the right service', 'icon' => 'fa-solid fa-handshake-angle',
                    'items' => [
                        ['label' => 'Browse services', 'text' => 'Read the scope, deliverables, exclusions, fee, and required information before starting a request.'],
                        ['label' => 'Check existing requests', 'text' => 'Use Requests to finish for unpaid requests. Use Action needed for paid services that need your response.'],
                        ['label' => 'Keep services optional', 'text' => 'These services support your team. Buying one does not award a scholarship or automatically publish a program.'],
                    ],
                ],
                [
                    'title' => 'Prepare and review the request', 'icon' => 'fa-solid fa-clipboard-list',
                    'items' => [
                        ['label' => 'Request details', 'text' => 'Provide the contact, related program when needed, and service-specific requirements. Saving these details is not a payment.'],
                        ['label' => 'Review before payment', 'text' => 'Check the saved scope, contact, fee, and terms. Only open checkout when the request is correct.'],
                        ['label' => 'Check payment status', 'text' => 'Use the recorded payment confirmation, not just a return from checkout. Check a pending payment before trying again to avoid duplicates.'],
                    ],
                ],
                [
                    'title' => 'Work with the platform team', 'icon' => 'fa-solid fa-comments',
                    'items' => [
                        ['label' => 'Open the service workspace', 'text' => 'Use Overview to check who acts next. Payment and service delivery have separate statuses.'],
                        ['label' => 'Meeting, Files, and Updates', 'text' => 'Provide requested information, upload the relevant files, and follow meeting arrangements in their sections.'],
                        ['label' => 'Follow the queue', 'text' => 'Check Action needed, Waiting to start, or In progress as the work moves forward. Payment alone does not mean delivery is complete.'],
                    ],
                ],
                [
                    'title' => 'Review delivery and close out', 'icon' => 'fa-solid fa-flag-checkered',
                    'items' => [
                        ['label' => 'Check the delivered work', 'text' => 'Read the updates and files before choosing Confirm complete in Closeout. Review the confirmation before accepting.'],
                        ['label' => 'Request a correction', 'text' => 'Use Request a revision when available and explain what needs fixing. Follow the request rather than assuming the change is already complete.'],
                        ['label' => 'Keep the final record', 'text' => 'Use Records and history to review delivery and decisions. Cancellation or refund requests require platform review; submitting one is not an automatic refund.'],
                    ],
                ],
            ],
        ];
    }

    private static function manager(): array
    {
        return [
            'title' => 'Provider dashboard guide',
            'purpose' => 'Use the organization overview to find the next task and hand work to the right workspace.',
            'steps' => [
                [
                    'title' => 'Start with what needs attention', 'icon' => 'fa-solid fa-gauge-high',
                    'items' => [
                        ['label' => 'Read the priority action', 'text' => 'Start with the highlighted next task when one is available. Counts summarize records; they are not completed decisions.'],
                        ['label' => 'Check the work queues', 'text' => 'Open a queue to review the records behind its count. Use the program filter in that workspace when needed.'],
                        ['label' => 'Follow your access', 'text' => 'Only available workspaces appear. Owners, managers, and custom staff may have different permissions and scholarship assignments.'],
                    ],
                ],
                [
                    'title' => 'Prepare the organization and offer', 'icon' => 'fa-solid fa-building-columns',
                    'items' => [
                        ['label' => 'Organization profile', 'text' => 'Keep public details, contact location, and legal proof current if you have access. An administrator decides verification.'],
                        ['label' => 'Team Access', 'text' => 'When available, give staff separate accounts with the right roles and assigned scholarships. Do not share a manager login.'],
                        ['label' => 'Programs', 'text' => 'Prepare and preview the offer, then submit it for review. Saving or submitting a program does not publish it.'],
                    ],
                ],
                [
                    'title' => 'Follow the applicant handoffs', 'icon' => 'fa-solid fa-route',
                    'items' => [
                        ['label' => 'Review and Selection Activities', 'text' => 'Reviewers check evidence; selection staff arrange activities and record stage evaluations. A schedule is not a passed outcome.'],
                        ['label' => 'Final Decisions and Recipients', 'text' => 'Authorized staff review evaluations and record the final choice, then follow agreements and recipient readiness. Selection is not agreement acceptance.'],
                        ['label' => 'Monitoring and Benefit Releases', 'text' => 'Follow requirements and release readiness in their own workspaces. A release schedule is not proof that the applicant received a benefit.'],
                    ],
                ],
                [
                    'title' => 'Coordinate without duplicating work', 'icon' => 'fa-solid fa-people-arrows',
                    'items' => [
                        ['label' => 'Use the responsible workspace', 'text' => 'Open the record before acting, check its current status, and leave a useful note when handing work to another staff member.'],
                        ['label' => 'Support and Platform Services', 'text' => 'Handle concerns separately from optional paid platform assistance. Neither replaces application or scholarship decisions.'],
                        ['label' => 'Reopen help when needed', 'text' => 'Each workspace has its own Workspace guide button. Those guides stay on demand here so moving between workspaces does not interrupt your work.'],
                    ],
                ],
            ],
        ];
    }

    public static function shouldOffer(User $user, string $workspace): bool
    {
        if ($workspace === 'manager') {
            return $user->isProvider() && ProviderWorkspace::preferredRouteName($user) === null;
        }
        // Managers use several workspaces; their guides stay available on demand.
        return $user->isManagedAccount()
            && strtolower(trim((string) $user->account_title)) !== 'manager'
            && match ($workspace) {
                'programs' => ProviderWorkspace::usesProgramCoordinatorWorkspace($user),
                'reviews' => ProviderWorkspace::usesApplicationReviewerWorkspace($user),
                'selection' => ProviderWorkspace::usesSelectionOfficerWorkspace($user),
                'decisions' => ProviderWorkspace::usesDecisionOfficerWorkspace($user),
                'recipients' => ProviderWorkspace::usesRecipientOfficerWorkspace($user),
                'monitoring' => ProviderWorkspace::usesMonitoringOfficerWorkspace($user),
                'releases' => ProviderWorkspace::usesBenefitReleaseOfficerWorkspace($user),
                'organization-profile' => ProviderWorkspace::usesOrganizationProfileManagerWorkspace($user),
                'team' => ProviderWorkspace::usesTeamAdministratorWorkspace($user),
                'support' => ProviderWorkspace::usesSupportStaffWorkspace($user),
                'billing' => ProviderWorkspace::usesBillingStaffWorkspace($user),
                default => false,
            };
    }
}
