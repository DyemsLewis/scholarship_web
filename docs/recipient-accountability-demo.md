# Recipient Accountability Demo

This guide covers agreement concerns, missing benefits, independent reviews,
appeals, and privacy safeguards. It supplements `fresh-workflow-demo.md`.

## Starting Accounts

Use the existing demo password, `password123`. These credentials are deliberately
unchanged for the demonstration; they are not suitable for a real production launch.

| Login | Use |
|---|---|
| `learner.support.decision` | Sabina Ocampo: accepted scholarship with reviewable obligations |
| `marilag.recipients` | Recipient officer: prepare and issue agreement concerns |
| `learner.release.confirm` | Yasmin Alcantara: accepted award with available benefit-delivery options |
| `marilag.releases` | Release officer: investigate delivery, propose corrections, and upload proof |
| `platform.reviews` | First independent admin case reviewer |
| `platform.manager` | Different authorized admin for an appeal of the first reviewer's outcome |
| `marilag.manager` | Demonstrate access to both originating-provider workspaces |
| `silayan.recipients` | Demonstrate that another organization cannot access Marilag cases |

No concern, adverse finding, support termination, appeal, or privacy assessment was
seeded by deployment. Empty case queues are intentional. Testers create the facts,
send notices, respond, and make decisions themselves. Existing applications,
agreements, benefit records, accounts, and passwords were preserved, not reset.

## Agreement Concern

1. Sign in as `marilag.recipients`. Open the accepted recipient record for Sabina
   Ocampo under Recipient management, then the support section and **Agreement concerns**.
2. Select **New concern**. Choose an actual accepted obligation, enter a subject
   and factual explanation, and continue to save a private draft. The applicant
   and another organization cannot see this draft.
3. Add a clearly labelled fictional supporting PDF/JPG/PNG. Continue to the notice
   step, review the response deadline, acknowledge accuracy, and send the notice.
   This does not establish a breach or change scholarship support.
4. Sign in as `learner.support.decision`. Open the application's Decision/outcome
   page and the concern. Read the accepted obligation and proof, then submit an
   explanation, correction, or circumstances with optional supporting evidence.
5. As `marilag.recipients`, reopen the issued concern and select **Case review and
   appeals**, then **Request review**. Before a response arrives, the provider must
   wait for the response opportunity to expire; silence is not proof.
6. As `platform.reviews`, open **Case reviews**, open the case, and review the
   accepted source, statements, and proof. Request more information if needed, or
   explicitly choose a finding/recommendation, cite proof, and explain the reasons.
7. Open **Privacy & access**. External sharing remains disabled. An internal
   assessment can document reasons, a recheck, and an access-log retention hold;
   it cannot authorize disclosure, penalize the applicant, or change support.
8. As the applicant, read the reasoned outcome and submit an appeal if desired.
   The original record remains available. Its prior privacy assessment becomes
   outdated automatically; no external warning is left active.
9. Sign in as `platform.manager`, not the officer who made the original outcome,
   to review the appeal. The original reviewer cannot decide their own appeal.

A confirmed finding is not an automatic support termination. A term-related early
termination requires a separate provider decision, supported accepted consequences,
and the expired 14-day appeal opportunity with no pending appeal. Do not change the
system clock or backdate real records to bypass this for a demonstration. Isolated
automated tests cover termination and reinstatement without altering demo awards.

## Missing or Incomplete Benefit

1. Sign in as `learner.release.confirm`. Open Monitoring, the accepted scholarship,
   and **Benefits / Benefit releases**, then **Report a benefit issue**.
2. Select the relevant agreed benefit or published release. Choose the issue and
   describe what happened. For an unscheduled recurring benefit, name its period.
   A future release cannot be reported as already late; ask about delivery instead.
3. Submit the report. It is not an adverse finding against the applicant or provider.
4. As `marilag.releases`, open **Delivery reports**. Give a response, request more
   information, or propose a correction. An actual delivery proposal needs a linked
   distribution record, the delivery date, and private supporting proof.
5. The applicant reviews the proposal. Confirm actual receipt or explain that the
   issue remains unresolved. A provider response/proof alone cannot confirm receipt.
6. Either authorized party may request an independent review. A delivery review can
   recommend follow-up but cannot penalize the applicant or replace their receipt
   confirmation. Even an admin finding does not mark the benefit as received.

One-time benefits use the same delivery safeguards without requiring recurring
academic monitoring. Do not create a second benefit merely to resolve the first.

## Access and Privacy Checks

- Recipient-only officers cannot perform benefit-release work; release-only officers
  cannot issue agreement concerns. The provider manager can access both workspaces.
- Another applicant or provider cannot read the case or its proof. No public profile
  marker, blacklist, application rejection, or eligibility/DSS penalty is created.
- Applicant/originating-provider access history shows authorized team-level entries.
  Authorized admins can see blocked attempts and account identities. Detailed internal
  privacy-assessment notes are admin-only.
- Repeated reads may be grouped within five minutes. Access metadata normally expires
  after 90 days, unless explicitly held. A case change supersedes a privacy assessment
  but does not release its access-log retention hold.
- Cross-provider sharing is disabled, including after a confirmed finding or an internal
  privacy assessment. Future activation requires separate reviewed policy and development.

Live smoke tests open forms without submitting business actions. All mutations in the
integrated regression tests run against an isolated test database with private fake
storage. They do not make financial transfers or prove external email delivery.
