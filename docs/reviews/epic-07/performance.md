# Review Report - Performance Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (appointment list + calendar views, manual write actions, queued cancellation mail)
- **Requirements/rules in scope:** NFR-PERF-1/2, NFR-RELY-1/2, NFR-OPS-2, QG-PERF

## Files reviewed

- `resources/views/pages/appointments/⚡index.blade.php` - `appointments()` eager loads `staff, service, customer`; modal computeds load with relations
- `resources/views/pages/appointments/⚡calendar.blade.php` - `dayColumns()` builds from one staff query + one appointment query (`with(['service','customer'])`, `groupBy`)
- `app/Actions/Availability/GetBookableSlots.php` - reschedule/new-slot lookups bounded to one local day; time-off and reserved ranges bounded by the UTC range
- `app/Actions/Appointments/RescheduleAppointment.php`, `app/Actions/Booking/BookAppointment.php` - transactional, constraint-arbitrated writes
- `database/migrations/2026_06_10_234907_create_appointments_table.php` - indexes `(team_id, starts_at)`, `(staff_id, starts_at)` + GiST exclusion index
- `app/Mail/AppointmentCancellationMail.php` - `ShouldQueue`

## Flows reviewed

- List render with filters: single appointments query with three eager loads; filter options are two `select`-projected queries; no per-row queries in the table loop (all data from eager-loaded relations)
- Calendar render: visible staff (1 query) + day's appointments (1 query) grouped in memory; blocks computed without further queries
- Reschedule modal: one-day engine pass per day change (bounded); occupied attempt rolls back, no partial state
- Cancellation: status update + queued mail, request returns without SMTP work

## Tests reviewed

- `AppointmentViewsTest::the appointments list query count does not grow with the number of appointments` - `DB::listen` count equality between 1 and 8 appointments (NFR-PERF-2)
- `AppointmentViewsTest::the calendar query count does not grow with the number of appointments` - same equality proof for the calendar
- `ConcurrencyTest` (9 tests) - two-connection races: exactly one insert wins, partial overlaps rejected, cancelled rows do not block (NFR-RELY-1)
- `ManualBookingTest::rescheduling onto an occupied slot fails and keeps the original time` - atomic rollback, blocker untouched
- `ManualBookingTest::cancelling ... queues the cancellation email` + `Mail::assertQueued` - async proof

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` (incl. query-count + concurrency suites) | pass | 407/407; both Epic 07 query-count equality tests green |
| `make e2e` | pass | 30/30; appointment pages render without JS errors |
| `make performance` | not run locally | Epic 07 adds no public pages; `PUBLIC_PATHS` unchanged, so the Lighthouse gate set is identical to the Epic 06 pass. Authenticated pages are outside the QG-PERF harness by design (axe runs in the browser tests instead, per test-plan §Accessibility & performance per page). Local Herd/Lighthouse constraints documented in docs/assumptions.md §Environment |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | Eager loads on both views; `DB::listen` equality tests for list AND calendar (cited above). This is the epic's "performance review finds no N+1" done-when condition |
| 2 | Query efficiency | ✅ | Calendar groups in memory from one bounded query; appointment lookups hit `(staff_id, starts_at)` / `(team_id, starts_at)` indexes; filter options use column projections |
| 3 | Lighthouse budget | n/a | No public pages added or changed (see Tools) |
| 4 | Server response budget | ⚠️ | Render work is one bounded query for typical datasets, but the list is **unpaginated** (`->get()`); see F1 |
| 5 | Async | ✅ | Cancellation + confirmation mails queued (`ShouldQueue`, `Mail::queue`); no inline sending |
| 6 | Reliability/concurrency | ✅ | Reschedule is a same-row UPDATE inside one transaction with the exclusion constraint as arbiter; concurrency suite green; cancel-then-rebook proven |
| 7 | Asset weight | ✅ | No new JS/CSS bundles; pages reuse the existing app build |
| 8 | Caching | n/a | Authenticated, per-tenant pages; nothing cacheable added |
| 9 | Observability | ✅ | Unchanged Epic 00 structured logging/correlation; failed mail jobs land in the database queue's failed-jobs table |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-PERF-1 | `⚡index.blade.php::appointments()` (`->get()`, no pagination) | The list renders every matching row. The default filter (from today, horizon-bounded future) keeps it small, but a busy tenant or a cleared/wide date range can render thousands of rows (e.g. 10 staff x 8/day x 60 days ≈ 4800), busting the render budget. Not visible with the demo dataset | Track for Epic 09/10: paginate (or cap + "narrow your filters" notice) once the dashboard epic touches these views; add to the deferred findings log |
| F2 | Low | NFR-PERF-2 (carried deferral) | Epic 06 deferred log line "No query-count assertion on the booking page ... add with Epic 07's mandated N+1 tests" | Epic 07 added N+1 assertions for its own two views but not for the public booking page, so that Low deferral remains open | Carry forward explicitly (Epic 10 hardening) rather than implicitly |
| F3 | Low | NFR-PERF-1 | `⚡index.blade.php::rescheduleSlots/newSlots` | Each modal interaction runs a full one-day engine pass (rules + time off + reserved ranges). Bounded and identical to the public booking page's per-day cost; acceptable | None; revisit only if slot browsing becomes hot |

## Required fixes (blocking)

- None.

## Re-review after fixes (2026-06-11)

The QA fixes add tests only; no query, queue, or rendering path changed. The
new constraint-race test additionally exercises the NFR-RELY-1 guarantee
through the application action (real exclusion constraint as arbiter, row
count stays 1). Re-ran: `make test` 410/410 (query-count + concurrency suites
green), `make e2e` 31/31. F1-F3 remain open as written. Decision unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: both new views are proven N+1-free with genuine query-count equality tests, writes are atomic and constraint-arbitrated, and all mail is queued; the unpaginated list (F1) is a real but dataset-dependent risk that must be tracked, not a current budget miss.
- Blocking findings remaining: 0
