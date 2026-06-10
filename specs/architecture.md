# LibreNexus — Architecture Rules

These rules constrain **how** the app is built. They are deliberately
lightweight: a server-rendered Laravel monolith with low architectural noise, as
chosen for the benchmark. The agent records concrete decisions as ADRs in
`docs/adr/`. The **Architecture reviewer** verifies these rules; where possible
they are enforced with Pest `arch()` tests.

## Stack (fixed)

- PHP 8.4, Laravel 13.
- PostgreSQL (production-like concurrency; required for the booking exclusion
  constraint — see ARCH-DATA-3).
- Blade + Livewire 4 (SFC pages under `resources/views/pages`) + Flux UI +
  Tailwind 4.
- Fortify for auth; queues for async work.
- Pest + PHPUnit; Playwright via Pest 4 browser tests.

Changing the stack or adding base dependencies requires an ADR and is otherwise
out of bounds (per project guardrails).

## ARCH-STRUCTURE — Application structure

- **ARCH-STRUCTURE-1** Keep the existing directory layout; do not introduce new
  top-level `app/` folders without an ADR. Established homes:
  - `app/Models` — Eloquent models (aggregates: User, Tenant(=Team), Membership,
    TeamInvitation (shipped by the starter kit), plus new: Staff, Service,
    AvailabilityRule, TimeOff, Appointment, Customer).
  - `app/Actions/<Domain>` — single-purpose write operations (e.g. `BookAppointment`).
  - `app/Concerns` — reusable traits.
  - `app/Data` — DTOs / value objects (e.g. a `Slot` value object).
  - `app/Enums` — enums (TitleCase keys), e.g. `AppointmentStatus`.
  - `app/Policies` — authorization.
  - `app/Rules` — validation rules.
  - `app/Http/Middleware`, `app/Http/Responses` — HTTP concerns.
  - `app/Notifications` / Mailables — customer + user messaging.
  - `resources/views/pages/**` — Livewire SFC page components.
- **ARCH-STRUCTURE-2** Business logic lives in Actions / domain services /
  models, **not** in Blade or in fat Livewire components. Livewire components
  orchestrate; they delegate writes to Actions.
- **ARCH-STRUCTURE-3** The slot engine (Epic 05) is a pure service with no
  Eloquent side effects, so it is fast and unit/mutation-testable.

## ARCH-TENANCY — Multi-tenancy

- **ARCH-TENANCY-1** Tenant = the `Team` aggregate. Tenant-scoped routes use the
  existing `{current_team}` slug prefix + `EnsureTeamMembership` middleware.
- **ARCH-TENANCY-2** Every tenant-owned model carries a `tenant_id` (team id) and
  is scoped through **one** central mechanism (global scope keyed on the active
  tenant + membership, or a guarded base query). New models must opt into it.
- **ARCH-TENANCY-3** An `arch()`/scope test asserts that tenant-owned models
  declare the scope, so isolation cannot regress silently (SEC-TENANT-3).
- **ARCH-TENANCY-4** No query may select tenant-owned data without a tenant
  constraint. Public booking resolves the tenant from the slug, then scopes.

## ARCH-DATA — Data & persistence

- **ARCH-DATA-1** Schema via migrations only; forward-only and deploy-safe
  (NFR-OPS-4). Foreign keys and `not null` where appropriate.
- **ARCH-DATA-2** Timestamps stored in UTC; all human-facing time math done in
  the tenant timezone (FR-TENANT-8, Epic 05).
- **ARCH-DATA-3** Double-booking prevented at the database level: a Postgres
  exclusion constraint on (staff, time range) using `tstzrange` +
  `EXCLUDE USING gist`, **or** a unique constraint plus `SELECT … FOR UPDATE`
  inside the booking transaction. The constraint applies only to
  **time-reserving statuses** (`pending`, `confirmed`) per FR-APPT-4 (e.g. a
  partial constraint) so cancelled/no-show appointments do not block slots. The
  choice is an ADR. Application checks alone are insufficient (Epic 06, AC-3).
- **ARCH-DATA-4** Money (service price) stored as integer minor units, never
  float.
- **ARCH-DATA-5** Use Eloquent with bindings; no string-interpolated SQL
  (SEC-INPUT-2).

## ARCH-HTTP — HTTP & request lifecycle

- **ARCH-HTTP-1** Validation in Form Requests / Livewire rules; controllers and
  components stay thin.
