# Review Report - Architecture Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (transition/reschedule actions, appointment pages, cancellation mailable)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2/3, ARCH-TENANCY-2/3/4, ARCH-DATA-1/2/3/5, ARCH-HTTP-*, ARCH-ASYNC-*, ARCH-CONFIG-*, ARCH-FRONTEND-*, ARCH-TEST-3

## Files reviewed

- `app/Actions/Appointments/TransitionAppointmentStatus.php`, `RescheduleAppointment.php` - new domain actions
- `app/Actions/Booking/BookAppointment.php` - 23505 retry added; transaction/constraint path reused for manual writes
- `app/Actions/Availability/GetBookableSlots.php` - `excludeAppointmentId` parameter
- `app/Enums/AppointmentStatus.php` - transition matrix as enum behavior
- `app/Models/Appointment.php`, `app/Models/TenantModel.php` - tenant scoping, `reservingTime` scope
- `app/Policies/AppointmentPolicy.php` - authorization
- `app/Mail/AppointmentCancellationMail.php` - queued mailable, scalar capture
- `resources/views/pages/appointments/⚡index.blade.php`, `⚡calendar.blade.php` - Livewire components
- `database/migrations/2026_06_10_234907_create_appointments_table.php` - exclusion constraint (unchanged, re-verified for the update path)
- `docs/adr/0003-double-booking-constraint.md` - reschedule strategy recorded
- `tests/Unit/TenantScopingTest.php`, `tests/Unit/ArchTest.php` - structural rules

## Flows reviewed

- Manual create: component -> `BookAppointment` (same action as public booking) -> engine re-validation inside `DB::transaction` -> insert guarded by `appointments_no_overlap`
- Reschedule: component -> `RescheduleAppointment` -> terminal check -> transaction: engine slot match with `excludeAppointmentId` -> same-row `update()` -> 23P01 translated to the domain exception
- Status transition: component -> `TransitionAppointmentStatus` -> matrix check on the enum -> update -> queued cancel mail
- Queue worker: cancellation mail constructor captures scalars, no tenant context needed at send time

## Tests reviewed

- `tests/Unit/TenantScopingTest.php` - every model with `team_id` outside the membership fabric uses `BelongsToTenant` (covers Appointment)
- `tests/Feature/Tenancy/IsolationTest.php::appointment management isolation (Epic 07)` - 404/forbidden/not-found semantics through routes and Livewire actions
- `tests/Feature/Booking/ConcurrencyTest.php` - DB-level proof the constraint, not app code, is the arbiter (two raw pg connections, 23P01)
- `tests/Feature/Appointments/ManualBookingTest.php` - reschedule keeps row identity (token hash unchanged), occupied slot rolls back cleanly

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 407/407 incl. arch + tenant-scoping suites |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean over app/config/database/routes |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in `app/Actions/Appointments`, `app/Mail`, `app/Policies`, `resources/views/pages/appointments`; no new top-level folder |
| 2 | Logic placement | ✅ | Matrix lives on the enum, conflict/atomicity in actions; components validate, authorize, and delegate (`⚡index.blade.php:126,150,185,271`); slot engine untouched except the pure `excludeAppointmentId` pass-through |
| 3 | Tenant scoping | ✅ | `Appointment extends TenantModel`; TenantScopingTest enforces the trait; isolation suite extended for Epic 07 |
| 4 | No leaky queries | ✅ | All page queries go through the scoped `Appointment::query()`; `staffOptions`/`serviceOptions`/`visibleStaff` are scoped models; the only `withoutGlobalScopes` is the pre-existing token lookup (documented) |
| 5 | Data | ✅ | No new migrations; timestamps UTC with tenant-tz math (`localDate()`, `setTimezone($team->timezone)`); no string-interpolated SQL in new code |
| 6 | Double-booking | ✅ | Manual create reuses `BookAppointment`; reschedule updates the same row inside a transaction so the partial GiST exclusion constraint checks the move as a unit; 23P01 translated; ADR-0003 explicitly documents the reschedule-as-update strategy |
| 7 | HTTP | ✅ | Validation via `$this->validate()` with tenant-scoped `Rule::exists`; `Gate::authorize` on mount and on every mutating action; named routes (`appointments.index`, `calendar.index`) |
| 8 | Async | ✅ | `AppointmentCancellationMail implements ShouldQueue`, dispatched via `Mail::queue`; confirmation mail also queued; nothing sent inline |
| 9 | Config/secrets | ✅ | No new config, no secrets, no environment branching in the new code |
| 10 | Frontend | ✅ | Server-rendered Livewire + Flux; shared `x-appointments.status-badge`/`detail` components reused by both pages; no inline `<script>` (inline `style` color attributes come from the enum palette, CSP-safe) |
| 11 | Arch tests | ✅ | ArchTest (no debug helpers, scope contract) green inside the 407 |
| 12 | ADRs | ✅ | ADR-0003 covers the booking constraint and the Epic 07 reschedule path; no new significant decision lacking a record |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | ARCH-STRUCTURE-2 (component size, tracked pattern) | `resources/views/pages/appointments/⚡index.blade.php` (486 PHP lines, 26 public methods) | The list component carries five concerns (filters, detail, cancel, reschedule, create). Business logic is correctly delegated to actions, but the component is the largest SFC in the codebase and continues the smell already deferred for Epic 04 | Track with the existing "extract to Actions / split components" deferral; consider splitting the new-appointment modal into its own component by Epic 10 |
| F2 | Low | ARCH-STRUCTURE-2 | `⚡index.blade.php:289-291` and `pages/booking/⚡show.blade.php:197` | The confirm-mail dispatch after `BookAppointment` is duplicated in both components instead of living behind the action (consistent with the Epic 06 choice, so it is a consistency note, not a violation) | If a third call site appears (Epic 08), move the dispatch into the action or a wrapper |

## Required fixes (blocking)

- None.

## Re-review after fixes (2026-06-11)

The QA fixes touch one production line in scope here:
`BookAppointment::attempt()` is now protected instead of private, purely as a
test seam for the retry tests; the action's boundaries, transaction shape, and
constraint-arbiter role are unchanged. Notably, the new
BookingHardeningTest constraint-race test strengthens the ARCH-DATA-3
evidence: it proves through the action (stub engine, real exclusion
constraint) that the DB constraint, not the app check, is the final arbiter.
Re-ran: `make test` 410/410, `make static` 0 errors. Decision unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: boundaries hold everywhere it matters: the conflict guarantee is DB-level and shared with public booking (no duplicated conflict logic), tenant scoping is trait-enforced and tested, async work is queued, and the reschedule strategy is recorded in ADR-0003. The only warnings are tracked structural smells.
- Blocking findings remaining: 0
