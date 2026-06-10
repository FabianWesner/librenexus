# Review Report - Architecture - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f`
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2, ARCH-TENANCY-2/3/4, ARCH-DATA-1/2/4/5, ARCH-HTTP-*, ARCH-CONFIG-*, ARCH-FRONTEND-*, ARCH-TEST-3, ADR-0002

## Files reviewed

- `app/Models/Staff.php`, `app/Models/Service.php` - extend `TenantModel`, casts, scopes, relations
- `app/Models/TenantModel.php`, `app/Concerns/BelongsToTenant.php`, `app/Models/Scopes/TenantScope.php` - central scoping mechanism (unchanged, reused)
- `database/migrations/2026_06_10_215709_create_services_table.php`, `..._create_staff_table.php`, `..._create_service_staff_table.php` - FKs, constraints
- `app/Policies/StaffPolicy.php`, `app/Policies/ServicePolicy.php` - role-gated manage, member view
- `app/Providers/AppServiceProvider.php` - `Livewire::addPersistentMiddleware([EnsureTeamMembership::class])`
- `app/Concerns/HasTeams.php` - `staffRecordFor()`, pivot-based `toUserTeam()` role read
- `resources/views/pages/staff/⚡index.blade.php`, `resources/views/pages/services/⚡index.blade.php` - component logic placement
- `routes/web.php` - tenant route group
- `docs/adr/0002-tenant-scoping.md` - scoping decision still accurate

## Flows reviewed

- Livewire update request lifecycle: snapshot path is replayed through `PersistentMiddleware`, re-running `EnsureTeamMembership` so `CurrentTenant` is set before any action executes (verified empirically with a log probe: middleware ran on the page GET and on both update requests of a real browser form submission)
- Staff creation: `BelongsToTenant::creating` fills `team_id` from `CurrentTenant`, rejects spoofed ids
- Member removal: DB `nullOnDelete` FK unlinks the staff record without touching its row

## Tests reviewed

- `tests/Unit/TenantScopingTest.php` - arch-style rule: any model with `team_id` outside the allowlisted membership fabric must use `BelongsToTenant`; Staff/Service covered automatically
- `tests/Feature/Tenancy/IsolationTest.php` (probe-model describe block) - scope fail-closed, spoof rejection, auto-fill
- `tests/Feature/Staff/StaffManagementTest.php::removing a member from the team unlinks but preserves the staff record` - proves the `nullOnDelete` FK behavior (closes the Epic 03 AC-9 deferral)
- `tests/Unit/ArchTest.php` - no debug helpers, model/enum conventions (ran green in `make test`)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 257/257 incl. arch + scoping rules |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in `app/Models`, `app/Policies`, `app/Enums`, `resources/views/pages`; no new top-level folders |
| 2 | Logic placement | ⚠️ | CRUD + link logic lives inline in the Livewire SFC pages (~170 lines PHP each), not in Actions. Acceptable for thin CRUD, but the same pattern was already flagged for the teams edit component in Epic 03; see F1 |
| 3 | Tenant scoping | ✅ | Both models extend `TenantModel`; `TenantScopingTest` enforces opt-in structurally |
| 4 | No leaky queries | ✅ | All page queries run under the global scope; the single `withoutGlobalScope` in `HasTeams::staffRecordFor()` re-constrains `team_id` explicitly and documents why |
| 5 | Data | ✅ | Forward-only migrations with FKs (`cascadeOnDelete` for team, `nullOnDelete` + UNIQUE for membership link, unique pivot pair); `price_minor` integer minor units (ARCH-DATA-4); no raw interpolated SQL (the `whereRaw('1 = 0')` fail-closed clause is a constant) |
| 6 | Double-booking | n/a | No appointment writes in this epic; ADR-0003 unchanged |
| 7 | HTTP | ✅ | Validation server-side in component actions, `Gate::authorize` on every action incl. `mount`, named routes only |
| 8 | Async | n/a | No mail/jobs introduced |
| 9 | Config/secrets | ✅ | No new env/config; no environment branching added |
| 10 | Frontend | ✅ | Server-rendered Flux components; the only inline `style` is the palette hex from a closed enum, no inline scripts |
| 11 | Arch tests | ✅ | `ArchTest` + `TenantScopingTest` green |
| 12 | ADRs | ✅ | ADR-0002 covers the scoping reused here; the persistent-middleware registration is documented inline and in the ADR's spirit. No new decision rises to ADR level |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | ARCH-STRUCTURE-2 | `pages/staff/⚡index.blade.php`, `pages/services/⚡index.blade.php` | Save/link/validation logic inline in SFC components; with Epic 05+ building on these models, repeated inline CRUD will accrete. Same trend as the Epic 03 teams-edit deferral | Extract save/link logic into Actions when the next epic touches these flows, or by Epic 10 |
| F2 | Low | ARCH-STRUCTURE-2 | `resources/views/dashboard.blade.php:4-10` | AC-7 eligibility computed in a Blade `@php` block | Move into a view composer or small component when the dashboard becomes real (Epic 09) |
| F3 | Low | ARCH-DATA-1 | `create_staff_table` migration | `staff.team_id == team_members.team_id` consistency is app-level only (validation rule); no composite FK | Acceptable; revisit if linking ever moves out of the validated admin path |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: boundaries hold, the central scoping mechanism is reused and structurally enforced, migrations carry real constraints; the inline component logic (F1) is a tracked maintainability smell, not a boundary breach.
- Blocking findings remaining: 0
