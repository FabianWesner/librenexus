# Review Report - QA - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** Epic §Required tests, QG-TESTS, QG-COVERAGE (≥80% overall, ≥95% critical), QG-MUTATION (≥70%, ≥85% critical), QG-A11Y (axe on the editor), test-plan.md §Edge cases + §Epic 05

## Files reviewed

- `tests/Unit/SlotEngineTest.php` (31 cases) - the dedicated engine suite with `covers()`
- `tests/Feature/Availability/AvailabilityManagementTest.php` (15 cases), `BookableSlotsTest.php` (6 cases)
- `tests/Feature/Tenancy/IsolationTest.php` (3 new availability cases + the persistent-middleware structural test)
- `tests/Browser/AvailabilitySmokeTest.php` (2 cases, axe + add-rule-through-UI)
- `app/Actions/Availability/ComputeSlots.php` - read line by line against the tests
- `database/factories/AvailabilityRuleFactory.php`, `TimeOffFactory.php`

## Flows reviewed

- **Hand re-derivation 1 (DST spring-forward, Europe/Berlin 2026-03-29, rule 01:00-05:00):** 01:00 CET = 2026-03-29T00:00Z; 05:00 CEST = 03:00Z; window [00:00Z, 03:00Z) gives hourly UTC starts 00:00/01:00/02:00 = local 01:00 CET, 03:00 CEST, 04:00 CEST; the skipped 02:00 local never appears. Matches the test's exact expected instants (SlotEngineTest.php:367-388).
- **Hand re-derivation 2 (fall-back 2026-10-25, rule 02:00-04:00):** ambiguous 02:00 resolves to the first occurrence (CEST) = 00:00Z; 04:00 CET = 03:00Z; three real hours -> three slots. Matches SlotEngineTest.php:390-409.
- **Hand re-derivation 3 (buffer packing, 09:00-12:00, 45 min + 5/10 buffers):** step 60; customer starts 09:05/10:05/11:05; third buffered end = 12:00 = window end, allowed by the strict `>` break. Matches SlotEngineTest.php:74-90 and 120-129.
- **Hand re-derivation 4 (inclusive boundaries):** lead boundary 09:00 local exactly -> offered; horizon `now + 7d` landing exactly on the 07:00Z slot -> offered, the next slot excluded. Matches SlotEngineTest.php:186-193 and 320-330.
- **Mutant equivalence audit:** see Findings; one accepted-by-omission mutant is provably non-equivalent.

## Tests reviewed

- Test-plan edge-case list, item by item: DST spring-forward ✅, fall-back ✅, midnight/day boundary (`24:00` end-of-day window fully usable) ✅, overlapping rules (5 union permutations: overlap, touch, nested, out-of-order, disjoint-survives-merge) ✅, back-to-back buffers (no overlap incl. buffers) ✅, service longer than any window -> zero slots ✅, time off fully/partially covering + touching-does-not-block ✅, lead time + horizon clamping incl. exact boundaries ✅, already-passed times ✅, non-UTC server tz determinism (America/New_York, Pacific/Auckland, UTC against a reference) ✅. Cancel cut-off, just-taken slot, tokens are later epics.
- `BookableSlotsTest` - AC-5 exclusions assert outcomes (empty collections, exact merged slot order with staff ids), not mere execution
- `AvailabilityManagementTest` - validation tests assert error bags AND database row counts; UTC storage asserted with exact timestamps
- `IsolationTest::the tenant middleware is registered as Livewire persistent middleware` - the Epic 04 QA F1 required follow-up is delivered

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 313/313, 932 assertions, 0 skips (grep for skip/only/todo clean) |
| `make coverage` | pass | total 95.3% (gate ≥80); ComputeSlots, GetBookableSlots, Slot, SlotComputation all 100% (absent from the below-100% list) - critical ≥95 met |
| `make mutation` (full) | pass | 96.15% (104 mutations: 98 killed, 2 timeout, 4 survived - all 4 in ComputeSlots) |
| `vendor/bin/pest tests/Unit/SlotEngineTest.php --mutate --covered-only` | pass | engine-only 95.29% (85 mutations, same 4 survivors); critical ≥85 met numerically |
| `php artisan test tests/Browser/AvailabilitySmokeTest.php` | pass | 2/2, axe + JS-error assertions on the editor |
| standalone mutant simulation (scratch PHP, removed) | done | proves the `array_shift` survivor is non-equivalent, see F1 |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | All four required suites present and green |
| 2 | Right layer | ✅ | Engine pure-unit (no DB), management feature-level, journey + axe in browser |
| 3 | Coverage | ✅ | 95.3% total; engine classes 100% |
| 4 | Mutation | ❌ | Score 96.15%/95.29% meets the numeric gates, but of the 4 survivors only 3 are equivalent; the 4th (`ArrayPopToArrayShift`, ComputeSlots.php:113) is killable and exposes an untested merge path - and the suite docblock plus docs/assumptions.md claim "everything else is killed" (F1) |
| 5 | Meaningful assertions | ✅ | Exact UTC instants for DST cases; DB state asserted after rejections; no assertion-free tests |
| 6 | Edge cases | ✅ | Full test-plan list for this epic covered (see Tests reviewed) |
| 7 | Named suites | ✅ | IsolationTest extended (+3 cases + structural test), prior cases intact; concurrency suite n/a until Epic 06 |
| 8 | Factories & data | ✅/⚠️ | Factories with `window()` state used throughout; RefreshDatabase on PostgreSQL; default factory states are cross-team inconsistent (F3) |
| 9 | Async assertions | n/a | No queued work this epic |
| 10 | No skips | ✅ | None |
| 11 | Determinism | ✅ | Fixed `now` injected everywhere; explicit three-timezone determinism test with restore in `finally` |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | QG-MUTATION ("weak assertions exposed by surviving mutants are fixed") | `ComputeSlots.php:113`, `SlotEngineTest.php:13-17`, `docs/assumptions.md` §Availability | The surviving mutant `array_pop -> array_shift` in `mergeWindows` is NOT equivalent: for sorted windows `[[9,10],[12,13],[12:30,14]]` the original merges to `[[9,10],[12,14]]` while the mutant yields `[[12,13],[9,10],[12:30,14]]` (verified by simulation), i.e. a disjoint-then-overlapping rule set would produce overlapping/duplicate slots under the mutated code. No current test constructs "disjoint first window, then a window overlapping the second". The shipped code is correct (verified by re-derivation), but the documented claim that all non-equivalent mutants are killed is false on the domain core | Add a unit test with three same-day rules, e.g. 09:00-10:00, 12:00-13:00, 12:30-14:00, asserting starts [09:00, 12:00, 13:00]; correct the equivalence notes in SlotEngineTest.php and docs/assumptions.md to list exactly the verified equivalents |
| F2 | Low | QG-MUTATION robustness | `ComputeSlots.php:152-170` | The 2 mutation timeouts are the `while (true)` loop spinning when the step mutates to non-positive; a `$stepMinutes < 1` early return would make the engine total and turn the timeouts into clean kills (shared with Architecture F1) | Add the guard + a zero-duration test; acceptable to land with the F1 fix or in Epic 06 |
| F3 | Low | test-plan §Conventions | `AvailabilityRuleFactory.php`, `TimeOffFactory.php` | Default states pair `team_id => Team::factory()` with `staff_id => Staff::factory()`, producing a rule whose staff belongs to a different team when defaults are used; every current call site overrides both, so latent only | Derive `staff_id` from the rule's team (e.g. `Staff::factory()->for($team)` via a closure) when convenient |
| F4 | Low | QG-COVERAGE | `app/Models/AvailabilityRule.php:38` | Model at 0.0% line coverage: the `staff()` relation is never invoked (rules are always read from the staff side) | Will be exercised naturally in Epic 06/07; no action now |

