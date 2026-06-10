# Review Report - QA - Epic 00 (Foundations & quality harness)

## Reviewed scope

- **Epic / change:** Epic 00 (Foundations & quality harness)
- **Requirements/rules in scope:** QG-TESTS, QG-COVERAGE, QG-MUTATION, QG-E2E (wired only), epic "Required tests" list (health 200/503, correlation-ID header, SEC-HEADERS + cookie flags, baseline `arch()`)

## Files reviewed

- `tests/Feature/Ops/HealthCheckTest.php` - required health tests
- `tests/Feature/Ops/CorrelationIdTest.php` - required correlation-ID tests
- `tests/Feature/Ops/SecurityHeadersTest.php` - required SEC-HEADERS tests
- `tests/Feature/Ops/ObservabilityTest.php` - AC-6 observability wiring tests
- `tests/Unit/ArchTest.php` - required baseline arch test
- `tests/Pest.php`, `phpunit.xml` - suite wiring (PostgreSQL `librenexus_test` per CI env and local run)
- `Makefile` (test/coverage/mutation/e2e targets), `specs/quality-gates.md` §Baseline status

## Flows reviewed

- `/health` happy + degraded paths, including middleware contract (no auth/throttle)
- Correlation-ID lifecycle: generation, safe reuse, unsafe rejection, log-context sharing, JSON log emission
- Security headers on success and on a 404 error response; cookie flags under default and prod-like config

## Tests reviewed

- `HealthCheckTest::health check reports ok when the database is reachable` - asserts status code, exact JSON values, and full structure (not just 200)
- `HealthCheckTest::health check reports 503 when the database is unreachable` - simulates DB down by swapping to an unreachable connection, restores config, asserts 503 + degraded payload (the epic's required simulation)
- `HealthCheckTest::health check requires no authentication and no rate limiting` - inspects gathered route middleware
- `CorrelationIdTest` (5 tests) - covers generated UUID, stable echo of safe inbound ID, replacement of injection-shaped input (`"bad value\nwith newline"`), `Log::sharedContext()` propagation, and a real JSON log line decode asserting `context.correlation_id`
- `SecurityHeadersTest` (4 tests) - exact header values; CSP directive content; HttpOnly/SameSite/Secure cookie flags read from actual Set-Cookie objects
- `ObservabilityTest` (2 tests) - DSN key exists and is null by default; `queue.failed.driver` is `database-uuids` and the `failed_jobs` table actually exists (`Schema::hasTable`)
- `ArchTest` (5 arch rules) - debug-helper ban, model/enum/middleware conventions, strict equality

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact tests/Feature/Ops tests/Unit/ArchTest.php` | pass | 19/19, 47 assertions (fresh run on PostgreSQL for this review) |
| `make test` (build log) | pass | 79/79 full suite on PostgreSQL |
| `make coverage` (build log) | 76.8% overall | Below the 80% app-level bar by design at this stage; per quality-gates.md §Baseline status and goal.prompt blocking-scope, overall coverage is non-blocking until Phase 6/Epic 10. Epic-touched code (HealthController, AddCorrelationId, SetSecurityHeaders) is fully exercised by the Ops tests (every branch has a dedicated test) |
| `make mutation` (build log) | tooling verified | No classes carry `covers()`/`mutates()` yet; per QG-MUTATION the annotation requirement targets critical domain classes (slot engine, booking, tenancy, tokens), none of which exist yet |
| `make e2e` | wired, no-op | `tests/Browser` does not exist yet; Makefile:92-97 prints the explicit warning required by the baseline note instead of silently passing |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | All four "Required tests" from the epic file are present and pass (files above; 19/19 fresh run) |
| 2 | Right layer | ✅ | HTTP behaviors feature-tested; conventions arch-tested. No browser test needed (no pages added); E2E gate wired with visible warning |
| 3 | Coverage | ⚠️ | Overall 76.8% < 80% (baseline-accepted, non-blocking for Epic 00 per quality-gates.md:113 and goal.prompt blocking-scope); the three classes this epic added are covered on every branch (200/503, reuse/generate/reject ID, headers on success/error, secure/insecure cookie) (F1) |
| 4 | Mutation | ⚠️ | Gate wired (Makefile:89-90, threshold 70 matches QG-MUTATION); no `covers()`/`mutates()` classes yet, which QG-MUTATION only mandates for critical domain logic (none exists yet). Consider annotating the Ops middleware when mutation runs become meaningful (F2) |
| 5 | Meaningful assertions | ✅ | Every test asserts concrete outcomes: exact header values, decoded JSON log content, schema existence, middleware lists; zero assertion-free tests |
| 6 | Edge cases | ✅ | Epic-relevant edges present: DB down (503), injection-shaped inbound request ID, headers on error responses, prod-config secure cookie. DST/booking edges n/a until Epics 05-08 |
| 7 | Named suites | n/a | Tenancy-isolation and booking-concurrency suites do not exist yet (Epics 03/06); nothing weakened |
| 8 | Factories & data | ✅ | No models created in these tests (none needed); suite runs on PostgreSQL (`librenexus_test`), as required |
| 9 | Async assertions | n/a | No queued work added; failed-job store wiring asserted via config + schema (ObservabilityTest) |
| 10 | No skips | ✅ | No `skip`/`only`/`todo`/incomplete markers in any test file reviewed |
| 11 | Determinism | ✅ | The 503 test restores `database.default` before asserting; the log test writes to a unique temp file and unlinks it; no sleeps, no time-dependence, no port/network coupling |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | QG-COVERAGE | build log (`make coverage`) | Overall line coverage 76.8% is below the 80% application bar. Explicitly accounted for in quality-gates.md §Baseline status (starter-kit scaffolding uncovered) and non-blocking until Phase 6/Epic 10; epic-touched code is fully covered. | Track: each subsequent epic must cover its code; Epic 10 closes the overall gap. Keep the entry in the baseline table honest (do not lower `COVERAGE_MIN`). |
| F2 | Low | QG-MUTATION | `tests/Feature/Ops/*` | Ops tests carry no `covers()` annotations, so mutation runs cannot attribute these classes. Not required (Ops middleware is not critical domain logic) but cheap signal. | Optionally add `covers()` to Ops tests in a later epic; mandatory only for the Epic 03/05/06/08 critical classes. |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: All required Epic 00 tests exist, pass on PostgreSQL, and assert real outcomes with the right edge cases; the only gaps are the documented baseline coverage shortfall (76.8%, tracked, non-blocking by the spec's own baseline rules) and optional mutation annotations.
- Blocking findings remaining: 0