- **ARCH-HTTP-2** Authorization via policies/gates on every action (SEC-AUTHZ).
- **ARCH-HTTP-3** Named routes + `route()` for links; tenant routes carry the
  slug.
- **ARCH-HTTP-4** Long-running work (email, reminders) is dispatched to queues,
  never run inline (NFR-OPS-2).

## ARCH-ROUTING — Route & slug strategy

Three route families share the URL space; precedence and slug reservation keep
them unambiguous.

- **ARCH-ROUTING-1 Static-first precedence.** Fixed top-level paths are
  registered **before** any tenant-slug wildcard so they always win:
  - **Public marketing/legal:** `/`, `/pricing`, `/docs`, `/open-source`,
    `/privacy`, `/imprint`.
  - **Auth:** `/login`, `/register`, `/forgot-password`, `/reset-password`,
    `/verify-email`, `/two-factor-challenge`, `/confirm-password`.
  - **Account (non-tenant):** `/settings/*`.
  - **Customer self-service:** `/manage/{token}`.
  - **System:** `/health`.
- **ARCH-ROUTING-2 Authenticated tenant routes** are nested under the slug with
  auth: `/{tenant}/dashboard`, `/{tenant}/staff`, `/{tenant}/services`,
  `/{tenant}/appointments`, `/{tenant}/calendar`, `/{tenant}/settings`, etc.
  (the existing `{current_team}` prefix + `EnsureTeamMembership` middleware).
- **ARCH-ROUTING-3 Public booking** is the tenant-slug **catch-all**, registered
  **last** (lowest priority): `/{tenant}` for the booking entry and
  `/{tenant}/book/*` for steps/confirmation. It resolves the tenant from the
  slug, requires no auth, then scopes all data to that tenant (ARCH-TENANCY-4).
  Authenticated tenant sub-paths in ARCH-ROUTING-2 take precedence over the
  public booking sub-paths.
- **ARCH-ROUTING-4 Reserved slugs.** A tenant slug must be URL-safe and is
  validated against a reserved list so it can never shadow a static path. The
  list = every top-level route segment (the existing `TeamName` rule already
  derives these from registered routes) **plus** an explicit static list
  (`book`, `manage`, `health`, `settings`, `api`, `assets`, `storage`, `up`,
  and the marketing/auth segments above). Slug creation and rename both enforce
  it. New top-level routes must be added to the reserved list in the same change.
- **ARCH-ROUTING-5** Personal tenants get an auto-generated unique slug; all
  slug generation runs through the reserved-name check (the existing
  `GeneratesUniqueTeamSlugs` concern).

## ARCH-ASYNC — Background work

- **ARCH-ASYNC-1** Emails sent via queued Mailables/Notifications.
- **ARCH-ASYNC-2** Reminders via a scheduled command enqueuing idempotent jobs
  (Epic 08).
- **ARCH-ASYNC-3** Failed jobs are recorded and inspectable (NFR-OBS-4).

## ARCH-CONFIG — Configuration & secrets

- **ARCH-CONFIG-1** All environment-specific values via config/env; no secrets in
  code (SEC-SECRETS).
- **ARCH-CONFIG-2** No environment branching in domain code; behavior differences
  come from config.

## ARCH-FRONTEND — Frontend

- **ARCH-FRONTEND-1** Server-rendered Blade/Livewire; reach for Alpine for small
  client interactions, not a separate SPA framework.
- **ARCH-FRONTEND-2** Reuse Flux UI + existing components before writing new ones
  (project guardrail); follow [styleguide.md](styleguide.md).
- **ARCH-FRONTEND-3** Assets built with Vite; no unbundled inline scripts that
  would break CSP (SEC-HEADERS-1).

## ARCH-TEST — Testability

- **ARCH-TEST-1** Domain logic is unit-testable without HTTP (pure services /
  Actions).
- **ARCH-TEST-2** Critical logic (slot engine, booking, tenancy) is structured so
  mutation testing is meaningful (`covers()`/`mutates()`).
- **ARCH-TEST-3** `arch()` tests guard: models extend the base model, enums are
  used for statuses, no debug helpers (`dd`, `dump`, `ray`) in app code, and
  tenant-owned models declare scoping.

## ADR log

Record each significant decision in `docs/adr/NNNN-title.md` (context, decision,
consequences). Minimum ADRs expected: stack choice (ADR-0001), tenant scoping
mechanism, and the double-booking constraint strategy.
