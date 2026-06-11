# Review Report - QA Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (appointment views, manual write actions, status lifecycle, cancellation mail)
- **Requirements/rules in scope:** test-plan.md (layers, ≥80%/≥95% coverage, mutation ≥70%/≥85%, named suites, §Epic 07 required tests, §Edge cases), QG-TESTS/QG-COVERAGE/QG-MUTATION/QG-E2E, project rule "every change must be programmatically tested"

## Files reviewed

- `tests/Feature/Appointments/AppointmentViewsTest.php` (16 tests) - visibility, filters, query counts
- `tests/Feature/Appointments/ManualBookingTest.php` (11 tests) - create/reschedule/cancel + authz
- `tests/Feature/Appointments/StatusTransitionTest.php` (12 tests incl. datasets) - lifecycle
- `tests/Unit/AppointmentStatusTest.php` (5 tests, `covers(AppointmentStatus::class)`) - full FR-APPT-4 matrix
- `tests/Feature/Tenancy/IsolationTest.php` - Epic 07 block (+4 tests)
- `tests/Browser/AppointmentsSmokeTest.php` (3 tests) - axe + JS errors
- `tests/Feature/Booking/ConcurrencyTest.php` - named suite, unweakened (9 tests, raw two-connection races intact)
- `app/Actions/Booking/BookAppointment.php`, `app/Actions/Appointments/RescheduleAppointment.php` - coverage gap analysis (F1, F3)

## Flows reviewed

- Role visibility proven at the query layer (`appointments->modelKeys()`), not just the rendered DOM
- Conflict attempts: just-taken slot on create; occupied slot on reschedule with original time preserved; terminal reschedule rejection
- Atomicity: same row, same token hash, count stays 1 after reschedule
- Cancel frees the slot: rebooking the identical slot succeeds afterwards (data + constraint path)
- Mail assertions via `Mail::fake` + `assertQueued` with recipient checks (async, not inline)

## Tests reviewed

