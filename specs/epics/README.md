# LibreNexus — Epics

Each epic is a vertical slice with explicit acceptance criteria traceable to
[../requirements.md](../requirements.md). An epic is **done** only when it meets
the per-epic checklist and the shared
[Definition of Done](../definition-of-done.md), with all six structured reviews
([../review-checklists/](../review-checklists/)) passing (no blocking findings).

| # | Epic | Status gate |
|---|------|-------------|
| 00 | [Foundations & quality harness](epic-00-foundations.md) | `make verify` green |
| 01 | [Public marketing & legal site](epic-01-public-site.md) | a11y + perf budgets |
| 02 | [Auth & accounts](epic-02-auth.md) | auth + rate-limit tests |
| 03 | [Tenancy & isolation](epic-03-tenancy.md) | **isolation suite green** |
| 04 | [Staff & services](epic-04-staff-services.md) | authz tests |
| 05 | [Availability & slot engine](epic-05-availability.md) | slot unit + mutation |
| 06 | [Public booking & concurrency](epic-06-booking.md) | **concurrency test** |
| 07 | [Appointment management](epic-07-appointments.md) | authz + status tests |
| 08 | [Customer self-service & comms](epic-08-comms.md) | token + mail tests |
| 09 | [Admin dashboard & onboarding](epic-09-dashboard.md) | a11y + perf budgets |
| 10 | [Hardening & quality report](epic-10-hardening.md) | **all gates + report** |

## Epic file structure

Every epic file contains:

- **Goal** — one-sentence restatement.
- **Requirements covered** — FR IDs.
- **In scope / Out of scope** — to prevent scope creep.
- **Acceptance criteria** — testable statements (AC-n).
- **Implementation notes** — constraints, not prescriptions.
- **Required tests** — the minimum automated coverage.
- **Done when** — links to the DoD plus epic-specific gates.
