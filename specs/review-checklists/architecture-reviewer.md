# Architecture Reviewer — Checklist

**Verifies against:** [../architecture.md](../architecture.md),
[../non-functional.md](../non-functional.md) (maintainability/reliability).

**Mission:** confirm the code respects the intended structure, boundaries, and
data/async patterns, and that decisions are recorded.

## Checklist

1. **Structure** — new code lives in the established directories
   (ARCH-STRUCTURE-1); no new top-level `app/` folder without an ADR.
2. **Logic placement** — business logic is in Actions/services/models, not in
   Blade or fat Livewire components (ARCH-STRUCTURE-2); the slot engine is a pure
   service (ARCH-STRUCTURE-3).
3. **Tenant scoping** — every new tenant-owned model uses the central scoping
   mechanism and is covered by the scope/`arch()` test (ARCH-TENANCY-2/3).
4. **No leaky queries** — no query selects tenant-owned data without a tenant
   constraint (ARCH-TENANCY-4).
5. **Data** — migrations are forward-only with FKs/constraints (ARCH-DATA-1);
   timestamps UTC, time math in tenant tz (ARCH-DATA-2); money as integer minor
   units (ARCH-DATA-4); no string-interpolated SQL (ARCH-DATA-5).
6. **Double-booking** — prevented at the DB level (exclusion/unique + locking),
   not by app checks alone (ARCH-DATA-3); the strategy is in an ADR.
7. **HTTP** — validation in Form Requests/rules, thin controllers/components,
   authorization on every action, named routes (ARCH-HTTP-*).
8. **Async** — emails/reminders queued, not inline; failed jobs visible
   (ARCH-ASYNC-*, NFR-OPS-2).
9. **Config/secrets** — env-driven, no secrets in code, no environment branching
   in domain logic (ARCH-CONFIG-*).
10. **Frontend** — server-rendered, Flux/components reused, no CSP-breaking
    inline scripts (ARCH-FRONTEND-*).
11. **Arch tests** — `arch()` rules present and green (no `dd`/`dump`/`ray`,
    model/enum conventions, tenant scoping) (ARCH-TEST-3).
12. **ADRs** — significant decisions recorded in `docs/adr/` (stack, scoping,
    booking constraint).

## Decision rule

- **Fail** for any tenant-scoping gap, an app-only double-booking guard, or
  business logic embedded in views.
- **Pass with warnings** for Medium structural smells that are tracked.
- **Pass** when boundaries hold and required ADRs exist.
