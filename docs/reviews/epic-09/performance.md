# Review Report — Performance Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard metric queries, demo seeder data volume, public gate URLs)
- **Requirements/rules in scope:** NFR-PERF-1/2, QG-PERF, NFR-RELY (regression), NFR-OBS (regression)

## Files reviewed

- `resources/views/pages/dashboard/⚡index.blade.php` — query shapes: two `count()`s, one eager-loaded day list, one `limit(5)` list, one group-by aggregate + staff list; all `#[Computed]` (memoized per request)
- `database/migrations/2026_06_10_234907_create_appointments_table.php:35-36` — `(team_id, starts_at)` and `(staff_id, starts_at)` indexes serving every dashboard window query
- `database/seeders/DemoSeeder.php` — bounded data volume (~26 appointments, 10 rules, 6 customers); constraint-aware layout avoids retry loops
- `Makefile:16-18,120-123` — `/demo-clinic` and `/manage/demo-manage-token` in PUBLIC_PATHS for pa11y/Lighthouse

## Flows reviewed

- Dashboard render (metrics state) — fixed query budget: today count, upcoming count, today list (with `with(['staff','service','customer'])`), recent bookings (`with(['customer','service'])`), staff-load group-by + one staff query; no per-row queries in the Blade loops (status badge and colors come from loaded models/enums)
- Dashboard render (onboarding state) — three `exists()` probes plus one `value('id')`; trivially bounded
- Aggregation strategy — `groupBy('staff_id')->selectRaw('staff_id, count(*)')` merged in memory with the staff list; no loops over appointments (epic implementation note honored)

## Tests reviewed

- `tests/Feature/Dashboard/DashboardMetricsTest.php:157` — query count with 1 appointment equals query count with 10 across both staff (strict equality, full HTTP request): the NFR-PERF-2 proof for this page
- `tests/Feature/Ops/DemoSeederTest.php` — seeded volume assertions keep the demo dataset realistic but bounded
- `tests/Browser/DashboardSmokeTest.php` — no JS errors in either state (no client-side perf hazards introduced)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 462/462 incl. the dashboard query-count test and the booking concurrency suite (fresh run) |
| `make e2e` | pass | 35/35 (fresh run) |
| `make performance` | pass (lead-verified) | Lighthouse budgets green on the seeded app at 127.0.0.1 for all 11 PUBLIC_URLS incl. `/demo-clinic` and `/manage/demo-manage-token` |
| pa11y-ci | pass (lead-verified) | 11/11 on the same URL set |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | Eager loads on both lists; query-count no-growth test at DashboardMetricsTest:157 (strict equality 1 vs 10 appointments) |
| 2 | Query efficiency | ✅ | Counts + one group-by aggregate, no per-row loops; `(team_id, starts_at)` and `(staff_id, starts_at)` indexes cover the window predicates |
| 3 | Lighthouse budget | ✅ | Lead-verified green for the demo booking and manage pages now exercised with seeded data; dashboard is authenticated, covered by the documented browser-test mechanism instead (test-plan §A11y & perf per page) |
| 4 | Server response budget | ✅ | Constant query count; demo dataset is small (~26 appointments); full suite incl. dashboard requests runs 462 tests in ~35s with no outlier |
| 5 | Async | n/a | No mail/jobs added; nothing inline |
| 6 | Reliability/concurrency | ✅ | Booking paths untouched; concurrency suite green in the fresh full run; seeder writes are layout-proven to avoid exclusion-constraint conflicts |
| 7 | Asset weight | ✅ | No new JS/CSS bundles; copy button uses existing Alpine; CSS-only bars for staff load |
| 8 | Caching | n/a | Authenticated, per-tenant page; `#[Computed]` memoization prevents intra-request recomputation |
| 9 | Observability | ✅ | Correlation-ID/structured-log middleware unchanged (Ops suite green in fresh run) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-PERF (scaling note) | `⚡index.blade.php:177-190` | `todayAppointments` is unbounded within the day; a tenant with hundreds of same-day appointments would render them all. Realistic ceilings (availability windows, slot packing) keep this small in practice | Defer: cap with a "view all" link to the appointments page if Epic 10 load review finds it worthwhile |
| F2 | Low | Deferred-item interaction | `docs/assumptions.md` (Epic 07 deferral) | The unpaginated appointments list deferral said "paginate in Epic 09/10 before dashboards drive traffic to it"; the dashboard now links there and Epic 09 did not paginate it | Keep the existing deferral but mark it Epic 10-due; not a dashboard defect |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: the page meets NFR-PERF-2 with a strict query-count equality test, aggregates use group-by as mandated, supporting indexes exist, and QG-PERF is green (lead-verified Lighthouse on the seeded public URLs; axe/browser mechanism for the authenticated dashboard); both findings are Low scaling/tracking notes.
- Blocking findings remaining: 0
