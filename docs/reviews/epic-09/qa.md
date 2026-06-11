# Review Report — QA Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard metrics/onboarding tests, demo seeder tests, dashboard browser smoke)
- **Requirements/rules in scope:** Epic 09 "Required tests", QG-TEST/QG-COVERAGE/QG-MUTATION/QG-E2E, test-plan §Accessibility & performance per page

## Files reviewed

- `tests/Feature/Dashboard/DashboardMetricsTest.php` — metric correctness incl. timezone, status filtering, window edges, ordering, grouping, query-count growth
- `tests/Feature/Dashboard/OnboardingTest.php` — checklist state machine via rendered-HTML `data-state` assertions
- `tests/Feature/Ops/DemoSeederTest.php` — idempotency, explorability, bookability via the real slot engine, deterministic token, demo login
- `tests/Browser/DashboardSmokeTest.php` — axe + JS-error checks for both dashboard states, copy-button focusability
- `tests/Feature/DashboardTest.php` — preserved guest-redirect and AC-7 callout tests against the new SFC

## Flows reviewed

- AC-1 day-window derivation — re-derived `todayWindow()` (tenant-tz `startOfDay()` then `->utc()`, `addDay()` before conversion so DST-short/long days stay correct); the Pacific/Auckland test pins both boundary directions (late-UTC-yesterday counts, early-UTC-tomorrow does not)
- AC-2 state machine — tests cover all four progression states plus the completed swap; the `currentStepKey` highlight is asserted via `data-state` regexes, not just text presence
- AC-3 end-to-end trace — `make setup` → `db:seed --force` (Makefile:50) → demo tenant with Mon-Fri 09:00-17:00 availability for two staff, three linked services, future samples only from 13:00 so morning slots stay open; `DemoSeederTest` calls the production `GetBookableSlots` action and asserts non-empty slots; the booking submit/confirm path itself is proven by the existing `BookingFlowTest` + `BookingSmokeTest` against identical data shapes
- Idempotency — double `$this->seed()` with full before/after count snapshot across teams, staff, services, rules, appointments, users

## Tests reviewed

- `DashboardMetricsTest::the today count uses the tenant-timezone day boundary, not UTC` — kills the naive-UTC implementation
- `DashboardMetricsTest::the dashboard query count does not grow with the number of appointments` — equality (not ≤) between 1 and 10 appointments across both staff
- `DashboardMetricsTest::the upcoming count covers exactly the next seven days…` — both window edges (day 6 in, day 8 out, past out) and status filtering
- `OnboardingTest` (6) — assert outcomes (rendered states, real route URLs), not execution
- `DemoSeederTest::a demo service is genuinely bookable…` — uses the real engine, not a row-count proxy
- `DemoSeederTest::the deterministic token appointment exists…` — future + confirmed + `findByManageToken` round trip
- `DashboardSmokeTest` (2) — `assertNoAccessibilityIssues()` + `assertNoJavascriptErrors()` in both states; programmatic focus proof for the copy button

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 462/462, 1469 assertions (fresh run) |
| `make coverage` | pass | Total 96.6% (fresh run), gate ≥ 80% |
| `make e2e` | pass | 35/35, 140 assertions (fresh run) |
| `php artisan test tests/Feature/Tenancy` | pass | 87/87, named isolation suite intact |
| `make mutation` | pass (lead-verified) | 98.20%; only the 4 documented equivalent mutants survive (assumptions.md §Availability) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | All four "Required tests" of the epic present: metric correctness, onboarding states, seeder bookability + deterministic token, axe + N+1 + PUBLIC_URLS wiring (Makefile:18) |
| 2 | Right layer | ✅ | Metrics/onboarding feature-tested; rendered-state checks at HTTP level; a11y/JS at browser level; seeder tested through real actions |
| 3 | Coverage | ✅ | 96.6% total fresh; the dashboard logic is exercised by 13 feature + 2 browser tests |
| 4 | Mutation | ⚠️ | Gate green (98.20%, lead-verified); note the dashboard SFC class lives in resources/views and is not a `covers()` mutation target, so its logic is guarded by behavioral assertions only (F1) |
| 5 | Meaningful assertions | ✅ | Exact counts, exact orderings (`modelKeys()` lists), regex state assertions, query-count equality; no assertion-free tests |
| 6 | Edge cases | ✅ | Non-UTC tenant tz day boundary (Auckland, +12), 7-day window edges, reserving-status matrix, double-seed, deterministic token; DST/booking edges unchanged from prior epics and still green |
| 7 | Named suites | ✅ | Tenancy isolation 87/87 fresh; concurrency suite untouched and green in the full run |
| 8 | Factories & data | ✅ | Factories with states (`between`, `status`, `window`, `linkedTo`) throughout; `RefreshDatabase` on PostgreSQL (librenexus_test) |
| 9 | Async assertions | n/a | No mail/queue work in this epic |
| 10 | No skips | ✅ | No `skip`/`only`/`todo` in the new tests (grep clean in the run output) |
| 11 | Determinism | ✅ | `CarbonImmutable::setTestNow` pins time-sensitive tests; seeder tests use relative dates safely; non-UTC tz exercised explicitly |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | QG-MUTATION reach | `resources/views/pages/dashboard/⚡index.blade.php` | The SFC component class is outside the mutation harness (`covers()` targets app/ classes), so e.g. a `>=` to `>` flip in a metric query would only be caught by the behavioral tests. Those tests are edge-pinning (window edges asserted both sides), so practical risk is low | Defer to the existing Epic 10 item that brings SFC classes under the complexity/mutation tooling |
| F2 | Low | Test depth (SEC-TENANT overlap) | `tests/Feature/Dashboard/DashboardMetricsTest.php` | No second-tenant fixture asserting metric exclusion (the isolation suite covers route access, the arch test covers scoping) | Defer: one cross-tenant appointment in a metric test, Epic 10 |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: every required test exists and passes in fresh runs, coverage (96.6%) and mutation (98.20%) gates clear with real margins, assertions are outcome-based and edge-pinning, and the named suites are intact; both findings are Low test-depth notes already aligned with tracked Epic 10 work.
- Blocking findings remaining: 0