- `StatusTransitionTest::every allowed transition succeeds` (6-pair dataset) + `invalid transitions are rejected server-side` (4-pair dataset) + unknown value - AC-4
- `AppointmentStatusTest::every status pair resolves canTransitionTo consistently with the matrix` - all 25 pairs against an independent table (kills matrix mutants)
- `AppointmentViewsTest` query-count block - `DB::listen` equality between 1 and 8 appointments for list AND calendar (a real non-growth proof, not a magic number)
- `ManualBookingTest::a staff-role member cannot act on another staff member's appointment` - all four actions 403 with status + mail unchanged
- `IsolationTest` Epic 07 block - cross-tenant denial with data-unchanged assertions

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 407/407, 1253 assertions, 27.8s |
| `make coverage` | pass | total **94.9%** (gate ≥80). But: `BookAppointment` **87.2%** (uncovered 43..47 = new 23505 retry, 88..92 = 23P01 catch), `RescheduleAppointment` **91.7%** (uncovered 56..60 = 23P01 catch), `AppointmentCancellationMail` 78.9% (replyTo branch + `content()`) |
| `make mutation` | pass | **98.04%** (153 mutations, 9 files); the only 3 untested mutants are the documented equivalent ComputeSlots mutants (zero-width window, two int casts) |
| `make e2e` | pass | 30/30, 107 assertions, no JS errors |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ⚠️ | test-plan §Epic 07 list fully present (view/filter+roles, manual writes incl. conflict, transition matrix, cancellation mail enqueued, N+1). But the epic's a11y requirement names "list / calendar / **detail**" and the detail modal has no axe test (F2); and the shipped 23505 retry has no test at all (F1) |
| 2 | Right layer | ✅ | Matrix unit-tested on the enum; flows feature-tested through the Livewire components; browser tests for a11y/JS; DB-level races in the concurrency suite |
| 3 | Coverage | ⚠️ | 94.9% overall. Critical booking-domain classes: `AppointmentStatus` 100%, `GetBookableSlots`/`ComputeSlots` not in the uncovered report, but `BookAppointment` regressed 92.9% -> **87.2%** (below the ≥95% critical bar) purely because the new retry branch is untested (F1, F3) |
| 4 | Mutation | ⚠️ | 98.04% with only documented equivalents. Caveat: `--covered-only` cannot mutate the uncovered catch branches of F1/F3, and the two new actions carry no `covers()` so they are outside the targeted mutation set (F5) |
| 5 | Meaningful assertions | ✅ | Outcome assertions throughout (DB state, modelKeys, token hash, mail recipient, data-unchanged after denials); no assertion-free tests |
| 6 | Edge cases | ✅ | Invalid transition (`cancelled -> confirmed` etc.), just-taken slot, cross-tenant IDs, cancelled/no_show does not block, terminal reschedule, unlinked staff member, malformed dates. DST/cut-off/token forgery owned by Epics 05/08 suites |
| 7 | Named suites | ✅ | Concurrency suite (9) and isolation suite green and extended, not weakened; ran inside the 407 |
| 8 | Factories & data | ✅ | `Appointment::factory()` with `between()`/`status()` states; `fake()` data; RefreshDatabase on PostgreSQL |
| 9 | Async assertions | ✅ | `Mail::fake` + `assertQueued`/`assertNotQueued` with recipient closures; mailable implements `ShouldQueue` |
| 10 | No skips | ✅ | grep for `skip(`/`only(`/`todo(` over the Epic 07 tests: none |
| 11 | Determinism | ✅ | `travelTo` fixed Mondays in 2027; URL/day fallbacks tested; non-UTC tz behavior owned by the Epic 05 engine suite (these tests pin team tz to UTC deliberately) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | Project test enforcement / QG-COVERAGE critical ≥95% | `app/Actions/Booking/BookAppointment.php:40-48` | The new 23505 customer-race retry (the claimed closure of the Epic 06 deferral) has **zero test coverage** (coverage report: lines 43..47 untested). A behavior change on the booking hot path shipped untested, and it dropped a critical booking-domain class from 92.9% to 87.2%. The mutation gate cannot see it (`--covered-only`) | Add a test that proves the retry: e.g. race two first-time bookings with the same new email over a raw second connection (ConcurrencyTest pattern), or force a 23505 `QueryException` on the first `attempt()` and assert the booking succeeds reusing the customer row. Only then mark the Epic 06 deferral closed |
| F2 | High | Epic 07 §Required tests ("axe assertions in the appointments list / calendar / **detail** E2E tests") | `tests/Browser/AppointmentsSmokeTest.php` | The detail modal has no axe assertion; the smoke tests cover the list, the new-appointment modal, and the calendar, but never open the detail modal on either page | Add a browser test (or extend an existing one) that opens an appointment's detail modal and asserts `assertNoAccessibilityIssues()` |
| F3 | Medium | QG-COVERAGE critical / test-plan "cover branches" | `BookAppointment.php:87-93`, `RescheduleAppointment.php:55-61` | The 23P01 -> friendly-error translation is never executed through either action: all conflict tests are rejected earlier by the engine re-validation. The constraint itself is proven at the SQL level, but the user-facing path under a true mid-transaction race is untested (pre-existing for BookAppointment, replicated into the new action) | Cover via a stubbed `GetBookableSlots` returning a conflicting slot so the UPDATE/INSERT hits the constraint, asserting `SlotNoLongerAvailableException`; acceptable to schedule with F1 |
| F4 | Low | SEC-AUTHZ-2 regression depth | `AppointmentViewsTest` | No test sets `staffFilter` to a foreign staff id as staff-role (code makes it a no-op today) | One-line addition to the visibility test |
| F5 | Low | test-plan mutation convention | `tests/Feature/Appointments/*` | `TransitionAppointmentStatus`/`RescheduleAppointment` have no `covers()`, so targeted mutation skips them (the critical matrix logic itself is mutation-covered via the enum) | Add `covers()` when F1/F3 tests land |
| F6 | Low | Mail coverage | `AppointmentCancellationMail` (78.9%) | `content()` and the replyTo branch are never rendered by a test (queueing + recipient are asserted; the markdown body is not) | Cover in Epic 08 comms polish alongside branding work |

## Required fixes (blocking)

