# Review Report - Code Quality - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `app/Actions/Booking/BookAppointment.php`, `app/Actions/Availability/GetBookableSlots.php` - idioms, typing, structure
- `app/Enums/AppointmentStatus.php` - enum conventions and API surface
- `app/Models/Appointment.php`, `app/Models/Customer.php` - sibling consistency with Staff/Service models
- `app/Data/BookingRequest.php`, `BookedAppointment.php` - readonly DTO pattern consistency
- `app/Exceptions/SlotNoLongerAvailableException.php`, `app/Http/Middleware/ResolvePublicTenant.php`, `app/Mail/AppointmentConfirmationMail.php`
- `resources/views/pages/booking/⚡show.blade.php`, `⚡manage.blade.php`, `⚡confirmed.blade.php`, `layouts/booking.blade.php`, `components/booking/appointment-summary.blade.php`
- `database/migrations/2026_06_10_23490[67]_*.php`, `database/seeders/DemoSeeder.php`, `database/factories/` (Appointment/Customer)
- `Makefile` (memory_limit change), `docs/assumptions.md` §Booking

## Flows reviewed

- Naming and typing pass over all new classes: explicit return types and parameter types throughout, constructor promotion (`BookAppointment`, `GetBookableSlots`), readonly DTOs matching `Slot`/`SlotComputation` precedent, TitleCase enum keys (`Pending`, `NoShow`), descriptive names (`reservesTime`, `findByManageToken`, `refreshAvailableDates`), array-shape PHPDoc on engine inputs
- Consistency with siblings: `Appointment`/`Customer` mirror `Staff`/`Service` structure (`#[Fillable]`, `casts()`, relation docblocks); booking pages follow the `⚡` Livewire page conventions and reuse Flux components and the existing `bookable()` scopes; `data-test` attributes match the established pattern
- Gate-gaming check: no new ignores, baselines, jscpd config widening, or `nosemgrep`; Makefile change limited to `memory_limit=1G` on test targets with thresholds untouched (documented in assumptions.md §Booking)

## Tests reviewed

- New test files follow sibling structure (Pest, factories with states, `covers()` on critical classes, named-suite docblocks citing requirement IDs)
- `ConcurrencyTest` helper functions are file-local with typed signatures and docblocks; raw SQL is intrinsic to the test's purpose

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint clean |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline, no new ignores |
| `make complexity` | pass | phpmd (incl. unusedcode rules) clean |
| `make duplication` | pass | jscpd under the 3% gate (worst format 2.18%); 21 clones, none in booking code beyond template idioms |
| `make unused` | pass | composer-unused clean |
| `make require-check` | pass | composer-require-checker clean |
| `grep TODO/FIXME/dd(/dump(/ray(` | pass | none in app code (arch test also guards this) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | `make format-check` clean |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline |
| 3 | Complexity | ✅ | phpmd clean; longest new method (`⚡show` `confirmBooking`) stays within limits; helpers extracted (`matchingSlot`, `upsertCustomer`, `reservedRangesByStaff`) |
| 4 | Dead code | ⚠️ | phpmd unusedcode clean (it only sees private members), but `AppointmentStatus::reservesTime/isTerminal/canTransitionTo/allowedTransitions` are public methods with zero callers and zero tests - speculative API shipped ahead of Epic 07 (F1) |
| 5 | Duplication | ✅ | Under gate; the `detailRules()` reuse between `submitDetails` and `confirmBooking` is one shared method, not duplication; summary `<dl>` extracted into `appointment-summary` component |
| 6 | Dependencies | ✅ | No dependency changes; both checkers green |
| 7 | Idioms | ✅ | Promotion, full typing, TitleCase enums, descriptive names, array shapes (`@return array{0: CarbonImmutable, 1: CarbonImmutable}` etc.) |
| 8 | Laravel way | ✅ | Eloquent relationships, named routes via `route()`, casts(), scopes, factories with states; config over magic (policy fields from team) |
| 9 | Reuse | ✅ | Reuses `bookable()` scopes, TenantModel fabric, Flux components, existing layout partials (`partials.head`), `CurrentTenant` |
| 10 | No debug/leftovers | ✅ | Greps clean; no commented-out code |
| 11 | Consistency | ✅ | New files match sibling structure throughout (models, pages, factories, tests) |
| 12 | Docs | ✅ | ADR-0003 verbatim-implemented; assumptions.md §Booking records token format, any-available rule, withoutGlobalScopes rationale, memory_limit change; docblocks cite requirement IDs |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-MAINT / QG-DEADCODE spirit | `app/Enums/AppointmentStatus.php:19-56` | The transition-matrix methods (`reservesTime`, `isTerminal`, `canTransitionTo`, `allowedTransitions`) are unused by any production or test code in this epic - delivered ahead of Epic 07 without verification. Unexercised public API on the critical status model is a regression risk (QA F1 owns the blocking test) | Either test them now (QA F1) or move them to Epic 07; do not leave untested speculative API on the booking domain |
| F2 | Low | consistency | `⚡show.blade.php::staffSelection` | The `'any'`-or-numeric-string union (`?string $staffSelection`) is stringly typed where siblings use ids or enums; handled correctly but invites parsing mistakes (`(int) $this->staffSelection`) | Consider a small backing enum/value object if the flow grows in Epic 08 |
| F3 | Low | consistency | `BookAppointment::EXCLUSION_VIOLATION` | SQLSTATE constant is private to the action; Epic 07's reschedule path will need the same translation | Extract to the exception or a shared helper when Epic 07 lands |

## Required fixes (blocking)

- None (F1's blocking aspect - the missing tests - is owned by QA F1).

## Re-review after fixes (2026-06-11)

- **F1 (Medium) - resolved.** The shared root cause with QA F1 is fixed: `tests/Unit/AppointmentStatusTest.php` now verifies every previously unexercised method (`reservesTime`, `isTerminal`, `canTransitionTo`, `allowedTransitions`, plus `reservingValues` and `label`) against an independent literal FR-APPT-4 matrix. The API is no longer untested speculation; it ships verified, ready for its Epic 07 production callers. Checklist item 4 (Dead code) is now ✅. Residual Low note: production callers still arrive only in Epic 07, which is by design (the status model is this epic's deliverable).
- Re-ran the gates after the new file: `make test` 364/364; `make format-check` and `make static` clean (the new test file is Pint- and PHPStan-conformant); no gate configuration was touched.
- F2 and F3 (Low) remain open as tracked nits for Epic 07/08.

## Final decision

**PASS**

- Rationale: all six code-quality gates are green with no gate-gaming, the new code is idiomatic and consistent with its siblings, and the one Medium finding (untested enum API) is resolved by the new unit suite; only tracked Low nits remain.
- Blocking findings remaining: 0
