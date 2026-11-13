# Fresh Workflow Demonstration

The new fictional dataset is installed locally and on hosting. Use the demo credentials provided separately. All applicant profiles use the platform silhouette.

## How To Run The Demo

- Start a new application in **Talunaw Campus Pathways Grant**. This is an open intake and has no selection sessions.
- Use separate closed-intake rounds for screening, formal verification, exams, interviews, and final decisions. Choose the matching account below rather than moving a date or pre-recording a decision.
- Intake deadlines include the entire day in Manila time. Dates are relative to the day the dataset is built. Run `php artisan demo:audit` on a fresh candidate before a future switch.
- No failed or rejected outcomes are preloaded. Earlier successful steps are prerequisites; the current action belongs to the tester. Do not send real money or email for the demo.

## Applicant Accounts

| Login | Applicant | Starting action | Acting account |
|---|---|---|---|
| `demo.student.profile` | Alira Dawilang | Complete profile evidence | Applicant login |
| `demo.student.verification` | Brenel Dumayun | Review submitted profile evidence | `demoops.reviews` |
| `demo.student.apply` | Ciela Banlawi | Start a new application in the open round | Applicant login |
| `demo.student.screening` | Darel Kalimbas | Review application evidence and record screening result | `talunaw.reviewer` |
| `demo.student.formal` | Elani Talagsik | Record formal application verification | `talunaw.selection` |
| `demo.student.exam.assign` | Firan Magsilay | Create or assign an examination session | `talunaw.selection` |
| `demo.student.exam.confirm` | Galia Samilon | Acknowledge the published examination schedule | Applicant login |
| `demo.student.exam.complete` | Harel Salinaw | Complete the conducted exam session | `talunaw.selection` |
| `demo.student.exam.result` | Ivara Dalimpon | Record the examination result | `talunaw.selection` |
| `demo.student.interview.assign` | Jovin Rimayag | Assign an interview session | `talunaw.selection` |
| `demo.student.interview.confirm` | Kalea Kaluntog | Acknowledge the online interview schedule | Applicant login |
| `demo.student.interview.result` | Luvan Silambay | Record the interview result | `talunaw.selection` |
| `demo.student.decision` | Mavina Balungit | Record the final scholarship decision | `talunaw.decisions` |
| `demo.student.agreement` | Nilan Ibayun | Respond to the recipient agreement | Applicant login |
| `demo.student.release.plan` | Orani Sulatay | Schedule the first benefit release | `talunaw.releases` |
| `demo.student.monitoring.appointment` | Parel Dumilay | View the upcoming in-person check-in appointment | Applicant login |
| `demo.student.monitoring.review` | Quinara Balimpon | Record the first document result after the attended check-in | `talunaw.monitoring` |
| `demo.student.monitoring.close` | Revan Dahilaw | Close the fully reviewed check-in case | `talunaw.monitoring` |
| `demo.student.support.decision` | Sarila Labuntay | Record renewal or support completion | `talunaw.recipients` |
| `demo.student.release.record` | Tivan Salintog | Record scheduled in-person distribution | `talunaw.releases` |
| `demo.student.release.confirm` | Ulara Lagimbay | Confirm receipt of the released benefit | Applicant login |
| `demo.student.onetime.plan` | Vanel Dagimtay | Schedule a one-time award without recurring check-ins | `talunaw.releases` |
| `demo.student.onetime.record` | Winara Limangit | Record one-time award distribution | `talunaw.releases` |
| `demo.student.onetime.confirm` | Xavel Dawantog | Confirm the received one-time award | Applicant login |
| `demo.student.partner.screening` | Yalina Ilawig | Review a separate provider application | `sidlakbay.reviewer` |
| `demo.student.payout.details` | Zeran Simantay | Submit fictional bank payout details | Applicant login |
| `demo.student.payout.review` | Avelin Binalaw | Review submitted fictional e-wallet details | `talunaw.releases` |
| `demo.student.payout.transfer` | Biran Kalibay | Record transfer proof for an approved fictional payout destination | `talunaw.releases` |

