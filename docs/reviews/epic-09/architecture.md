# Review Report — Architecture Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard SFC, DemoSeeder extension, Makefile/README wiring)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2, ARCH-TENANCY-2/3/4, ARCH-DATA-1/2/3/4/5, ARCH-HTTP-*, ARCH-CONFIG-*, ARCH-FRONTEND-*, ARCH-TEST-3

## Files reviewed

- `resources/views/pages/dashboard/⚡index.blade.php` — component structure, query placement, tenant scoping, timezone math
- `database/seeders/DemoSeeder.php` — tenant-context handling (`CurrentTenant` set/clear in try/finally), idempotency mechanics, exclusion-constraint-aware sample layout
- `database/seeders/DatabaseSeeder.php` — seeder composition
- `app/Models/Appointment.php` — `scopeReservingTime`, `findByManageToken` (reused, not duplicated)
- `app/Concerns/HasTeams.php` — `teamRole`/`staffRecordFor` helpers reused by the AC-7 callout
- `routes/web.php:32` — `Route::livewire('dashboard', …)->name('dashboard')` unchanged inside the tenant middleware group
- `database/migrations/2026_06_10_234907_create_appointments_table.php` — existing indexes serving the new queries
- `tests/Unit/TenantScopingTest.php` — arch rule: every model with `team_id` uses `BelongsToTenant` (allowlist unchanged)

## Flows reviewed

- Dashboard request — middleware establishes tenant context, `mount()` receives the bound team, every metric query runs under the `BelongsToTenant` global scope
- Seeder run — explicit `CurrentTenant` set before tenant-scoped writes, cleared in `finally`; demo owner attached outside tenant context via the membership fabric
- Repeated seeding — `firstOrCreate`/`updateOrCreate` keyed on natural keys; sample block skipped entirely once non-token appointments exist

## Tests reviewed

- `tests/Unit/TenantScopingTest.php` — no new model escapes the scoping rule (no new models in this epic)
- `tests/Feature/Tenancy/IsolationTest.php:41` — member of A gets 404 on B's dashboard (fresh run: suite 87/87)
- `tests/Feature/Dashboard/DashboardMetricsTest.php` — queries run under the tenant scope with `CurrentTenant` set; query-count no-growth test
- `tests/Feature/Ops/DemoSeederTest.php` — seeder context handling proven by scoped/unscoped count assertions

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 462/462 incl. arch + tenancy suites |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `php artisan test tests/Feature/Tenancy` | pass | 87/87, 278 assertions |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | Dashboard stays at `pages/dashboard/⚡index.blade.php` (existing pages convention); seeder in `database/seeders/`; no new top-level folders |
| 2 | Logic placement | ⚠️ | Metric/checklist logic lives in the Livewire SFC (~270 lines) rather than an Action/query service. It is read-only aggregation with no mutations, matching the documented SFC pattern of Epics 03/04/07, but it continues the tracked "oversized SFC / PHPMD blind spot" deferral (F1) |
| 3 | Tenant scoping | ✅ | No new models; all queries go through `BelongsToTenant`-scoped models; arch test green |
| 4 | No leaky queries | ✅ | No `withoutGlobalScopes` in the dashboard; the seeder's unscoped reads are test-side or keyed by `team_id` explicitly (DemoSeeder:227-230) |
| 5 | Data | ✅ | No new migrations; timestamps UTC with day-window math in tenant tz (`todayWindow()`, ⚡index.blade.php:262-267); seeder stores UTC converted from Europe/Berlin; prices in minor units (DemoSeeder:109-111) |
| 6 | Double-booking | ✅ | Untouched; the seeder respects ADR-0003 by construction (sample hour layout documented at DemoSeeder:233-237 keeps buffered ranges disjoint) |
| 7 | HTTP | ✅ | Read-only page behind auth + `EnsureTeamMembership`; named routes everywhere (`route('dashboard')`, `route('booking.show')`, …); `#[Locked]` team property |
| 8 | Async | n/a | No mail/jobs in this epic |
| 9 | Config/secrets | ✅ | No env branching in domain logic; demo constants are intentional fixtures documented in assumptions.md §Tokens |
| 10 | Frontend | ✅ | Server-rendered Blade/Flux components reused (`flux:callout`, `x-appointments.status-badge`); copy button is Alpine (`x-data`), consistent with the existing CSP posture |
| 11 | Arch tests | ✅ | `tests/Unit/TenantScopingTest.php` + ArchTest green in the fresh full run |
| 12 | ADRs | ✅ | No new significant decisions; seeder layout references ADR-0003; demo credentials decision recorded in assumptions.md |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | ARCH-STRUCTURE-2 / NFR-MAINT | `resources/views/pages/dashboard/⚡index.blade.php` | Aggregation logic (six computed query methods + window math) sits in the SFC class, which PHPMD does not scan (tracked Epic 07 deferral). Read-only and well-documented, but the pattern keeps growing | Defer to the existing Epic 10 item: extend the complexity gate to resources/views and extract a `DashboardMetrics` query class if it grows further |
| F2 | Low | Seeder idempotency edge | `database/seeders/DemoSeeder.php:166` | `updateOrCreate` re-points the token appointment at "next Monday 10:00" on every run; if a reviewer previously booked an overlapping slot for the same staff, the exclusion constraint aborts the re-seed with a DB error instead of a friendly skip | Defer: tolerate the constraint violation (skip with a warning) in Epic 10 polish; local-only, low probability |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: boundaries hold (tenant scoping, UTC storage with tenant-tz math, named routes, reuse of existing scopes/helpers); the only structural smell is the already-tracked SFC pattern, and the seeder edge case is a local-tooling nuisance, not a production path.
- Blocking findings remaining: 0