- F1: test the 23505 retry branch before declaring the Epic 06 deferral closed. **Resolved, see re-review.**
- F2: axe assertion on the appointment detail modal (explicitly named in the epic's required tests). **Resolved, see re-review.**

## Re-review after fixes (2026-06-11)

Both blocking findings were fixed; the fixes were read and all gates re-ran
fresh in this re-review.

**F1 (23505 retry) - resolved.** `tests/Feature/Booking/BookingHardeningTest.php`
now proves the retry from three angles, verified by reading the tests:

- *"a customer unique-violation race is retried once and succeeds"* - an
  anonymous subclass throws a reflection-built SQLSTATE-23505
  `QueryException` on the first `attempt()` and delegates to
  `parent::attempt()` on the second; asserts exactly 2 attempts, a persisted
  appointment, and exactly one customer row (the dedup outcome, not just
  execution).
- *"a non-unique-violation query exception is rethrown, not retried"* -
  SQLSTATE 08006, asserts 1 attempt and the exception propagates (guards the
  retry condition against widening).
- *Bonus, closes the BookAppointment half of F3:* *"a constraint-level lost
  race is translated to the friendly slot error"* - a stub engine reports an
  occupied slot as free, the **real Postgres exclusion constraint** fires
  inside the action's transaction, and the test asserts
  `SlotNoLongerAvailableException` plus row count 1. The 23P01 catch is now
  genuinely executed, with the constraint (not the engine) as arbiter.

The only production change for the seam is `attempt()` widening from private
to protected (`BookAppointment.php:51`); no behavior change, PHPStan level 7
still clean.

**F2 (detail-modal axe) - resolved.**
`AppointmentsSmokeTest::the appointment detail modal is accessible and error free`
opens a real row's action menu (`@appointment-actions-button`), opens the
detail modal (`@appointment-view-button`), asserts the customer name renders,
finishes the pending dialog transition (same documented headless workaround
as the new-appointment modal test), then asserts `assertNoJavascriptErrors()`
and `assertNoAccessibilityIssues()`. The epic's "list / calendar / detail"
axe requirement is now fully met (browser suite 4/4).

Fresh gate evidence (all re-run by this reviewer):

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | **410/410**, 1261 assertions |
| `make coverage` | pass | total **95.5%**; `BookAppointment` **97.9%** (above the ≥95% critical bar; only the generic rethrow line 92 remains), `RescheduleAppointment` unchanged 91.7% (its 23P01 catch, F3 remainder) |
| `make mutation` | pass | **98.09%**; the newly covered retry/23P01 branches now enter the `--covered-only` set and their mutants are killed; only the documented equivalent ComputeSlots mutants remain |
| `make e2e` | pass | **31/31** (AppointmentsSmokeTest 4/4), no JS errors |
| `vendor/bin/pint --test` | pass | 0 diffs |
| `make static` | pass | level 7, 0 errors |

Checklist deltas: item 1 (Tests exist) ⚠️ -> ✅, item 3 (Coverage) ⚠️ -> ✅,
item 4 (Mutation) ⚠️ -> ✅ (the `covers()` convention note stays as Low F5).

Findings after re-review:

- F1 **closed**, F2 **closed**.
- F3 reduced to **RescheduleAppointment only** (`RescheduleAppointment.php:55-61`,
  91.7%): its 23P01 catch is still never executed through the action; the
  identical BookingHardeningTest stub-engine pattern applies. Medium, defer
  with F5 (add `covers()` + the stubbed-engine reschedule race test by
  Epic 10 at the latest, or alongside Epic 08's reschedule work).
- F4, F5, F6 remain Low, tracked as written.

## Final decision

**PASS WITH WARNINGS** (supersedes the FAIL above; re-review of 2026-06-11)

- Rationale: both blocking findings are fixed with genuine, outcome-asserting tests (the retry proves dedup and attempt count; the constraint test makes the real exclusion constraint the arbiter; the detail modal is axe-checked end to end), and every gate re-ran green with improved numbers (410/410, coverage 95.5%, BookAppointment 97.9%, mutation 98.09%, e2e 31/31). The remaining warnings (F3 remainder on RescheduleAppointment, F4-F6) are Medium/Low and tracked.
- Blocking findings remaining: 0
