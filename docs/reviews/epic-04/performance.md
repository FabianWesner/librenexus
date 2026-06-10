# Review Report - Performance - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f`
- **Requirements/rules in scope:** NFR-PERF-1/2, QG-PERF, QG-A11Y (mechanism check), NFR-OBS (unchanged)

## Files reviewed

- `resources/views/pages/staff/⚡index.blade.php` - `staffMembers()` uses `withCount('services')`; `linkableMemberships()` eager-loads `user:id,name,email`
- `resources/views/pages/services/⚡index.blade.php` - flat scoped query, no relations needed per row
- `app/Concerns/HasTeams.php` - `toUserTeam()` reads the role from the loaded pivot (`pivotTeamRole`) instead of one query per team
- `tests/Feature/Tenancy/ListPageQueryCountTest.php` - budgets and no-growth assertions
- `database/migrations/*` - index situation on the new tables
- `Makefile` - coverage/mutation now run with `-d memory_limit=1G`; thresholds (`COVERAGE_MIN=80`, `MUTATION_MIN=70`) and `PUBLIC_PATHS` untouched

## Flows reviewed

- Staff list render: constant query count regardless of staff rows (services shown as `services_count`, no per-row relation access)
- Services list render: constant query count; `formattedPrice()` is pure computation
- Team switcher: role resolution from the already-loaded pivot, 1 query for 4 teams (closes the Epic 03 1+N deferral)
- Modal option lists (`serviceOptions`, `linkableMemberships`): fixed number of queries, not per-row

## Tests reviewed

- `ListPageQueryCountTest::the staff list page query count does not grow with the number of staff` - 1 vs 8 staff (with service assignments), equality assertion plus budget ≤12
- `ListPageQueryCountTest::the services list page query count does not grow with the number of services` - 1 vs 8 services, same pattern
- `ListPageQueryCountTest::the team switcher resolves roles from the loaded pivot without extra queries` - exactly 1 query for 4 memberships

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` (incl. query-count suite) | pass | 257/257 |
| `make accessibility` (against `php artisan serve` on 127.0.0.1:8123) | pass | 9/9 public URLs, 0 errors (regression check, public pages untouched this epic) |
| `make performance` | not rerun | No public page added or changed; per test-plan §Accessibility & performance per page, the new authenticated pages are covered by axe browser tests + query-count tests instead of Lighthouse. `PUBLIC_PATHS` correctly unchanged (no stale gate list) |
| `make e2e` | pass | 23/23, includes axe on both new pages and their modals |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | `ListPageQueryCountTest` no-growth + budget assertions for both new list pages; switcher 1+N fixed and pinned by a test |
| 2 | Query efficiency | ✅ | `withCount` aggregate instead of per-row counts; `team_id` FKs are indexed (FK + Postgres); `membership_id` UNIQUE doubles as its index. No hot time-range queries yet (Epic 05+) |
| 3 | Lighthouse budget | n/a | No public page touched; mechanism check passed (PUBLIC_PATHS list not stale) |
| 4 | Server response budget | ✅ | List pages render in a constant ≤12 queries with the test dataset; no heavy work in render paths |
| 5 | Async | n/a | No emails/jobs introduced; nothing inline |
| 6 | Reliability/concurrency | n/a | No booking paths yet; the membership-link race is closed at the DB level by the UNIQUE constraint on `staff.membership_id` |
| 7 | Asset weight | ✅ | No new JS/CSS assets; pages reuse the built Flux/Tailwind bundle |
| 8 | Caching | n/a | Authenticated pages; no per-request heavy work observed |
| 9 | Observability | ✅ | Correlation-ID middleware untouched; `ObservabilityTest` green in suite |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-PERF-2 | `pages/staff/⚡index.blade.php` (`linkableMemberships`) | Computed on every render that includes the form modal even when it is closed; constant cost (2 queries) so harmless now | Consider lazy-loading modal data if member lists grow large; no action needed for v1 |
| F2 | Low | QG-PERF process | `Makefile` | `make performance` was not executed in this review (no public-page change). Fine per the documented mechanism, but Epic 10's full `make verify` must still run it end to end | None now; verify at Epic 10 |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: both new list views are proven N+1-free by no-growth query assertions, the Epic 03 switcher 1+N is fixed and pinned, no async or budget regressions; gate mechanisms were applied correctly (axe + query counts for authenticated pages, public lists unchanged).
- Blocking findings remaining: 0
