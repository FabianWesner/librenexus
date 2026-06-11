# Review Report - Architecture - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2/3, ARCH-TENANCY-2/3/4, ARCH-DATA-1/2/3/4/5, ARCH-HTTP-*, ARCH-ASYNC-*, ARCH-ROUTING-3/4/5, ARCH-FRONTEND-*, ADR-0002, ADR-0003

## Files reviewed

- `database/migrations/2026_06_10_234907_create_appointments_table.php` - constraint SQL vs ADR-0003
- `database/migrations/2026_06_10_234906_create_customers_table.php` - per-tenant unique email index
- `docs/adr/0003-double-booking-constraint.md` - the decided strategy
- `app/Actions/Booking/BookAppointment.php` - transaction boundary, 23P01 translation, customer upsert
- `app/Actions/Availability/GetBookableSlots.php` - reserved-range feeding, bounded queries, deterministic ordering
- `app/Models/Appointment.php`, `app/Models/Customer.php` - TenantModel descendants, casts, fillable
- `app/Models/TenantModel.php`, `app/Concerns/BelongsToTenant.php`, `app/Models/Scopes/TenantScope.php` - scoping fabric (unchanged, reused)
- `app/Enums/AppointmentStatus.php` - status model (FR-APPT-4)
- `app/Http/Middleware/ResolvePublicTenant.php` - public tenant context resolution
- `routes/web.php` - catch-all registered last, named routes
- `app/Mail/AppointmentConfirmationMail.php` - queued mail, scalar capture
- `resources/views/pages/booking/⚡show.blade.php`, `⚡manage.blade.php`, `⚡confirmed.blade.php` - component/logic split
- `app/Data/BookingRequest.php`, `app/Data/BookedAppointment.php`, `app/Exceptions/SlotNoLongerAvailableException.php` - boundaries/DTOs

## Flows reviewed

- Booking write path: Livewire component validates -> `BookAppointment::handle` opens one `DB::transaction`, re-resolves service/staff via `bookable()` scopes, re-validates the requested instant through the slot engine, upserts the customer, inserts the appointment; `QueryException` with SQLSTATE `23P01` is translated to `SlotNoLongerAvailableException` outside the closed transaction
- Public tenant resolution: `{tenant}` slug -> `ResolvePublicTenant` sets the request-scoped `CurrentTenant` (fail-closed scope applies to all reads); Livewire update requests re-establish context in `hydrate()` from the `#[Locked]` team
- Queue path: `AppointmentConfirmationMail implements ShouldQueue`; all content captured as scalars in the constructor so workers never deserialize tenant-scoped models without context

## Tests reviewed

- `tests/Feature/Booking/ConcurrencyTest.php` - DB-level guarantee exercised with two raw pgsql connections and an in-flight uncommitted conflict (ARCH-DATA-3)
- `tests/Feature/Tenancy/IsolationTest.php` "customers and appointments isolation (Epic 06)" - new models covered by the named suite (ARCH-TENANCY-3)
- `tests/Unit/TenantScopingTest.php` / `tests/Unit/ArchTest.php` - generic rule: every model with `team_id` outside the tenancy fabric uses `BelongsToTenant`; Appointment and Customer are caught automatically
- `tests/Feature/Booking/PublicRoutingTest.php` - ARCH-ROUTING-3/4/5 precedence and reserved slugs
- `tests/Feature/Booking/BookingHardeningTest.php` - middleware sets context and shares the team

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 359/359, incl. arch + isolation + concurrency suites |
| `make static` | pass | PHPStan level 7, 0 errors, no baseline |
| psql `pg_get_constraintdef('appointments_no_overlap')` | pass | live DB: `EXCLUDE USING gist (staff_id WITH =, tstzrange(buffered_starts_at, buffered_ends_at) WITH &&) WHERE status IN ('pending','confirmed')`; `btree_gist` installed - matches ADR-0003 verbatim |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in existing `app/Actions/Booking`, `app/Data`, `app/Exceptions`, `app/Mail`, `app/Http/Middleware`; no new top-level folders |
| 2 | Logic placement | ✅ | Booking semantics in `BookAppointment`; engine stays a pure service; the Livewire page orchestrates steps and delegates writes (see F3) |
| 3 | Tenant scoping | ✅ | `Appointment`/`Customer extends TenantModel`; isolation suite extended; generic scope test covers them |
| 4 | No leaky queries | ✅ | All page queries run under `CurrentTenant`; the sole `withoutGlobalScopes` read is `Appointment::findByManageToken` - justified: the hashed token is the credential and resolves exactly one row, after which the caller re-establishes tenant context from the appointment (documented in assumptions.md §Booking); test asserts a token exposes only its own appointment |
| 5 | Data | ✅ | Forward-only migration with FKs + cascade; `timestampTz` columns, math in tenant tz (`utcRange`, mail formatting); `price_minor` untouched; constraint DDL is static SQL with no interpolation (ARCH-DATA-5) |
| 6 | Double-booking | ✅ | Partial GiST exclusion constraint on buffered ranges at the DB level + in-transaction engine re-validation; strategy in ADR-0003; migration matches the ADR exactly (verified live) |
| 7 | HTTP | ✅ | Server-side rules in the component (`detailRules`, validated transitions), named routes (`booking.show/confirmed/manage`), public routes intentionally unauthenticated, locked properties prevent tampering |
| 8 | Async | ✅ | Confirmation mail queued (`ShouldQueue`), `Mail::assertQueued` in BookingFlowTest; scalar capture avoids tenant-context deserialization in workers |
| 9 | Config/secrets | ✅ | No new env/secrets; no environment branching in domain logic |
| 10 | Frontend | ✅ | Server-rendered Livewire + Flux components, shared `appointment-summary` partial, no inline scripts |
| 11 | Arch tests | ✅ | ArchTest/TenantScopingTest green in `make test` |
| 12 | ADRs | ✅ | ADR-0003 accepted; assumptions.md §Booking records token format, any-available rule, honeypot/throttle, `withoutGlobalScopes` justification, memory_limit change |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-RELY / ARCH-DATA-1 | `app/Actions/Booking/BookAppointment.php:upsertCustomer` | Two concurrent first-time bookings with the same email in one tenant can race the `(team_id, email)` unique index: the loser gets SQLSTATE 23505 (unique_violation), which is not translated like 23P01, so the customer sees a 500 instead of a friendly retry. No data corruption is possible (the index holds) | Translate 23505 into a retryable domain error or retry the upsert once; track for Epic 07 (which reuses this path) or Epic 10 |
| F2 | Low | SEC-TENANT / ADR-0002 | `⚡manage.blade.php`, `⚡confirmed.blade.php` | Unlike `⚡show.blade.php`, these components have no `hydrate()` re-establishing `CurrentTenant`. Harmless today (zero interactive actions, mount-only), but Epic 08 adds cancel/reschedule actions to the manage page and must add the context re-establishment or actions will fail closed | Add `hydrate()` when Epic 08 introduces actions; note recorded here |
| F3 | Low | ARCH-STRUCTURE-2 | `⚡show.blade.php` (~380 LOC class) | The booking component carries substantial step/state orchestration (dates cache, computed slots, recovery). Business rules stay in actions/engine, so this is acceptable orchestration, but it is the largest Livewire class so far | None now; consider extracting a step-state object if Epic 08 grows it |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: boundaries hold, the double-booking guarantee is DB-level and matches ADR-0003 verbatim in the live schema, tenant scoping covers both new models, and async is queued; F1 is a rare-race UX/reliability gap with no integrity impact, tracked for the next epic that touches the path.
- Blocking findings remaining: 0
