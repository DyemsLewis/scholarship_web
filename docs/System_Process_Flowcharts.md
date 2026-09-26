# Scholarship Platform Process Flowcharts

These flowcharts describe the processes currently implemented in the Scholarship Portal. Matching and decision-support results guide reviewers; they do not replace provider decisions or in-person verification of original documents.

## 1. Overall Platform Flow

```mermaid
flowchart LR
    A([User enters platform]) --> B{Account type}
    B -->|Applicant| C[Verify email and complete profile]
    B -->|Provider| D[Verify email and submit organization evidence]
    B -->|Administrator| E[Use assigned role and permissions]

    D --> F[Admin reviews provider]
    F --> G{Provider approved?}
    G -->|Changes needed| D
    G -->|Yes| H[Create scholarship draft]
    H --> I[Admin reviews scholarship]
    I --> J{Program approved?}
    J -->|Revise| H
    J -->|Yes| K[Publish scholarship]

    C --> L[Find, save, and compare scholarships]
    K --> L
    L --> M[Profile matching and eligibility guidance]
    M --> N{Required blockers present?}
    N -->|Yes| O[Update profile or choose another program]
    O --> L
    N -->|No| P[Submit pre-screening application]

    P --> Q[Provider reviews profile, conditions, and files]
    Q --> R{Pass current stage?}
    R -->|Correction needed| S[Applicant corrects information or files]
    S --> Q
    R -->|No| T([Application closed])
    R -->|Yes| U[Complete configured formal application, exam, or interview stages]
    U --> V{Final decision}
    V -->|Not selected| T
    V -->|Waitlisted| W[Remain on waitlist]
    W -->|Promoted| X[Selected applicant]
    W -->|Closed| T
    V -->|Selected| X

    X --> Y[Applicant reviews recipient agreement]
    Y --> Z{Agreement accepted?}
    Z -->|No| AA[Provider reviews declined response]
    Z -->|Yes| AB[Benefit release and recipient monitoring]
    AB --> AC{Support decision}
    AC -->|Renew| AB
    AC -->|Complete| AD([Scholarship support completed])
    AC -->|End early| AE([Scholarship support terminated])

    E -. oversees .-> F
    E -. oversees .-> I
    E -. reviews evidence .-> AB
```

## 2. Provider and Program Lifecycle

```mermaid
flowchart TB
    A([Provider registration]) --> B[Verify email]
    B --> C[Complete organization and representative profile]
    C --> D[Upload provider verification evidence]
    D --> E[Submit for admin review]
    E --> F{Admin decision}
    F -->|Changes requested| G[Update profile or replace evidence]
    G --> E
    F -->|Rejected| H([Provider cannot publish])
    F -->|Approved| I[Provider workspace activated]

    I --> J[Create scholarship draft]
    J --> K[Complete program setup]
    K --> K1[Basics, benefits and dates]
    K1 --> K2[Eligible applicants and matching restrictions]
    K2 --> K3[Application files and questions]
    K3 --> K4[Selection flow and formal handoff]
    K4 --> K5[Public contact, terms, and review]
    K5 --> L[Submit program for admin review]

    L --> M{Admin program decision}
    M -->|Changes requested| N[Revise draft]
    N --> L
    M -->|Rejected| O[Keep unpublished or revise]
    O --> N
    M -->|Approved| P[Publish program]

    P --> Q[Accept applications until deadline]
    Q --> R[Review applicants and configured stages]
    R --> S[Record final decisions]
    S --> T[Manage selected recipients and waitlist]
    T --> U[Schedule benefit releases]
    U --> V[Monitor recipient requirements]
    V --> W{Program support finished?}
    W -->|No| U
    W -->|Yes| X([Close or renew support])

    I --> Y[Invite team members]
    Y --> Z[Team member verifies email and changes password]
    Z --> ZA[Access limited by assigned role]
```

## 3. Applicant Application and Selection Flow

