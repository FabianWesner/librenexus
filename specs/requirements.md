# LibreNexus — Functional Requirements

LibreNexus is a free, MIT-licensed, multi-tenant appointment scheduling system
for small offices (clinics, salons, studios, advisors). A **tenant** is one
office. Each tenant has staff, services, availability, a public booking page,
and appointments booked by customers.

This document is the authoritative list of **what** the product does. The
**how** (architecture, schema, UI) is defined by the agent and constrained by
[architecture.md](architecture.md), [security.md](security.md),
[non-functional.md](non-functional.md), and [quality-gates.md](quality-gates.md).

## Conventions

- Each requirement has a stable ID (`FR-AREA-n`). Tests, epics, and review
  reports reference these IDs.
- **MUST** = mandatory for "done". **SHOULD** = expected unless a documented
  assumption removes it. **MAY** = optional.
- Every MUST requirement must be covered by at least one automated test
  (see [test-plan.md](test-plan.md)).

## Domain glossary

| Term | Meaning |
|------|---------|
| Tenant | A single office/organization. Maps to the `Team` aggregate. Owns all scheduling data. |
| User | An authenticated person. May belong to one or more tenants with a role. |
| Member | A user's membership in a tenant, carrying a role (owner, admin, staff). |
| Staff member | A bookable person within a tenant (often, but not always, a user). |
| Service | A bookable offering (name, duration, buffer, price, color). |
| Availability rule | Recurring weekly working hours for a staff member. |
| Time off | A one-off interval where a staff member is unavailable. |
| Slot | A concrete bookable start time derived from availability minus existing appointments and time off. |
| Customer | The (usually unauthenticated) person who books. Identified by email; not a User. |
| Appointment | A booking of one service with one staff member at one slot for one customer. |
| Cancellation token | An unguessable token allowing a customer to view/cancel/reschedule without login. |

---

## FR-AUTH — Authentication & accounts

- **FR-AUTH-1 (MUST)** A visitor can register an account with name, email, and
  password. Email must be unique and validated. Passwords follow
  [security.md](security.md) SEC-AUTH rules.
- **FR-AUTH-2 (MUST)** A registered user can log in and log out.
- **FR-AUTH-3 (MUST)** A user can reset a forgotten password via emailed link.
- **FR-AUTH-4 (MUST)** Email verification is required before a user can manage a
  tenant; unverified users see a verification notice.
- **FR-AUTH-5 (SHOULD)** A user can enable two-factor authentication (TOTP) and
  passkeys (provided by the starter kit; must remain functional).
- **FR-AUTH-6 (MUST)** Login is rate-limited (see SEC-RATE).

## FR-TENANT — Tenant management & isolation

- **FR-TENANT-1 (MUST)** On first login a user has a personal tenant; they can
  create additional tenants with a name and a unique URL slug.
- **FR-TENANT-2 (MUST)** A user can switch their active tenant. All tenant-scoped
  screens reflect only the active tenant's data.
- **FR-TENANT-3 (MUST)** A user can belong to multiple tenants; a tenant can have
  multiple users.
- **FR-TENANT-4 (MUST — critical)** A user can only read or write data belonging
  to a tenant they are a member of. Cross-tenant access is impossible via UI,
  route, ID guessing, or API. Enforced server-side and covered by tests
  (see SEC-TENANT, [test-plan.md](test-plan.md) §Tenant isolation).
- **FR-TENANT-5 (MUST)** Roles: **owner** (full control incl. manage members,
  transfer ownership, and delete tenant), **admin** (manage staff, services,
  availability, appointments), **staff** (manage own availability and own
  appointments).
  Permissions enforced by policies. "Own" is resolved by the staff-record link
  in FR-STAFF-4: a staff-role member acts on the staff record linked to their
  membership. A staff-role member with **no** linked staff record can view the
  dashboard but has no bookable availability and sees no appointments until an
  admin links them; this state is surfaced in the UI.
- **FR-TENANT-6 (MUST)** An owner/admin can invite users by email with an
  assigned role; invitations are single-use and expire. Lifecycle:
  - The invite is keyed to the invited **email address**.
  - If the invitee has no account, accepting first routes them through
    registration/login; the account email **must match** the invited email
    (case-insensitive) or acceptance is refused.
  - Accepting consumes the invitation and creates the membership with the
    assigned role; an expired, revoked, or already-used invitation cannot be
    accepted.
