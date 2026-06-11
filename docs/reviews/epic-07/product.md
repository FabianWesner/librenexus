# Review Report - Product Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (appointment list + calendar, manual create/reschedule/cancel, status lifecycle, cancellation mail)
- **Requirements/rules in scope:** FR-APPT-1..5, FR-CANCEL-4 (admin-side freeing), AC-1..AC-5, pages.md §Appointment management

## Files reviewed

- `resources/views/pages/appointments/⚡index.blade.php` - list page: filters, row actions, detail/cancel/reschedule/new-appointment modals
- `resources/views/pages/appointments/⚡calendar.blade.php` - day view: staff columns, blocks, now-marker, mobile list
- `resources/views/components/appointments/status-badge.blade.php`, `detail.blade.php` - shared status badge and detail body
- `app/Actions/Appointments/TransitionAppointmentStatus.php`, `RescheduleAppointment.php` - lifecycle and move behavior
- `app/Enums/AppointmentStatus.php` - FR-APPT-4 matrix, labels
- `app/Mail/AppointmentCancellationMail.php` + `resources/views/mail/appointments/cancellation.blade.php` - cancellation notice copy
- `routes/web.php:36-37`, `resources/views/layouts/app/sidebar.blade.php:29-35` - routes and navigation
- `specs/epics/epic-07-appointments.md`, `specs/requirements.md` §FR-APPT, `specs/pages.md` §Appointment management

## Flows reviewed

- List + filter (staff/service/status/date range) as owner, admin, and staff role - filters work, staff role locked to own record
- Calendar day navigation (prev/today/next/date input, malformed input fallback)
- Manual create: service -> staff -> day -> slot -> customer details -> confirmation mail queued
- Reschedule via modal: day pick, slot grid, occupied-slot failure message, terminal rejection
- Status transitions from the row menu (only allowed transitions offered) and cancel with confirm dialog + customer-notified note

## Tests reviewed

- `tests/Feature/Appointments/AppointmentViewsTest.php` - all 5 filters, role visibility, default range, calendar columns/blocks/navigation (AC-1, AC-2)
- `tests/Feature/Appointments/ManualBookingTest.php` - create (admin + staff role), dedup, just-taken slot error, reschedule atomic move + occupied failure, cancel queues mail (AC-3, AC-5, FR-APPT-5 cancel half)
- `tests/Feature/Appointments/StatusTransitionTest.php` - all 6 valid + 4 invalid pairs + unknown value + cancel-frees-slot rebooking (AC-4, FR-CANCEL-4)
- `tests/Browser/AppointmentsSmokeTest.php` - list, new-appointment modal, calendar render without JS errors

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 407/407, 1253 assertions |
| `make e2e` | pass | 30/30 browser tests, 107 assertions |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1: views + filters + N+1 tests (AppointmentViewsTest); AC-2: query-level own-staff restriction proven via `modelKeys()` assertions; AC-3: create/reschedule run through `BookAppointment`/`GetBookableSlots` + exclusion constraint (ManualBookingTest conflict tests); AC-4: full matrix tested (StatusTransitionTest); AC-5: cancel frees slot (rebooking test), reschedule updates the same row in one transaction |
| 2 | MUST requirements | ⚠️ | FR-APPT-1..4 (MUST) all satisfied with tests. FR-APPT-5 (SHOULD) only half met: cancel queues `AppointmentCancellationMail`, but **reschedule sends no customer email** and no assumption documents the reduction (F1) |
| 3 | Pages present | ✅ | `/{tenant}/appointments` and `/{tenant}/calendar` route + render 200 (feature tests assert `assertOk`); detail modal exists; elements match pages.md (sticky filters, status badges, row actions, staff columns, now-marker) |
| 4 | Happy path works | ✅ | Manual create end to end (ManualBookingTest line 87), reschedule (line 187), cancel (line 237); browser smoke on both pages |
| 5 | Validation & errors | ✅ | Just-taken slot -> friendly `newSlot`/`rescheduleSlot` error; invalid transition -> clear message with from/to labels; unknown status rejected; create form validates all fields with tenant-scoped exists rules |
| 6 | Empty / loading / error states | ✅ | List empty state with guidance (`appointments-empty-state`); calendar empty state; "No open times on this day" in reschedule; success toasts on every action |
| 7 | Copy | ✅ | Action-oriented ("Keep appointment" / "Cancel appointment", "The customer has been notified."); glossary terms (pending/confirmed/completed/cancelled/no-show); no em-dashes in any new view or mail |
| 8 | Navigation & links | ✅ | Sidebar items for Appointments and Calendar with `route()` + current-state; tenant slug carried by the `{current_team}` prefix |
| 9 | Scope discipline | ✅ | Status filter is a small additive on top of the spec filters; no analytics, no customer self-service; week view (optional in pages.md) not built, day view delivered |
| 10 | Onboarding / discoverability | n/a | FR-DASH-2 is Epic 09 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | FR-APPT-5 (SHOULD), epic scope "Reschedule/cancel trigger customer emails" | `app/Actions/Appointments/RescheduleAppointment.php` / `⚡index.blade.php::rescheduleTo` | Rescheduling sends no customer email; only cancellation does. The customer's confirmation mail still shows the old time. No assumption documents the reduction | Send a reschedule notice (Epic 08 comms work can own the mailable) or record the deferral in docs/assumptions.md §Deferred findings now |
| F2 | Low | pages.md §Appointment detail / edit | `resources/views/components/appointments/detail.blade.php` | The detail modal is read-only; status control, reschedule, and cancel live in the list row menu. From the calendar the detail modal offers no actions at all, the user must switch to the list | Track for Epic 09/10 polish: add the action buttons to the detail modal (both pages) |
| F3 | Low | pages.md §Calendar / day view "service colors" | `⚡calendar.blade.php:324` | Blocks are colored by staff color, not service color. Service name is shown as text so no information is lost | Accept as a documented visual choice or switch to `service->color` |
| F4 | Low | FR-APPT-1/FR-STAFF history retention | `⚡calendar.blade.php::visibleStaff` (`bookable()` scope) | Deactivated staff get no calendar column, so their still-confirmed future appointments are invisible in the calendar (the list still shows them) | Track: include staff with remaining time-reserving appointments, or show a hint |

## Required fixes (blocking)

- None.

## Re-review after fixes (2026-06-11)

The QA fixes add tests only (no user-facing change). Relevant to this review:
the detail modal now has a browser test that opens it from a real row and
verifies it renders the customer's data accessibly, strengthening the evidence
for checklist items 3 and 4. Fresh totals: `make test` 410/410, `make e2e`
31/31. F1 (reschedule email, Medium) and F2-F4 (Low) remain open and tracked.
Decision unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all five acceptance criteria and every FR-APPT MUST are met with test evidence; the one gap (F1) concerns the SHOULD-level reschedule email, which must be either delivered in Epic 08 or recorded as an assumption.
- Blocking findings remaining: 0
