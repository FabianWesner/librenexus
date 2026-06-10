# Review Report - Code Quality - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md conventions (incl. styleguide copy rules)

## Files reviewed

- `routes/web.php:7-12` - idiomatic `Route::view()` + named routes, ordered before the tenant group with sibling-consistent style
- `resources/views/components/layouts/public.blade.php` - `@props` typed default, reuses `partials.head` and `x-app-logo-icon`, mirrors the existing `layouts/` siblings' structure
- `resources/views/marketing/{home,pricing,docs,open-source,privacy,imprint}.blade.php` - consistent page skeleton (layout component, `title` prop, section landmarks, shared link/heading patterns)
- `resources/css/app.css:26-48` - brand tokens with an explanatory comment referencing the styleguide; existing `--color-accent` mechanism preserved rather than forked
- `config/app.php:28` - config over magic values for the repository URL
- `tests/Feature/Public/PublicPagesTest.php`, `tests/Browser/PublicSmokeTest.php` - Pest style matches existing suites (datasets, `expect()`, typed closures)
- `.jscpd.json`, `lighthouserc.json`, `.pa11yci`, `composer-unused.php` - confirmed no gate config was widened/weakened for this epic (git status: configs unchanged since Epic 00 review)

## Flows reviewed

- Gate integrity - compared current gate configs against the Epic 00 review record: thresholds (jscpd 3%, PHPStan level 7 no baseline, Lighthouse budgets, pa11y WCAG2AA) are unchanged; nothing was gamed to get Epic 01 green.
- Convention conformance - new Blade files read like the existing component files (props at top, Tailwind utility ordering via Pint/prettier conventions, dark-mode variants alongside light values, `route()`/`config()` helpers everywhere, no hardcoded URLs).

## Tests reviewed

- `tests/Unit/ArchTest.php::no debug helpers in application code` - guards `dd`/`dump`/`ray`/`var_dump` etc.; green in fresh run
- `tests/Feature/Public/PublicPagesTest.php::marketing copy never uses the em-dash` - automated enforcement of the styleguide copy rule (also verified by direct grep over the blade sources: none)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `vendor/bin/pint --format agent` | pass | no changes needed (fresh run for this review) |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline file present (fresh run) |
| `make complexity` | pass | PHPMD (incl. unusedcode rules) 0 findings (fresh run) |
| `make duplication` | pass | jscpd 1.80% lines / 1.99% tokens < 3%, blade views included in scan (fresh run) |
| `make unused` | pass | composer-unused: 0 (filters carry reasons, unchanged) (fresh run) |
| `make require-check` | pass | composer-require-checker: 0 unknown symbols (fresh run) |
| `grep -rn "TODO\|FIXME" app routes tests resources/views resources/css` | clean | 0 matches (fresh run) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Pint clean on fresh run; blade formatting consistent with siblings |
| 2 | Static | ✅ | PHPStan level 7, 0 errors, no baseline, no new ignores (`phpstan.neon` unchanged) |
| 3 | Complexity | ✅ | PHPMD clean; epic adds no PHP methods at all (views/routes/config only) |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; no orphaned views (all six are routed; layout/logo components consumed) |
| 5 | Duplication | ✅ | 1.80% < 3% with `resources/views` in scope; `.jscpd.json` ignore list not widened for this epic |
| 6 | Dependencies | ✅ | `make unused` + `make require-check` clean; no dependencies added by this epic |
| 7 | Idioms | ✅ | Typed closures in tests (`function (string $routeName, string $path)`), `@props` defaults, `@class` directive over string concatenation, descriptive test names |
| 8 | Laravel way | ✅ | `Route::view()` for static pages, named routes + `route()` everywhere, `config('app.repository_url')` over a literal URL, Blade components for the layout |
| 9 | Reuse | ✅ | Reuses `partials.head`, `x-app-logo-icon`, Flux icons, and the existing accent token mechanism instead of new variants |
| 10 | No debug/leftovers | ✅ | arch() debug rule green; one commented-out CSS ruler in `app.css:76-78` predates this epic (starter artifact, noted F2); no TODO/FIXME (grep) |
| 11 | Consistency | ✅ | All six marketing pages share the same skeleton, heading scale, and link styling; tests mirror the Ops suites' structure |
| 12 | Docs | ✅ | Scope decisions recorded in `docs/assumptions.md` §Public site (Epic 01); no ADR needed; user-facing docs are themselves part of the deliverable (`/docs`) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-MAINT | `public.blade.php` + marketing views | The link utility-class string (text color + hover + focus outline, light/dark) is repeated ~20 times across the public surface; each instance is below jscpd thresholds so the gate stays honest, but it is manual duplication | Extract a shared link component or `@utility` when the public surface grows (Epic 06); track for Epic 10 |
| F2 | Low | no commented-out code | `resources/css/app.css:76-78` | A commented-out CSS rule (`\[:where(&)\]:size-4`) left over from the starter kit, predating this epic | Delete the dead block in the next touch of `app.css` |

## Required fixes (blocking)

- None. No Critical/High findings.

## Final decision

**PASS**

- Rationale: every code-quality gate re-ran green for this review with unchanged thresholds and no baselines or new ignores; the new code is idiomatic, reuses existing components and config mechanisms, and matches sibling conventions, leaving only two Low maintainability nits.
- Blocking findings remaining: 0
