# Complete Demo Accounts

The complete demo uses fictional identities and synthetic applicant portraits. Every account uses `password123` unless `DEMO_PASSWORD` is set before seeding.

## Main Accounts

| Role | Username | Email |
| --- | --- | --- |
| Super admin | `superadmin` | `admin@findscholarship.test` |
| Provider owner | `tulayaral` | `programs@tulayaral.test` |

## Provider Verification Accounts

| Demonstration | Username | Email |
| --- | --- | --- |
| Verification pending | `provider.pending` | `verification.pending@demo.test` |
| Verification returned for replacement | `provider.revision` | `verification.revision@demo.test` |

## Provider Team Accounts

| Workspace | Username | Email |
| --- | --- | --- |
| Full-access manager | `tulay.manager` | `tulay.manager@scholarship.test` |
| Program coordinator | `tulay.programs` | `tulay.programs@scholarship.test` |
| Application reviewer | `tulay.reviewer` | `tulay.reviewer@scholarship.test` |
| Selection officer | `tulay.selection` | `tulay.selection@scholarship.test` |
| Decision officer | `tulay.decisions` | `tulay.decisions@scholarship.test` |
| Recipient officer | `tulay.recipients` | `tulay.recipients@scholarship.test` |
| Monitoring officer | `tulay.monitoring` | `tulay.monitoring@scholarship.test` |
| Benefit release officer | `tulay.releases` | `tulay.releases@scholarship.test` |
| Organization profile manager | `tulay.profile` | `tulay.profile@scholarship.test` |
| Team administrator | `tulay.team` | `tulay.team@scholarship.test` |
| Support staff | `tulay.support` | `tulay.support@scholarship.test` |
| Billing staff | `tulay.billing` | `tulay.billing@scholarship.test` |

Program-scoped staff are assigned to the Complete Path Scholarship. The reviewer has two pre-screening records assigned, while the selection, decision, recipient, monitoring, release, support, and billing workspaces contain records for their respective demonstrations.

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
| Tulay Aral Complete Path Scholarship | Published with the complete application and recipient lifecycle |
| Tulay Aral Transportation Starter Grant | Draft |
| Tulay Aral Community Technology Access Grant | Waiting for administrator review |
| Tulay Aral School Essentials Grant 2025-2026 | Closed prior cycle |
| Tulay Aral Weekend Learning Hub Grant | Returned for revision |
| Tulay Aral One-Time College Readiness Award | Published and at its one-recipient capacity |

## Reset Command

Run only against a disposable local database:

```bash
php artisan migrate:fresh --seeder=CompleteDemoSeeder --force
```

The seeder intentionally creates the super admin as user ID `1`, the primary provider as user ID `2`, the complete-path scholarship as ID `1`, and applications as IDs `1` through `24`.