```mermaid
flowchart TB
    A([Applicant registration]) --> B[Verify email]
    B --> C[Complete personal, household, guardian, education, goals, and location profile]
    C --> D[Upload supporting profile evidence]
    D --> E[Admin may verify profile authenticity]
    C --> F[Browse approved scholarships]
    F --> G[Save or compare eligible programs]
    G --> H[Review benefits, deadline, requirements, terms, and provider purpose]

    H --> I[System compares saved profile with structured restrictions]
    I --> J{Blocking eligibility difference?}
    J -->|Yes| K[Explain the difference]
    K --> L[Update profile or choose another scholarship]
    L --> F
    J -->|No| M[Start pre-screening]

    M --> N[Confirm required files and answer program questions]
    N --> O[Accept application terms]
    O --> P[Submit application snapshot]
    P --> Q[Provider reviews profile, eligibility guidance, written conditions, and evidence]

    Q --> R{Pre-screening result}
    R -->|Correction requested| S[Applicant updates requested data or files]
    S --> Q
    R -->|Not passed| T([Application closed])
    R -->|Passed| U[Formal application handoff]

    U --> V{Configured provider stages}
    V -->|Exam included| W[Provider publishes exam schedule and records result]
    V -->|Interview included| X[Provider publishes interview schedule and records result]
    V -->|No activity| Y[Proceed to final decision]
    W -->|Passed| X
    W -->|Not passed| T
    X -->|Passed| Y
    X -->|Not passed| T

    Y --> Z{Final provider decision}
    Z -->|Selected| AA[Review recipient agreement]
    Z -->|Waitlisted| AB[Wait for promotion or closure]
    Z -->|Not selected| T
    AB -->|Promoted| AA
    AA --> AC{Accept terms?}
    AC -->|No| AD[Provider reviews applicant note]
    AC -->|Yes| AE([Enter recipient monitoring])
```

## 4. Benefit Release and Recipient Monitoring

```mermaid
flowchart TB
    A([Selected applicant]) --> B[Recipient agreement created from selection-time program terms]
    B --> C{Applicant response}
    C -->|Decline| D[Provider reviews reason and decides next action]
    C -->|Accept| E[Provider creates benefit release schedule]

    E --> F[Applicant sees date, method, location, and instructions]
    F --> G[Provider verifies originals when required]
    G --> H[Provider records released, prepared, missed, or withheld status]
    H --> I[Provider uploads release or receipt evidence]
    I --> J[Admin can review benefit evidence and fairness records]

    H --> K[Provider opens academic monitoring cycle]
    K --> L[Applicant uploads grade record]
    L --> M{OCR extracts a usable result?}
    M -->|Yes| N[Show extracted grade for review]
    M -->|No| O[Applicant enters result manually and keeps original ready]
    N --> P[Provider compares record with ongoing grade requirement]
    O --> P

    P --> Q{Provider review}
    Q -->|Correction needed| R[Applicant replaces file or corrects result]
    R --> L
    Q -->|Requirement met| S[Mark monitoring requirement complete]
    Q -->|Requirement not met| T[Review circumstances and provider terms]

    S --> U{Next support action}
    T --> U
    U -->|Next release| E
    U -->|Renew support| K
    U -->|Complete program| V([Support completed])
    U -->|End support early| W([Support terminated with reason])
```

## 5. Administrator, Reports, Finance, and Provider Services

```mermaid
flowchart LR
    A([Administrator signs in]) --> B{Assigned permission}
    B -->|Reviews| C[Provider, program, applicant-proof, and benefit-evidence queues]
    B -->|Accounts| D[Create, activate, suspend, and assign roles]
    B -->|Reports| E[Review applicant or provider issue reports]
    B -->|Finance| F[View service payments, receipts, totals, and transaction records]
    B -->|Logs| G[Review activity and audit records]

    C --> H{Review decision}
    H -->|Approve| I[Allow the next controlled process]
    H -->|Request changes| J[Return record with review note]
    J --> C
    H -->|Reject| K[Keep record restricted or unpublished]

    L([Provider needs optional assistance]) --> M[Choose a one-time service]
    M --> N[Describe requested support]
    N --> O[Create service request]
    O --> P[Complete payment when required]
    P --> Q[Admin confirms and assigns request]
    Q --> R[Provider and support team agree on meeting schedule]
    R --> S[Use focused service workspace]
    S --> T[Upload deliverable or record completion]
    T --> U([Request completed])

    E --> V[Track response and resolution]
    F --> W[Keep receipt and transaction audit trail]
    G --> X[Support accountability and investigation]
```

## Diagram Notes

- Profile matching is guidance. Required structured blockers control whether pre-screening can start, while written provider conditions are confirmed during review.
- Exam and interview stages are optional and depend on the program's configured selection flow.
- Online files support initial checking. Providers may still require original documents for complete verification.
- The application deadline stops new applications; already submitted applications continue through review and selection.
- Services are optional one-time provider support requests, not subscriptions and not part of applicant selection.
- Recipient monitoring begins only after selection and acceptance of the recipient agreement.
