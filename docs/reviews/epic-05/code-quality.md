# Review Report - Code Quality - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md conventions

## Files reviewed

- `app/Actions/Availability/ComputeSlots.php`, `GetBookableSlots.php` - naming, types, decomposition, doc quality
- `app/Data/Slot.php`, `SlotComputation.php` - DTO idioms, PHPDoc array shapes, the documented PHPMD suppression
- `app/Models/AvailabilityRule.php`, `TimeOff.php` - sibling consistency with Staff/Service models
- `database/factories/AvailabilityRuleFactory.php`, `TimeOffFactory.php`, both migrations
- `resources/views/pages/staff/⚡availability.blade.php` - consistency with the Epic 04 staff/services pages
- `tests/Unit/SlotEngineTest.php` and the two feature suites - test code quality

## Flows reviewed

- Engine readability: `handle -> localDates -> windowsForDate -> localTimeToUtc/mergeWindows -> slotsInWindow -> slotIsOfferable` decomposes into single-purpose private methods, each under PHPMD limits, each with a docblock stating the time semantics it owns
- Convention sweep: constructor promotion (`GetBookableSlots`), `final readonly` DTOs, explicit return types and param hints everywhere, list/array-shape PHPDoc on every array boundary, descriptive names (`slotIsOfferable`, `ensureRuleDoesNotOverlap`, `bufferedEndsAt`)
- Suppression audit: exactly one new suppression, `@SuppressWarnings("PHPMD.ExcessiveParameterList")` on SlotComputation, with a written rationale (flat named-argument engine contract) - a deliberate, documented trade-off, not gate-dodging
- Leftover sweep: no `dd`/`dump`/`ray`/`var_dump`, no commented-out code, no TODO/FIXME in the new files (grep)

## Tests reviewed

- `SlotEngineTest.php` - shared `computation()`/`localStarts()` helpers keep 31 cases declarative; comments explain each expected instant (e.g. why 09:00 Berlin = 07:00Z in June)
- Feature suites mirror the structure/naming of the Epic 04 suites (beforeEach fixtures, role actors, dataset-free explicit cases)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint, zero diffs |
| `make static` | pass | PHPStan/Larastan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean (1 justified suppression as above) |
| `make duplication` | pass | jscpd under threshold |
| `make unused` | pass | composer-unused: only the pre-existing reasoned livewire/flux filter |
| `make require-check` | pass | no unknown symbols |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Fresh run, zero diffs |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline/ignores added |
| 3 | Complexity | ✅ | Engine decomposed; longest method (`slotsInWindow`) is a simple loop with named locals |
| 4 | Dead code | ✅ | PHPMD unusedcode clean |
| 5 | Duplication | ✅ | jscpd clean; window/time helpers shared, not copy-pasted |
| 6 | Dependencies | ✅ | unused + require-check clean, filters carry reasons |
| 7 | Idioms | ✅ | Promotion, readonly DTOs, explicit types, array shapes, ISO-weekday ints documented at every boundary |
| 8 | Laravel way | ✅ | Factories + states, relation-based writes, named routes, casts() methods, `#[Fillable]` like siblings |
| 9 | Reuse | ✅ | Flux table/select/toast patterns and the TenantModel base reused; no new bespoke components |
| 10 | No debug/leftovers | ✅ | Grep clean |
| 11 | Consistency | ✅ | New models/factories/pages match Epic 04 siblings in structure and naming |
| 12 | Docs | ⚠️ | Decisions are well captured in docblocks + docs/assumptions.md, but the mutant-equivalence note in SlotEngineTest.php:13-17 (echoed in assumptions.md) is factually wrong for one survivor - see F1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-MAINT / docs accuracy | `SlotEngineTest.php:13-17`, `docs/assumptions.md` §Availability | The documented claim "everything else is killed" is inaccurate: a third, non-equivalent mutant survives (`array_pop -> array_shift`, ComputeSlots.php:113). Inaccurate quality documentation on the domain core misleads future reviewers | Correct both notes when fixing QA F1 (which owns the blocking test addition) |
| F2 | Low | Consistency | `AvailabilityRuleFactory.php`, `TimeOffFactory.php` | Default `team_id`/`staff_id` factories create a staff member in a different team than the rule's team (latent; all call sites override) | Align defaults (shared with QA F3) |
| F3 | Low | Idioms | `GetBookableSlots.php:64-77` | `array_values((...)->all())` double-wraps; `->values()->all()` reads cleaner | Cosmetic; fold into the next touch |

## Required fixes (blocking)

- None in this report. The documentation correction (F1) is blocking via the QA report, which owns the underlying mutation finding.

## Final decision (initial review, 2026-06-10 - superseded by the re-review below)

**PASS WITH WARNINGS**

- Rationale: all six code-quality gates ran fresh and pass with no new ignores; the code is idiomatic, well-decomposed, and consistent with its siblings; the warning is the inaccurate mutant-equivalence note that must be corrected alongside the QA fix.
- Blocking findings remaining: 0

## Re-review after fixes (2026-06-11)

### Fix verified

- **F1 (Medium, docs accuracy) - resolved.** SlotEngineTest.php:8-18 now documents exactly the three verified equivalent mutants ("verified case by case": zero-width window comparison, both int casts) and docs/assumptions.md §Availability was updated to match ("Three engine mutants are accepted as behaviorally equivalent, verified case by case... engine mutation 95%+"). Cross-checked against the fresh mutation output: documented survivors and measured survivors (Line 81 GreaterToGreaterOrEqual, Line 97 RemoveIntegerCast x2) are now identical, and the stated 95%+ figure is accurate (96.47% engine-only, 97.12% full).
- The new test (SlotEngineTest.php:333-347) follows the suite's existing conventions: shared `computation()`/`localStarts()` helpers, behavioral name, exact-value assertion.
- **F2/F3 (Low)** remain open as tracked cosmetic items - non-blocking.

### Tools executed (re-review)

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint, zero diffs on the changed test/docs |
| `make static` | pass | PHPStan level 7, 0 errors |
| `make test` | pass | 314/314, 933 assertions |

### Checklist deltas

- Item 12 (Docs): ⚠️ -> ✅ - the equivalence notes are now factually accurate and match the measured survivor set.

## Final decision

**PASS**

- Rationale: the only warning (inaccurate mutant-equivalence documentation) is corrected and re-verified against fresh mutation output; all code-quality gates remain green and the fix itself matches the suite's conventions.
- Blocking findings remaining: 0
