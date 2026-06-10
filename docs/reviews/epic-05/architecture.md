# Review Report - Architecture - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2/3, ARCH-TENANCY-2/3/4, ARCH-DATA-1/2/5, ARCH-HTTP-*, ARCH-CONFIG, ARCH-FRONTEND, ARCH-TEST-3

## Files reviewed

- `app/Actions/Availability/ComputeSlots.php` - purity check: imports only Carbon + Support Collection, no Eloquent, no facades, no I/O, no static state
- `app/Actions/Availability/GetBookableSlots.php` - DB assembly kept out of the engine; constructor injection
- `app/Data/Slot.php`, `app/Data/SlotComputation.php` - `final readonly` value objects feeding the engine plain data
- `app/Models/AvailabilityRule.php`, `app/Models/TimeOff.php` - extend `TenantModel` (BelongsToTenant)
- `app/Models/TenantModel.php`, `tests/Unit/TenantScopingTest.php` - central scoping mechanism + the generic team_id/trait assertion
- `database/migrations/2026_06_10_224956_create_availability_rules_table.php`, `..._224957_create_time_offs_table.php` - FKs, cascade, indexes
- `config/database.php:100-103` - pgsql session `'timezone' => 'UTC'` pin (ARCH-DATA-2)
- `resources/views/pages/staff/⚡availability.blade.php` - component thickness, validation, authorization, binding-order workaround
- `docs/adr/0001..0003`, `docs/assumptions.md` §Availability - decision records

## Flows reviewed

- Engine data flow: Eloquent rows -> plain arrays/DTO in GetBookableSlots::computationFor -> pure ComputeSlots -> Slot DTOs; the engine cannot touch the database (ARCH-STRUCTURE-3 verified by import inspection and by the unit suite running without RefreshDatabase)
- Livewire binding order: `{staff}` deliberately not implicitly bound; resolved in `mount()` after `EnsureTeamMembership` has set the tenant context (page lines 41-54); rationale documented in docs/assumptions.md §Availability and regression-covered by IsolationTest (both 404 directions plus the persistent-middleware structural test at IsolationTest.php:375)
- Time semantics: rules local (time columns), time off and slots UTC (`timestampTz`), all conversions through the tenant timezone at the edges

## Tests reviewed

- `tests/Unit/TenantScopingTest.php::every model with a team_id column uses the BelongsToTenant trait` - automatically covers the two new models (ARCH-TENANCY-3)
- `tests/Feature/Tenancy/IsolationTest.php` availability block - no leaky queries (ARCH-TENANCY-4): `AvailabilityRule::query()->pluck()` and `TimeOff::query()->count()` under tenant A see no tenant B rows
- `tests/Unit/SlotEngineTest.php` - engine runs with zero framework bootstrapping side effects beyond the test case
- `tests/Feature/Availability/AvailabilityManagementTest.php::time off entered in the tenant timezone is stored as UTC` - Berlin 10:00 -> 08:00 UTC (ARCH-DATA-2)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 313/313 incl. arch + scope tests |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean (one justified suppression, see Code Quality) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in existing `app/Actions`, `app/Data`, `app/Models`; no new top-level folders |
| 2 | Logic placement | ⚠️ | Slot engine is a pure service (ARCH-STRUCTURE-3) ✅; the editor component performs simple CRUD writes inline via relations instead of delegating to Actions - consistent with the Epic 04 staff/services pages, trivial writes, see F2 |
| 3 | Tenant scoping | ✅ | Both models extend TenantModel; generic TenantScopingTest asserts the trait |
| 4 | No leaky queries | ✅ | IsolationTest availability block; editor queries all go through the scoped `staffMember` relations |
| 5 | Data | ✅ | Forward-only migrations with FKs + cascade; rules as `time` (local), time off as `timestampTz` (UTC); pgsql session tz pinned to UTC; no money fields; no string-interpolated SQL (Semgrep clean) |
| 6 | Double-booking | n/a | Epic 06; strategy already recorded in ADR-0003 and referenced from Slot.php |
| 7 | HTTP | ✅ | Server-side validation in the component, `Gate::authorize` in mount and all four actions, named route `staff.availability` |
| 8 | Async | n/a | No mail/jobs in this epic |
| 9 | Config/secrets | ✅ | Booking policy from team columns; no env branching in domain logic; gitleaks clean |
| 10 | Frontend | ✅ | Server-rendered Flux components; no inline scripts; reuses table/select/toast patterns from Epic 04 pages |
| 11 | Arch tests | ✅ | ArchTest + TenantScopingTest green within `make test` |
| 12 | ADRs | ✅ | 0001-0003 exist; Epic 05 decisions (contiguous grid, union, 24:00, DST resolution, binding order, pgsql tz pin) recorded in docs/assumptions.md - appropriate weight, no new ADR needed |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | ARCH-STRUCTURE-3 (robustness of the pure service) | `ComputeSlots.php:152-170` | `slotsInWindow` loops forever if the packing step is non-positive (duration 0 with zero buffers). Unreachable through the app (service duration validated `between:5,480`), but the engine is a public pure function and Epic 06 will call it from new code paths | Add a `$stepMinutes < 1` guard returning no slots (also turns the two mutation timeouts into clean kills); do before/with Epic 06 |
| F2 | Low | ARCH-STRUCTURE-2 | availability page component | CRUD writes (`addRule`, `addTimeOff`, ...) live in the component rather than Actions; matches the Epic 04 precedent and stays thin, but the pattern should not grow into multi-step writes | Keep as is; extract to Actions if Epic 06+ adds any multi-step write logic |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: the headline architectural requirement of this epic (a pure, Eloquent-free slot engine fed by DTOs) is genuinely met and verifiable; tenancy, data, and HTTP boundaries all hold; the two findings are low-risk hygiene items.
- Blocking findings remaining: 0
