# Epic 04 — Staff & services

## Goal

Let a tenant model what it offers (services) and who provides it (staff), with
the staff↔service assignment that the slot engine and booking flow depend on.

## Requirements covered

FR-STAFF-1 … FR-STAFF-4, FR-SERVICE-1 … FR-SERVICE-3.

## In scope

- Staff CRUD (name, email, optional **linked membership**, color, active) scoped
  to tenant; admin-only linking/unlinking per FR-STAFF-4 (at most one membership
  per staff record).
- Deactivation hides staff from booking but preserves history.
- Service CRUD (name, description, duration, buffer-before/after, price, color,
  active) scoped to tenant.
- Archiving services preserves history but removes them from booking.
- Staff↔service assignment (which staff can deliver which services).

## Out of scope

Availability rules and slots (Epic 05). Booking (Epic 06).

## Acceptance criteria

- **AC-1** Admin can create/edit/deactivate staff; validation server-side; all
  operations tenant-scoped (inherits SEC-TENANT pattern).
- **AC-2** Admin can create/edit/archive services with the FR-SERVICE-3
  validation: duration positive `5–480` min, buffers **non-negative** `0–120`
  min, optional non-negative integer price in minor units (tenant currency).
- **AC-3** Deactivated staff and archived services are excluded from bookable
  data but their past appointments remain intact.
- **AC-4** Staff can be assigned to a subset of services; assignment is
  tenant-scoped and respected later by the slot engine.
- **AC-5** Authorization: staff role cannot manage other staff or services;
  admin/owner can (SEC-AUTHZ).
- **AC-6** Admin can link/unlink a staff record to a tenant membership (≤1 per
  record, FR-STAFF-4); a user cannot self-link; unlinking preserves history.
- **AC-7** A staff-role member with no linked staff record sees a clear in-UI
  notice (and has no bookable availability / no appointments) until an admin
  links them (FR-TENANT-5).

## Implementation notes

- Use the tenant scoping mechanism from Epic 03 for every new model; add the
  arch/scope test entries.
- Colors feed the calendar/booking UI; constrain to a small documented palette.

## Required tests

- CRUD + validation tests for staff and services (incl. buffer = 0 accepted,
  negative rejected, duration/price bounds).
- Authorization tests for each role; staff-link admin-only + ≤1-per-record.
- Tenant-isolation tests extended to staff and services.
- Tests that deactivated/archived records are excluded from bookable queries but
  retained in history.
- Accessibility: axe assertions in the staff/service E2E tests, plus an N+1 check
  on the list views (QG-A11Y / NFR-PERF for authenticated pages, per test-plan.md
  §Accessibility & performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); SEC-AUTHZ and
SEC-TENANT reviews pass for the new models.
