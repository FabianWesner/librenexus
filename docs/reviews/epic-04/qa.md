# Review Report - QA - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f`
- **Requirements/rules in scope:** epic §Required tests, QG-TESTS, QG-COVERAGE (≥80%), QG-MUTATION (≥70%, ≥85% critical), QG-A11Y (axe mechanism for authenticated pages), test-plan.md §Accessibility & performance per page

## Files reviewed

- `tests/Feature/Staff/StaffManagementTest.php` (13 cases, 17 with dataset expansion)
- `tests/Feature/Services/ServiceManagementTest.php` (10 cases, 19 with datasets)
- `tests/Feature/Tenancy/IsolationTest.php` (Epic 04 describe block, 6 new cases)
- `tests/Feature/Tenancy/ListPageQueryCountTest.php` (3 cases)
- `tests/Unit/CalendarColorTest.php` (3 cases incl. computed WCAG AA contrast)
- `tests/Browser/StaffServicesSmokeTest.php` (4 cases, axe + JS-error assertions)
- `tests/Feature/DashboardTest.php` (3 new AC-7 cases)
- `database/factories/StaffFactory.php`, `ServiceFactory.php` - states `inactive()`, `linkedTo()`, `archived()`

## Flows reviewed

- Required-test mapping: CRUD + validation (incl. buffer 0 accepted / -1 rejected, duration 4/5/480/481, price -1/null/0/cap) ✓; per-role authorization incl. link admin-only and ≤1-per-record ✓; isolation extended to staff/services ✓; deactivated/archived excluded from `bookable()` but retained ✓; axe on the new pages + N+1 checks ✓
- Review experiment 1: a scratch browser test submitting the staff form over real Livewire update requests passed (then removed)
- Review experiment 2: with `Livewire::addPersistentMiddleware` disabled, the full set of staff/services/isolation/browser tests (62) still passed, because feature tests set `CurrentTenant` manually and the browser-test server reuses one container across requests (context from the page GET leaks into update requests). The seam this epic's headline fix sits on is therefore unprotected by regression tests

## Tests reviewed

- `StaffManagementTest::a staff-role member cannot create, update, link, or deactivate staff` - per-action 403 with fresh component instances, then asserts no state change (outcome, not just status)
- `ServiceManagementTest::duration/buffer/price bounds` - exact FR-SERVICE-3 boundary values on both sides, asserting both error bags and database state
- `IsolationTest` staff/services block - reads AND mutations cross-tenant, plus list-bleed assertions
- `ListPageQueryCountTest` - no-growth assertions (1 vs 8 records) plus absolute budget (12); switcher pivot-role case asserts exactly 1 query for 4 teams
- `CalendarColorTest` - computes relative luminance and asserts ≥4.5:1 for all 8 hexes (genuine AA proof, not a snapshot)
- `StaffServicesSmokeTest` - the animation-finish workaround calls `getAnimations().finish()` on open dialogs before axe; this completes the entry transition that headless Chrome never plays (no animation frames), it does not hide or alter audited content. Sound, and documented in the test

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 257/257, 816 assertions, 0 skips |
| `make e2e` | pass | 23/23, 69 assertions |
| `make coverage` | pass | total 94.4% (gate ≥80); Staff 85.7%, Service 92.3%, HasTeams 93.9%; policies 66.7% (unused `view()` methods, see F3) |
| `make mutation` | pass | 100% (19 mutations) on the `covers()`-declared tenant-scoping classes (critical ≥85% met) |
| scratch persistent-middleware experiments | done | see Flows; all review artifacts removed afterwards |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | Every required test in the epic file mapped (see Flows); all green |
| 2 | Right layer | ✅ | Palette unit-tested, flows feature-tested, pages browser-tested with axe |
| 3 | Coverage | ✅ | 94.4% total; epic-touched classes 85-100% |
| 4 | Mutation | ✅ | 100% for `covers()` classes; no new survivors |
| 5 | Meaningful assertions | ✅ | Authorization tests assert DB state after the 403; validation tests assert both errors and absence of rows |
| 6 | Edge cases | ✅ | Buffer = 0, boundary durations/prices, cross-tenant ids, already-linked and self-link memberships all present; DST/booking edges n/a this epic |
| 7 | Named suites | ✅ | IsolationTest extended, not weakened (all prior cases intact per diff); concurrency suite n/a yet |
| 8 | Factories & data | ✅ | Factories with `inactive()`/`archived()`/`linkedTo()` states; `fake()` used; RefreshDatabase on PostgreSQL |
| 9 | Async assertions | n/a | No queued work introduced |
| 10 | No skips | ✅ | No `skip`/`only`/`todo` markers (grep) |
| 11 | Determinism | ⚠️ | Suite is deterministic, but see F1/F2: two environment seams reduce what the suite can detect |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | QG-TESTS / SEC-TENANT regression | `AppServiceProvider.php:34` + test suite | Deleting the `addPersistentMiddleware` registration leaves all 257 tests green (verified experimentally), yet in production it is what establishes `CurrentTenant` on Livewire update requests (verified live with a log probe). The epic's flagship correctness fix has no regression guard | Add a cheap structural test (persistent middleware list contains `EnsureTeamMembership`) and/or an HTTP-level update-request test; do this before Epic 05 builds more Livewire actions on the same seam |
| F2 | Medium | test-plan §browser fidelity | `tests/Browser/*` | The Pest browser server shares one app container across requests, so request-scoped state (`CurrentTenant`) leaks between the page GET and subsequent update requests. Browser tests therefore cannot catch per-request tenancy regressions | Document the limitation; consider resetting scoped services per request in the browser-test server, or rely on the F1 structural test |
| F3 | Low | QG-COVERAGE | `StaffPolicy::view`, `ServicePolicy::view` | `view()` policy methods are not yet called anywhere (66.7% policy coverage) | They become live in Epic 05+; leave, but ensure they get exercised then |
| F4 | Low | AC-3 | retention tests | "Past appointments remain" is provable only as record retention until Epic 06/07 | Re-test with appointment fixtures once they exist (shared with Product F1) |

## Required fixes (blocking)

- None. F1/F2 are Medium: the guarded behavior currently works (verified live during this review) and its failure mode is fail-closed.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all required tests exist, are meaningful (boundary values, post-403 state assertions, computed contrast), and every gate passes with margin; the warnings concern regression protection for the persistent-middleware seam, not current behavior.
- Blocking findings remaining: 0
