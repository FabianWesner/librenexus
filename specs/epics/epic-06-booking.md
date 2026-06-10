# Epic 06 — Public booking & concurrency

## Goal

Deliver the public booking flow and guarantee that **double-booking is
impossible**, even under concurrent requests. Critical-risk epic: ships with a
named concurrency test.

## Requirements covered

FR-BOOK-1 … FR-BOOK-7, FR-CUST-1 … FR-CUST-4.

## In scope

- Public, login-free booking page per tenant at the tenant slug URL.
- Flow: choose service → choose staff (or "any available") → choose slot → enter
  customer details (name, email, optional phone, notes) → confirm.
- **Customer entity (FR-CUST):** tenant-owned, identified by case-insensitive
  email unique per tenant; a repeat booking reuses the existing record (updating
  name/phone) instead of duplicating. Customers never authenticate and are
  covered by the tenant-isolation suite.
- Server-side validation: required fields, valid email, slot still available,
  within horizon and lead time.
- **Concurrency-safe persistence**: a DB-level guarantee (unique/exclusion
  constraint and/or locking transaction) preventing overlapping appointments for
  the same staff member.
- Confirmation page + a minimal **view-only** manage page at `/manage/{token}`
  (Epic 08 adds cancel/reschedule actions to it).
- Cancellation token created here per SEC-TOKEN (high-entropy, hashed, constant-
  time compare, single-appointment scope).
- Confirmation mailable created here: queued, templated, and branded (tenant
  name + contact email). Epic 08 only polishes branding; it does not re-template.
- Optional booking-approval mode (pending → confirmed) — MAY.

## Out of scope

Reminder emails and reschedule flow (Epic 08). Admin-side management (Epic 07).

## Acceptance criteria

- **AC-1** A customer can complete a booking from the public page and reach a
  confirmation whose manage link resolves to a working view-only `/manage/{token}`
  page (no 404); cancel/reschedule controls arrive in Epic 08.
- **AC-2** Slots offered come from the Epic 05 engine and exclude unavailable
  staff/services.
- **AC-1b (FR-CUST)** Booking creates or **reuses** a tenant-scoped customer by
  case-insensitive email (unique per tenant); a second booking with the same
  email in the same tenant does not create a duplicate and updates name/phone;
  the same email in a different tenant is a separate customer. Customers are
  added to the tenant-isolation suite.
- **AC-3 (critical)** Two concurrent booking attempts for the same staff and
  overlapping time: exactly one succeeds, the other gets a clear "slot no longer
  available" error. Proven by a concurrency test (see
  [../test-plan.md](../test-plan.md) §Concurrency) using a DB constraint or
  locking, not just application checks.
- **AC-4** All booking input validated server-side; invalid input returns
  actionable errors and never persists.
- **AC-5** Slot reservation follows the status model (FR-APPT-4): only
  time-reserving statuses (`pending`, `confirmed`) block a slot. Setting an
  appointment to a non-reserving status (`cancelled`/`no_show`) frees its time
  immediately and the slot becomes bookable again. This epic proves the
  **mechanism** at the data level (transition an appointment's status directly in
  a test and assert the slot reopens), independent of the customer/admin cancel
  UIs which arrive in Epics 07–08.
- **AC-6** The booking page passes QG-A11Y and QG-PERF and works on mobile.
- **AC-7** "Any available" picks a valid staff member deterministically and
  cannot create a conflict.

## Implementation notes

- Prefer a Postgres exclusion constraint (`tstzrange` + `EXCLUDE USING gist`) or
  a unique constraint plus `SELECT … FOR UPDATE` in a transaction. Document the
  choice in an ADR; this is why the stack mandates PostgreSQL.
- The constraint must apply **only to time-reserving statuses** (`pending`,
  `confirmed`) per FR-APPT-4 — e.g. a partial exclusion constraint
  `WHERE status IN ('pending','confirmed')` — so a `cancelled`/`no_show`
  appointment does not block a new booking at the same time.
- Re-validate slot availability inside the same transaction that inserts the
  appointment; never trust the slot shown to the customer.
- Rate-limit public booking submissions (SEC-RATE) and protect against abuse
  (honeypot or throttle), without harming accessibility.

## Required tests

- **`tests/Feature/Booking/ConcurrencyTest.php`** (named suite): simulate
  concurrent/overlapping bookings; assert exactly one wins and no overlap exists
  in the DB.
- Full booking-flow feature tests (happy path + each validation failure).
- Customer dedup test (FR-CUST-2): repeat booking with the same email reuses the
  record per tenant; same email across tenants stays separate; isolation suite
  extended to customers (FR-CUST-1).
- View-only `/manage/{token}` resolves for a valid token and rejects a forged or
  wrong-appointment token (token created per SEC-TOKEN).
- Status-reservation test: a `cancelled`/`no_show` appointment does not block a
  new booking at the same time, and transitioning a held appointment to a
  non-reserving status frees the slot (AC-5).
- Add the public booking-flow URL(s) to `PUBLIC_URLS` so pa11y + Lighthouse cover
  them (QG-A11Y / QG-PERF; test-plan.md §Accessibility & performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); the concurrency
suite is green and kept as a regression guard; SEC-INPUT and SEC-RATE reviews
pass.
