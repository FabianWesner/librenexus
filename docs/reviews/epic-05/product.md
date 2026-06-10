# Review Report - Product - Epic 05 (Availability & slot engine)

## Reviewed scope

- **Epic / change:** Epic 05, working tree on `main` after commit `21257e8` (Epic 04)
- **Requirements/rules in scope:** FR-AVAIL-1..4, FR-TENANT-8 (booking policy inputs), Epic 05 AC-1..AC-5, pages.md §Availability, docs/assumptions.md §Availability

## Files reviewed

- `app/Actions/Availability/ComputeSlots.php` - the slot engine; behavior against FR-AVAIL-3/4
- `app/Actions/Availability/GetBookableSlots.php` - orchestration + AC-5 exclusions
- `app/Data/Slot.php`, `app/Data/SlotComputation.php` - slot contract (customer window vs buffered staff block)
- `resources/views/pages/staff/⚡availability.blade.php` - the editor page against pages.md
- `resources/views/pages/staff/⚡index.blade.php:309-310` - availability row action on the staff list
- `app/Models/AvailabilityRule.php`, `app/Models/TimeOff.php` - FR-AVAIL-1/2 data shapes
- `docs/assumptions.md` §Slot grid + §Availability - documented v1 decisions (contiguous packing, union of overlaps, 24:00, DST resolution)

## Flows reviewed

- Admin opens staff list -> "Edit availability" row action -> editor renders weekly grid + time-off list with tenant timezone shown ("All times are in Europe/Berlin.") - matches pages.md elements
- Add weekly rule per weekday (select start/end in 15-minute steps), remove rule, add/remove time off with optional reason - all give toast feedback
- Staff-role member: sees only the link for their own linked record, can manage own availability, gets 403 for another staff member (AC-1 role scoping)
- Slot computation happy path: service + assigned active staff + Monday 09:00-12:00 rule -> three 60-minute slots in UTC (BookableSlotsTest)
- Hand re-derivation of the engine math (see QA report for full detail): DST spring-forward 2026-03-29 Europe/Berlin, buffer packing 5+45+10, inclusive lead/horizon boundaries - all match the implementation and tests

## Tests reviewed

- `tests/Feature/Availability/AvailabilityManagementTest.php` (15 cases) - AC-1: render, add/remove rule, add/remove time off, UTC storage, role scoping (own vs other), link visibility, validation (inverted, overlap, weekday 8, malformed, 24:00, inverted time off)
- `tests/Feature/Availability/BookableSlotsTest.php` (6 cases) - AC-5: unassigned, deactivated, archived all yield zero slots; any-staff merge sorted; time off end to end
- `tests/Unit/SlotEngineTest.php` (31 cases) - AC-2/AC-3/AC-4 edge cases including DST both directions and determinism under three server timezones
- `tests/Browser/AvailabilitySmokeTest.php` (2 cases) - happy path through the real UI incl. axe

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 313/313, 932 assertions |
| `php artisan test tests/Browser/AvailabilitySmokeTest.php` | pass | 2/2, 8 assertions, axe + JS-error clean |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1: AvailabilityManagementTest role-scoped cases; AC-2: pure engine + DTO, unit-tested in isolation; AC-3: every listed edge case has a named test (see QA #6); AC-4: `the computation is deterministic regardless of the server timezone`; AC-5: BookableSlotsTest all four exclusion cases |
| 2 | MUST requirements | ✅ | FR-AVAIL-1 (weekday + start/end in tenant tz, AvailabilityRule), FR-AVAIL-2 (TimeOff, removal proven end to end), FR-AVAIL-3 (windows, partition incl. buffers, minus time off/reserved/passed, tenant tz, lead + horizon from team policy fields), FR-AVAIL-4 (deterministic, edge-case tested) |
| 3 | Pages present | ✅ | `/{tenant}/staff/{staff}/availability` (routes/web.php:29, named `staff.availability`); renders 200 with weekly grid, time-off list, explicit timezone - matches pages.md §Availability |
| 4 | Happy path works | ✅ | AvailabilitySmokeTest adds a window through the real UI and sees it rendered |
| 5 | Validation & errors | ✅ | Inverted range, overlap, weekday out of range, malformed time, inverted time off all rejected with specific actionable messages ("The end time must be after the start time.") |
| 6 | Empty / loading / error states | ✅ | "Not available." per empty weekday; "No time off planned" empty state with guidance; success toasts on every action |
| 7 | Copy | ✅ | Clear and action-oriented; no em-dashes in the page (grep clean); timezone called out explicitly |
| 8 | Navigation & links | ✅ | Staff list row action + "Back to staff" both via named routes carrying the tenant slug |
| 9 | Scope discipline | ✅ | No booking persistence; engine accepts `reserved` ranges (tested) while the orchestrator passes `[]` until Epic 06 - documented in GetBookableSlots docblock; contiguous packing/union/24:00/DST choices recorded in docs/assumptions.md |
| 10 | Onboarding / discoverability | ✅ | Availability reachable from the staff list every member sees; deeper onboarding is Epic 09 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | FR-AVAIL-3 ("minus existing appointments") | `GetBookableSlots.php:87` | Reserved appointment subtraction is engine-supported and unit-tested but not wired to real data until Epic 06 (no Appointment model exists yet) | None now; verify wiring in the Epic 06 review (documented deferral) |
| F2 | Low | pages.md §Availability | editor page | Time-off add form has no inline hint that entries are interpreted in the tenant timezone beyond the page subheading | Optional copy polish; track for Epic 10 |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: all five ACs and all four FR-AVAIL MUSTs are implemented with direct test evidence; the page matches its pages.md description; the only gaps are a documented Epic 06 deferral and a copy nit.
- Blocking findings remaining: 0
