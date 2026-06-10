# Epic 05 — Availability & slot engine

## Goal

Implement weekly availability and time-off, and the **deterministic slot
calculation** that turns them into concrete bookable start times. This is the
domain core; it must be exhaustively unit-tested and survive mutation testing.

## Requirements covered

FR-AVAIL-1 … FR-AVAIL-4. Provides the slot data consumed by Epic 06.

## In scope

- Weekly recurring availability rules per staff member (weekday + start/end) in
  tenant timezone.
- Time-off intervals (one-off date/time ranges) per staff member.
- A pure, testable **slot engine**: given a tenant, staff, service, and date
  range, return available start times, accounting for:
  - availability windows partitioned by service duration + buffers,
  - existing appointments,
  - time off,
  - already-passed times,
  - tenant timezone,
  - minimum lead time and maximum booking horizon (from the tenant booking
    policy, FR-TENANT-8, with the documented defaults),
  - only time-reserving appointment statuses block slots (FR-APPT-4).

## Out of scope

Persisting bookings / conflict locking (Epic 06) — the engine only *computes*
availability; it does not write.

## Acceptance criteria

- **AC-1** Admin/staff can manage availability rules and time off (tenant- and
  role-scoped).
- **AC-2** The slot engine is a pure function/service with no hidden state, unit-
  tested in isolation.
- **AC-3** Edge cases covered by tests: DST spring-forward/fall-back, midnight
  boundaries, overlapping availability rules, back-to-back buffer math, service
  longer than any window (yields no slots), time-off fully/partially covering a
  window, lead-time and horizon clamping.
- **AC-4** Computation is deterministic: same inputs → same outputs regardless of
  server timezone (tests run with a non-UTC app timezone too).
- **AC-5** The engine excludes deactivated staff, archived services, and
  unassigned staff↔service combinations.

## Implementation notes

- Do all time math in the tenant timezone via Carbon; store timestamps in UTC.
- Keep the engine free of Eloquent side effects so it is fast and mutation-
  friendly; feed it data via plain inputs/DTOs.
- Add `covers()`/`mutates()` to slot-engine tests so mutation testing targets it
  (QG-MUTATION).

## Required tests

- A dedicated unit suite for the slot engine with the AC-3 edge cases.
- Feature tests for availability/time-off management with authorization.
- Mutation testing focused on the slot engine class meeting QG-MUTATION.
- Accessibility: axe assertions in the availability-editor E2E test (QG-A11Y for
  authenticated pages, per test-plan.md §Accessibility & performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); slot-engine line
coverage and mutation score meet the elevated targets for critical domain logic
in [../test-plan.md](../test-plan.md).
