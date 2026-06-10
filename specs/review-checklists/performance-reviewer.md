# Performance Reviewer — Checklist

**Verifies against:** [../non-functional.md](../non-functional.md) (NFR-PERF,
NFR-RELY, NFR-OBS), [../quality-gates.md](../quality-gates.md) (QG-PERF).

**Mission:** confirm pages and queries are efficient, async work doesn't block
requests, and the system behaves reliably under load/concurrency.

## Checklist

1. **No N+1** — list, calendar, and dashboard views eager-load relations; a
   query-count assertion test proves it (NFR-PERF-2). Cite the test.
2. **Query efficiency** — aggregates use group-by/counts, not per-row loops;
   appropriate indexes exist for hot queries (e.g. appointment lookups by staff +
   time, tenant scoping columns).
3. **Lighthouse budget** — `make performance` meets QG-PERF (performance ≥ 0.90,
   a11y ≥ 0.95, best-practices ≥ 0.90, SEO ≥ 0.90) for pages added/changed.
4. **Server response budget** — key pages render within the NFR-PERF-1 budget
   with the demo dataset (spot-check timing/log evidence).
5. **Async** — emails/reminders are queued, not inline (NFR-OPS-2); requests
   return promptly.
6. **Reliability/concurrency** — booking/reschedule are atomic; the concurrency
   suite proves no double-booking (NFR-RELY-1); jobs are idempotent (NFR-RELY-2).
7. **Asset weight** — built assets are reasonable; no accidental large bundles or
   unoptimized images on public pages.
8. **Caching** — public pages are cacheable where appropriate; no per-request
   heavy work on marketing pages.
9. **Observability** — structured logs + correlation ID present (NFR-OBS); failed
   jobs are visible.

## Tools to run

`make performance`, plus N+1/query-count tests and the concurrency suite.

## Decision rule

- **Fail** for an N+1 on a list/calendar/dashboard view, a missed Lighthouse
  budget on a key page, inline email sending, or a non-atomic booking path.
- **Pass with warnings** for Medium perf items (e.g. a small budget miss on a
  low-traffic page) that are tracked.
- **Pass** when QG-PERF is green and no N+1/blocking-async issues remain.
