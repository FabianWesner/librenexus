# Review Report - Architecture - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2, ARCH-DATA (n/a, no schema changes), ARCH-HTTP-* (named routes, routing order), ARCH-CONFIG-*, ARCH-FRONTEND-*, ARCH-TEST-3; NFR-MAINT

## Files reviewed

- `routes/web.php:7-12` - six `Route::view()` definitions, named, registered before the `{current_team}` prefix group (lines 16-20), so the future tenant-slug catch-all cannot shadow them (pages.md §ARCH-ROUTING precedence)
- `resources/views/components/layouts/public.blade.php` - the "lighter public layout" the styleguide prescribes, as an anonymous layout component beside the existing `layouts/` siblings
- `resources/views/marketing/*.blade.php` (6 files) - new `marketing/` view directory, content-only, no business logic
- `resources/css/app.css:26-48` - `--color-brand-*` tokens; `--color-accent`/`--color-accent-content` remapped to brand so the existing Flux accent mechanism keeps working (styleguide requirement)
- `resources/views/components/app-logo.blade.php`, `app-logo-icon.blade.php` - logo reuse across public layout and existing app shell
- `config/app.php:28` - `repository_url` env-driven config, no hardcoded URL in views
- `resources/views/partials/head.blade.php` - shared head partial reused by the public layout (title prop threaded through)
- `tests/Unit/ArchTest.php` - arch() rules (debug helpers, models, enums, middleware, strict equality)
- `docs/adr/` - existing ADRs; no new structural decision introduced by this epic

## Flows reviewed

- HTTP request to each public route - resolved by `Route::view()`, no controller, no middleware beyond the global stack; no database query originates from any marketing view (views reference only `route()`, `config()`, static arrays).
- Route precedence - static public paths registered first in `routes/web.php`; verified the tenant prefix group and auth group come after (lines 16-24), keeping the reserved-slug strategy intact for Epic 03/06.
- Theming flow - Flux components (`flux:icon.*`) consume the remapped `--color-accent`; brand utilities (`bg-brand-600` etc.) come from `@theme` tokens, no inline styles bypassing the theme.

## Tests reviewed

- `tests/Unit/ArchTest.php` - all five arch() rules green (fresh run, part of 95/95)
- `tests/Feature/Public/PublicPagesTest.php::no auth-only routes leak into the public page set` - public routes carry no `auth` middleware (route-graph boundary check)
- `tests/Feature/Public/PublicPagesTest.php::public page renders successfully` - asserts the named route resolves to the documented path (named-route convention, ARCH-HTTP)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 95/95 incl. ArchTest (fresh run for this review) |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline (fresh run) |
| `make complexity` | pass | PHPMD 0 findings (fresh run) |
| `make duplication` | pass | jscpd 1.80% lines / 1.99% tokens < 3% over app + views (fresh run) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | Views in `resources/views/marketing/` + `components/layouts/`; routes in `routes/web.php`; tokens in `resources/css/app.css`; no new top-level `app/` folder, no new PHP classes at all |
| 2 | Logic placement | ✅ | Pages are pure content; only presentational `@class`/`@foreach` over literal arrays (e.g. home.blade.php:40-56 calendar mock). No queries, no domain logic in Blade |
| 3 | Tenant scoping | n/a | No tenant-owned models touched; public pages are tenant-free by design |
| 4 | No leaky queries | ✅ | Zero Eloquent/DB usage in the public layout and marketing views (grep: no model, no `DB::`) |
| 5 | Data | n/a | No migrations or schema changes in this epic |
| 6 | Double-booking | n/a | Booking lands in Epic 06 |
| 7 | HTTP | ✅ | `Route::view()` with named routes (`home`...`imprint`); all view links use `route()`; public routes precede the tenant prefix group (routes/web.php:7-20); nothing state-changing added |
| 8 | Async | n/a | No mail/jobs introduced |
| 9 | Config/secrets | ✅ | Repo URL via `config('app.repository_url')` backed by `APP_REPOSITORY_URL` (config/app.php:28); imprint contact via `config('mail.from.address')`; no secrets, no env branching in views |
| 10 | Frontend | ✅ | Server-rendered Blade; Flux icons reused; `x-app-logo-icon` reused; brand tokens + accent remap keep one theme mechanism; no inline `<script>` in marketing views (grep clean), CSP-compatible |
| 11 | Arch tests | ✅ | `tests/Unit/ArchTest.php` green (debug helpers, model/enum/middleware conventions, strict equality) |
| 12 | ADRs | ✅ | No new architectural decision warranted (views/routes/tokens only); existing ADR-0001 stack decision covers the Blade/Flux/Tailwind approach |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-MAINT | `public.blade.php:24-26,60-78`, marketing views | The long Tailwind utility string for text links (hover + focus-outline pattern) is repeated many times across the layout and pages (each instance below jscpd thresholds) | Extract a small `link` Blade component or `@utility` class when the public surface grows (Epic 06 booking pages); track for Epic 10 |

## Required fixes (blocking)

- None. No Critical/High findings.

## Final decision

**PASS**

- Rationale: the epic adds only views, named routes, and theme tokens in the established locations; boundaries hold (no logic in Blade, no queries, route precedence preserved for tenancy), arch tests and all structural gates are green, and no decision rises to ADR level.
- Blocking findings remaining: 0
