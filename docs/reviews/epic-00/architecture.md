# Review Report - Architecture - Epic 00 (Foundations & quality harness)

## Reviewed scope

- **Epic / change:** Epic 00 (Foundations & quality harness)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2, ARCH-DATA-1, ARCH-HTTP-*, ARCH-ASYNC-* (failed-job visibility), ARCH-CONFIG-*, ARCH-TEST-3, ADR coverage; NFR-OBS-1..4, NFR-OPS-1..4

## Files reviewed

- `app/Http/Controllers/HealthController.php` - placement and thinness of the health endpoint
- `app/Http/Middleware/AddCorrelationId.php` - cross-cutting concern as HTTP middleware
- `app/Http/Middleware/SetSecurityHeaders.php` - cross-cutting concern as HTTP middleware
- `bootstrap/app.php` - global middleware registration order (lines 17-26)
- `routes/web.php` - named route `health` (line 9)
- `config/logging.php`, `config/services.php`, `config/queue.php` - env-driven configuration
- `database/migrations/0001_01_01_000002_create_jobs_table.php` - jobs/failed_jobs schema with unique uuid + index
- `tests/Unit/ArchTest.php` - baseline `arch()` boundaries
- `docs/adr/0001-stack.md`, `docs/adr/0002-tenant-scoping.md`, `docs/adr/0003-double-booking-constraint.md` - decision records

## Flows reviewed

- Request lifecycle: `AddCorrelationId` then `SetSecurityHeaders` appended to the global stack (bootstrap/app.php:18-21), so the ID is in log context before any later middleware/controller logs, and headers apply to every response including errors (proven by SecurityHeadersTest error-response test).
- Health probe: route -> invokable controller -> single `select 1` via the default connection; no business logic in routes or views.
- Failed-job flow: `queue.failed.driver = database-uuids` (config/queue.php:123-126) writing to the migrated `failed_jobs` table.

## Tests reviewed

- `tests/Unit/ArchTest.php::no debug helpers in application code` - bans `dd`/`dump`/`ray`/`var_dump`/`print_r`/`die`/`exit`/`eval` (ARCH-TEST-3)
- `tests/Unit/ArchTest.php::models live in App\Models and extend the Eloquent base model` - structure rule
- `tests/Unit/ArchTest.php::enums are real enums`, `::http middleware stays invokable middleware`, `::strict equality is preferred` - convention rules
- `tests/Feature/Ops/ObservabilityTest.php::failed queue jobs are recorded in an inspectable store` - NFR-OBS-4 wiring
- `tests/Feature/Ops/HealthCheckTest.php::health check requires no authentication and no rate limiting` - route middleware contract

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact tests/Feature/Ops tests/Unit/ArchTest.php` | pass | 19/19 incl. all 5 arch rules (fresh run) |
| `php artisan route:list --path=health` | pass | named route, invokable controller |
| `make static` / `make complexity` (build log) | pass | PHPStan level 7 zero errors; PHPMD clean over app, config, database, routes |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in established dirs: `app/Http/Controllers`, `app/Http/Middleware`, `config/`, `tests/Feature/Ops`, `tests/Unit`. No new top-level `app/` folder added by this epic |
| 2 | Logic placement | ✅ | HealthController is thin (one query, one response); middleware are single-purpose; no logic in Blade. Slot engine rule (ARCH-STRUCTURE-3) n/a until Epic 05 |
| 3 | Tenant scoping | n/a | No tenant-owned models introduced by Epic 00; scoping mechanism is ADR-0002 and lands in Epic 03 |
| 4 | No leaky queries | ✅ | Only query added is `DB::connection()->select('select 1')` (HealthController.php:28); touches no tenant data |
| 5 | Data | ✅ | `failed_jobs` migration is forward-only with unique `uuid` and composite index (migration lines 37-47); no SQL string interpolation anywhere in new code (`select 1` is a constant) |
| 6 | Double-booking | n/a | Epic 06 concern; strategy already recorded in `docs/adr/0003-double-booking-constraint.md` (exclusion constraint) |
| 7 | HTTP | ✅ | Named route `health` (routes/web.php:9); invokable controller; no input to validate; endpoint is deliberately public per epic implementation notes and asserted by test |
| 8 | Async | ✅ | Failed jobs visible via `database-uuids` store + table (ObservabilityTest); no inline email/long work added |
| 9 | Config/secrets | ✅ | `ERROR_TRACKING_DSN` env-driven (config/services.php:20, .env.example:68); structured channel selected by `LOG_CHANNEL`/`LOG_STACK` env; no environment branching in app code (no `app()->environment()` in new code) |
| 10 | Frontend | n/a | No frontend changes; SetSecurityHeaders CSP is written for the server-rendered Blade/Livewire stack (see Security review) |
| 11 | Arch tests | ✅ | 5 `arch()` rules green (ArchTest.php:5-23); tenant-scoping arch rule to be added with the first tenant-owned model (Epic 03), tracked in checklist of that epic |
| 12 | ADRs | ✅ | ADR-0001 stack, ADR-0002 tenant scoping, ADR-0003 double-booking present and "accepted" in `docs/adr/` |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | ARCH-TEST-3 | `tests/Unit/ArchTest.php` | Baseline arch rules do not yet include the tenant-scoping rule (no tenant models exist yet). Must be extended in Epic 03 per ARCH-TENANCY-3. | Add the scoping `arch()`/scope test when the first tenant-owned model lands (Epic 03). |
| F2 | Low | NFR-OBS-3 | `config/services.php:19-21` | The error-tracking integration point is a config key only; no tracker SDK is wired (intentional: inert without a DSN, no dependency added). Later epics/Epic 10 should document or wire the consuming side when a tracker is chosen. | Record the chosen tracker in an ADR when one is adopted; nothing required now. |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: New code respects the intended structure (thin controller, cross-cutting concerns as global middleware, env-driven config), required ADRs exist, and the baseline arch tests are green; remaining items are deferred by design to their owning epics.
- Blocking findings remaining: 0
