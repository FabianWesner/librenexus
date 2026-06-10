# Review Report - Product - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** FR-PUBLIC-1..7, AC-1..AC-5 of `specs/epics/epic-01-public-site.md`, `specs/pages.md` §Public, `specs/styleguide.md`

## Files reviewed

- `routes/web.php:7-12` - six named public routes (`home`, `pricing`, `docs`, `open-source`, `privacy`, `imprint`)
- `resources/views/components/layouts/public.blade.php` - public shell: header nav, skip link, global footer (FR-PUBLIC-6)
- `resources/views/marketing/home.blade.php` - FR-PUBLIC-1 homepage
- `resources/views/marketing/pricing.blade.php` - FR-PUBLIC-2 pricing
- `resources/views/marketing/docs.blade.php` - FR-PUBLIC-4 user manual
- `resources/views/marketing/open-source.blade.php` - FR-PUBLIC-3 proof page
- `resources/views/marketing/privacy.blade.php`, `resources/views/marketing/imprint.blade.php` - FR-PUBLIC-5 legal pages
- `LICENSE` - FR-PUBLIC-7 MIT license at repo root
- `config/app.php:28` - `repository_url` (env `APP_REPOSITORY_URL`)
- `docs/assumptions.md` §Public site (Epic 01) - scope decisions (demo CTA target, repo URL placeholder, demo imprint)

## Flows reviewed

- Visitor lands on `/` - hero with headline, subhead, primary CTA "Get started free" (route `register`), secondary CTA "See how booking works" (`docs#booking`, never a dead link per the epic note), 4 feature highlights with Flux icons, "Why is it free?" open-source strip, footer.
- Visitor compares pricing on `/pricing` - single centered "Free" plan card with accent border, 8 included features, sign-up CTA, FAQ including "Is it really free?" and "Can I self-host?", link to open-source page.
- Visitor reads `/docs` - sticky left section nav, prose sections Getting started, Add staff, Add services, Set availability, Share your booking link, Manage appointments, Self-hosting.
- Visitor verifies the project on `/open-source` - GitHub repo, MIT license, CI links, quality-evidence list, `make setup` / `make verify` reproduction section.
- Visitor reads `/privacy` (last-updated date, what/why/isolation/control/cookies) and `/imprint` (operator, software, liability).
- Every page reaches every other page via the global footer (Product / Project / Legal columns + repo link + "MIT licensed" tagline).

## Tests reviewed

