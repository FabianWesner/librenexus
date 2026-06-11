# Review Report — Code Quality Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard SFC, DemoSeeder, DatabaseSeeder, tests, Makefile/README/assumptions updates)
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-*, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `resources/views/pages/dashboard/⚡index.blade.php` — idioms, naming, typing, PHPDoc array shapes, Blade conventions vs sibling pages
- `database/seeders/DemoSeeder.php` — structure, typed private helpers, documented constants, list<> PHPDoc shapes
- `database/seeders/DatabaseSeeder.php` — idempotency guard style
- `tests/Feature/Dashboard/*.php`, `tests/Feature/Ops/DemoSeederTest.php`, `tests/Browser/DashboardSmokeTest.php` — Pest conventions, helper functions, dataset/factory usage
- `Makefile`, `README.md`, `docs/assumptions.md` — docs consistency with the shipped behavior

## Flows reviewed

- Gate integrity — confirmed no new baselines, ignores, or config widenings: `phpstan.neon`, `phpmd.xml`, `.jscpd.json`, `composer-unused.php`, `composer-require-checker.json` untouched by this epic
- Convention match — dashboard SFC mirrors the structure of `pages/staff/⚡index.blade.php` and friends (anonymous class + `#[Computed]`, `data-test` attributes, Flux components, `wire:key` in loops)

## Tests reviewed

- New tests follow the established Pest style: file-level docblocks naming the FR/AC, top-level helper functions, factory states (`between`, `status`, `window`), `data-test` selectors instead of brittle markup matching

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint clean (fresh run) |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline (fresh run) |
| `make complexity` | pass | PHPMD clean incl. unusedcode (fresh run) |
| `make duplication` | pass | jscpd under threshold, exit 0 (fresh run) |
| `make unused` | pass | composer-unused clean (fresh run) |
| `make require-check` | pass | no unknown symbols (fresh run) |
| `make test` | pass | 462/462 (fresh run) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | `make format-check` clean |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline, no new ignores |
| 3 | Complexity | ⚠️ | Gate green, but PHPMD still skips resources/views, so the ~270-line dashboard SFC class is outside the gate (tracked Epic 07 deferral, due Epic 10); methods themselves are short and single-purpose |
| 4 | Dead code | ✅ | unusedcode clean; no unused members in the new code |
| 5 | Duplication | ✅ | jscpd under 3%; seeder helpers extracted rather than copy-pasted (sampleStartHours/sampleStatus/createSampleAppointment) |
| 6 | Dependencies | ✅ | No dependency changes; both dep gates clean |
| 7 | Idioms | ✅ | Typed constants (`private const int UPCOMING_DAYS`), explicit return types everywhere, PHPDoc array shapes (`list<array{key: string, …}>`, `array{CarbonImmutable, CarbonImmutable}`), descriptive names (`setupComplete`, `needsStaffLink`, `hasSampleAppointments`) |
| 8 | Laravel way | ✅ | Named routes throughout, scopes reused (`reservingTime`, `bookable`), `firstOrCreate`/`updateOrCreate` for idempotency, `trans_choice` for pluralization |
| 9 | Reuse | ✅ | Reuses `x-appointments.status-badge`, Flux components, `pending-invitations-modal`, existing `HasTeams` helpers and `findByManageToken`; no parallel implementations |
| 10 | No debug/leftovers | ✅ | No `dd`/`dump`/`ray`, no commented-out code, no TODO/FIXME in the new files (grep + arch test) |
| 11 | Consistency | ✅ | SFC structure, `data-test` naming, test layout all match sibling files |
| 12 | Docs | ⚠️ | README setup paragraph accurate; seeder decisions documented inline and in assumptions.md; but the assumptions entry "Swapped to the seeded demo booking URL in Epic 09" does not match the code (F2, shared with Product F1) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | QG-COMPLEXITY reach (tracked) | `resources/views/pages/dashboard/⚡index.blade.php` | The dashboard SFC continues the pattern of substantial component classes that the PHPMD gate never scans; this epic adds the largest read-only one yet | Already tracked (Epic 07 deferral): extend the complexity target to resources/views in Epic 10 and refactor any findings |
| F2 | Low | Docs accuracy (NFR-MAINT) | `docs/assumptions.md` §Public site (Epic 01) | The entry claims the homepage demo CTA swap happened in Epic 09; `marketing/home.blade.php:22` still links `route('docs')#booking`. Stale docs erode the assumptions log's reliability | Correct the entry or ship the swap; one-line fix, track for Epic 10 with Product F1 |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all six code-quality gates pass in fresh runs with no baselines or config widenings, and the new code is idiomatic and consistent with its siblings; the warnings are the pre-existing PHPMD blind spot for SFC classes and one stale assumptions line.
- Blocking findings remaining: 0
