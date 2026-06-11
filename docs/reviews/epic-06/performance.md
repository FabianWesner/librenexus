# Review Report - Performance - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** NFR-PERF-1/2, NFR-RELY-1/2, NFR-OPS-2, NFR-OBS, QG-PERF (perf ≥0.90, a11y ≥0.95, bp ≥0.90, seo ≥0.90)

## Files reviewed

- `resources/views/pages/booking/⚡show.blade.php` - query profile of each step (computed properties, `refreshAvailableDates`)
- `app/Actions/Availability/GetBookableSlots.php` - bounded time-off and appointment loads, single grouped reserved-ranges query
- `app/Actions/Booking/BookAppointment.php` - transaction scope, single engine re-validation
- `app/Mail/AppointmentConfirmationMail.php` - queued, scalar capture at construct
- `database/migrations/2026_06_10_234907_create_appointments_table.php` - indexes `(team_id, starts_at)`, `(staff_id, starts_at)`, GiST exclusion index, unique token-hash index
- `Makefile` - PUBLIC_PATHS incl. `/demo-clinic` and `/manage/demo-manage-token`
- `reports/lighthouse/` - fresh per-URL artifacts (2026-06-11 00:21)

## Flows reviewed

- Booking page query profile (read from code): step 1 one `services` query; step 2 one `staffOptions` query; step 3 entry one horizon-wide engine pass (`refreshAvailableDates`: 1 staff query with eager `availabilityRules` + bounded `timeOff`, 1 grouped reserved-appointments query, pure computation) plus one single-day pass per re-render; no per-row lazy loads anywhere in the loop bodies - constant query counts, no N+1
- Manage/confirmed pages: one hash-indexed appointment lookup + three constant relation loads for the summary - O(1)
- Mail path: relations touched once in the constructor during the request (4 queries), then pure scalars; `ShouldQueue` keeps SMTP out of the request
- Concurrency/atomicity: insert + engine re-validation inside one transaction; loser resolves via the GiST constraint, no lock-and-retry loops

## Tests reviewed

- `tests/Feature/Booking/ConcurrencyTest.php` - NFR-RELY-1: exactly one booking wins under a genuine in-flight DB race; partial overlap blocked; Eloquent path equally guarded
- `tests/Feature/Booking/BookingFlowTest.php::a customer can complete a booking through the whole flow` - `Mail::assertQueued` proves async send (NFR-OPS-2)
- `tests/Browser/BookingSmokeTest.php` - full flow renders and interacts without JS errors at real browser speed

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| Lighthouse artifacts `reports/lighthouse/` (today 00:21, 127.0.0.1:8123) | pass | `/demo-clinic` 0.94/1.0/1.0/0.91; `/manage/demo-manage-token` 0.95/1.0/1.0/0.91 - all ≥ QG-PERF budgets; 9 prior public URLs also green |
| `curl -w %{time_total}` on Herd | pass | `/demo-clinic` 0.65s (cold), `/manage/demo-manage-token` 0.085s - within NFR-PERF-1 spot-check |
| `vendor/bin/pest tests/Feature/Booking` (incl. concurrency suite) | pass | within 124/124 fresh run |
| `make test` | pass | 359/359 |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ⚠️ | Code review shows constant query counts on every booking step (eager `availabilityRules`/bounded `timeOff`, one grouped reserved-ranges query); however no query-count assertion test exists for the booking page. test-plan.md assigns N+1 tests to list/calendar views (Epics 04/07), not Epic 06, so this is a tracked gap, not a breach (F2) |
| 2 | Query efficiency | ✅ | Reserved ranges fetched in one `whereIn(staff_id) + range` query and grouped in memory; hot-path indexes exist: `(staff_id, starts_at)`, `(team_id, starts_at)`, GiST on the range, unique index on the token hash (O(1) manage lookup) |
| 3 | Lighthouse budget | ✅ | Fresh artifacts: booking page 0.94 perf / 1.0 a11y / 1.0 bp / 0.91 seo; manage page 0.95/1.0/1.0/0.91 |
| 4 | Server response budget | ✅ | curl spot-checks 0.65s cold / 0.085s warm with the demo dataset |
| 5 | Async | ✅ | Confirmation mail `ShouldQueue`, asserted queued; nothing sends inline |
| 6 | Reliability/concurrency | ✅ | Booking atomic in one transaction; named concurrency suite proves no double-booking incl. in-flight race and partial overlap (NFR-RELY-1); see F1 for the customer-upsert edge |
| 7 | Asset weight | ✅ | Booking layout reuses the existing built bundle; no new assets; Lighthouse perf ≥0.94 confirms |
| 8 | Caching | ✅ | No per-request heavy work on marketing pages touched; booking page is inherently dynamic; horizon pass runs once per step entry, not per render (cached in `$availableDates`) |
| 9 | Observability | ✅ | Correlation-ID middleware applies to public routes; queued mail failures land in `failed_jobs` (framework default, database queue) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-RELY-1 (graceful concurrency) | `BookAppointment::upsertCustomer` | Concurrent first-time bookings with the same email race the `(team_id, email)` unique index; the loser gets an untranslated 23505 -> 500 instead of the friendly retry the 23P01 path gets. Integrity holds; UX/reliability does not | Translate/retry 23505 like 23P01; shared finding with Architecture F1, track for Epic 07/10 |
| F2 | Low | NFR-PERF-2 | `tests/` | No query-count assertion for the booking page (constant-count verified by reading only). Epic 07's list/calendar N+1 test is where the plan mandates it | Add a query-count budget test when Epic 07 introduces its mandated N+1 checks |
| F3 | Low | NFR-PERF-1 | `⚡show.blade.php::refreshAvailableDates` | The step 3 entry computes slots for the whole horizon (default 60 days x staff) in one request; fine at demo scale (Lighthouse 0.94) but the heaviest public code path; grows linearly with horizon, staff count, and appointment density | Monitor; consider per-week windowing if tenants raise the horizon (Epic 10 hardening candidate) |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: QG-PERF is green on both new public pages with fresh artifacts, the mail path is queued, indexes cover the hot lookups, and the concurrency suite proves atomic, race-safe booking; the Medium finding is a rare-race error-path UX issue with no integrity impact, and the remaining items are tracked monitoring/test gaps.
- Blocking findings remaining: 0
