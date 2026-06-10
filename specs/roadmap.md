# LibreNexus — Roadmap

The build proceeds as **vertical slices** (epics). Each epic delivers a working,
tested, shippable increment that passes its Definition of Done
([definition-of-done.md](definition-of-done.md)) before the next begins. This is
the incremental delivery model required by the experiment brief: implement the
smallest complete version, test it, run the gates, review it, fix blockers, then
move on.

## Delivery phases (from goal.prompt)

The agent works through eight phases. Phases 1–4 are planning and are largely
satisfied by this `specs/` directory; the agent confirms/updates them before
coding.

1. **Discovery** — read requirements + guardrails, list assumptions.
2. **Roadmap** — confirm/refine this file.
3. **Architecture** — produce/confirm [architecture.md](architecture.md) and an
   ADR log under `docs/adr/`.
4. **Quality plan** — confirm gates in [quality-gates.md](quality-gates.md) all
   run (`make verify` wired).
5. **Epic-by-epic implementation** — the epics below, in order.
6. **Verification** — full `make verify` green; structured reviews per epic.
7. **Final hardening** — close review findings, performance/a11y polish.
8. **Final quality report** — `docs/quality-report.md` + scorecard.

## Epic sequence

Epics are ordered so each builds on a verified base. The critical-risk epics
(tenant isolation, slot math, concurrency) come early so they harden the rest.

| # | Epic | Delivers | Key FRs | Critical gates |
|---|------|----------|---------|----------------|
| 00 | [Foundations & quality harness](epics/epic-00-foundations.md) | `make verify` green on a clean checkout; CI; ADR log; health check; structured logging | FR-OPS-1/2 | All gates wired |
| 01 | [Public marketing & legal site](epics/epic-01-public-site.md) | Homepage, pricing, docs, open-source, privacy, imprint, footer, MIT license | FR-PUBLIC-* | QG-A11Y, QG-PERF |
| 02 | [Auth & accounts](epics/epic-02-auth.md) | Register, login, logout, password reset, email verification, 2FA/passkeys, settings | FR-AUTH-*, FR-SETTINGS-1/2 | SEC-AUTH, SEC-RATE |
| 03 | [Tenancy & isolation](epics/epic-03-tenancy.md) | Tenants, slugs, switching, roles, invitations, **enforced isolation** | FR-TENANT-*, FR-SETTINGS-3 | **SEC-TENANT** |
| 04 | [Staff & services](epics/epic-04-staff-services.md) | Staff CRUD, service CRUD, staff↔service assignment | FR-STAFF-*, FR-SERVICE-* | SEC-AUTHZ |
| 05 | [Availability & slot engine](epics/epic-05-availability.md) | Availability rules, time off, deterministic slot calculation | FR-AVAIL-* | Unit coverage, mutation |
| 06 | [Public booking & concurrency](epics/epic-06-booking.md) | Public booking flow, customer entity, validation, **double-booking prevention** | FR-BOOK-*, FR-CUST-* | **Concurrency test**, SEC-INPUT |
| 07 | [Appointment management](epics/epic-07-appointments.md) | List/calendar, manual create/reschedule/cancel, statuses | FR-APPT-* | SEC-AUTHZ |
| 08 | [Customer self-service & comms](epics/epic-08-comms.md) | Tokened manage/cancel/reschedule, confirmation/cancellation/reminder emails | FR-CANCEL-*, FR-COMMS-* | SEC-TOKEN |
| 09 | [Admin dashboard & onboarding](epics/epic-09-dashboard.md) | Dashboard metrics, empty-state onboarding, demo seeder | FR-DASH-*, FR-OPS-3 | QG-A11Y, QG-PERF |
| 10 | [Hardening & quality report](epics/epic-10-hardening.md) | Close all findings, final `make verify`, quality report + scorecard, proof package | DoD (app) | **All gates** |

## Stretch goals (only if all gates are green and time remains)

- ICS calendar export / "add to calendar" links on confirmation.
- Booking approval workflow (FR-BOOK-7).
- Per-tenant booking page theming (logo, accent color).
- Basic analytics (bookings over time).

Stretch goals must not jeopardize any gate; if a stretch goal cannot be fully
tested, it is cut and noted in the quality report.

## Sequencing rules

- Do not start an epic until the previous epic's DoD is met and committed.
- Each epic ends with the six structured reviews
  ([review-checklists/](review-checklists/)). Blocking findings are fixed before
  proceeding.
- Tenant isolation (Epic 03) and concurrency (Epic 06) each ship with a
  dedicated, named test suite that must stay green for the rest of the build.
