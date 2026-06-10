# Review Report - QA - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** Epic 01 §Required tests (smoke 200 + no console errors, footer-link resolution, a11y/perf gates extended), QG-TESTS, QG-COVERAGE, QG-MUTATION, QG-E2E, QG-A11Y, QG-PERF; test-plan.md §Accessibility & performance per page (public pages via `PUBLIC_URLS` tool gates)

## Files reviewed

- `tests/Feature/Public/PublicPagesTest.php` - the epic's feature suite (6 test functions, 16 cases)
- `tests/Browser/PublicSmokeTest.php` - browser smoke + axe suite (7 cases)
- `Makefile:15-19,118-122` - `PUBLIC_PATHS`/`PUBLIC_URLS` cover all six pages; `accessibility`/`performance` targets consume them (no stale gate list)
- `.github/workflows/ci.yml:162,217,220` - CI passes `APP_URL` only; `PUBLIC_URLS` derives from it, so local and CI check the same path list
- `lighthouserc.json`, `.pa11yci` - unmodified thresholds (perf 0.90 / a11y 0.95 / bp 0.90 / seo 0.90; WCAG2AA)
- `specs/quality-gates.md` §Baseline status - coverage baseline accounting

## Flows reviewed

- Each public route over HTTP (feature) and in a real Chromium (browser): 200, expected copy, zero JS/console errors.
- Link integrity: footer links on every page plus a crawl of every internal `href` in the rendered HTML of all six pages, each asserted 2xx.
- Gate wiring: adding a page to `PUBLIC_PATHS` automatically enrolls it in both pa11y and Lighthouse; verified all six paths are listed.

## Tests reviewed

- `PublicPagesTest::public page renders successfully` (6 cases) - 200 per route and named-route/path agreement; meaningful (asserts both status and URL mapping)
- `PublicPagesTest::the global footer appears on every public page with resolving links` (6 cases) - asserts all six footer targets plus the repo URL appear on each page
- `PublicPagesTest::every internal link target on the public pages resolves` - dynamic extraction of hrefs, not a hardcoded list, so new dead links fail the suite; asserts the set is non-empty (guards against a silently empty crawl)
- `PublicPagesTest::the repository ships an MIT license linked from the open-source page` - reads `LICENSE`, asserts MIT, asserts the page references it
- `PublicPagesTest::marketing copy never uses the em-dash` - rendered-output check, catches copy regressions wherever they originate
- `PublicPagesTest::no auth-only routes leak into the public page set` - middleware regression guard
- `PublicSmokeTest::public page loads without javascript or console errors` (6 cases) - real-browser render per page
- `PublicSmokeTest::the homepage is accessible` - axe `assertNoAccessibilityIssues()` in-browser; complements the pa11y tool gate

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact tests/Feature/Public` | pass | 16/16, 83 assertions (fresh run for this review) |
| `php artisan test --compact` | pass | 95/95, 265 assertions, 1 risky (risky test is outside Public/Ops/Unit suites; see F4) |
| `make e2e` | pass | 7/7 browser tests, 19 assertions (fresh run) |
| `make accessibility` (build log) | pass | pa11y-ci WCAG2AA: 6/6 URLs, 0 errors |
| `make performance` | pass | `reports/lighthouse/manifest.json` verified: all 6 URLs 1.0/1.0/1.0/1.0 on 127.0.0.1 (CI-equivalent serving; Herd-HTTP quirks documented in `docs/assumptions.md`) |
| `make coverage` (build log) | 76.8% baseline | Epic 01 adds no PHP application code (views/routes/config only), so no new uncovered lines; below-80 baseline is the tracked Epic 00 deferral (quality-gates.md §Baseline status) |
| `make mutation` (build log) | n/a for this epic | No new PHP classes, so no `covers()`/`mutates()` targets exist for Epic 01 code |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | All three required test groups present and passing: route smoke (feature + browser), footer-link resolution, a11y/perf gates extended via `PUBLIC_PATHS` |
| 2 | Right layer | ✅ | Static content checked at HTTP layer (cheap, exact), real-browser layer (JS/console/axe), and tool layer (pa11y/Lighthouse); no over-engineered unit tests for Blade |
| 3 | Coverage | ⚠️ | Overall 76.8% (build log), unchanged by this epic since it ships no PHP logic; gate remains a tracked Medium deferral from Epic 00, blocking at Phase 6/Epic 10 (F3) |
| 4 | Mutation | n/a | No PHP classes added; mutation targets begin with Epic 02+ domain code |
| 5 | Meaningful assertions | ✅ | Every test asserts outcomes (status, content, link resolution, license text, console state); the crawl test guards against an empty link set |
| 6 | Edge cases | ✅ | Relevant edge cases for a static site are covered: dead internal links (crawl), auth-middleware leak, em-dash copy regression; DST/booking edge cases are n/a until Epic 05/06 |
| 7 | Named suites | n/a | Tenancy isolation / booking concurrency suites do not exist yet (Epics 03/06); nothing weakened |
| 8 | Factories & data | ✅ | No models needed; tests run on PostgreSQL (`librenexus_test`) per phpunit.xml; no fixture hacks |
| 9 | Async assertions | n/a | No mail/queue behavior in scope |
| 10 | No skips | ✅ | No `skip`/`only`/`todo` in `tests/Feature/Public` or `tests/Browser` (grep clean) |
| 11 | Determinism | ✅ | No time-dependent logic; both suites re-run green twice during this review; browser suite self-serves on a local port |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | QG-TESTS | `tests/Feature/Public/PublicPagesTest.php:74` | The auth-middleware-leak dataset omits `home`; the `/` route is exercised as a guest elsewhere, but the explicit guard skips it | Add `'home'` to the dataset |
| F2 | Low | AC-2 | `tests/Feature/Public/PublicPagesTest.php:44-46` | In-page anchors (`/docs#booking`, docs section nav) are not asserted against existing element ids; a renamed section id would silently break the homepage secondary CTA | Assert anchor targets exist in the docs page HTML (cheap string check) |
| F3 | Medium | QG-COVERAGE | build log / quality-gates.md §Baseline status | Overall line coverage 76.8% < 80% (starter-kit baseline, pre-existing tracked deferral; not regressed by Epic 01) | Close progressively per epic; blocking at Epic 10 per the baseline rules |
| F4 | Low | QG-TESTS | full-suite run | 1 test flagged risky in the full run; isolated runs show it is outside the Public/Ops/Unit suites (pre-existing starter-area test) | Identify and fix the risky test before Epic 10 |

## Required fixes (blocking)

- None. No Critical/High findings; F3 is the pre-existing tracked baseline deferral, not an Epic 01 regression.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every required test exists, is meaningful, and passes on fresh runs at all three layers, and the a11y/perf tool gates genuinely cover all six new URLs through `PUBLIC_PATHS`; warnings are two Low test-hardening nits plus the inherited coverage baseline already tracked for Epic 10.
- Blocking findings remaining: 0
