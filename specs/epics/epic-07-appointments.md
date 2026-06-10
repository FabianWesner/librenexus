# Epic 07 — Appointment management (admin side)

## Goal

Give tenant staff the tools to see and manage appointments: list and
calendar/day views, manual create/reschedule/cancel, and a validated status
lifecycle.

## Requirements covered

FR-APPT-1 … FR-APPT-5.

## In scope

- Appointment list and a calendar/day view for the active tenant, filterable by
  staff, service, and date.
- Visibility rules: staff see their own appointments; admins/owners see all.
- Manual create, reschedule, and cancel by admin/staff, reusing the Epic 06
  conflict guarantee.
- Status lifecycle per FR-APPT-4 (pending, confirmed, completed, cancelled,
  no_show) with validated transitions and the reservation rules.
- Reschedule/cancel trigger customer emails. The **cancellation mailable is
  introduced in this epic** (first cancel path); it reuses the confirmation
  mailable's queued/branded pattern from Epic 06. Epic 08 adds the reminder
  mailable and the customer self-service flow, and polishes branding — it does
  not retro-supply Epic 07's cancellation email.

## Out of scope

Customer self-service (Epic 08). Analytics (stretch).

## Acceptance criteria

- **AC-1** Admin/staff can view appointments in list and calendar/day views with
  working filters; queries are tenant-scoped and free of N+1 (NFR-PERF).
- **AC-2** Staff role sees only their appointments; admin/owner see all
  (SEC-AUTHZ).
- **AC-3** Manual create/reschedule respects the same concurrency guarantee as
  public booking (no overlaps), verified by tests.
- **AC-4** Status transitions are validated (e.g. cannot complete a cancelled
  appointment); invalid transitions are rejected server-side.
- **AC-5** Cancelling frees the slot; rescheduling moves it atomically.

## Implementation notes

- Reuse the booking transaction/constraint path for manual writes; do not
  duplicate conflict logic.
- Eager-load relations for list/calendar views; assert no N+1 in tests.

## Required tests

- View + filter feature tests with role-based visibility.
- Manual create/reschedule/cancel tests including a conflict attempt.
- Status-transition tests (valid and invalid).
- N+1 assertion test for the list/calendar query.
- Accessibility: axe assertions in the appointments list / calendar / detail E2E
  tests (QG-A11Y for authenticated pages, per test-plan.md §Accessibility &
  performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); SEC-AUTHZ review
passes; performance review finds no N+1 on appointment views.
