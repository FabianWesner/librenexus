# Review Report - Performance Reviewer - Epic 10 (Hardening & quality report / final application state)

> Final-state review against NFR-PERF/RELY/OBS and QG-PERF, leaning on the
> per-epic performance reviews (epic-00..09, all pass) and re-verifying the
> final Lighthouse/concurrency evidence.

## Reviewed scope

- **Epic / change:** Epic 10 hardening + final application state
- **Requirements/rules in scope:** NFR-PERF-1/2, NFR-RELY-1/2, NFR-OBS-*,
  NFR-OPS-2, QG-PERF

## Files reviewed

- `/tmp/claude/verify.log:1036-1085` - Lighthouse ran 11 URLs, "Checking
  assertions against 11 URL(s), 11 total run(s) ... All results processed!"
  with zero assertion failures, then `ALL QUALITY GATES PASSED`
- `reports/lighthouse/` - 11 HTML+JSON reports dumped to disk
- `resources/views/pages/appointments/⚡index.blade.php` - pagination
  (`paginate(25)`, line 364) closing the unbounded-list deferral from Epic 07;
  eager loads retained
- `resources/views/pages/booking/⚡show.blade.php` - step throttle (line 338)
  bounds the slot-computation hot path against scripted abuse
- `tests/Feature/Tenancy/ListPageQueryCountTest.php`,
  `tests/Feature/Appointments/AppointmentViewsTest.php`,
  `tests/Feature/Dashboard/DashboardMetricsTest.php` - query-count assertions
- `docs/quality-report.md` §Performance & reliability - claims vs evidence

## Flows reviewed

- Booking + reschedule under concurrency - atomic transaction with the GiST
  exclusion constraint as final arbiter; suite re-run green by this review
- Appointments list at volume - paginated, query count asserted flat across
  pages/filters
- Reminder idempotency - conditional claim UPDATE, simulated mid-run race
  (Epic 08 review + green suite)
- Mail paths - all queued (database queue), scalar capture; nothing inline

## Tests reviewed

- `tests/Feature/Booking/ConcurrencyTest.php` - re-run: pass (part of 40/40,
  91 assertions); no double-booking under raced live connections (NFR-RELY-1)
- Query-count tests on lists, calendar, dashboard - strict equality
  assertions, green in the 469-test run (NFR-PERF-2)
- `tests/Browser/*` - 35/35 with no console errors; page weights sane on the
  public pages audited by Lighthouse

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make performance` (verify.log) | pass | all 11 URLs pass perf >= 0.90, a11y >= 0.95, bp >= 0.90, seo >= 0.90; reports in `reports/lighthouse/` |
| `pest ConcurrencyTest` | pass | fresh run by this review |
| `make test` query-count tests | pass | within the green 469-test run |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | Strict query-count equality tests on the appointments list, calendar, dashboard, and team lists (NFR-PERF-2); pagination kept counts flat |
| 2 | Query efficiency | ✅ | Dashboard aggregates via group-by `selectRaw` counts (Epic 09 review); GiST + tenant/staff/time indexes from the booking migrations (ADR-0003) |
| 3 | Lighthouse budget | ✅ | 11/11 URLs pass all four category assertions (verify.log:1066-1074) |
| 4 | Server response budget | ⚠️ | NFR-PERF-1 spot-checked only (~85 ms warm on the booking page locally); no p95 load measurement. Honestly capped in the quality report (limitation 5, scorecard "solid MVP+" for this category) |
| 5 | Async | ✅ | All four mailables queued; verified per epics 06-08; requests return promptly |
| 6 | Reliability/concurrency | ✅ | Constraint-backed atomicity re-proven by the fresh concurrency run; reminders idempotent under a simulated race |
| 7 | Asset weight | ✅ | Vite-built assets; Lighthouse perf scores 0.94-1.0 imply no heavy bundles; no unoptimized images flagged |
| 8 | Caching | ✅ | Marketing pages are static Blade renders with no per-request heavy work (Epic 01 review) |
| 9 | Observability | ✅ | Correlation-ID JSON logs (Epic 00), failed-job store, inert error-tracking DSN hook (config/services.php) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-PERF-1 | whole app | No load testing; the p95 < 300 ms budget rests on local spot checks plus N+1 guarantees. Honestly disclosed (quality report limitation 5 and the scorecard cap) | Accepted for the experiment scope; load-test before production traffic (already the report's next-step 4) |
| F2 | Low | QG-PERF environment | local Herd domain | Lighthouse locally requires a secure-context URL (artisan serve or herd secure); documented in assumptions.md and README, not an app defect | None |
| F3 | Low (cross-ref) | App DoD #2 | CI run 27322534001 | The CI job that would re-run Lighthouse/pa11y publicly failed before reaching them (install-step bug at HEAD, fix uncommitted). Local evidence is complete; public CI evidence pending. Tracked as product F1 | Push the fix; confirm the public perf job |

## Required fixes (blocking)

- None owned by this review.

## Re-review after fixes (2026-06-11)

- F3 resolved: CI run 27323217271 on main (commit `214272c`) is green,
  including the "e2e, accessibility, performance" job, so Lighthouse and
  pa11y now have public CI evidence (verified via `gh run view 27323217271
  --json conclusion,jobs`). The fix also added a demo-data re-seed between
  the browser tests and the public-page gates, removing the cross-job data
  coupling in CI.
- The scorecard now uses the defined level "solid MVP" for performance &
  reliability with the no-load-testing cap in the evidence column, matching
  this review's checklist item 4 (docs/quality-report.md:201).

## Final decision

**PASS**

- Rationale: QG-PERF is fully green with margin on all 11 audited URLs, both
  locally and in the public CI run 27323217271; N+1 freedom is proven by
  strict query-count tests rather than asserted, booking is atomic under
  genuinely raced connections, and all mail is queued. The only real gap,
  absent load testing, is explicitly disclosed and correctly capped in the
  scorecard, which is the honest treatment the benchmark asks for.
- Blocking findings remaining: 0
