# Review Report - Code Quality Reviewer - Epic 10 (Hardening & quality report / final application state)

> Final-state review of the code-quality gates and the Epic 10 changes,
> leaning on per-epic code-quality reviews (epic-00..09, all pass) for
> unchanged code, with fresh re-runs of the format and static gates.

## Reviewed scope

- **Epic / change:** Epic 10 hardening + final application state
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY,
  QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO,
  QG-NO-IGNORE, NFR-MAINT, CLAUDE.md idioms

## Files reviewed

- `app/Actions/Teams/UpdateMemberRole.php` - new Epic 10 class: constructor
  promotion, explicit types, transaction + lock, matches sibling Actions
- `app/Providers/AppServiceProvider.php` + `config/auth.php` - password-policy
  flag: config over magic values, PHPDoc explains the null fallback
- `resources/views/pages/appointments/⚡index.blade.php` - pagination change
  consistent with Livewire conventions (`WithPagination`, `PER_PAGE` const)
- `resources/views/pages/booking/⚡show.blade.php` - `throttleStepAction()`
  private helper, no duplication of the confirm throttle
- `Makefile` - complexity target still scans `app,config,database,routes`
  (line 64); `resources/views` SFC front-matter remains outside PHPMD
- `/tmp/claude/verify.log` - jscpd clone report (mailable boilerplate),
  passes under the 3% threshold
- `docs/assumptions.md` §Deferred findings log - closure status per entry

## Flows reviewed

- Gate-gaming audit - no new baseline, no `nosemgrep`, no PHPMD suppressions,
  no widened jscpd ignores; thresholds untouched in the Makefile
- Deferred-log closure - password policy, pagination, step throttles,
  UpdateMemberRole extraction, session-fixation test, decline tests, CSP
  re-check: all genuinely closed with code + tests, verified individually

## Tests reviewed

- `tests/Unit/ArchTest.php` - no `dd`/`dump`/`ray`, model/enum conventions
  enforced (green in the 469 run)
- Epic 10 closure tests (AuthHardeningTest:67, TeamInvitationTest:159/185,
  strict-policy registration test) - assert outcomes, match sibling style

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint clean (fresh run by this review) |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline (fresh run) |
| `make complexity` (verify.log:2) | pass | PHPMD 0 violations over app,config,database,routes |
| `make duplication` (verify.log) | pass | jscpd ~2% (< 3%); remaining clones are mailable use-block boilerplate |
| `make unused` / `make require-check` (verify.log) | pass | 0 findings |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Pint clean on fresh run |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline, no new ignores |
| 3 | Complexity | ⚠️ | PHPMD clean on its scanned paths, but Livewire SFC front-matter in `resources/views` is still outside the gate; the Epic 07 deferral planned to extend the Makefile in Epic 10 and Epic 10 instead accepted the limitation (quality report limitation 1). PHPStan does cover those files |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; no unused members found in spot checks |
| 5 | Duplication | ⚠️ | ~2%, under threshold; the four mailables still share near-identical headers/scalar-capture (Epic 08 Low deferral, conditional on the gate complaining, which it does not) |
| 6 | Dependencies | ✅ | unused + require-check clean, filters carry reasons |
| 7 | Idioms | ✅ | Promotion, explicit return/param types, TitleCase enums, descriptive names, array-shape PHPDoc (UpdateMemberRole, ComputeSlots spot-checked) |
| 8 | Laravel way | ✅ | Named routes, config over magic values, Eloquent relations idiomatic |
| 9 | Reuse | ✅ | Flux components and existing Actions reused; no parallel helpers introduced |
| 10 | No debug/leftovers | ✅ | Arch test enforces; grep found no TODO/FIXME/dd |
| 11 | Consistency | ✅ | New Action mirrors siblings; tests follow house Pest style |
| 12 | Docs | ⚠️ | ADRs current; quality report thorough. But two deferred-log entries are stale (PHPMD/resources-views says "extend the Makefile in Epic 10"; appointments-SFC split says "tracked for Epic 10") when the actual Epic 10 resolution was documented acceptance; and six files sit modified-uncommitted in the working tree (ci.yml, FortifyServiceProvider, services.php, 2FA view, smoke test, open-source page) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | DoD honesty / docs | docs/assumptions.md deferred log (Epic 07 rows) | Two deferral entries still state the superseded plan instead of the actual resolution (accepted limitation, documented in the quality report). The log should be the single source of truth for deferral outcomes | Update both rows to "Accepted in Epic 10: documented as quality-report limitation 1" |
| F2 | Medium | repo hygiene | working tree | Six modified files are uncommitted, including the CI fixes the proof package depends on; the final state being reviewed is not yet the published state | Commit/push (this is the code-quality face of the shared product F1) |
| F3 | Low | QG-DUPLICATION | app/Mail/* | Mailable boilerplate clones, under threshold and tracked since Epic 08 | Optional shared base class |
| F4 | Low | QG-COMPLEXITY scope | Makefile:64 + largest SFCs (780/603/491 lines) | SFC front-matter complexity is human/PHPStan-reviewed only; accepted and documented | Keep tracking; extract front-matter to Actions if components grow |

## Required fixes (blocking)

- None owned by this review (F2 is the shared publication blocker, tracked as
  product F1).

## Final decision

**PASS WITH WARNINGS**

- Rationale: every code-quality gate is green on the final tree, re-verified
  for format and static by fresh runs, with no baselines, suppressions, or
  threshold edits anywhere in the history of the Makefile gates. Warnings are
  documentation staleness in the deferred log, the accepted PHPMD blind spot
  for SFC front-matter, sub-threshold mailable duplication, and the
  uncommitted working tree, none of which is a gate failure or gate-gaming.
- Blocking findings remaining: 0 owned here (1 shared, tracked as product F1)
