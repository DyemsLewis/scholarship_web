# Fresh Workflow Demonstration

All organizations, people, documents, and payment records are fictional. Applicant photos are neutral silhouettes.

Demo password: `password123` (unless explicitly overridden during seeding). Change shared credentials after the demonstration.

## Demonstration Order

Each account stops at a tester-controlled next action. Earlier successful decisions are prerequisites, not decisions made for the tester at the current stage.

| Applicant Login | Name | Next Action | Acting Account |
|---|---|---|---|
| `learner.profile` | Arielle Mercado | Complete profile evidence | `applicant` |
| `learner.verification` | Benedict Salcedo | Review submitted profile evidence | `platform.reviews` |
| `learner.apply` | Cassandra Miraflor | Start a new application | `applicant` |
| `learner.screening` | Dario Valmores | Review application evidence and record screening result | `marilag.reviewer` |
| `learner.formal` | Estella Sandoval | Record formal application verification | `marilag.selection` |
| `learner.exam.assign` | Felix Talavera | Create or assign an examination session | `marilag.selection` |
| `learner.exam.confirm` | Giselle Maceda | Acknowledge the published examination schedule | `applicant` |
| `learner.exam.complete` | Hugo Candelaria | Complete the conducted exam session | `marilag.selection` |
| `learner.exam.result` | Isabel Montano | Record the examination result | `marilag.selection` |
| `learner.interview.assign` | Joaquin Fajardo | Assign an interview session | `marilag.selection` |
| `learner.interview.confirm` | Kiara Natividad | Acknowledge the online interview schedule | `applicant` |
| `learner.interview.result` | Leandro Villacorta | Record the interview result | `marilag.selection` |
| `learner.decision` | Maelle Esquivel | Record the final scholarship decision | `marilag.decisions` |
| `learner.agreement` | Nestor Ledesma | Respond to the recipient agreement | `applicant` |
| `learner.release.plan` | Odessa Balmoria | Schedule the first benefit release | `marilag.releases` |
| `learner.monitoring.upload` | Paolo Manansala | Submit the current check-in documents | `applicant` |
| `learner.monitoring.review` | Querida Tolentino | Review submitted check-in evidence | `marilag.monitoring` |
| `learner.monitoring.close` | Rafael Vergara | Close the fully reviewed monitoring case | `marilag.monitoring` |
| `learner.support.decision` | Sabina Ocampo | Record renewal or support completion | `marilag.recipients` |
| `learner.release.record` | Tomas Evangelista | Record scheduled benefit distribution | `marilag.releases` |
| `learner.release.confirm` | Yasmin Alcantara | Confirm receipt of the released benefit | `applicant` |
| `learner.onetime.plan` | Xavier De Vera | Schedule a one-time award without recurring monitoring | `marilag.releases` |
| `learner.onetime.record` | Zenaida Medel | Record one-time award distribution | `marilag.releases` |
| `learner.onetime.confirm` | Warren Suico | Confirm the received one-time award | `applicant` |
| `learner.partner.screening` | Victoria Pineda | Review a separate provider application | `silayan.reviewer` |

## Provider And Admin Accounts

| Login | Role | Organization / Purpose |
|---|---|---|
| `superadmin` | Super admin | Platform administration |
| `platform.accounts` | Account manager | Platform administration |
| `platform.reviews` | Review officer | Platform administration |
| `platform.support` | Support officer | Platform administration |
| `platform.billing` | Billing officer | Platform administration |
| `platform.finance` | Finance officer | Platform administration |
| `platform.records` | Records officer | Platform administration |
| `platform.manager` | Portal manager | Platform administration |
| `marilag` | Organization Representative | Marilag Learning Collective |
| `silayan` | Organization Representative | Silayan Education Circle |
| `dalisay` | Organization Representative | Dalisay Learning Association - organization evidence awaits admin approval |
| `marilag.manager` | Provider Manager | Marilag Learning Collective |
| `marilag.programs` | Program Coordinator | Marilag Learning Collective |
| `marilag.reviewer` | Application Reviewer | Marilag Learning Collective |
| `marilag.selection` | Selection Officer | Marilag Learning Collective |
| `marilag.decisions` | Decision Officer | Marilag Learning Collective |
| `marilag.recipients` | Recipient Officer | Marilag Learning Collective |
| `marilag.monitoring` | Monitoring Officer | Marilag Learning Collective |
| `marilag.releases` | Benefit Release Officer | Marilag Learning Collective |
| `marilag.profile` | Organization Profile Manager | Marilag Learning Collective |
| `marilag.team` | Team Administrator | Marilag Learning Collective |
| `marilag.support` | Support Staff | Marilag Learning Collective |
| `marilag.billing` | Billing Staff | Marilag Learning Collective |
| `marilag.newstaff` | Support Staff | Marilag Learning Collective - first-login password setup |
| `silayan.manager` | Provider Manager | Silayan Education Circle |
| `silayan.programs` | Program Coordinator | Silayan Education Circle |
| `silayan.reviewer` | Application Reviewer | Silayan Education Circle |
| `silayan.selection` | Selection Officer | Silayan Education Circle |
| `silayan.decisions` | Decision Officer | Silayan Education Circle |
| `silayan.recipients` | Recipient Officer | Silayan Education Circle |
| `silayan.monitoring` | Monitoring Officer | Silayan Education Circle |
| `silayan.releases` | Benefit Release Officer | Silayan Education Circle |
| `silayan.profile` | Organization Profile Manager | Silayan Education Circle |
| `silayan.team` | Team Administrator | Silayan Education Circle |
| `silayan.support` | Support Staff | Silayan Education Circle |
| `silayan.billing` | Billing Staff | Silayan Education Circle |
| `silayan.newstaff` | Support Staff | Silayan Education Circle - first-login password setup |

## Additional Work Queues

- `platform.reviews`: applicant profile, provider organization, and scholarship publication reviews.
- `platform.accounts`: managed accounts, first-login setup, and account controls.
- `marilag.programs`: a complete draft and a program awaiting publication review.
- `marilag.selection`: unassigned activities, schedule acknowledgements, session completion, and result recording.
- `marilag.monitoring`: pending uploads, submitted evidence, and a ready-to-close case.
- `marilag.recipients`: agreement tracking and the support renewal/completion decision.
- `marilag.releases`: first release setup, distribution recording, receipt confirmation, and a mentoring announcement.
- `marilag.support` and `platform.support`: open questions and a privacy information request.
- `marilag.billing` and `platform.billing`: service intake, unpaid checkout, assignment, delivery, meeting review, and provider acceptance.
- `platform.finance`: clearly labelled simulated paid-service records; no real payments were made.
- `platform.records`: fresh audit history and exports. `platform.manager` and `superadmin`: platform oversight.
- `silayan.*`: separate organization roles and a separate application review queue.

One-time awards have monitoring policy None. Recurring support has a plan, checklist, and non-overdue check-in.
No failed, rejected, withdrawn, terminated, or waitlisted scenario is seeded. Testers may create those outcomes themselves.
New staff logins ending in `.newstaff` intentionally open first-login password setup.

Registration can also be demonstrated from scratch; no demo data is needed to register a new account.
