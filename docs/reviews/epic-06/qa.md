# Review Report - QA - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** test-plan.md (layers, ≥80%/≥95% coverage, mutation ≥70%/≥85%, named suites, §Concurrency, §Accessibility & performance per page), Epic 06 "Required tests", QG-TESTS/QG-COVERAGE/QG-MUTATION/QG-E2E

## Files reviewed

- `tests/Feature/Booking/ConcurrencyTest.php` - the named FR-BOOK-3 suite, line by line
- `tests/Feature/Booking/BookingFlowTest.php`, `CustomerDedupTest.php`, `ManageTokenTest.php`, `PublicRoutingTest.php`, `BookingHardeningTest.php`
- `tests/Feature/Tenancy/IsolationTest.php` (Epic 06 describe block)
- `tests/Browser/BookingSmokeTest.php`
- `app/Enums/AppointmentStatus.php` - coverage gap analysis (see F1)
- `Makefile` (test targets, `memory_limit=1G`), `phpunit.xml`, `tests/Pest.php`

## Flows reviewed

- The concurrency test genuinely races in flight: connection A inserts inside an open transaction (constraint locks the range, nothing committed), connection B's `pg_send_query` insert blocks server-side on A's outcome, A commits, B resolves to SQLSTATE 23P01 and the table holds exactly one row. The partial-overlap variant (09:00-10:00 vs 09:30-10:30) proves range semantics, not just equal start times. Raw `PGSQL_CONNECT_FORCE_NEW` connections bypass the test transaction, with cleanup in `finally`
- AC-5 data-level proof: book -> rebook fails -> direct `update(['status' => Cancelled])` -> rebook succeeds and `reservingTime()` count stays 1
- AC-7: two staff, same window, two "any available" bookings -> first goes to the lowest staff id, second to the other, same start instant, no conflict possible (the constraint is per staff)
- Lost-race UX: component returns to step 3 with errors on `selectedSlot`, exactly one appointment persisted, no mail queued

## Tests reviewed

- `ConcurrencyTest::two genuinely concurrent bookings for the same slot: exactly one wins` - in-flight DB race, 23P01, count = 1
- `ConcurrencyTest::concurrent bookings with partially overlapping ranges` - overlap (not equality) blocked
- `ConcurrencyTest::a cancelled appointment does not block a concurrent booking` + `::a no-show appointment does not block` - partial constraint scope (FR-APPT-4)
- `ConcurrencyTest::transitioning a held slot to a non-reserving status frees it immediately` - AC-5 mechanism
- `ConcurrencyTest::the exclusion constraint also stops overlapping inserts through Eloquent` - constraint guards every code path
- `BookingFlowTest` (12) - happy path with mailed-token round-trip (`hash('sha256', raw) === stored`), each validation failure persists nothing, past-slot rejection via `travelTo`, just-taken slot disappears on refresh, honeypot, throttle, unknown slug
- `CustomerDedupTest` (3) - AC-1b reuse/update, cross-tenant separation, case-insensitivity
- `ManageTokenTest` (5) - valid/forged/cross tokens, slug cross-check
- `BookingHardeningTest` (8) - token format/entropy, notes persistence, trim+lowercase, mail branding/reply-to/pending subject, model normalization, middleware context
- `IsolationTest` Epic 06 block (2) - customers + appointments in the named isolation suite
- `BookingSmokeTest` (2) - browser click-through of all five steps + manage page, axe + JS-error assertions

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 359/359, 1078 assertions |
| `make coverage` | pass | total 93.3% (gate ≥80); BookAppointment 92.9%, GetBookableSlots 95.5%, **AppointmentStatus 31.6%** (see F1) |
| `make mutation` | pass | 98.04% (153 mutants, 149 tested); only the 3 documented equivalent ComputeSlots mutants survive (matches assumptions.md) |
| `vendor/bin/pest tests/Browser` | pass | 27/27, 98 assertions (an initial run concurrent with the mutation run reported "no tests found"; a clean rerun and a single-file run both pass - do not run e2e and mutation simultaneously) |
| `vendor/bin/pest tests/Feature/Booking tests/Feature/Tenancy` | pass | 124/124, 404 assertions |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | Every "Required test" in epic-06-booking.md present and passing (concurrency, flow + validations, dedup, manage token, status reservation, PUBLIC_PATHS extended) |
| 2 | Right layer | ✅ | DB race at raw-connection level, flows via Livewire feature tests, journey via browser test with axe |
| 3 | Coverage | ❌ | 93.3% overall passes the gate, but `AppointmentStatus` - declared critical via `covers()` in the named suite - is at 31.6%: `reservesTime()`, `isTerminal()`, `canTransitionTo()`, `allowedTransitions()` have zero direct tests and zero production callers. test-plan.md sets ≥95% for booking-domain logic (F1) |
| 4 | Mutation | ⚠️ | 98.04% ≥ 70/85 thresholds, and survivors match the documented equivalents - but `--covered-only` skips the uncovered enum lines entirely, so the score is blind to F1; the number is honest only for covered code |
| 5 | Meaningful assertions | ✅ | Outcome assertions throughout (SQLSTATE, row counts, hash equality, mail contents, persisted-nothing checks); no assertion-free tests found |
| 6 | Edge cases | ✅ | Just-taken slot, past slot, lost race, partial overlap, cancelled/no-show non-blocking, cross-tenant IDs, forged token, case/whitespace email, approval mode; DST/midnight/buffers carried by the Epic 05 engine suite (unchanged) |
| 7 | Named suites | ✅ | IsolationTest extended (not weakened, prior blocks intact); ConcurrencyTest is a genuine DB-level race, fresh-run green |
| 8 | Factories & data | ✅ | Factories with states (`between`, `status`, `window`); raw SQL only where raw connections are the point of the test; RefreshDatabase on PostgreSQL |
| 9 | Async assertions | ✅ | `Mail::fake` + `assertQueued`/`assertNothingQueued` with recipient + token-content checks; mailable `implements ShouldQueue` |
| 10 | No skips | ✅ | No skip/only/todo in the new suites |
| 11 | Determinism | ✅ | `travelTo` pins time; fixed 2027 dates on known weekdays; non-UTC tenant timezones exercised (Europe/Berlin in ManageToken + browser tests); engine timezone matrix unchanged from Epic 05 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | test-plan.md coverage targets (critical domain ≥95%) / project test enforcement | `app/Enums/AppointmentStatus.php:23-55` | The FR-APPT-4 status model shipped as an Epic 06 deliverable, but `reservesTime()`, `isTerminal()`, `canTransitionTo()` and `allowedTransitions()` have **no tests and no callers** (31.6% line coverage on a class the named suite declares critical via `covers()`). The mutation gate cannot see this (`--covered-only`). A wrong transition matrix would surface only in Epic 07, which builds its server-side enforcement on these methods | Add a unit test asserting the full FR-APPT-4 matrix (reserves-time table, terminal statuses, every allowed and a representative set of rejected transitions) before marking the epic done; alternatively delete the matrix methods and ship them with Epic 07 tests |
| F2 | Low | tooling | `Makefile` e2e/mutation | Running `make e2e` while `make mutation` is executing yields "No tests found" (shared Pest state); sequential runs are fine | Note in docs or serialize in `make verify`; track for Epic 10 |
| F3 | Low | test hygiene | `ConcurrencyTest.php:96-192` | The raw-connection tests write outside RefreshDatabase; cleanup relies on the `finally` cascade delete of the team. A crash between fixture insert and `try` could leak rows into the test DB | Acceptable; move fixture creation inside the `try` if it ever flakes |

