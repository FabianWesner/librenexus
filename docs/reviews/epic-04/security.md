# Review Report - Security - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f`
- **Requirements/rules in scope:** SEC-TENANT-1..4 (highest priority), SEC-AUTHZ-1..3, SEC-INPUT-1, QG-SECRETS/SAST/DEPS-VULN

## Files reviewed

- `app/Concerns/BelongsToTenant.php`, `app/Models/Scopes/TenantScope.php` - fail-closed scope + spoof rejection (unchanged, reused by Staff/Service)
- `app/Http/Middleware/EnsureTeamMembership.php` - 404 for non-members, sets `CurrentTenant`
- `app/Providers/AppServiceProvider.php:34-36` - `Livewire::addPersistentMiddleware([EnsureTeamMembership::class])`
- `app/Policies/StaffPolicy.php`, `app/Policies/ServicePolicy.php` - view = member, manage = owner/admin
- `resources/views/pages/staff/⚡index.blade.php` - `Gate::authorize` in `mount`, `openCreateForm`, `editStaff`, `saveStaff`, `deactivateStaff`, `reactivateStaff`; `#[Locked]` on `team` and `staffId`; membership/services existence rules scoped to the team; self-link denial
- `resources/views/pages/services/⚡index.blade.php` - same pattern for all six actions
- `database/migrations/..._create_staff_table.php` - UNIQUE on `membership_id` (DB backstop for the ≤1-link rule)

## Flows reviewed

- Every Livewire action on both pages: each one re-authorizes before touching data (SEC-AUTHZ-1); record lookups go through the tenant-scoped query, so foreign ids raise `ModelNotFoundException` (404), matching the documented denial choice
- IDOR probes: foreign staff/service ids (cross-tenant), foreign membership ids, already-linked membership ids, own membership id - all rejected (tests cited below)
- Livewire update-request path: with the persistent middleware registered, `EnsureTeamMembership` runs on every update request (verified with a log probe during a real browser form submission: 1 page GET + 2 update requests, 3 middleware executions). Snapshot integrity is checksum-protected, so the replayed path cannot be forged
- Removal experiment: with `addPersistentMiddleware` commented out, `CurrentTenant` stays null on production-style update requests; the system fails closed (creates throw, scoped reads return nothing), so no cross-tenant exposure exists even then. See F1 for the test-gap consequence

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php::staff and services isolation (Epic 04)` - 404 on foreign pages, forbidden mount, `ModelNotFoundException` on foreign staff/service mutation attempts, cross-tenant membership link rejected, cross-tenant list bleed asserted absent
- `tests/Feature/Staff/StaffManagementTest.php` - staff-role member forbidden on all five mutating actions (fresh component per call); self-link and double-link denied; foreign-team membership denied
- `tests/Feature/Services/ServiceManagementTest.php` - staff-role member forbidden on all five mutating actions
- `tests/Unit/TenantScopingTest.php` - structural opt-in enforcement (SEC-TENANT-3)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` (incl. named isolation suite) | pass | 257/257; isolation suite extended for Staff + Service and green |
| `make security` (secrets, sast, audit, osv) | pass | gitleaks clean, Semgrep 46 rules / 0 findings, composer + npm audit 0 vulns, OSV 0 issues |
| `make mutation` | pass | 100% on `TenantScope`, `BelongsToTenant`, `CurrentTenant` (19 mutations) |
| removal experiment + log probe | done | persistent middleware proven live on update requests; reverted after review |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation (SEC-TENANT) | ✅ | Isolation suite extended and run (see above); fail-closed scope mutation-tested at 100%; 404 denial consistent with the documented choice |
| 2 | Authorization (SEC-AUTHZ) | ✅ | Every action gate-checked server-side; staff role is view-only (proven per action); IDOR tests for staff, service, and membership ids |
| 3 | Authentication (SEC-AUTH) | ✅ | Untouched this epic; auth suites green in `make test` |
| 4 | Input & injection (SEC-INPUT) | ✅ | All writes validated server-side (incl. `Rule::exists(...)->where('team_id', ...)`); no `{!! !!}`; mass assignment via `#[Fillable]` with the spoof guard on `team_id`; `#[Locked]` on identity props |
| 5 | Customer tokens (SEC-TOKEN) | n/a | No tokens in this epic |
| 6 | Secrets (SEC-SECRETS) | ✅ | gitleaks clean (`make security`) |
| 7 | Dependencies (SEC-DEPS) | ✅ | composer/npm audit + OSV clean |
| 8 | SAST | ✅ | Semgrep 0 findings, no `nosemgrep` added |
| 9 | Headers & transport | ✅ | Unchanged; `SecurityHeadersTest` green in suite. CSP `unsafe-inline` remains the tracked Epic 00 deferral |
| 10 | Sessions & CSRF | ✅ | Livewire update endpoint keeps CSRF (framework); no changes |
| 11 | Rate limiting | n/a | No new public endpoints |
| 12 | Logging & errors | ✅ | No new logging of PII/secrets; cross-tenant probes return generic 404 |
| 13 | Uploads | n/a | None |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | SEC-TENANT (regression protection) | `AppServiceProvider.php:34` | The persistent-middleware registration is load-bearing for tenant context on Livewire update requests, but no automated test detects its removal: feature tests set `CurrentTenant` manually, and the Pest browser server shares one container across requests, so the context set by the page GET leaks into update requests (proven: all 62 related tests pass with the registration removed). Failure mode is fail-closed (no leak), so this is hardening, not a hole | Add a regression test asserting `EnsureTeamMembership` is in `app(PersistentMiddleware::class)->getPersistentMiddleware()`; track the browser-server container-leak limitation |
| F2 | Low | SEC-TENANT-2 | staff link validation | Same-team consistency of `membership_id` is enforced only by the validation rule (plus the global UNIQUE); the DB does not force `staff.team_id == team_members.team_id` | Acceptable for the admin-only path; note for Epic 10 hardening |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: SEC-TENANT and SEC-AUTHZ hold with strong test and mutation evidence; the one Medium item is missing regression protection for a defense that currently works (verified live), not a vulnerability.
- Blocking findings remaining: 0
