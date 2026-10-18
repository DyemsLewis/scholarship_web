# Role Dashboard Foundation

Role dashboards use a shared presentation contract without sharing role-specific queries or records.

## Information limits

- Up to four summary metrics.
- Up to three full-width sections.
- Up to five records in each section.
- One primary header action.
- No dashboard duplicates a complete queue or directory.

## Route migration

Each current workspace root remains unchanged until its dashboard is complete. When a role dashboard is introduced, the root becomes the dashboard and the existing operational view uses its already available focused path:

| Workspace | Dashboard root | Existing focused work path |
| --- | --- | --- |
| Application review | `/provider/workspaces/reviews` | `/provider/workspaces/reviews/assigned` |
| Selection | `/provider/workspaces/selection` | `/provider/workspaces/selection/active` |
| Decisions | `/provider/workspaces/decisions` | `/provider/workspaces/decisions/pending` |
| Recipients | `/provider/workspaces/recipients` | `/provider/workspaces/recipients/agreements` |
| Monitoring | `/provider/workspaces/monitoring` | `/provider/workspaces/monitoring/review` |
| Benefit releases | `/provider/workspaces/releases` | `/provider/workspaces/releases/issues` |
| Organization profile | `/provider/workspaces/organization-profile` | `/provider/workspaces/organization-profile/readiness` |
| Team access | `/provider/workspaces/team` | `/provider/workspaces/team/setup` |
| Support | `/provider/workspaces/support` | `/provider/workspaces/support/needs-response` |
| Billing | `/provider/workspaces/billing` | `/provider/workspaces/billing/action` |

Old bookmarks remain valid because focused work paths are introduced before a workspace root changes behavior.

## Backend rules

Every provider dashboard query uses `ProviderWorkspaceScope`. It limits programs and applications to the provider organization and, for managed accounts, to assigned program IDs. A dashboard query may not return an organization-wide count when the actor only has selected-program access.

Every role gets a separate query class and data endpoint. `RoleDashboardPayload` only normalizes the shared shape and enforces information limits; it does not combine permissions or business logic between roles.
