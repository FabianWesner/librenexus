# Epic 03 — Tenancy & isolation

## Goal

Make the application genuinely multi-tenant with strict, server-enforced tenant
isolation, role-based permissions, tenant switching, and invitations. This is a
**critical-risk** epic: its isolation test suite must stay green for the rest of
the build.

## Requirements covered

FR-TENANT-1 … FR-TENANT-10, FR-SETTINGS-3.

## In scope

- Tenant entity (maps to the `Team` aggregate) with profile (name, unique slug,
  timezone, contact email, locale, currency) **and** booking-policy settings
  (lead time, horizon, cancel cut-off, reminder lead time, approval flag) per
  FR-TENANT-8, with documented defaults applied on creation.
- Personal tenant on first login; create additional tenants.
- Active-tenant switching; all tenant-scoped screens reflect only active tenant.
- Roles owner/admin/staff with policy enforcement (FR-TENANT-5), including the
  "own = linked staff record" resolution (FR-STAFF-4).
- Ownership lifecycle (FR-TENANT-9/10): at-least-one-owner invariant, ownership
  transfer, sole-owner protection on leave/demote/tenant-delete/account-delete.
  This **extends the Epic 02 account-deletion flow** with the sole-owner block
  and personal-tenant cleanup, and applies the Epic 02 `verified` gate to the
  real tenant-management screens introduced here.
- Email invitations with expiry; accept-to-join with assigned role; revoke;
  unregistered-invitee flow with email matching (FR-TENANT-6).
- Member removal (FR-TENANT-7): owner removes a member; removing a member linked
  to a staff record unlinks the membership but keeps the staff record and its
  appointment history (FR-STAFF-4).
- Tenant settings screen (owner/admin).
- Slug reservation/precedence per ARCH-ROUTING-4 (slugs cannot shadow static
  routes).
- **Tenant isolation enforced server-side** for every tenant-owned model.

## Out of scope

Scheduling domain models (Epics 04+) — but they must adopt the isolation pattern
established here.

## Acceptance criteria

- **AC-1** A user belongs to ≥1 tenant; can create, switch, and (owner) delete
  tenants; slug is unique and URL-safe.
- **AC-2** Roles grant exactly the permissions in FR-TENANT-5, enforced by
  policies; UI hides disallowed actions but the **server** is the source of
  truth.
- **AC-3 (critical, SEC-TENANT)** A member of tenant A cannot read or mutate any
  tenant B record through any route, ID in URL, form field, or query — verified
  by an explicit isolation test suite covering each tenant-owned model.
- **AC-4** Invitations: created by owner/admin, emailed, expire, single-use,
  revocable; accepting joins with the assigned role; an unregistered invitee is
  routed through registration and the account email must match the invited email
  (FR-TENANT-6).
- **AC-5** Switching tenants updates the active context; deep links to another
  tenant's resource without membership return 403/404 (not data).
- **AC-6** Tenant timezone is stored and later used by the slot engine; booking-
  policy defaults are present after creation.
- **AC-7** Ownership: a tenant always has ≥1 owner; ownership can be transferred;
  the last owner cannot leave, be demoted, or delete their account without
  transferring or deleting the tenant (FR-TENANT-9/10). Covered by tests.
- **AC-8** Slug creation/rename rejects reserved names and never shadows a static
  route (ARCH-ROUTING-4).
- **AC-9** An owner can remove a member (FR-TENANT-7); removing a member linked to
  a staff record unlinks the membership while preserving the staff record and its
  history.

## Implementation notes

- Establish a single, enforced scoping mechanism (e.g. a global scope keyed on
  active tenant + membership check, or a base query/repository) so future models
  cannot accidentally leak. Document it in an ADR.
- Prefer 404 over 403 for non-member access to avoid leaking existence, but be
  consistent; document the choice.
- Add an `arch()` rule or test ensuring tenant-owned models declare the scope.

## Required tests

- **`tests/Feature/Tenancy/IsolationTest.php`** (named suite): for each
  tenant-owned model, a member of A is denied read/update/delete of B's records
  via controller/Livewire actions and direct route access.
- Role-permission matrix tests (owner vs admin vs staff).
- Invitation lifecycle tests (create, expire, accept, revoke, single-use,
  unregistered-invitee email-match enforcement).
- Tenant CRUD + switching + slug uniqueness tests, plus reserved-slug rejection
  (ARCH-ROUTING-4).
- Ownership tests: transfer, at-least-one-owner invariant, sole-owner blocked
  from leave/demote/tenant-delete/account-delete (FR-TENANT-9/10).
- Member-removal test (FR-TENANT-7): member removed; linked staff record + its
  history preserved on unlink.
- Accessibility: axe assertions in the tenant-settings / accept-invite E2E tests
  (QG-A11Y for authenticated pages, per test-plan.md §Accessibility & performance
  per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); the isolation suite
is green and treated as a regression guard; SEC-TENANT review passes with no
blocking findings.
