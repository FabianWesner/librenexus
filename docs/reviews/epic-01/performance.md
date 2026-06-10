# Review Report - Performance - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** QG-PERF (Lighthouse budgets on public pages), NFR-PERF-1 (server render budget), NFR-PERF-2 (no N+1), epic note "keep these pages public, cacheable, and free of N+1 or heavy queries"

## Files reviewed

- `resources/views/marketing/*.blade.php` (6 files) - confirmed zero database access: views render literals, `route()`, and `config()` only; the homepage "calendar mock" is pure CSS/Blade, no image payload
- `resources/views/components/layouts/public.blade.php` - no per-request heavy work; one Vite CSS+JS bundle via the shared head partial
- `routes/web.php:7-12` - `Route::view()` (no controller, no middleware-level queries beyond the framework session layer)
- `Makefile:15-19,121-122` - all six paths enrolled in `make performance`
- `lighthouserc.json` - budgets perf ≥ 0.90, a11y ≥ 0.95, best-practices ≥ 0.90, SEO ≥ 0.90 (unmodified, no threshold gaming)
- `public/build/assets/` - built asset weights
- `docs/assumptions.md:140-146` - documented local-vs-CI Lighthouse serving difference

## Flows reviewed

- GET on each marketing route - static Blade render; no Eloquent models exist in these views, so N+1 is structurally impossible; no external HTTP calls, no filesystem reads beyond compiled views.
- Asset pipeline - single hashed CSS bundle (240 KB pre-compression, Tailwind-purged) + small JS; Instrument Sans served as self-hosted subsets (~20 KB woff2 per weight); no images on any marketing page (the product visual is CSS), so nothing unoptimized can ship.
- Gate flow - CI serves `php artisan serve` on 127.0.0.1 and runs `make performance APP_URL=...`; the same mechanism was reproduced for the build-log evidence.

## Tests reviewed

- `tests/Browser/PublicSmokeTest.php::public page loads without javascript or console errors` (6 cases) - pages fully render in a real browser without errors (a prerequisite for the Lighthouse best-practices score)
- `tests/Feature/Public/PublicPagesTest.php::public page renders successfully` - sub-second full-suite evidence: 16 HTTP renders complete in ~0.75 s total on the test runner, comfortably inside the NFR-PERF-1 budget
- N+1/query-count tests - n/a this epic (no queries to count; first list views arrive with Epic 04)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make performance` | pass | `reports/lighthouse/manifest.json` verified for this review: all 6 URLs score performance 1.0, accessibility 1.0, best-practices 1.0, SEO 1.0 (served on 127.0.0.1:8123, CI-equivalent). Local Herd-HTTP runs fail only `is-on-https` and a Herd favicon proxy quirk; both documented as environment effects in `docs/assumptions.md:140-146` |
| `make accessibility` (build log) | pass | pa11y-ci 6/6 URLs, 0 errors (feeds the a11y category) |
| `make e2e` | pass | 7/7, no console errors (fresh run) |
| `du -sh public/build/assets/*` | pass | largest asset 240 KB CSS (pre-compression); fonts ≤ 24 KB each; no large bundles or images |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | No queries at all in marketing views (file review); query-count tests become applicable with Epic 04 lists |
| 2 | Query efficiency | n/a | Zero database access on these pages |
| 3 | Lighthouse budget | ✅ | All 6 pages 1.0/1.0/1.0/1.0 vs budgets 0.90/0.95/0.90/0.90 (manifest verified); thresholds unmodified |
| 4 | Server response budget | ✅ | Static `Route::view()` renders; 16 feature-test renders in ~750 ms total; no heavy work per request |
| 5 | Async | n/a | No mail/jobs in this epic |
| 6 | Reliability/concurrency | n/a | Booking concurrency lands in Epic 06 |
| 7 | Asset weight | ✅ | One purged CSS bundle (240 KB raw), subset self-hosted fonts, zero images on marketing pages |
| 8 | Caching | ⚠️ | Pages are statically renderable and edge-cacheable in principle, but responses carry the framework session/no-cache defaults and no `Cache-Control: public` headers (F1); acceptable at current scale |
| 9 | Observability | ✅ | Epic 00 correlation-ID + structured logging middleware applies to these routes; nothing regressed (Ops suite green in fresh full run) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | epic note "cacheable" | `routes/web.php:7-12` | Marketing responses use framework defaults (session cookie, no public cache headers), so they are not shared-cache friendly despite being static; render cost is trivial today | Consider `Cache-Control: public` headers or route-level cache middleware for marketing pages in Epic 10 hardening; re-evaluate once the booking page raises public traffic |

## Required fixes (blocking)

- None. No Critical/High findings.

## Final decision

**PASS**

- Rationale: QG-PERF is green with maximum scores on every public page against unmodified budgets, the pages perform no database work at all, and asset weight is lean; the single Low item (explicit cache headers) is an optimization note for Epic 10, not a budget risk.
- Blocking findings remaining: 0
