# Review Report — Architecture Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (SelfService actions, reminder command + schedule, two new mailables, actioned manage page)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2/3, ARCH-TENANCY-2/3/4, ARCH-DATA-1..5, ARCH-HTTP-*, ARCH-ASYNC-*, ARCH-CONFIG-*, ARCH-FRONTEND-*, ARCH-TEST-3, NFR-OPS-2

## Files reviewed

- `app/Actions/SelfService/{CancelAppointmentViaToken,RescheduleAppointmentViaToken,EnsureWithinCancellationCutoff}.php` — placement, delegation, constructor promotion
- `app/Console/Commands/SendAppointmentReminders.php` — tenant-less console query, `withoutGlobalScopes` justification, claim pattern
- `app/Mail/{AppointmentReminderMail,AppointmentRescheduledMail}.php` — queued mailables, scalar capture for tenant-less workers
- `app/Models/Appointment.php:99-110` — `findByManageToken` (scope bypass justified inline)
- `app/Actions/Appointments/{RescheduleAppointment,TransitionAppointmentStatus}.php` — reused domain actions (no duplication of move/transition logic)
- `resources/views/pages/booking/⚡manage.blade.php` — component thinness, hydrate() tenant re-establishment
- `routes/console.php`, `routes/web.php:20` — schedule registration, named route
- `database/migrations/2026_06_10_234907_create_appointments_table.php` — `reminder_sent_at`, token hash unique index, exclusion constraint (pre-existing, unchanged)
- `docs/adr/0002-tenant-scoping.md`, `docs/adr/0003-double-booking-constraint.md` — decision records relied on by this epic

## Flows reviewed

- Self-service cancel — page resolves token, sets `CurrentTenant`, delegates to `CancelAppointmentViaToken` then the FR-APPT-4 transition action; no business logic in Blade
- Self-service reschedule — delegates to `RescheduleAppointment` (single transaction, exclusion constraint as final arbiter); the page only carries UI state
- Reminder run — console selection joined to each row's own team for the policy window; conditional single-row UPDATE claim; queued mailable
- Livewire update requests — `hydrate()` re-resolves the appointment by token and re-sets the tenant before any action (Epic 06 deferral closed, assumptions log line 270)

## Tests reviewed

- `tests/Unit/ArchTest.php` — no debug helpers, model/enum/scope conventions (green in full run)
- `tests/Unit/TenantScopingTest.php` — every model with `team_id` uses `BelongsToTenant` (Appointment included; allowlist is membership fabric only)
- `tests/Feature/SelfService/RescheduleViaTokenTest.php:203` — stub-engine 23P01 race proves the DB constraint, not the app check, is the final arbiter (carried Epic 07 obligation, closed)
- `tests/Feature/Comms/ReminderTest.php:117` — `Model::preventLazyLoading()` proves the scope-free eager loads stay in place

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 442/442 incl. arch + scoping + concurrency suites |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| `make complexity` | pass | PHPMD clean on app/config/database/routes |
| grep audit of `withoutGlobalScopes` | done | 6 occurrences, all in the two documented token/console paths, each justified inline |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in `app/Actions/SelfService`, `app/Console/Commands`, `app/Mail`; no new top-level folders |
| 2 | Logic placement | ✅ | Page component delegates to actions; cut-off/terminal/transition logic in actions; slot math stays in the pure engine (`GetBookableSlots`/`ComputeSlots`) |
| 3 | Tenant scoping | ✅ | No new tenant-owned models; `TenantScopingTest` still enforces the trait on every `team_id` model |
| 4 | No leaky queries | ✅ | The two unscoped paths (token lookup, console reminders) are credential-keyed or row-self-joined to the owning team, scalar-captured for the queue, and justified inline (SendAppointmentReminders.php:50-58, Appointment.php:99-103) |
| 5 | Data | ✅ | No new migrations; `reminder_sent_at` is `timestampTz`; time math via team timezone; `whereRaw` interval is parameter-bound (ARCH-DATA-5) |
| 6 | Double-booking | ✅ | Reschedule reuses the Epic 07 transactional same-row update under the GiST exclusion constraint (ADR-0003); 23P01 surfaced as the friendly error and tested with a blind engine |
| 7 | HTTP | ✅ | Named route `booking.manage`; `#[Locked]` token/selectedSlot; server-side slot validation; throttle inside the component because Livewire updates bypass route middleware (documented in the component docblock) |
| 8 | Async | ✅ | All four mailables `ShouldQueue`; reminder command queues, never sends inline; database queue with `failed_jobs` table (NFR-OPS-2) |
| 9 | Config/secrets | ✅ | Cut-off and lead time read from team booking-policy columns (FR-TENANT-8); no env branching in domain logic |
| 10 | Frontend | ✅ | Server-rendered Flux components; reuses `x-booking.appointment-summary` and the booking layout; no inline scripts |
| 11 | Arch tests | ✅ | ArchTest green (no dd/dump/ray, strict equality, conventions) |
| 12 | ADRs | ✅ | ADR-0002 (scoping) and ADR-0003 (exclusion constraint) cover this epic's decisions; token design recorded in docs/assumptions.md §Booking |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | ARCH-STRUCTURE-2 (tracked pattern) | `resources/views/pages/booking/⚡manage.blade.php` (~217 PHP lines, 12 methods) | The manage SFC carries cancel, reschedule date/slot state, throttling, and tenant re-establishment in one component. All domain logic is delegated and the size is well below the appointments-page smell already deferred, but it continues the SFC-growth pattern PHPMD cannot see (views are unscanned, Epic 07 deferral) | None now; covered by the existing Epic 10 deferrals (extend PHPMD to views, split oversized SFCs) |
| F2 | Low | ARCH-DATA-2 (portability note) | `SendAppointmentReminders.php:72` | `?::timestamptz + (teams.reminder_lead_time_hours * interval '1 hour')` is PostgreSQL-only SQL. Acceptable (the project is Postgres-pinned per ADR-0003, which already requires GiST), recorded for completeness | None; revisit only if a second database is ever supported |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: boundaries hold; the two scope-bypassing paths are narrowly keyed, justified inline, and covered by tests; async work is queued; the double-booking guarantee remains DB-level and is re-proven for the reschedule path.
- Blocking findings remaining: 0