- **FR-TENANT-7 (SHOULD)** An owner can remove members and revoke pending
  invitations. Removing a member who is linked to a staff record unlinks the
  membership but, per FR-STAFF-2/FR-APPT data-retention, does **not** delete the
  staff record or its historical appointments.
- **FR-TENANT-8 (MUST)** A tenant has settings in two groups:
  - **Profile:** display name, slug, timezone, contact email, locale. Timezone
    drives all slot calculations.
  - **Booking policy** (owner/admin editable; defaults applied on tenant
    creation and documented as assumptions): minimum lead time (default 2h),
    maximum booking horizon (default 60 days), cancellation cut-off (default 2h
    before start), reminder lead time (default 24h before start), and whether
    booking requires approval (FR-BOOK-7, default off). These are the single
    owner of the "configurable" values referenced in FR-AVAIL-3, FR-CANCEL-2,
    and FR-COMMS-3.
- **FR-TENANT-9 (MUST)** Tenant and ownership lifecycle:
  - A tenant always has **at least one owner**. The last remaining owner cannot
    leave or be demoted without first transferring ownership or deleting the
    tenant.
  - An owner can transfer ownership to another member (who becomes owner).
  - Deleting a tenant (owner only, confirmed) removes its scheduling data
    (staff, services, availability, appointments, customers, invitations). It
    does not delete other members' accounts.
  - A user's **personal tenant** cannot be deleted or left on its own; it is the
    user's default workspace and is removed only when the account is deleted
    (FR-TENANT-10). It is never re-created once the account is gone.
- **FR-TENANT-10 (MUST)** Account deletion (FR-SETTINGS-1) for a user who is the
  **sole owner** of any non-personal tenant is blocked until they transfer
  ownership or delete that tenant. Deleting the account removes the user's
  personal tenant and its data, and unlinks (does not delete) any staff records
  they were linked to in other tenants, preserving those tenants' history.

## FR-STAFF — Staff management

- **FR-STAFF-1 (MUST)** An admin can create, edit, and deactivate staff members
  (name, email, optional linked user, color, active flag).
- **FR-STAFF-2 (MUST)** Deactivated staff are not bookable and disappear from the
  public booking page, but their past appointments remain.
- **FR-STAFF-3 (SHOULD)** A staff member can be assigned to a subset of services.
- **FR-STAFF-4 (MUST)** Staff ↔ user mapping. A staff record is a tenant-owned
  entity that **may** be linked to one membership (a user in the tenant). The
  link is optional and at most one-to-one per tenant:
  - A staff member need not be a user (e.g. a contractor managed by an admin).
  - A staff-role member's "own" availability/appointments (FR-TENANT-5,
    FR-APPT-2) are exactly those of the staff record linked to their membership.
  - Linking/unlinking is an admin action; a user cannot self-link.
  - Deactivating a staff record or unlinking it never deletes history.

## FR-SERVICE — Services

- **FR-SERVICE-1 (MUST)** An admin can create, edit, archive services with: name,
  description, duration (minutes), buffer-before/after (minutes), price
  (optional), color, and active flag.
- **FR-SERVICE-2 (MUST)** Archived services keep historical appointments but are
  not bookable.
- **FR-SERVICE-3 (MUST)** Server-side validation, authoritative over Epic 04:
  - **Duration** is a **positive** integer, `5 ≤ duration ≤ 480` minutes.
  - **Buffers** (before/after) are **non-negative** integers, `0 ≤ buffer ≤ 120`
    minutes (0 = no buffer).
  - **Price** is optional; when set it is a **non-negative** integer in minor
    units (ARCH-DATA-4). The currency is a single tenant-level setting
    (ISO 4217 code, default from tenant locale; documented as an assumption);
    services do not carry their own currency in v1.

## FR-AVAIL — Availability

- **FR-AVAIL-1 (MUST)** A staff member has weekly recurring availability rules
  (weekday + start/end time) in the tenant timezone.