## Required fixes (blocking)

- F1: add the merge-path killing test and correct the "everything else is killed" claims in `tests/Unit/SlotEngineTest.php` and `docs/assumptions.md`. This epic's definition of done leans on the accepted-equivalents argument; that argument must be accurate.

## Final decision (initial review, 2026-06-10 - superseded by the re-review below)

**FAIL**

- Rationale: the suite is otherwise excellent - every test-plan edge case is present with exact-instant assertions, coverage and mutation scores clear the elevated critical targets, and the Epic 04 follow-up landed. But on the domain core the accepted-mutant equivalence claim is demonstrably wrong for one survivor, which is precisely what this review exists to catch; the fix is one small test plus two doc corrections, after which this report flips to PASS.
- Blocking findings remaining: 1 (F1)

## Re-review after fixes (2026-06-11)

### Fix verified

- **F1 (High) - resolved.** New unit test `a rule overlapping the second of two disjoint windows merges with the right one` (SlotEngineTest.php:333-347) builds exactly the killing scenario from the finding (rules 09:00-10:00, 12:00-13:00, 12:30-14:00) and asserts the exact merged slot starts [09:00, 12:00, 13:00] - the output the `array_shift` mutant cannot produce. The `ArrayPopToArrayShift` mutant no longer appears in any survivor list (verified in both the engine-only and full mutation runs below).
- **Documentation corrected.** SlotEngineTest.php:8-18 now claims exactly the three verified equivalents (zero-width window comparison + both int casts) with case-by-case rationale; docs/assumptions.md §Availability now reads "Three engine mutants are accepted as behaviorally equivalent, verified case by case" with the matching list and an accurate "engine mutation 95%+" figure (measured 96.47%). Both notes re-checked against the fresh mutation output: the documented set and the measured survivor set are now identical.
- **F2/F3/F4 (Low)** remain open as tracked defer notes (step guard, factory defaults, AvailabilityRule relation coverage) - unchanged, non-blocking.

### Tools executed (re-review)

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test tests/Unit/SlotEngineTest.php` | pass | 32/32 (was 31), 46 assertions |
| `vendor/bin/pest tests/Unit/SlotEngineTest.php --mutate --covered-only` | pass | engine-only 96.47% (86 mutations: 80 killed, 2 timeout, 3 survivors = exactly the documented equivalents: Line 81 GreaterToGreaterOrEqual, Line 97 RemoveIntegerCast x2) |
| `make mutation` (full) | pass, exit 0 | 97.12% (104 mutations: 99 killed, 2 timeout, 3 survivors - the same three; no other class regressed) |
| `make test` | pass | 314/314, 933 assertions |

### Checklist deltas

- Item 4 (Mutation): ❌ -> ✅ - 97.12% full / 96.47% engine-only, and every survivor is a documented, individually verified equivalent.

## Final decision

**PASS**

- Rationale: the single blocking finding is fixed with a genuine behavior-asserting test (re-verified to kill the previously surviving non-equivalent mutant), the equivalence documentation now matches the measured survivor set exactly, and all test gates pass fresh with margin.
- Blocking findings remaining: 0
