# Review Report - QA - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** epic-03 §Required tests, QG-TESTS, QG-COVERAGE (incl. the ≥95% critical clause for tenant scoping), QG-MUTATION (≥85% critical), QG-E2E, QG-A11Y (authenticated pages), test-plan.md §Tenant isolation

## Files reviewed

- `tests/Feature/Tenancy/{IsolationTest,RolePermissionTest,OwnershipTest,TenantSettingsTest,TeamMembershipMiddlewareTest}.php`
- `tests/Feature/Teams/{TeamTest,TeamMemberTest,TeamInvitationTest,TeamInvitationNotificationTest,PruneExpiredTeamInvitationsTest}.php`
- `tests/Unit/{TenantScopingTest,TeamRoleTest}.php`, `tests/Browser/TenancySmokeTest.php`
- `database/factories/TeamFactory.php`, `TeamInvitationFactory.php` usage (states `expired()`, `expiresIn()`)
- `phpunit.xml` - suite runs on PostgreSQL (`DB_CONNECTION=pgsql`, `librenexus_test`)

## Flows reviewed

- Required-test mapping against the epic file (see checklist 1)
- Mutation setup: `IsolationTest` declares `covers(TenantScope::class, BelongsToTenant::class, CurrentTenant::class)` per the test-plan critical-class convention

## Tests reviewed

- `IsolationTest` - 7 cross-tenant denial tests assert both the 403/404 **and** that data did not change (e.g. role still Owner, invitation still present); 4 mechanism probes use a throwaway table + anonymous model, asserting actual row visibility, autofill value, and the thrown exception message. Meaningful, not execution-only
- `RolePermissionTest` - dataset-driven full role x ability matrix plus non-member denial; Livewire-level checks for owner/admin/staff
- `OwnershipTest` - transfer, non-member transfer rejection (action + component), admin transfer forbidden, personal-team transfer forbidden, last-owner demote/remove blocked, co-owner demote allowed, account-deletion block/cleanup/success-after-transfer; asserts DB state after every step
- `TenantSettingsTest` - defaults after creation, owner+admin allowed / staff forbidden, slug change kills old URL, reserved/duplicate/format datasets, policy bounds dataset
- `TeamInvitationTest` - create, 7-day expiry (freezeTime), staff cannot invite, revoke, accept with role applied, wrong-email refusal, expired refusal, expired excluded from pending without deletion
- `TeamInvitationNotificationTest` - ShouldQueue + mail channel asserted, rendered mail contains inviter/team/login link
- `TenancySmokeTest` - tenant settings + teams index with `assertNoAccessibilityIssues()` (axe) and `assertNoJavascriptErrors()`; accept-invitation journey lands on the team dashboard

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 193/193, 568 assertions, 0 risky (browser suite included) - fresh run |
| `make coverage` | pass overall / **fail critical** | Total 91.7% (gate ≥80 ✅, first time). Critical tenant-scoping classes: TenantScope 100%, **BelongsToTenant 84.6%** (lines 26, 47), **CurrentTenant 75.0%** - below the ≥95% critical target (F1) |
| `make mutation` | pass | 13 mutations across the 3 `covers()` classes, score 100% (≥85 critical met) - but `--covered-only` excludes the uncovered spoof branch |
| `make e2e` (via full suite) | pass | 19/19 browser tests incl. the 3 tenancy smoke tests with axe assertions |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Required tests exist | ⚠️ | Isolation suite ✅; role matrix ✅; invitation lifecycle: create/expire/accept/revoke ✅ but **single-use re-acceptance has no test** (the `isAccepted()` guard is only implied) and the **unregistered-invitee registration routing is untested** (`FortifyServiceProvider::teamInvitation()` lines 105-121 at 0% coverage) - the email-match denial itself is tested (F2); CRUD/switching/slug/reserved ✅; ownership suite ✅; member removal ✅ (staff-record half deferred to Epic 04 with the Staff model); a11y/axe on tenant settings + accept path ✅ |
| 2 | Right layer | ✅ | Enum logic unit-tested (TeamRoleTest), flows feature-tested via Livewire, journeys browser-tested |
| 3 | Coverage | ❌ | 91.7% overall ≥80 ✅; critical clause (≥95% for tenant scoping) unmet: BelongsToTenant 84.6%, CurrentTenant 75.0% (F1). The uncovered BelongsToTenant line 26 is the security-relevant pre-set `team_id` branch |
| 4 | Mutation | ⚠️ | 100% on the three critical classes (target ≥85 ✅); weakened only by `--covered-only` skipping the uncovered branch - resolves with F1 |
| 5 | Meaningful assertions | ✅ | Denial tests always pair the status assertion with a data-unchanged assertion; no assertion-free tests |
| 6 | Edge cases | ✅ | Cross-tenant IDs, fail-closed no-context, expired/foreign invitations, last-owner demote/remove/delete, reserved + malformed + duplicate slugs, policy bounds; DST/booking edges belong to Epics 05+ |
| 7 | Named suites | ✅ | `tests/Feature/Tenancy/IsolationTest.php` green, carries the SEC-TENANT header and `covers()`; nothing weakened |
| 8 | Factories & data | ✅ | Factories + states (`expired()`, `expiresIn()`); RefreshDatabase on PostgreSQL (phpunit.xml) |
| 9 | Async assertions | ✅ | `Notification::fake()` where sending is incidental; ShouldQueue asserted explicitly in TeamInvitationNotificationTest |
| 10 | No skips | ✅ | Only Epic 02's conditional Fortify-feature guards; no `only`/incomplete tests |
| 11 | Determinism | ✅ | `freezeTime()`/`travelTo()` for expiry math; no sleeps; suite deterministic across the 3 fresh runs performed |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | QG-COVERAGE (critical ≥95%) | `app/Concerns/BelongsToTenant.php` (84.6%), `app/Data/CurrentTenant.php` (75.0%) | Tenant scoping is designated critical domain logic; two of its three classes miss the 95% target. Materially, the uncovered `BelongsToTenant` line 26 is the pre-set `team_id` early return (the form-field spoof vector flagged by Security/Architecture) and line 47 the `team()` relation; `CurrentTenant::get()` is unexercised | Add a spoofed-`team_id` create probe and a `team()` relation/`get()` assertion to the isolation/scoping suites; both classes reach ≥95% and the mutation set grows to include the branch |
| F2 | Medium | epic-03 §Required tests | invitation lifecycle suites | Missing: (a) explicit single-use test (accepting an already-accepted invitation is refused), (b) unregistered-invitee flow test (register page shows the invitation context and the post-registration accept enforces email match); `FortifyServiceProvider::teamInvitation()` is currently dead code as far as tests are concerned | Add both tests; (b) can be a feature test hitting `/register?invitation={code}` plus the existing accept path |
| F3 | Low | test hygiene | `tests/Feature/Teams/TeamInvitationTest.php` | `declineInvitation` (deletes the invitation) has no test | Cover decline alongside F2 |

