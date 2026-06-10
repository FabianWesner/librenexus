# Review Report - Code Quality - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f`
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md idioms

## Files reviewed

- `app/Enums/CalendarColor.php` - TitleCase keys, PHPDoc explains the 700-shade AA decision
- `app/Models/Staff.php`, `app/Models/Service.php` - `#[Fillable]` attributes, full property PHPDoc, typed casts, return types everywhere
- `app/Policies/StaffPolicy.php`, `app/Policies/ServicePolicy.php` - structure and naming
- `database/factories/StaffFactory.php`, `ServiceFactory.php` - states, `fake()` usage
- `resources/views/pages/staff/⚡index.blade.php`, `services/⚡index.blade.php` - consistency with sibling SFC pages (teams pages)
- `app/Concerns/HasTeams.php` diff - `pivotTeamRole()`, `staffRecordFor()` additions
- `Makefile` diff - only `-d memory_limit=1G` added to coverage/mutation invocations; thresholds and gate lists untouched (no gate gaming)
- Tool configs (`phpmd.xml`, `phpstan.neon`, `.jscpd.json`, `composer-unused.php`) - no new ignores or widened excludes in the working tree

## Flows reviewed

- Naming and idiom pass over all new code: descriptive method names (`ensureNotSelfLinking`, `linkableMemberships`, `scopeBookable`), explicit types, PHPDoc with generics and array shapes (`array{id: int, name: string}`)
- Gate-gaming audit: diffed `Makefile` and tool configs; the only change is the memory limit after an OOM, which does not alter any threshold

## Tests reviewed

- `tests/Unit/CalendarColorTest.php` - documents the palette contract in executable form
- New test files match sibling structure (beforeEach team setup, Pest datasets, expectation chaining)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint zero diffs (initial in-sandbox failure was a sandbox fork restriction, not a style issue) |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean (complexity, dead code, design) |
| `make duplication` | pass | jscpd under 3% threshold |
| `make unused` | pass | only the pre-existing justified `livewire/flux` filter |
| `make require-check` | pass | no unknown symbols |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | `make format-check` pass |
| 2 | Static | ✅ | Level 7, no baseline, no new ignores (`phpstan.neon` unchanged) |
| 3 | Complexity | ✅ | PHPMD clean; longest new method (`saveStaff`) stays linear and within limits |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; policy `view()` methods are conventional policy completeness, flagged by QA for future coverage |
| 5 | Duplication | ⚠️ | Gate passes, but `StaffPolicy` and `ServicePolicy` are structural twins, and the two SFC pages share the modal/toast/reset choreography; below threshold today, see F1 |
| 6 | Dependencies | ✅ | `make unused` + `make require-check` pass with pre-existing, reasoned filters only |
| 7 | Idioms | ✅ | Explicit return types and param types throughout; TitleCase enum keys; descriptive names; PHPDoc array shapes |
| 8 | Laravel way | ✅ | Eloquent relations/scopes idiomatic; `route()` + named routes; `Rule::enum`/`Rule::exists`/`Rule::unique`; factories with states |
| 9 | Reuse | ✅ | Reuses `TenantModel`, `TeamRole::isAtLeast`, Flux components, existing modal pattern from teams pages |
| 10 | No debug/leftovers | ✅ | grep clean for `dd`/`dump`/`ray`/`var_dump`, no commented-out code, no `TODO`/`FIXME` |
| 11 | Consistency | ✅ | New SFC pages mirror the teams pages' structure (Computed properties, Flux modals, `data-test` attributes) |
| 12 | Docs | ⚠️ | CalendarColor AA decision and the persistent-middleware rationale are documented inline; the Epic 04 deferral notes (AC-3 cross-epic proof, persistent-middleware regression test) are not yet in `docs/assumptions.md` deferred-findings log, see F2 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | QG-DUPLICATION (trend) | `StaffPolicy`/`ServicePolicy`, both SFC pages | Twin policies and repeated CRUD-page choreography; under threshold but will multiply with Epic 05+ models | Extract a shared `ManagesTeamResources` policy concern or base policy when the third tenant model arrives |
| F2 | Low | DoD item 12 | `docs/assumptions.md` | Deferred-findings log lacks the Epic 04 entries the reviews produced (AC-3/AC-4 cross-epic proof, persistent-middleware regression test, inline SFC logic) | Add the Epic 04 rows to the deferred log |
| F3 | Low | NFR-MAINT | `Service::formattedPrice()` | `price_minor / 100` assumes 2-decimal currencies; safe for the v1 currency list (EUR/USD/GBP/CHF) but would mis-format zero-decimal codes | Note alongside the currency assumption; revisit if the currency list grows |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: every code-quality gate is green from fresh runs, no gate was loosened (Makefile diff audited), and the new code reads like its siblings with consistent idioms; the findings are Low housekeeping items.
- Blocking findings remaining: 0
