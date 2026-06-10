# Review Report - Performance - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** NFR-PERF-1/2, QG-PERF (no new public pages this epic), NFR-RELY (concurrency n/a until Epic 06), NFR-OBS (unchanged)

## Files reviewed

- `app/Actions/Availability/GetBookableSlots.php` - query shape and eager loading
- `app/Actions/Availability/ComputeSlots.php` - algorithmic cost of the pure engine
- `resources/views/pages/staff/⚡availability.blade.php` - per-render query count of the editor
- `database/migrations/2026_06_10_22495{6,7}_*` - index coverage for the hot lookups

## Flows reviewed

- Slot computation query plan: one staff query per call (`service->staff()->bookable()` with optional key filter) + two eager loads (`availabilityRules`, `timeOff`) = 3 queries regardless of staff count; the engine itself does zero queries (pure)
- Engine complexity: O(days x rules) window building with a small usort per day, then O(slots x blockedIntervals) offerability checks; for realistic inputs (7 rules, a 60-day horizon, tens of intervals) this is microseconds-scale; engine unit suite (31 tests) runs in well under a second inside the full run
- Editor render: exactly two data queries via computed properties (`rulesByWeekday`, `timeOffEntries`), both per-staff indexed; weekday grid and selects are built in memory
- Index check: `availability_rules (staff_id, weekday)` matches the engine's read pattern; `time_offs (staff_id, starts_at)` matches future range filtering

## Tests reviewed

- `tests/Feature/Availability/BookableSlotsTest.php` - exercises the eager-loaded path incl. the multi-staff merge (no lazy loads would survive the strict assertions on exact slot output)
- `tests/Feature/Tenancy/ListPageQueryCountTest.php` (Epic 04) - staff list (which gained the availability row action) still under its absolute query budget
- `tests/Browser/AvailabilitySmokeTest.php` - editor renders without JS errors; subjective render is instant on the demo dataset

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 313/313 in 18.1s total; engine suite adds no measurable runtime |
| `make performance` | n/a | PUBLIC_PATHS unchanged this epic; the availability editor is authenticated and outside the Lighthouse budget scope; axe runs in the browser test instead |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | `with(['availabilityRules', 'timeOff'])` in GetBookableSlots:45; editor uses two fixed computed queries; no per-row lazy loads found |
| 2 | Query efficiency | ⚠️ | Indexes present and matching; but `timeOff` is eager-loaded unbounded (every interval ever recorded) regardless of the requested date range - see F1 |
| 3 | Lighthouse budget | n/a | No public pages added/changed |
| 4 | Server response budget | ✅ | Editor = 2 data queries + in-memory grid; feature/browser evidence shows instant renders on test data |
| 5 | Async | n/a | No mail/jobs this epic |
| 6 | Reliability/concurrency | n/a | Engine only computes; booking atomicity is Epic 06 (ADR-0003 ready) |
| 7 | Asset weight | ✅ | No new JS/CSS beyond existing Flux components |
| 8 | Caching | n/a | No marketing-page changes |
| 9 | Observability | ✅ | Unchanged; engine failures surface as normal exceptions |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-PERF-2 | `GetBookableSlots.php:45,72-77` | The `timeOff` eager load has no date-range constraint, so every historical interval is hydrated and then checked per slot. Harmless at current volumes, but Epic 06 puts this on the public booking hot path where time-off rows accumulate forever | Before/with Epic 06, constrain the load to the computed range (e.g. `ends_at >= from && starts_at <= until`, using the `(staff_id, starts_at)` index) and add a query-shape or timing assertion |
| F2 | Low | NFR-PERF-1 | `ComputeSlots.php:185` | `[...timeOff, ...reserved]` re-spreads both arrays for every slot checked; trivially avoidable by merging once per window/computation | Micro-optimization; fold into the F1 work if touched |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: no N+1, correct indexes, a pure in-memory engine, and no async/public-page exposure this epic; the unbounded time-off load is the one item that must be tightened before the engine serves public booking traffic in Epic 06.
- Blocking findings remaining: 0