- **FR-AVAIL-2 (MUST)** A staff member can add time-off intervals (date/time
  range) that remove slots.
- **FR-AVAIL-3 (MUST)** Bookable slots are computed as: availability windows,
  partitioned into service-duration slots (plus buffers), minus existing
  appointments, minus time off, minus already-passed times, respecting tenant
  timezone and the minimum lead time and maximum booking horizon from the
  tenant booking policy (FR-TENANT-8).
- **FR-AVAIL-4 (MUST)** Slot computation is deterministic and unit-tested with
  edge cases: DST transitions, midnight boundaries, overlapping rules,
  back-to-back buffers.

## FR-BOOK — Public booking

- **FR-BOOK-1 (MUST)** Each tenant has a public booking page at a stable,
  shareable URL using the tenant slug. No login required.
- **FR-BOOK-2 (MUST)** A customer selects a service, then a staff member (or
  "any available"), then an available slot, then enters name, email, optional
  phone, and notes, then confirms.
- **FR-BOOK-3 (MUST — critical)** Double-booking is impossible: two customers
  cannot book the same staff member for overlapping times. Enforced with a DB
  constraint and/or a transaction with row locking; covered by a concurrency
  test (see [test-plan.md](test-plan.md) §Concurrency).
- **FR-BOOK-4 (MUST)** On successful booking the customer sees a confirmation
  page with appointment details and a manage/cancel link, and receives a
  confirmation email.
- **FR-BOOK-5 (MUST)** Booking input is validated server-side (valid email,
  required fields, slot still available, within horizon).
- **FR-BOOK-6 (SHOULD)** The booking page is fully usable on mobile and meets
  accessibility gate QG-A11Y.
- **FR-BOOK-7 (MAY)** A tenant can require booking approval (pending → confirmed).

## FR-APPT — Appointment management (admin side)

- **FR-APPT-1 (MUST)** Admins/staff see a list and calendar/day view of
  appointments for the active tenant, filterable by staff, service, and date.
- **FR-APPT-2 (MUST)** Staff see their own appointments; admins see all.
- **FR-APPT-3 (MUST)** An admin/staff can manually create, reschedule, and cancel
  appointments, respecting the same conflict rules as public booking.
- **FR-APPT-4 (MUST)** Appointment status model. Statuses and whether they
  **reserve the staff member's time** (i.e. block overlapping bookings and count
  in the double-booking constraint of FR-BOOK-3 / ARCH-DATA-3):

  | Status | Reserves time? | Meaning |
  |--------|:--------------:|---------|
  | `pending` | **Yes** | Awaiting approval (FR-BOOK-7) or unconfirmed; still holds the slot. |
  | `confirmed` | **Yes** | Active booking. |
  | `completed` | No (in the past) | Took place; never overlaps future bookings. |
  | `cancelled` | **No** | Cancelled by anyone; frees the slot (FR-CANCEL-4). |
  | `no_show` | No | Customer did not attend; frees the slot. |

  Only time-reserving statuses participate in the conflict constraint, so a
  `cancelled`/`no_show` appointment must not block a new booking. Allowed
  transitions (anything else is rejected server-side):
  - `pending → confirmed | cancelled | no_show`
  - `confirmed → completed | cancelled | no_show`
  - `cancelled`, `completed`, `no_show` are **terminal** (no outgoing
    transitions; reschedule instead creates/moves an active appointment).

- **FR-APPT-5 (SHOULD)** Rescheduling and cancellation trigger customer emails
  (the cancellation mailable is introduced in this epic, Epic 07; reminders in
  Epic 08).

## FR-CANCEL — Customer self-service

- **FR-CANCEL-1 (MUST)** Via the emailed manage link (cancellation token) a
  customer can view their appointment and cancel it without logging in.
- **FR-CANCEL-2 (MUST)** The token is unguessable, single-appointment-scoped, and
  cancellation respects the cancellation cut-off from the tenant booking policy
  (FR-TENANT-8).
- **FR-CANCEL-3 (SHOULD)** The customer can reschedule to another available slot
  via the same link.
- **FR-CANCEL-4 (MUST)** When an appointment is cancelled (by any actor —
  customer, staff, or admin), its time is freed and becomes immediately bookable
  again, per the status reservation rules in FR-APPT-4.