## Additional Hosted Process Examples (November 13)

These are additive hosted examples for the current DSS and Scholarship Support workflows. Existing accounts, test decisions and applicant records were preserved. The local database was not replaced. Use the existing demo password `password123`; never enter real bank details or send real money.

| Applicant Login | Program / Next Action | Acting Account |
|---|---|---|
| `demo.process.dss.first` | Scholar Selection Round: highest evaluated candidate, final decision still pending | `talunaw.decisions` |
| `demo.process.dss.second` | Same round: second candidate; two award slots available | `talunaw.decisions` |
| `demo.process.dss.third` | Same round: third candidate; waitlist suggestion only, no outcome recorded | `talunaw.decisions` |
| `demo.process.dss.score` | Assessment Completion Round: enter missing interview evaluation before comparison | `talunaw.selection`, then `talunaw.decisions` |
| `demo.process.support.appointment` | Learning Continuity Grant: upcoming appointment to present original records | Applicant, then `talunaw.monitoring` |
| `demo.process.support.review` | Same grant: attendance recorded, provider checks original grades and enrollment | `talunaw.monitoring` |
| `demo.process.support.close` | Same grant: all required items met, close the check-in | `talunaw.monitoring` |
| `demo.process.support.decision` | Same grant: closed check-in and confirmed cash/mentoring benefits, ready for renewal or completion | `talunaw.recipients` |
| `demo.process.support.history` | Same grant: successful renewal history, recipient acknowledgment still available | Applicant |
| `demo.process.award.receipt` | Study Essentials Award: provider distribution recorded, confirm receipt | Applicant |
| `demo.process.award.complete` | Same one-time award: receipt confirmed, ready for support completion | `talunaw.recipients` |
| `demo.process.award.followup` | Same award: collection missed, reply to notice and arrange collection; award not cancelled | Applicant, then `talunaw.releases` |

For DSS, sign in as `talunaw.decisions`, open **Pending decisions**, and choose **Talunaw Scholar Selection Round**. Complete evaluations rank three candidates against two slots. Suggestions are advisory; the tester still makes every current final decision. The separate Assessment Completion Round deliberately has an unrecorded interview evaluation and does not treat it as zero.

For Scholarship Support, use **Talunaw Learning Continuity Grant** in the monitoring and recipient workspaces. The plan requires original academic and enrollment documents at a scheduled appointment, not applicant uploads. Reviewed prerequisites, accepted agreements, completed sessions, provider distribution proof and recipient confirmations are present where required. The Study Essentials Award has no recurring monitoring plan.

New programs are appended to the existing Talunaw staff program scopes. Sidlakbay and all previous program assignments are unchanged. The manager can inspect all examples using `talunaw.manager`.

## Provider Accounts

| Login | Role | Organization or purpose |
|---|---|---|
| `talunaw` | Organization Representative | Talunaw Learning Trust |
| `sidlakbay` | Organization Representative | Sidlakbay Education Guild |
| `hirayalon` | Organization Representative | Hirayalon Study Foundation (verification pending) |
| `talunaw.manager` | Provider Manager | Talunaw Learning Trust |
| `talunaw.programs` | Program Coordinator | Talunaw Learning Trust |
| `talunaw.reviewer` | Application Reviewer | Talunaw Learning Trust |
| `talunaw.selection` | Selection Officer | Talunaw Learning Trust |
| `talunaw.decisions` | Decision Officer | Talunaw Learning Trust |
| `talunaw.recipients` | Recipient Officer | Talunaw Learning Trust |
| `talunaw.monitoring` | Monitoring Officer | Talunaw Learning Trust |
| `talunaw.releases` | Benefit Release Officer | Talunaw Learning Trust |
| `talunaw.profile` | Organization Profile Manager | Talunaw Learning Trust |
| `talunaw.team` | Team Administrator | Talunaw Learning Trust |
| `talunaw.support` | Support Staff | Talunaw Learning Trust |
| `talunaw.billing` | Billing Staff | Talunaw Learning Trust |
| `talunaw.newstaff` | Support Staff | Talunaw Learning Trust (first-login password setup) |
| `sidlakbay.manager` | Provider Manager | Sidlakbay Education Guild |
| `sidlakbay.programs` | Program Coordinator | Sidlakbay Education Guild |
| `sidlakbay.reviewer` | Application Reviewer | Sidlakbay Education Guild |
| `sidlakbay.selection` | Selection Officer | Sidlakbay Education Guild |
| `sidlakbay.decisions` | Decision Officer | Sidlakbay Education Guild |
| `sidlakbay.recipients` | Recipient Officer | Sidlakbay Education Guild |
| `sidlakbay.monitoring` | Monitoring Officer | Sidlakbay Education Guild |
| `sidlakbay.releases` | Benefit Release Officer | Sidlakbay Education Guild |
| `sidlakbay.profile` | Organization Profile Manager | Sidlakbay Education Guild |
| `sidlakbay.team` | Team Administrator | Sidlakbay Education Guild |
| `sidlakbay.support` | Support Staff | Sidlakbay Education Guild |
| `sidlakbay.billing` | Billing Staff | Sidlakbay Education Guild |
| `sidlakbay.newstaff` | Support Staff | Sidlakbay Education Guild (first-login password setup) |

