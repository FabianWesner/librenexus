# Review Report — QA Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (self-service cancel/reschedule, reminders, mail branding)
- **Requirements/rules in scope:** Epic 08 "Required tests" + done-when (cancellation-token code coverage ≥ 95%, mutation ≥ 85%), test-plan edge cases (cancel cut-off boundary, just-taken slot, forged/cross tokens), QG-TESTS/QG-COVERAGE/QG-MUTATION/QG-E2E

## Files reviewed

- `tests/Feature/SelfService/{TokenSecurityTest,CancelViaTokenTest,RescheduleViaTokenTest}.php`
- `tests/Feature/Comms/ReminderTest.php`
- `tests/Browser/ManageSmokeTest.php`
- `tests/Feature/Booking/{BookingFlowTest,ManageTokenTest,ConcurrencyTest}.php` (named suites + AC-4 confirmation assertions)
- `app/Console/Commands/SendAppointmentReminders.php`, `app/Actions/SelfService/*`, `app/Actions/Appointments/RescheduleAppointment.php` (assertion targets)

## Flows reviewed

- Every epic "Required test" mapped to a present, passing test (see below)
- Mutation survivor audit, including independent verification of the `staff_id` equivalent-mutant claim

## Tests reviewed

- `TokenSecurityTest` — valid/forged/tampered/cross-appointment tokens; raw-token-never-logged probe (wipes logs, runs GET + cancel, scans all log files)
- `CancelViaTokenTest` — happy path frees the slot (proven by an actual rebooking through `BookAppointment`); **exactly-at-cutoff refused** (test-plan boundary case) plus 121-minutes-still-allowed companion; past-cutoff message content; terminal precedence; disabled UI; throttle; cancellation-mail body render incl. timezone conversion and reply-to fallback (carried Epic 07 obligation, closed)
- `RescheduleViaTokenTest` — atomic move with all four range columns + unchanged token hash asserted; occupied target keeps original; cut-off dataset [120, 45]; terminal rejection; page-level select/confirm validation; admin path linkless mail; **stub-engine 23P01 race test** (carried Epic 07 obligation, closed) proving the exclusion constraint is the final arbiter
- `ReminderTest` — full FR-COMMS-3 matrix in one run (8 statuses/windows, `assertQueuedCount(2)`); per-team lead time; inclusive window edge; double-run sends exactly once with output-count assertions; pre-claimed row skipped; `preventLazyLoading` eager-load proof; branded render
- `ManageSmokeTest` — axe (`assertNoAccessibilityIssues`) + JS-error-free cancel and reschedule click-throughs with DB state assertions
- `covers()` present: `CancelViaTokenTest` (action, guard, cancellation mail), `RescheduleViaTokenTest` (both actions, rescheduled mail), `ReminderTest` (command, reminder mail) — carried obligation closed

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 442/442, 1396 assertions (initial sandboxed run failed on port binding; clean rerun verified) |
| `make e2e` | pass | 33/33, 130 assertions |
| `make coverage` | pass | total 96.5% (min 80). Epic 08 critical classes absent from the uncovered list = 100%: `CancelAppointmentViaToken`, `EnsureWithinCancellationCutoff`, `RescheduleAppointmentViaToken`, all four mailables, `Appointment` token lookup. `SendAppointmentReminders` 97.3% (line 36), `RescheduleAppointment` 97.2% (line 60) |
| `make mutation` | pass | 98.18% (220 mutations: 215 tested, 1 timeout, 4 untested) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ✅ | All five "Required tests" of the epic present and passing (token security, cancel+reschedule incl. cut-off and slot freeing, mail/queue assertions for all three mail types + the new rescheduled one, reminder idempotency, axe on the tokened page) |
| 2 | Right layer | ✅ | Policy guards feature-tested at the action layer; page behavior Livewire-tested; journeys browser-tested with no JS errors |
| 3 | Coverage | ✅ | 96.5% overall; cancellation-token code 100%/97.2% versus the ≥ 95% elevated target (epic done-when met) |
| 4 | Mutation | ✅ | 98.18% versus ≥ 85% critical. Survivors: 3 documented ComputeSlots equivalents (verified set, Epic 05 re-review) + 1 new `RemoveArrayItem` on `RescheduleAppointment.php:46` (`staff_id`). **Equivalence claim independently verified:** `matchingSlot()` always passes `$appointment->staff`, and `GetBookableSlots` pins via `whereKey($staff->getKey())` before `ComputeSlots` emits per-member slots, so every offered slot carries the appointment's own `staff_id`; dropping it from the update writes identical state. The claim holds at every call site today and the rationale is recorded in RescheduleViaTokenTest:85-88 |
| 5 | Meaningful assertions | ✅ | Exact-instant ISO assertions, queued-count totals, DB state after every refusal, rendered mail content; no assertion-free tests |
| 6 | Edge cases | ✅ | Exactly-at-cutoff refused + one-minute-before allowed; just-taken slot (occupied + constraint race); forged/cross tokens; window-edge reminder; non-UTC branding (Europe/Berlin) in cancel/reminder render tests and the browser suite |
| 7 | Named suites | ✅ | IsolationTest, ConcurrencyTest, ManageTokenTest all green and untouched in the 442-test run |
| 8 | Factories & data | ✅ | Factories with states (`between`, `window`, `status`); `RefreshDatabase` on PostgreSQL |
| 9 | Async assertions | ✅ | `Mail::fake()` everywhere; `assertQueued`/`assertQueuedCount`/`assertNothingQueued` with recipient and content callbacks; all mailables `ShouldQueue` |
| 10 | No skips | ✅ | No skip/only in Epic 08 tests (the only `markTestSkipped` are the Epic 02 Fortify feature guards) |
| 11 | Determinism | ✅ | Fixed `travelTo` instants (2027-03-08 Monday); browser suite uses relative future dates with full-week availability so it cannot go stale |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | Checklist 5 (assertions prove the claim) | `ReminderTest.php:137` ("an already-claimed row is skipped even when it was selected") | The test name overstates what it proves: setting `reminder_sent_at` before the run means the row is filtered by `whereNull` in `dueAppointments()` and is **never selected**, so the claim-race `continue` branch (`SendAppointmentReminders.php:35-37`) stays unexecuted, which is exactly the uncovered line 36 in the coverage report. The true concurrent select-then-lose-the-claim race is untested; idempotency currently rests on the (structurally sound) conditional UPDATE plus the double-run test | Defer: rename the test to what it proves and add a genuine claim-race test (e.g. mark the row claimed via a second connection between selection and claim, or assert `claimReminder`-equivalent UPDATE semantics directly); track for Epic 09/10 |
| F2 | Low | Epic 05 precedent (documented survivor set = measured set) | `docs/assumptions.md:68-71` | The assumptions log still says "Three engine mutants are accepted as behaviorally equivalent" but the measured survivor set is now four (the verified `staff_id` item is documented only in the RescheduleViaTokenTest docblock). The Epic 05 QA re-review established that these two lists must match | Defer (one line): extend the assumptions note with the RescheduleAppointment `staff_id` equivalent and its pinned-staff rationale |
| F3 | Low | FR-COMMS-3 accuracy (shared with Product F1) | `RescheduleAppointment.php` | No test pins the reminder behavior across a reschedule (reminder already sent, then moved: no new reminder). Whatever is decided for Product F1, a test should encode it | Defer with Product F1 |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every required test exists with strong, outcome-level assertions; coverage and mutation comfortably clear the elevated done-when targets and the lead's reported numbers reproduce exactly (442/1396, 96.5%, 98.18%, same 4 survivors); the staff_id equivalence claim was independently re-derived and holds. The warnings are a mislabeled race test and two documentation/pin-down gaps, all tracked.
- Blocking findings remaining: 0