## FR-CUST — Customers

- **FR-CUST-1 (MUST)** A customer is a **tenant-owned** record (it carries
  `tenant_id` and is subject to SEC-TENANT). Customers are never shared across
  tenants; the same person booking with two tenants is two customer records.
- **FR-CUST-2 (MUST)** Within a tenant, a customer is identified by **email
  (case-insensitive), unique per tenant**. A repeat booking with the same email
  **reuses** the existing customer record (updating name/phone if changed)
  rather than creating duplicates.
- **FR-CUST-3 (MUST)** Customers are not user accounts and never authenticate;
  they act only via cancellation tokens (SEC-TOKEN).
- **FR-CUST-4 (SHOULD)** Customer PII (name, email, phone, notes) is minimal,
  shown only to that tenant's authorized members, and removed when the tenant is
  deleted (FR-TENANT-9).

## FR-COMMS — Customer communication

- **FR-COMMS-1 (MUST)** Confirmation email on booking, with details + manage link.
- **FR-COMMS-2 (MUST)** Cancellation email on cancellation.
- **FR-COMMS-3 (SHOULD)** Reminder email sent the reminder-lead-time before the
  appointment (tenant booking policy, FR-TENANT-8) by a scheduled job; queued and
  observable; idempotent (no double reminders). Reminders are sent only for
  upcoming `confirmed` appointments (and `pending` ones when the tenant does not
  require approval); never for `cancelled`/`no_show`/`completed`.
- **FR-COMMS-4 (MUST)** All emails are queued (not sent inline) and rendered from
  templates with tenant branding (name, contact email).

## FR-DASH — Admin dashboard

- **FR-DASH-1 (MUST)** A dashboard shows the active tenant's key figures: today's
  appointments, upcoming count, recent bookings, and per-staff load.
- **FR-DASH-2 (SHOULD)** Empty states guide a new tenant through setup
  (add staff → add service → set availability → share booking link).

## FR-SETTINGS — User & tenant settings

- **FR-SETTINGS-1 (MUST)** A user can update profile (name, email with
  re-verification) and password, and delete their account subject to the
  sole-owner rule in FR-TENANT-10.
- **FR-SETTINGS-2 (MUST)** Appearance setting (light/dark/system) persists.
- **FR-SETTINGS-3 (MUST)** Tenant settings (FR-TENANT-8) editable by owner/admin.

## FR-PUBLIC — Marketing & legal site

- **FR-PUBLIC-1 (MUST)** Public marketing homepage explaining the product with a
  clear call to action (sign up / view a demo booking page).
- **FR-PUBLIC-2 (MUST)** Pricing page that explains the free plan (everything is
  free, MIT licensed).
- **FR-PUBLIC-3 (MUST)** Open-source page: link to the GitHub repository, the MIT
  license, and the live public quality evidence (CI, reports).
- **FR-PUBLIC-4 (MUST)** User manual / documentation page covering setup and
  booking.
- **FR-PUBLIC-5 (MUST)** Privacy policy and imprint/legal notice pages.
- **FR-PUBLIC-6 (MUST)** A global footer linking to pricing, docs, open-source,
  privacy, imprint, and the repository.
- **FR-PUBLIC-7 (MUST)** The repository ships an MIT `LICENSE` file.

## FR-OPS — Operability

- **FR-OPS-1 (MUST)** A health-check endpoint reports app and database status.
- **FR-OPS-2 (MUST)** Structured logs with a per-request correlation ID
  (see NFR-OBS).
- **FR-OPS-3 (SHOULD)** A seeder creates a realistic demo tenant so the app is
  immediately explorable after `make setup`.

---

## Explicit non-goals (v1)

To keep the build to a few hours and the scope verifiable, the following are
**out of scope** and must be listed as assumptions if referenced:

- Payments/online prepayment, refunds, invoicing.
- Real SMS/WhatsApp delivery (email only; reminders via queue).
- Calendar sync (Google/Outlook/ICS export is a stretch goal, see roadmap).
- Native mobile apps.
- Recurring/series appointments and group/class bookings.
- Public REST API for third parties (internal server-rendered app only).
