# ADR-0002: Tenant scoping mechanism

- Status: accepted
- Date: 2026-06-10

## Context

Tenant isolation is the most critical security rule (SEC-TENANT). Every
tenant-owned model (Staff, Service, AvailabilityRule, TimeOff, Appointment,
Customer) must be scoped through one central mechanism so a new model cannot
accidentally leak data (ARCH-TENANCY-2/3). The starter kit already provides
the `Team` aggregate (= tenant), slug-prefixed routes, and the
`EnsureTeamMembership` middleware.

## Decision

1. **Tenant = `Team`.** Tenant-owned tables carry a non-nullable, foreign-keyed
   `team_id` column.
2. **Current-tenant context.** A `CurrentTenant` container singleton holds the
   active tenant for the request. It is set in exactly two places:
   - `EnsureTeamMembership` middleware for authenticated tenant routes (after
     verifying membership), and
   - the public booking/manage entry points, which resolve the tenant from the
     URL slug or the appointment token and set the context explicitly
     (ARCH-TENANCY-4).
3. **`BelongsToTenant` trait + global scope.** Every tenant-owned model uses a
   single `App\Concerns\BelongsToTenant` trait which:
   - registers a global `TenantScope` adding `where team_id = <current>` to
     every query, and **fails closed**: when no tenant context is set, the
     scope adds a `where 1=0` clause (queries return nothing) instead of
     returning unscoped data;
   - fills `team_id` automatically on create from the current tenant and
     refuses to create when no context is set;
   - defines the `team()` relation.
4. **Enforcement by arch test.** A Pest test asserts that every model whose
   table has a `team_id` column uses `BelongsToTenant` (SEC-TENANT-3,
   ARCH-TENANCY-3), so opting out cannot happen silently.
5. **Non-member access returns 404** (SEC-TENANT-4) so resource existence is
   not leaked; in-tenant permission denials return 403 (documented in
   docs/assumptions.md).

## Alternatives considered

- **Per-query manual scoping** (repository/base query): rejected; relies on
  discipline at every call site, exactly the failure mode SEC-TENANT-3 warns
  about.
- **Database-level RLS (row-level security)**: strongest isolation but adds
  per-connection role plumbing that the starter kit and Laravel tooling do not
  expect; out of proportion for v1 and harder to test.

## Consequences

- One opt-in line per model; impossible to forget without failing the arch
  test.
- Queries without tenant context return empty rather than leaking (fail
  closed), which also makes accidental cross-tenant relationship traversal
  return nothing.
- Tests must set the tenant context explicitly (helper provided), keeping the
  isolation suite honest.
