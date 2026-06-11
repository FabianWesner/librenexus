# Review Report — Product Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (self-service cancel/reschedule via manage token, reminder mailable + scheduled command, reschedule mailable, mail branding polish)
- **Requirements/rules in scope:** FR-CANCEL-1..4, FR-COMMS-1..4, FR-TENANT-8 (cut-off, reminder lead time), AC-1..AC-6, pages.md §Manage appointment

## Files reviewed

- `app/Actions/SelfService/CancelAppointmentViaToken.php` — AC-2 cancel policy (terminal rejection, cut-off, delegation to FR-APPT-4 transition)
- `app/Actions/SelfService/RescheduleAppointmentViaToken.php` — AC-3 reschedule + queued notice with manage link
- `app/Actions/SelfService/EnsureWithinCancellationCutoff.php` — FR-CANCEL-2 cut-off with human-readable refusal message
- `app/Console/Commands/SendAppointmentReminders.php` — FR-COMMS-3 reminder selection + idempotent claim
- `app/Mail/AppointmentReminderMail.php`, `app/Mail/AppointmentRescheduledMail.php` — new mailables (branding, scalar capture, nullable manage link)
- `resources/views/mail/appointments/{confirmation,cancellation,reminder,rescheduled}.blade.php` — AC-6 template sanity
- `resources/views/pages/booking/⚡manage.blade.php` — the actioned manage page (cancel modal, disabled-with-reason, reschedule day/slot picker, success notices)
- `routes/console.php` — reminder schedule (everyFifteenMinutes)
- `docs/assumptions.md` §Booking, §Emails — documented decisions (shared cut-off, linkless reminder, linkless admin reschedule notice)

## Flows reviewed

- Cancel via manage link — confirm modal, status to cancelled, slot freed (rebooking proven), cancellation mail queued, success notice
- Cancel at/past cut-off — button disabled with the reason text naming the window and the team contact
- Reschedule via manage link — day picker + slot list (own slot excluded), atomic move, notice with manage link, occupied-slot friendly error
- Admin reschedule — queues the same notice without a link (closes the Epic 07 F1/FR-APPT-5 deferral, logged as done in docs/assumptions.md:264)
- Reminder run — FR-COMMS-3 status set, per-team lead time, double-run idempotency
- Terminal appointment on manage page — informational callout, no actions

## Tests reviewed

- `tests/Feature/SelfService/CancelViaTokenTest.php` — cancel happy path + slot freeing + queued mail; exactly-at-boundary refused; past-cutoff message names "2 hours" and the team; disabled UI state; throttle message
- `tests/Feature/SelfService/RescheduleViaTokenTest.php` — atomic move, same token kept, notice with link and rendered new time; occupied target keeps original; cut-off dataset (at boundary, past); admin path linkless
- `tests/Feature/Comms/ReminderTest.php` — FR-COMMS-3 matrix (confirmed yes; pending only without approval; never cancelled/no_show/completed/past/outside window); per-team window; window-edge inclusive; double-run sends once; branded render
- `tests/Feature/SelfService/TokenSecurityTest.php` — valid token shows details + actions; forged/tampered 404
- `tests/Browser/ManageSmokeTest.php` — real-browser cancel and reschedule click-throughs land in the expected states
- `tests/Feature/Booking/BookingFlowTest.php:97` — confirmation mail queued with working manage URL (AC-4 confirmation side)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 442/442, 1396 assertions |
| `make e2e` | pass | 33/33 browser tests incl. manage click-throughs |
| pa11y-ci (https, all 11 public pages) | pass | 11/11 incl. `/manage/demo-manage-token` |
| Lighthouse CI (https, 11 pages) | pass | manage page: perf 1.0, a11y 1.0, bp 0.96, seo 0.90 |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1 TokenSecurityTest; AC-2 CancelViaTokenTest (free-slot rebooking at line 77, boundary refusal at line 90); AC-3 RescheduleViaTokenTest (atomic, occupied keeps original); AC-4 BookingFlowTest:97 + ShouldQueue on all four mailables; AC-5 ReminderTest (matrix + idempotency) + routes/console.php:15; AC-6 render assertions in CancelViaTokenTest:156, RescheduleViaTokenTest:92, ReminderTest:146 + all four templates read, no broken variables |
| 2 | MUST requirements | ✅/⚠️ | FR-CANCEL-1/2/4 and FR-COMMS-1/2/4 met with tests; FR-CANCEL-3 (SHOULD) met; FR-COMMS-3 (SHOULD) met with one accuracy gap after reschedule (F1) |
| 3 | Pages present | ✅ | `/manage/{token}` matches pages.md §Manage appointment: summary, cancel disabled past cut-off with reason, reschedule slots, single card, no app chrome, no login |
| 4 | Happy path works | ✅ | ManageSmokeTest: browser cancel and reschedule click-throughs |
| 5 | Validation & errors | ✅ | Off-list slot rejected with "no longer available"; confirm-without-slot rejected; cut-off refusal names the window and team; throttle message is clear; occupied slot resets list and keeps original |
| 6 | Empty / loading / error states | ✅ | "No open times on this day" empty state; success callouts after cancel/move; terminal-state callout |
| 7 | Copy | ✅ | Action-oriented ("Cancel appointment", "Confirm new time", "Keep appointment"); no em-dashes in views/mails (grep clean) |
| 8 | Navigation & links | ✅ | Mail manage URLs via `route('booking.manage')`; reminder/admin-reschedule mails point at the confirmation-mail link by design (documented, assumptions §Emails) |
| 9 | Scope discipline | ✅ | No SMS/marketing mails; reduced scopes documented (linkless reminder, shared cut-off) |
| 10 | Onboarding / discoverability | n/a | No tenant-facing onboarding in this epic |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | FR-COMMS-3 (SHOULD, reminder accuracy) | `app/Actions/Appointments/RescheduleAppointment.php` (no `reminder_sent_at` reset) | Rescheduling never resets `reminder_sent_at`. A customer who reschedules after their reminder fired gets no reminder for the new time; a reminder queued but not yet delivered shows the old time. Not a double-send (the spec's hard rule holds), but reminder accuracy degrades after a move and the behavior is undocumented | Defer: reset `reminder_sent_at` on reschedule (the conditional claim keeps idempotency) or document the limitation in docs/assumptions.md §Emails; track for Epic 09/10 |
| F2 | Low | AC-4 wording | `resources/views/mail/appointments/cancellation.blade.php` | AC-4 reads "confirmation and cancellation emails ... contain correct details + a valid manage link"; the cancellation mail carries no manage link. Defensible (the appointment is terminal and the raw token does not exist on the admin cancel path) but the deviation is not in the assumptions log | Defer: add one line to docs/assumptions.md §Emails recording that cancellation mails are intentionally linkless |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every AC is implemented and demonstrable with tests or browser evidence, and the primary cancel/reschedule journeys complete end to end; the two findings are reminder-accuracy and documentation gaps, not broken flows.
- Blocking findings remaining: 0