## Admin Accounts

| Login | Role |
|---|---|
| `demoops.accounts` | Account manager |
| `demoops.reviews` | Review officer |
| `demoops.support` | Support officer |
| `demoops.billing` | Billing officer |
| `demoops.finance` | Finance officer |
| `demoops.records` | Records officer |
| `demoops.manager` | Portal manager |
| `demoops.root` | Super admin |

## Hosted Admin Handoff Examples

The November 13 guide update adds four action-ready admin examples without changing existing accounts, applications, payment records or tester decisions. These additions are on hosting only.

| Record | Current State | Next Actor And Action |
|---|---|---|
| Check my sign-in settings, Iralen Dumaloy (`demo.process.support.appointment`) | Support has referred a limited access task | `demoops.accounts`: inspect the account, then Return result |
| Explain the account access check, Nivara Kalumay (`demo.process.support.review`) | Account Manager returned the task; platform response remains open | `demoops.support`: read the finding and send the reporter-facing response |
| `GUIDE-20261113-BILLING` | Internal record question is with Billing; paid service remains in progress | `demoops.billing`: Respond to Finance |
| `GUIDE-20261113-FINANCE` | Billing has returned its finding; Finance check is not yet recorded | `demoops.finance`: inspect the response and Record a financial check |

Returning an access task does not reset a password or verify a profile. Recording a financial check does not confirm a gateway payment, transfer scholarship funds, complete service delivery or issue a refund. Existing service examples still cover intake, assignment, delivery and provider acceptance. The expanded Word guide now includes the record-level handoffs and current hosted screenshots.

## Support And Payouts

- Hosted check-ins include saved venue pins. Open **View map** from the check-in record; monitoring officers can also open the group or recipient appointment map.
- Published programs now offer a semester **School supplies kit** instead of mentoring. The upcoming kit release uses **In person**, with a venue map and collection instructions. Previously delivered mentoring records remain unchanged. Open **Benefit releases**, then **View release** to read the provider instructions. Bank and e-wallet releases explain the payout-detail and receipt steps instead of showing a collection venue.
- `talunaw.monitoring` reviews attended in-person appointments. Applicants bring records; they do not upload documents for the seeded check-in. One-time awards have no recurring check-in.
- `demo.student.payout.details` starts with an empty bank destination. `talunaw.releases` can review the e-wallet details for `demo.student.payout.review`, or practice recording fictional proof for the approved destination of `demo.student.payout.transfer`.
- Provider-service payment examples are simulated. The release workflow records evidence and receipt; it does not send funds.
- The [accountability walkthrough](recipient-accountability-demo.md) covers concerns, missing benefits, appeals, and privacy. These queues start empty until the tester creates a case.
- The expanded Word guide under `deliverables` has the process screenshots and record-level steps. Demonstrate provider management, representative, portal management, and super admin views after the specialist workflows.