## Required fixes (blocking)

- F1: test the FR-APPT-4 transition matrix and `reservesTime()` (or remove the untested, uncalled methods until Epic 07 delivers them with tests). **Resolved - see re-review below.**

## Re-review after fixes (2026-06-11)

- **F1 (High) - resolved.** New `tests/Unit/AppointmentStatusTest.php` (5 tests, 46 assertions, `covers(AppointmentStatus::class)`) asserts: `reservesTime()` for all five statuses plus the exact `reservingValues()` list, `isTerminal()` for all five, the exact `allowedTransitions()` arrays per FR-APPT-4, the **full 25-pair `canTransitionTo` cross-product against an independent literal matrix** (declared in the test, not derived from the enum, so it cannot be tautological), and every label. Verified by reading the test and re-running fresh:
  - `vendor/bin/pest tests/Unit/AppointmentStatusTest.php` - 5/5, 46 assertions.
  - `make test` - 364/364, 1124 assertions (up from 359/1078).
  - `make coverage` - total **94.9%** (was 93.3%); `AppointmentStatus` no longer appears in the uncovered-lines report (fully covered), satisfying the ≥95% critical-domain policy for the status model.
  - `make mutation` - **98.04%**, 153 mutants / 9 files, only the three documented equivalent `ComputeSlots` mutants survive; the run now executes 102 tests (was 97), confirming the new suite participates.
  - Noted for the record: the mutation tool generates **zero mutants for the enum itself** (a `--class="App\Enums\AppointmentStatus"` scoped run creates 0 mutations, and the whole-app mutant count is unchanged at 153), so the mutation score was and remains structurally blind to this class - the direct unit test is the effective guard, which is exactly what F1 demanded.
- F2 and F3 (Low) remain open as tracked, non-blocking notes (e2e/mutation concurrent-run interference; raw-connection fixture cleanup).
- Checklist items 3 (Coverage) and 4 (Mutation) are now ✅: 94.9% overall, the critical booking-domain classes fully covered, and the mutation score honest for all mutated code with the enum guarded by direct assertions instead.

## Final decision

**PASS**

- Rationale: the single blocking finding is fixed with a genuine, independently-derived test of the full FR-APPT-4 matrix; all gates re-run green with improved numbers (364/364, coverage 94.9%, mutation 98.04% with only documented equivalents), and the named suites are unweakened. Remaining findings are Low and tracked.
- Blocking findings remaining: 0
