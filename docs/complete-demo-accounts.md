# Complete Demo Accounts

The complete demo uses fictional identities and synthetic applicant portraits. Local tests use the fallback password only when no demo credential is configured. Hosted demonstrations require strong `DEMO_ADMIN_PASSWORD` and `DEMO_PASSWORD` values supplied only for the seed command.

## Main Accounts

| Role | Username | Email |
| --- | --- | --- |
| Super admin | `superadmin` | `superadmin@findscholarship.test` |
| Provider owner | `harborlight` | `programs@harborlight.test` |

## Provider Verification Accounts

| Demonstration | Username | Email |
| --- | --- | --- |
| Verification pending | `provider.pending` | `verification.pending@demo.test` |
| Verification returned for replacement | `provider.revision` | `verification.revision@demo.test` |

## Provider Team Accounts

| Workspace | Username | Email |
| --- | --- | --- |
| Full-access manager | `harborlight.manager` | `harborlight.manager@scholarship.test` |
| Program coordinator | `harborlight.programs` | `harborlight.programs@scholarship.test` |
| Application reviewer | `harborlight.reviewer` | `harborlight.reviewer@scholarship.test` |
| Selection officer | `harborlight.selection` | `harborlight.selection@scholarship.test` |
| Decision officer | `harborlight.decisions` | `harborlight.decisions@scholarship.test` |
| Recipient officer | `harborlight.recipients` | `harborlight.recipients@scholarship.test` |
| Monitoring officer | `harborlight.monitoring` | `harborlight.monitoring@scholarship.test` |
| Benefit release officer | `harborlight.releases` | `harborlight.releases@scholarship.test` |
| Organization profile manager | `harborlight.profile` | `harborlight.profile@scholarship.test` |
| Team administrator | `harborlight.team` | `harborlight.team@scholarship.test` |
| Support staff | `harborlight.support` | `harborlight.support@scholarship.test` |
| Billing staff | `harborlight.billing` | `harborlight.billing@scholarship.test` |

Program-scoped staff are assigned to the Harborlight Senior Scholars Program. The reviewer has two pre-screening records assigned, while the selection, decision, recipient, monitoring, release, support, and billing workspaces contain records for their respective demonstrations.

## Admin Team Accounts

| Workspace | Username | Email |
| --- | --- | --- |
| Account manager | `admin.accounts` | `admin.accounts@scholarship.test` |
| Review officer | `admin.reviews` | `admin.reviews@scholarship.test` |
| Support officer | `admin.support` | `admin.support@scholarship.test` |
| Billing officer | `admin.billing` | `admin.billing@scholarship.test` |
| Finance officer | `admin.finance` | `admin.finance@scholarship.test` |
| Records officer | `admin.records` | `admin.records@scholarship.test` |
| Portal manager | `admin.manager` | `admin.manager@scholarship.test` |

## Applicant Workflow Accounts

| Demonstration | Username | Email |
| --- | --- | --- |
| Pre-screening review | `alyssamaereyes` | `alyssa-maereyes@demo.test` |
| Correction requested | `joshuamiguelsantos` | `joshua-miguelsantos@demo.test` |
| Formal application | `biancalouisenavarro` | `bianca-louisenavarro@demo.test` |
| Exam scheduled | `markangelodelacruz` | `mark-angelodela-cruz@demo.test` |
| Exam completed, result needed | `carlojaviermendoza` | `carlo-javiermendoza@demo.test` |
| Interview scheduled | `sofiamarievillanueva` | `sofia-marievillanueva@demo.test` |
| Interview completed, result needed | `gabrielluisramos` | `gabriel-luisramos@demo.test` |
| Final decision needed | `trishaannegarcia` | `trisha-annegarcia@demo.test` |
| Waitlisted | `camilleroseflores` | `camille-roseflores@demo.test` |
| Selected, agreement needed | `danielpaoloaquino` | `daniel-paoloaquino@demo.test` |
| Active monitoring and review work | `nicoleandreabautista` | `nicole-andreabautista@demo.test` |
| Completed monitoring and support | `vincentrafaeltorres` | `vincent-rafaeltorres@demo.test` |
| Pre-screening rejected | `janinemaelopez` | `janine-maelopez@demo.test` |
| Exam not passed | `ethancolemartinez` | `ethan-colemartinez@demo.test` |
| Interview not passed | `patriciajoycastillo` | `patricia-joycastillo@demo.test` |
| Final decision: not selected | `luisgabrielherrera` | `luis-gabrielherrera@demo.test` |
| Application withdrawn | `andreafaithdomingo` | `andrea-faithdomingo@demo.test` |
| Recipient agreement declined | `miguelandresalazar` | `miguel-andresalazar@demo.test` |
| Extension pending and exception approved | `hannahgracevaldez` | `hannah-gracevaldez@demo.test` |
| Overdue monitoring and replacement needed | `johncarlomanalo` | `john-carlomanalo@demo.test` |
| Open benefit receipt issue | `mariaisabelortega` | `maria-isabelortega@demo.test` |
| Support ended early with reconsideration | `paolonathancruz` | `paolo-nathancruz@demo.test` |
| Scholarship support renewed | `clarissemaeevangelista` | `clarisse-maeevangelista@demo.test` |
| Full-capacity program recipient | `adrianjamespascual` | `adrian-jamespascual@demo.test` |

## Program Lifecycle Examples

| Program | State |
| --- | --- |
| Harborlight Senior Scholars Program | Published with the complete application and recipient lifecycle |
| Commute Forward Student Grant | Draft |
| Connected Classrooms Access Grant | Waiting for administrator review |
| Ready-to-Learn Essentials Fund 2025-2026 | Closed prior cycle |
| Saturday Learning Support Award | Returned for revision |
| College Launch One-Time Grant | Published and at its one-recipient capacity |

## Reset Command

Run only against a database intentionally prepared for the demonstration:

```bash
php artisan migrate:fresh --seeder=CompleteDemoSeeder --force
```

The seeder intentionally creates the super admin as user ID `1`, the primary provider as user ID `2`, the Harborlight Senior Scholars Program as ID `1`, and applications as IDs `1` through `24`.
