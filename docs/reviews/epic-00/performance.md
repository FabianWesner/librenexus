# Review Report - Performance - Epic 00 (Foundations & quality harness)

## Reviewed scope

- **Epic / change:** Epic 00 (Foundations & quality harness)
- **Requirements/rules in scope:** NFR-PERF-1/2 (no pages added; wiring only), NFR-RELY (failed jobs), NFR-OBS-1..4, NFR-OPS-1/2, QG-PERF (gate wired, baseline status)

## Files reviewed

- `app/Http/Controllers/HealthController.php` - per-request cost of the health probe
- `app/Http/Middleware/AddCorrelationId.php` - per-request overhead of the global middleware
- `app/Http/Middleware/SetSecurityHeaders.php` - per-request overhead (constant header writes)
- `config/logging.php` - structured channel handler choice (single-file stream, JsonFormatter)
- `database/migrations/0001_01_01_000002_create_jobs_table.php` - queue/failed-jobs indexes
- `Makefile` (`performance`, `accessibility`, `e2e` targets), `lighthouserc.json` presence, `.github/workflows/ci.yml` e2e/perf job
- `specs/quality-gates.md` §QG-PERF and §Baseline status

## Flows reviewed

- `GET /health`: one constant `select 1` query, no model hydration, no view rendering; suitable for a load-balancer probe (NFR-OPS-1). The 503 path swallows the exception without retry loops, so a down database fails fast.
- Global middleware path: `AddCorrelationId` does one regex match + optional UUID generation; `SetSecurityHeaders` sets four static headers from a class constant. Both are O(1) per request with no I/O.
- Queue plumbing: `jobs.queue` indexed (migration line 16) and `failed_jobs (connection, queue, failed_at)` composite index (line 46), so failed-job inspection queries are indexed.

## Tests reviewed

- `tests/Feature/Ops/HealthCheckTest.php` - proves the endpoint responds correctly in both states (the only "hot path" this epic adds)
- `tests/Feature/Ops/CorrelationIdTest.php::structured log lines are json and include the correlation id` - proves NFR-OBS-1/2 wiring works end to end
- `tests/Feature/Ops/ObservabilityTest.php::failed queue jobs are recorded in an inspectable store` - NFR-OBS-4 / failed-job visibility
- N+1 / query-count tests: none required; no list/calendar/dashboard views were added or altered

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make performance` (Lighthouse) | tooling verified (build log / baseline table) | Needs real public pages (Epic 01); quality-gates.md:120 records this honestly. Gate is wired in Makefile:119-120 and runs in the CI e2e job |
| `make accessibility` (pa11y) | tooling verified (baseline table) | Same baseline note (starter welcome page replaced in Epic 01) |
| `php artisan test --compact tests/Feature/Ops` | pass | 14/14, sub-second suite; `/health` responds in milliseconds under test |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | n/a | No list/calendar/dashboard views added; zero Eloquent queries introduced (health uses raw constant `select 1`) |
| 2 | Query efficiency | ✅ | Health probe is a single constant query; `jobs`/`failed_jobs` carry the indexes used by queue workers and inspection (migration lines 16, 39, 46) |
| 3 | Lighthouse budget | n/a (wired) | No pages added/changed by this epic; gate wired in Makefile + CI and tracked in quality-gates.md §Baseline status (QG-PERF "tooling verified") |
| 4 | Server response budget | ✅ | `/health` does constant work (one trivial query, JSON encode of 3 scalars); feature suite confirms fast responses. No NFR-PERF-1 page in scope |
| 5 | Async | ✅ | No inline email/long-running work added; queue + failed-job store prepared so later epics queue work (NFR-OPS-2 wiring) |
| 6 | Reliability/concurrency | n/a | Booking atomicity is Epic 06 (strategy already fixed at the DB level per ADR-0003); failed-job visibility (NFR-RELY/OBS-4) proven by ObservabilityTest |
| 7 | Asset weight | n/a | No frontend assets added or changed by this epic |
| 8 | Caching | n/a | No public pages yet; nothing per-request heavy added |
| 9 | Observability | ✅ | Structured JSON channel (config/logging.php:72-81) + per-request correlation ID middleware, both test-proven; failed jobs recorded in an inspectable DB table |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-OPS-1 | `app/Http/Controllers/HealthController.php:28` | Each probe opens/uses a DB connection with no timeout tuning; with default driver timeouts a hung (rather than refused) database could make the probe slow instead of fast-failing. Acceptable for v1 LB probing. | Optionally set a short connect timeout for the health connection during Epic 10 hardening. |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: This epic adds only O(1) cross-cutting middleware and a constant-cost health probe; queue tables are correctly indexed, observability is in place, and the perf/a11y gates are wired with their baseline status honestly recorded for the page-bearing epics.
- Blocking findings remaining: 0
