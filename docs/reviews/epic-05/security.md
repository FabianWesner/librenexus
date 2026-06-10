# Review Report - Security - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** SEC-TENANT, SEC-AUTHZ, SEC-INPUT, SEC-SECRETS, SEC-DEPS, SAST; SEC-AUTH/SEC-TOKEN/SEC-HEADERS/SEC-SESSION/SEC-RATE unchanged by this epic

## Files reviewed

- `resources/views/pages/staff/⚡availability.blade.php` - the only new attack surface: mount binding, four state-changing actions, validation
- `app/Policies/StaffPolicy.php::manageAvailability` - role matrix (owner/admin all staff; staff role only the record linked to their own membership)
- `app/Models/AvailabilityRule.php`, `app/Models/TimeOff.php` - TenantModel + explicit `#[Fillable]` lists (mass assignment)
- `app/Models/TenantModel.php` + `app/Concerns/BelongsToTenant` usage - fail-closed scoping
- `tests/Feature/Tenancy/IsolationTest.php:315-373` - the Epic 05 isolation block
- `tests/Feature/Availability/AvailabilityManagementTest.php` - authorization + validation evidence

## Flows reviewed

- Cross-tenant access to the availability route: under tenant B's slug membership fails first (404); under tenant A's own slug the `{staff}` id of tenant B resolves through the tenant-scoped query in `mount()` and 404s - both directions asserted
- The Livewire binding-order seam: `{staff}` resolved in `mount()` after `EnsureTeamMembership` (page lines 41-54), with the persistent-middleware structural regression test from the Epic 04 review follow-up present (IsolationTest.php:375-380) - the seam flagged in the Epic 04 security/QA reviews is now guarded
- Component mutation by a non-member: with tenant A context the foreign record is invisible (ModelNotFoundException -> 404); with forced tenant B context the policy still denies (403, defense in depth); zero rows written either way
- Within-tenant IDOR: `removeRule`/`removeTimeOff` use `findOrFail` on the authorized staff member's relations, so a staff-role user cannot delete another staff member's rows by guessing ids; every action re-runs `Gate::authorize('manageAvailability', ...)`
- Locked state: `$team` and `$staffMember` are `#[Locked]`, so the authorized subject cannot be swapped between requests

## Tests reviewed

- `IsolationTest::a member of tenant A gets a 404 on the availability route of tenant B staff` - both 404 directions (SEC-TENANT)
- `IsolationTest::a member of tenant A cannot add rules or time off to tenant B staff via the component` - mutation attempt, asserts zero rows via `withoutGlobalScopes()`
- `IsolationTest::tenant B availability rules and time off never leak into tenant A queries` - query-level leak check
- `AvailabilityManagementTest::a staff-role member cannot manage the availability of another staff member` - 403 on GET and on the component, zero rows
- `AvailabilityManagementTest` validation cases - malformed time, weekday 8, inverted ranges all rejected server-side

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` (incl. isolation suite) | pass | 313/313; Epic 05 isolation block green |
| `make secrets` | pass | gitleaks: no leaks (12.03 MB scanned) |
| `make sast` | pass | Semgrep p/php + p/security-audit: 46 rules, 323 files, 0 findings, no `nosemgrep` |
| `make audit` | pass | composer + npm: 0 advisories |
| `make osv` | pass | 177 + 447 packages, no issues |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Isolation suite extended with 3 availability cases covering route, component mutation, and raw query paths; run fresh, green |
| 2 | Authorization | ✅ | `manageAvailability` gate on mount + all four actions; role matrix incl. staff-own-record tested both ways; IDOR via relation-scoped findOrFail |
| 3 | Authentication | n/a | Untouched (Fortify suites still green within make test) |
| 4 | Input & injection | ✅ | Regex/date_format/bounds validation server-side; explicit `#[Fillable]`; no raw SQL; no `{!! !!}` on the page (grep clean); Semgrep clean |
| 5 | Customer tokens | n/a | Epic 08 |
| 6 | Secrets | ✅ | gitleaks clean |
| 7 | Dependencies | ✅ | audit + osv clean |
| 8 | SAST | ✅ | 0 findings, 0 suppressions |
| 9 | Headers & transport | n/a | Unchanged this epic |
| 10 | Sessions & CSRF | ✅/n/a | Livewire-handled CSRF on all component calls; no auth changes |
| 11 | Rate limiting | n/a | No new public endpoints (slot data is consumed internally until Epic 06) |
| 12 | Logging & errors | ✅ | No new logging of payloads; failures are 403/404/422 with no data leakage |
| 13 | Uploads | n/a | None |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | SEC-AUTHZ (defense in depth) | `⚡availability.blade.php:52` | `Staff::query()->findOrFail((int) $staff)` casts the route param; non-numeric input becomes id 0 and 404s (fail-closed). Fine, but a `whereKey` on the raw string with explicit validation would be marginally clearer | Optional; no behavior change required |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: the new surface is small, every action is policy-checked server-side, tenant isolation is proven in both directions including the Livewire binding-order seam (now with the structural regression test requested by the Epic 04 review), and all security gates ran fresh and clean.
- Blocking findings remaining: 0