## Required fixes (blocking)

- F1: bring BelongsToTenant and CurrentTenant to the ≥95% critical coverage target with meaningful probes (this simultaneously closes the untested spoof branch Security flagged).

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: the suites that exist are excellent - layered correctly, assertion-rich, deterministic, on PostgreSQL, with axe-backed browser coverage and a 100% mutation score on the covered critical lines - but the project's own critical-coverage gate (≥95% for tenant scoping) is unmet, and the uncovered branch is precisely the one with security significance; two epic-required lifecycle tests (single-use, unregistered-invitee routing) are also missing.
- Blocking findings remaining: 1 (F1; F2 is Medium but should land with the same change)

## Re-review after fixes (2026-06-10)

Verified by reading the new tests and re-running the gates fresh:

- **F1 resolved.** The isolation suite's mechanism block gained four probes (spoofed `team_id` rejected + zero-rows proof, explicit match accepted as int **and** string, trusted no-context path, `team()` relation resolution) and an explicit `CurrentTenant::get()` assertion. Fresh `make coverage`: **Total 95.3%**; `TenantScope`, `BelongsToTenant`, and `CurrentTenant` no longer appear in the uncovered list, i.e. **100% each** - the ≥95% critical clause is met. Fresh `make mutation`: **19 mutations across the three `covers()` classes, score 100%**; the two prior escapees (AlwaysReturnNull on `CurrentTenant::get`, RemoveIntegerCast on the spoof check) are killed by the new explicit assertions, so the earlier `--covered-only` blind spot is gone.
- **F2 resolved.** `an accepted invitation cannot be accepted a second time` (single-use, asserts the error and that the invitee's team did not change) and `the register page carries the invitation context for an unregistered invitee` (valid code renders the team name on `/register`, bogus code does not - `FortifyServiceProvider::teamInvitation()` is now exercised, lines 105-121 covered). Plus new tests for the owner-role invite rejection, the unverified-invitee verification redirect, the reserved-slug generation dataset, and the four scoping probes: suite grew 193 -> 206 tests / 568 -> 602 assertions, all meaningful (every denial paired with a state assertion).
- **F3 (Low)** remains open: `declineInvitation` is still untested; tracked for the next invitations touch (Epic 10 hardening at the latest).
- Checklist updates: item 1 (required tests) now ✅; item 3 (coverage) now ✅ (95.3% overall, critical classes 100%); item 4 (mutation) now ✅ without caveat.
- Fresh runs: `php artisan test --compact` 206/206, 0 risky (browser suite incl. the 3 axe-checked tenancy journeys); `make coverage` 95.3% pass; `make mutation` 19/19 = 100% pass.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all test gates are green on fresh runs with the critical-coverage and critical-mutation targets met by meaningful, state-asserting probes; the named isolation suite grew stronger, not weaker. The only remaining item is a Low test-hygiene gap (decline path), tracked.
- Blocking findings remaining: 0
