# Review Report - QA Reviewer - Epic 10 (Hardening & quality report / final application state)

> Final-state review of the test system as a whole, leaning on the per-epic QA
> reviews (epic-00..09, all pass) and re-verifying the final numbers against
> the verify log plus fresh runs of the named suites.

## Reviewed scope

- **Epic / change:** Epic 10 hardening + final application state
- **Requirements/rules in scope:** QG-TESTS, QG-COVERAGE, QG-MUTATION, QG-E2E,
  test-plan.md targets and edge cases

## Files reviewed

- `/tmp/claude/verify.log` - full local pipeline output (final tree)
- `docs/quality-report.md` §Test summary and §Tool results - every claimed
  number cross-checked against the log
- `tests/Feature/Auth/AuthHardeningTest.php` - session-fixation assertion
  (line 67) added in Epic 10
- `tests/Feature/Teams/TeamInvitationTest.php` - decline coverage (159, 185)
- `tests/Unit/SlotEngineTest.php`, `docs/assumptions.md` §Availability - the
  four accepted-equivalent mutants, matched one-by-one against the survivors
  in the verify log
- `tests/Feature/Tenancy/ListPageQueryCountTest.php`,
  `tests/Feature/Appointments/AppointmentViewsTest.php` - query-count
  assertions backing the pagination change

## Flows reviewed

- Named regression suites - re-run fresh by this review
- Mutation survivors - verify.log lists exactly 4 untested mutants
  (RescheduleAppointment line 46 staff_id item; ComputeSlots line 81
  zero-width window; two int casts at ComputeSlots line 97), exactly matching
  the equivalents documented in assumptions.md and in-test; no undocumented
  survivor
- Coverage honesty - per-file output inspected; overall 97.2%; critical
  classes (scoping, engine, booking, tokens) 97-100% as claimed

## Tests reviewed

- `IsolationTest.php` + `ConcurrencyTest.php` - 40 tests, 91 assertions, pass
  (fresh run, exit 0)
- Edge cases from the test plan - DST both directions (SlotEngineTest, three
  server timezones), buffers, lead time/horizon, cancel cut-off boundary,
  just-taken slot (in-flight uncommitted conflict), cross-tenant IDs, forged
  tokens: all present per epics 05-08 QA reviews and the green suite
- Async assertions - Mail/Queue fakes assert queueing with scalar content
  (epics 06-08 reviews)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tenancy/IsolationTest.php tests/Feature/Booking/ConcurrencyTest.php` | pass | 40 tests, 91 assertions (this review) |
| `make test` (verify.log:951) | pass | 469/469, 1500 assertions |
| `make coverage` (verify.log:953) | pass | 97.2% overall, min 80 enforced |
| `make mutation` (verify.log:955) | pass | 98.20%: 217 tested, 1 timeout, 4 untested (all verified equivalents) over 222 mutants in the 17 `covers()` classes |
| `make e2e` (verify.log:956) | pass | 35/35, 140 assertions, no console errors |
| CI run 27322534001 (HEAD `fe103c7`) | **fail** | `AuthSettingsSmokeTest` 2FA challenge test failed in CI: server threw `TwoFactorChallengeViewResponse is not instantiable` (real defect at HEAD) plus an axe-timing weakness in the test; both fixed in the working tree, uncommitted |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | 469 + 35 tests green locally on the final tree; all Epic 10 deferral closures carry tests |
| 2 | Right layer | ✅ | Pure engine unit-tested; flows feature-tested; journeys browser-tested with axe |
| 3 | Coverage | ✅ | 97.2% overall (threshold 80); critical classes 97-100%. Cosmetic: `Models/AvailabilityRule` reads 0.0% (its single relation method is uncalled directly); negligible |
| 4 | Mutation | ✅ | 98.20% (thresholds 70/85); every survivor individually verified equivalent and documented next to its test |
| 5 | Meaningful assertions | ✅ | Mutation score is the proof; spot-checked decline + session-fixation tests assert outcomes (DB state, session id inequality) |
| 6 | Edge cases | ✅ | Test-plan edge cases all present (per-epic QA reviews; DST/boundary/race tests cited above) |
| 7 | Named suites | ✅ | Both re-run green by this review; not weakened (assertions grew over epics) |
| 8 | Factories & data | ✅ | Factories/states + `fake()` throughout; RefreshDatabase on PostgreSQL |
| 9 | Async assertions | ✅ | Mail/Queue fakes assert queueing, never inline (epics 06-08) |
| 10 | No skips | ✅ | No `skip`/`only`/incomplete markers in the suite |
| 11 | Determinism | ❌→⚠️ | Slot tests run under three timezones. But the 2FA-challenge browser test proved environment-sensitive in CI (axe ran mid-navigation) and masked a real HEAD defect locally; the uncommitted fix adds an explicit wait. Until the fix is pushed and CI is green, QG-E2E is red on the default branch |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | QG-E2E / App DoD #2 | CI run 27322534001; `tests/Browser/AuthSettingsSmokeTest.php`; `app/Providers/FortifyServiceProvider.php` | QG-E2E is red on the default branch: at HEAD the 2FA challenge view is unregistered (route 500s) and the smoke test races axe against navigation. Local verify is green only because the fixes already sit uncommitted in the working tree | Commit/push the fixes (Fortify registration, test wait, contrast tweak), confirm green CI |
| F2 | Low | QG-COVERAGE hygiene | AvailabilityRule.php | 0.0% file coverage on a one-method model; overall and critical thresholds unaffected | Optional: a trivial relation assertion |

## Required fixes (blocking)

- F1: publish the working-tree fixes; re-run CI; QG-E2E must be green on main.

## Final decision

**FAIL**

- Rationale: the test system itself is excellent: thresholds beaten by wide
  margins, mutation-backed assertions, honest survivor accounting, named
  suites re-verified green by this review. But the E2E gate is currently red
  on the default branch because a real defect at HEAD (unregistered 2FA
  challenge view) was caught by CI while its fix remains uncommitted. The DoD
  requires the pipeline green in CI, so this blocks until pushed. Single,
  well-understood fix; flips to PASS on a green run.
- Blocking findings remaining: 1 (F1, shared with product F1)
