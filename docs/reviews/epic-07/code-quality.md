# Review Report - Code Quality Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (actions, enum behavior, policy, mailable, two Livewire pages, shared components, tests)
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `app/Actions/Appointments/TransitionAppointmentStatus.php`, `RescheduleAppointment.php` - new actions
- `app/Actions/Booking/BookAppointment.php`, `app/Actions/Availability/GetBookableSlots.php` - modified
- `app/Enums/AppointmentStatus.php` - matrix methods + labels
- `app/Policies/AppointmentPolicy.php`, `app/Mail/AppointmentCancellationMail.php`
- `resources/views/pages/appointments/⚡index.blade.php`, `⚡calendar.blade.php`
- `resources/views/components/appointments/status-badge.blade.php`, `detail.blade.php`
- `phpstan.neon`, `phpmd.xml`, `.jscpd.json`, `composer-unused.php` - checked for gate-dodging config changes (none found)

## Flows reviewed

- Sibling-consistency: actions mirror `app/Actions/Booking/BookAppointment.php` (constructor promotion, `handle()`, domain exceptions, PHPDoc); pages mirror `pages/staff/⚡index.blade.php` (front-matter class, `#[Computed]`, Flux modals, `data-test` attributes)
- Reuse: status badge and detail body extracted once and shared by list + calendar; `GetBookableSlots` extended with a parameter instead of a forked engine path

## Tests reviewed

- `tests/Unit/ArchTest.php` - no debug helpers in app code (green inside the suite)
- Epic 07 test files - naming, helper functions, and dataset style consistent with the Epic 05/06 suites

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` (pint --test) | pass | 0 diffs (parallel mode needs an unsandboxed TCP port; result identical) |
| `make static` | pass | PHPStan/Larastan level 7, 0 errors, no baseline, no new ignores |
| `make complexity` | pass | PHPMD app/config/database/routes: exit 0 (complexity, dead code, design) |
| `make duplication` | pass | jscpd under threshold, exit 0; no ignore widening in `.jscpd.json` |
| `make unused` | pass | composer-unused: only the pre-existing reasoned filters (livewire/blaze, flux) |
| `make require-check` | pass | no unknown symbols |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Pint clean |
| 2 | Static | ✅ | Level 7, zero errors, no baseline; generics annotated on new computeds/collections |
| 3 | Complexity | ✅ | PHPMD clean; action methods are short with extracted private helpers (`matchingSlot`, `blockFor`, `rowForMinutes`) |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; no unused members in new files |
| 5 | Duplication | ✅ | jscpd pass; badge/detail markup extracted instead of copied between the two pages |
| 6 | Dependencies | ✅ | No dependency changes; both dep gates green |
| 7 | Idioms | ✅ | Constructor promotion (`RescheduleAppointment`), explicit return types and param types everywhere, TitleCase enum keys (`NoShow`), array-shape PHPDoc (`dayColumns()`, `reservedRangesByStaff()`), descriptive names (`ensureOwnStaffRecord`, `reservesTime`) |
| 8 | Laravel way | ✅ | Named routes via `route()`; Eloquent relations + scoped queries; enum casts; `Rule::exists` with tenant constraint; typed class constants for SQLSTATEs instead of magic strings |
| 9 | Reuse | ✅ | Reuses `BookAppointment`/`GetBookableSlots`/`SlotNoLongerAvailableException`/`CalendarColor`; shared `x-appointments.*` components |
| 10 | No debug/leftovers | ✅ | ArchTest green; grep: no `dd`/`dump`/`ray`, no commented-out blocks, no TODO/FIXME in new files |
| 11 | Consistency | ⚠️ | New files match sibling structure, but the list SFC is now the largest component in the codebase (see F1) |
| 12 | Docs | ✅ | ADR-0003 documents the reschedule-as-update decision; every new class/method carries an intent-level PHPDoc referencing its FR/AC |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-MAINT / tracked Epic 04 pattern | `resources/views/pages/appointments/⚡index.blade.php` (486 PHP lines, 26 public methods + 5 modals in one SFC) | The component aggregates filtering, detail, cancel, reschedule, and create. Logic is thin and delegated, but the file is past the size where sibling consistency holds | Track with the existing "extract/split components" deferral (Epic 10 at the latest); the new-appointment modal is the natural first extraction |
| F2 | Medium | QG-COMPLEXITY blind spot | `Makefile` `complexity` target (`app,config,database,routes`) | PHPMD never scans `resources/views`, so Livewire SFC front-matter classes (the largest classes in the project) are invisible to the complexity/dead-code gate. Not a gamed gate, but a growing blind spot as pages accumulate | Add the pages path (or extracted component classes) to the PHPMD target in Epic 10 hardening |
| F3 | Low | Logic placement nit | `⚡index.blade.php:596-601` | The transition action labels are a `match` inside the Blade loop; an `actionLabel()` next to `label()` on `AppointmentStatus` would keep all status wording on the enum | Optional cleanup whenever the enum is next touched |

## Required fixes (blocking)

- None.

## Re-review after fixes (2026-06-11)

Re-checked the fix code for gate health and idiom: the `attempt()` visibility
widening is minimal and purpose-documented by the tests that use it; the new
tests follow the suite's conventions (anonymous-class seams, reflection helper
with a PHPDoc, outcome assertions, `data-test` selectors in the browser test).
Re-ran: `vendor/bin/pint --test` clean, `make static` level 7 with 0 errors,
`make test` 410/410. No new suppressions, ignores, or config changes. F1-F3
remain tracked. Decision unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every code-quality gate ran green in this review with no baselines, ignores, or config widening; the code reads like its Epic 05/06 siblings. The warnings are the oversized list component (already a tracked project pattern) and the PHPMD blind spot for SFC classes.
- Blocking findings remaining: 0
