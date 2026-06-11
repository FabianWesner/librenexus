# Review Report — Code Quality Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (3 SelfService actions, 1 command, 2 mailables + 2 templates, actioned manage SFC, tests)
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `app/Actions/SelfService/{CancelAppointmentViaToken,RescheduleAppointmentViaToken,EnsureWithinCancellationCutoff}.php`
- `app/Console/Commands/SendAppointmentReminders.php`
- `app/Mail/{AppointmentReminderMail,AppointmentRescheduledMail}.php` (+ the two pre-existing mailables for sibling consistency)
- `resources/views/mail/appointments/{reminder,rescheduled}.blade.php`
- `resources/views/pages/booking/⚡manage.blade.php`
- `routes/console.php`, `tests/Feature/SelfService/*`, `tests/Feature/Comms/ReminderTest.php`, `tests/Browser/ManageSmokeTest.php`

## Flows reviewed

- Sibling-consistency pass: new mailables mirror `AppointmentConfirmationMail`/`AppointmentCancellationMail` structure exactly (scalar capture, envelope reply-to fallback, markdown content); new actions mirror the Appointments action style (promotion, single `handle`, ValidationException refusals)
- Gate-gaming check: no new ignores, baselines, or threshold changes (`phpstan.neon`, `phpmd.xml`, `.jscpd.json`, Makefile thresholds untouched; mutation/coverage minimums unchanged)

## Tests reviewed

- Test style matches the suite conventions: Pest, factories with states, `covers()` for mutation targeting, dataset usage for the cut-off boundary pair, docblock intent comments

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint zero diffs (ran non-parallel locally; `--parallel` needs a TCP port the review sandbox blocked, not a code issue) |
| `make static` | pass | PHPStan/Larastan level 7, 0 errors, no baseline, no new ignores |
| `make complexity` | pass | PHPMD clean (complexity, unused/dead code, design) |
| `make duplication` | pass | jscpd under the 3% gate |
| `make unused` | pass | composer-unused clean; only the two pre-existing named filters (flux, blaze) with reasons |
| `make require-check` | pass | no implicit dependencies |
| grep `TODO|FIXME|dd(|dump(` in app + views | clean | also enforced by ArchTest |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Pint test mode passes, zero diffs |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline (QG-NO-IGNORE upheld) |
| 3 | Complexity | ✅ | PHPMD clean; longest new method (`dueAppointments`, 26 lines) well within limits |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; no unused privates |
| 5 | Duplication | ✅ | jscpd under threshold; no config widening |
| 6 | Dependencies | ✅ | unused + require-check pass, filters carry reasons |
| 7 | Idioms | ✅ | Constructor promotion in both composite actions; explicit return types and param hints everywhere; descriptive names (`hasClosed`, `claimReminder`, `dueAppointments`, `throttleMutations`); array-shape PHPDoc on `reminderAppointment()` |
| 8 | Laravel way | ✅ | `#[Signature]`/`#[Description]` command attributes; `route()` for the manage URL; policy values from team columns, not magic numbers; `Schedule::command` in routes/console.php |
| 9 | Reuse | ✅ | Reuses `TransitionAppointmentStatus`, `RescheduleAppointment`, `GetBookableSlots`, `x-booking.appointment-summary`, the booking layout; no parallel implementations |
| 10 | No debug/leftovers | ✅ | grep + ArchTest clean; no commented-out blocks; no TODO/FIXME |
| 11 | Consistency | ✅ | Mailables byte-for-byte consistent with siblings; SelfService actions follow the Appointments action shape; tests follow suite conventions incl. `covers()` |
| 12 | Docs | ✅ | Non-obvious decisions documented where they live (cut-off boundary semantics in the guard docblock, claim pattern in the command, linkless-reminder rationale in the mailable) and in docs/assumptions.md §Booking/§Emails; deferred log updated (Epic 07 items marked done) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | NFR-MAINT (DRY, below gate threshold) | `app/Mail/Appointment*Mail.php` | The four mailables repeat the identical scalar-capture constructor block (7-8 public props + team/timezone formatting) and the reply-to envelope logic. jscpd stays under the gate, but a fourth copy is the point where a shared base mailable or concern pays off | Defer to Epic 10 polish: extract an `AppointmentMailable` base (or a `CapturesAppointmentScalars` concern); pure refactor, fully covered by the existing render tests |
| F2 | Low | QG-COMPLEXITY blind spot (carried) | `resources/views/pages/booking/⚡manage.blade.php` | PHPMD does not scan `resources/views`, so the new SFC class (like its Epic 04/07 siblings) escapes the complexity gate. Already in the deferred log (Epic 07, assumptions log line 266) | None new; covered by the existing Epic 10 deferral |
| F3 | Low | Docs accuracy (shared with QA F2) | `docs/assumptions.md:68-71` | "Three engine mutants" no longer matches the measured four-survivor set after this epic adds the documented-equivalent `staff_id` mutant | Defer: one-line addition to the assumptions note |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: every code-quality gate is green with no baselines, ignores, or threshold changes; the new code reads exactly like its siblings and reuses the domain actions rather than duplicating them. The three Low findings are tracked maintenance niceties.
- Blocking findings remaining: 0