- `tests/Feature/Public/PublicPagesTest.php::public page renders successfully` - all six routes 200 at their exact paths (AC-1)
- `tests/Feature/Public/PublicPagesTest.php::the global footer appears on every public page with resolving links` - footer with all six FR-PUBLIC-6 targets on every page (AC-2)
- `tests/Feature/Public/PublicPagesTest.php::every internal link target on the public pages resolves` - every internal `href` extracted from rendered HTML returns 2xx (AC-2)
- `tests/Feature/Public/PublicPagesTest.php::the repository ships an MIT license linked from the open-source page` - AC-4
- `tests/Feature/Public/PublicPagesTest.php::marketing copy never uses the em-dash` - styleguide voice rule
- `tests/Browser/PublicSmokeTest.php::public page loads without javascript or console errors` - real-browser render of all six pages
- `tests/Browser/PublicSmokeTest.php::the homepage is accessible` - axe scan, zero issues

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact tests/Feature/Public` | pass | 16/16, 83 assertions (fresh run for this review) |
| `make e2e` | pass | 7/7 browser tests, 19 assertions (fresh run) |
| `make accessibility` (build log) | pass | pa11y-ci WCAG2AA, 6/6 URLs, 0 errors |
| `make performance` | pass | `reports/lighthouse/manifest.json`: all 6 pages performance 1.0, a11y 1.0, best-practices 1.0, SEO 1.0 (served on 127.0.0.1 like CI; local Herd HTTP fails only `is-on-https` + a Herd favicon proxy quirk, documented in `docs/assumptions.md`) |
| `curl api.github.com/repos/librenexus/librenexus` | 404 | default repository URL placeholder does not resolve yet (F1) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1: route 200 test + styleguide conformance below. AC-2: footer + internal-link tests; external repo link is a tracked placeholder (F1). AC-3: pa11y 0 errors and Lighthouse 1.0/1.0/1.0/1.0 for `/` and `/pricing` (and all others). AC-4: MIT `LICENSE` + open-source page link, tested. AC-5: mobile-first base classes with `sm:`/`md:`/`lg:` breakpoints on every page (e.g. home.blade.php:6 `lg:grid-cols-2`, pricing grid `sm:grid-cols-2`, docs `lg:grid-cols-[14rem_1fr]`) |
| 2 | MUST requirements | ✅ | FR-PUBLIC-1 home.blade.php (hero + CTA), 2 pricing.blade.php, 3 open-source.blade.php, 4 docs.blade.php, 5 privacy/imprint, 6 public.blade.php:44-87 footer, 7 `LICENSE` (MIT, verified by test) |
| 3 | Pages present | ✅ | All six pages.md §Public pages exist at their intended routes and carry the described elements; gaps are placeholder-level only (F3, F4, F5) |
| 4 | Happy path works | ✅ | Browser smoke test renders each page with expected copy and no JS/console errors; CTA targets (`register`, `docs#booking`, `open-source`) resolve via the internal-link test |
| 5 | Validation & errors | n/a | Static content pages; no forms or async actions in this epic |
| 6 | Empty / loading / error states | n/a | No lists/dashboards; pages are fully static |
| 7 | Copy | ✅ | Short, plain-spoken, action-oriented ("Get started free", "Create your free account"); no hype; em-dash test green; grep over marketing blades finds none |
| 8 | Navigation & links | ⚠️ | All internal links resolve (tested). Footer on every page (tested). The external GitHub link uses a placeholder default that 404s today (F1, tracked assumption). Header product links are hidden below `md` with no mobile menu; footer mitigates (F2) |
| 9 | Scope discipline | ✅ | No out-of-scope features. Secondary CTA points to `docs#booking` instead of the demo tenant per the epic's never-404 instruction; recorded in `docs/assumptions.md:123-126`, switched in Epic 09. Docs and evidence links explicitly seeded for Epic 10 finalization |
| 10 | Onboarding / discoverability | ✅ | "Get started free" / "Sign up" visible in header, hero, pricing card, and footer; docs explain the first steps after sign-up |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | AC-2 / FR-PUBLIC-3 | `config/app.php:28` + footer/open-source/docs/imprint repo links | Default `APP_REPOSITORY_URL` (`https://github.com/librenexus/librenexus`) returns 404 (verified via GitHub API); the project has not been published yet. Tracked in `docs/assumptions.md:127-130` | Confirm/publish the real repository URL with the proof package; verify all derived links (LICENSE blob, /actions, /tree/main/docs, /tree/main/reports) resolve in Epic 10 |
| F2 | Low | styleguide §Responsiveness | `public.blade.php:23` | Pricing/Docs/Open source header links are `hidden ... md:flex` with no mobile disclosure menu; on phones they are only reachable via the footer | Add a small mobile nav (or accept footer-only) by Epic 10 |
| F3 | Low | pages.md §Docs | `marketing/docs.blade.php` | No screenshots/callouts yet; epic explicitly seeds docs here and finalizes in Epic 10 | Add screenshots when the real screens exist (Epic 10) |
| F4 | Low | pages.md §Open source | `marketing/open-source.blade.php:10-26` | CI links present but no visual badge row; badges depend on the published repo/CI (F1) | Add badge images when public CI exists (Epic 10) |
| F5 | Low | pages.md §Privacy/Imprint | `marketing/imprint.blade.php:4` | Imprint shows "Legal notice for this installation" but no last-updated date (privacy has one) | Add a last-updated line |

## Required fixes (blocking)

- None. No Critical/High findings.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all five ACs and all seven FR-PUBLIC MUSTs are implemented with test and tool evidence; the only Medium item (F1) is an external publication dependency already tracked as an assumption with an Epic 10 confirmation plan, and the remaining findings are Low placeholder/UX polish items the epic itself defers.
- Blocking findings remaining: 0
