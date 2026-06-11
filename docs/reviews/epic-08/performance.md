# Review Report — Performance Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (manage page actions, reminder command, queued mailables)
- **Requirements/rules in scope:** NFR-PERF-1/2, NFR-RELY-1/2, NFR-OPS-2, NFR-OBS, QG-PERF

## Files reviewed

- `app/Console/Commands/SendAppointmentReminders.php` — query shape, eager loads, per-row claim
- `resources/views/pages/booking/⚡manage.blade.php` — per-request work (token lookup, slot computation scope)
- `app/Actions/Appointments/RescheduleAppointment.php` — transaction boundaries, single-day engine pass
- `app/Mail/*.php` — `ShouldQueue` on all four mailables
- `database/migrations/2026_06_10_234907_create_appointments_table.php` — indexes: unique `cancellation_token_hash`, `(team_id, starts_at)`, `(staff_id, starts_at)`, GiST exclusion
- `routes/console.php` — 15-minute cadence

## Flows reviewed

- Manage page request — one indexed token lookup + single-appointment relations; reschedule slot list constrained to one day (`fromDate = untilDate`), not the full horizon
- Reschedule — one transaction: single-day engine pass + one-row UPDATE; constraint violation exits cleanly
- Reminder run — one set-based SELECT with eager loads, then one conditional single-row UPDATE + one queue push per due appointment; no per-row policy queries (team join carries the window)
- Mail dispatch — queued via the database queue; failed jobs land in `failed_jobs` (NFR-OPS-2)

## Tests reviewed

- `tests/Feature/Comms/ReminderTest.php:117` — `Model::preventLazyLoading()` over a multi-row run proves no N+1 in the command (NFR-PERF-2)
- `tests/Feature/SelfService/RescheduleViaTokenTest.php:203` — constraint-level race translated cleanly; original row intact (NFR-RELY-1)
- `tests/Feature/Comms/ReminderTest.php:104` — double-run idempotency, exactly one queue entry (NFR-RELY-2)
- `tests/Feature/Booking/ConcurrencyTest.php` — named concurrency suite green in the full run
- `tests/Feature/Ops/{ObservabilityTest,CorrelationIdTest}.php` — structured logs + correlation ID still green (NFR-OBS)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| Lighthouse CI, 11 public pages over https | pass | all assertions met; `/manage/demo-manage-token`: performance 1.0, accessibility 1.0, best-practices 0.96, SEO 0.90 (QG-PERF thresholds 0.90/0.95/0.90/0.90) |
| pa11y-ci, 11 pages over https | pass | 11/11, 0 errors |
| `make test` / `make e2e` | pass | 442/442, 33/33 (concurrency + N+1 suites included) |

Tool note: the stock `make performance`/`make accessibility` fail on this machine for environmental reasons only: `APP_URL` defaults to `http://librenexus.test`, Herd 301-redirects to https, and the puppeteer-managed Chrome does not trust Herd's local CA (`ERR_CERT_AUTHORITY_INVALID`). Re-run against `https://librenexus.test` with cert-ignore flags using the same configs and thresholds; CI is unaffected (it overrides `APP_URL` to the artisan serve URL).

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | Reminder command proven by the `preventLazyLoading` test; manage page is a single-record view (no list rendering) |
| 2 | Query efficiency | ⚠️ | Set-based selection with a single join; token lookup hits the unique hash index. But the reminder due-query (`reminder_sent_at IS NULL` + `starts_at` window across all teams) has no dedicated index; existing `(team_id, starts_at)`/`(staff_id, starts_at)` indexes do not serve it directly (F1) |
| 3 | Lighthouse budget | ✅ | All 11 pages pass QG-PERF incl. the manage page (1.0/1.0/0.96/0.90) |
| 4 | Server response budget | ✅ | Manage page TTFB negligible in Lighthouse runs (perf 1.0); per-request work is one indexed lookup + at most one single-day engine pass |
| 5 | Async | ✅ | All four mailables `ShouldQueue`; command queues only; nothing sent inline (grep + Mail::assertQueued evidence) |
| 6 | Reliability/concurrency | ✅ | Reschedule atomic under the exclusion constraint (stub-engine 23P01 test); reminder claim is a conditional single-row UPDATE, double-run tested |
| 7 | Asset weight | ✅ | No new JS/CSS; page reuses the booking layout and built bundle |
| 8 | Caching | n/a | Tokened page must not be cached publicly; marketing pages untouched |
| 9 | Observability | ✅ | Correlation-ID and observability suites green; command reports queued count; failed jobs visible in `failed_jobs` |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-PERF (hot-query indexing) | `SendAppointmentReminders.php:65-78` + appointments migration | The every-15-minutes due-query filters `reminder_sent_at IS NULL AND starts_at > now()` across all tenants; no index covers that predicate, so it degrades to a scan as the appointments table grows. Harmless at v1/demo scale and bounded by cadence | Defer to Epic 10: add a partial index, e.g. `(starts_at) WHERE reminder_sent_at IS NULL`, if volume warrants |
| F2 | Low | Tooling reproducibility | `Makefile` (`APP_URL ?= http://librenexus.test`) | Local `make performance`/`make accessibility` cannot pass against Herd's https-redirecting site with an untrusted local CA; gates only run faithfully in CI or with manual overrides | Defer: default `APP_URL` to the https Herd URL and add cert-ignore flags to `.pa11yci`/lighthouse settings for local runs (Epic 10 toolchain polish) |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: QG-PERF is green on every page including the new tokened page, all mail is queued, no N+1 exists on the new paths (proven by test), and both reliability mechanisms (constraint-arbitrated reschedule, conditional-UPDATE reminder claim) are tested. Both findings are Low and tracked.
- Blocking findings remaining: 0
