# LibreNexus — Pages & Visuals

Every page the application must render, grouped by area, with its route intent,
purpose, key elements, and a short **visual description**. URLs are intent, not
final strings; tenant-scoped routes carry the tenant slug (`{tenant}`). All
pages follow [styleguide.md](styleguide.md) and meet QG-A11Y; public + key pages
meet QG-PERF.

Legend: 🌐 public (no auth) · 🔒 authenticated · 🏢 tenant-scoped · 🎟️ tokened
(no auth).

---

## Public marketing & legal (🌐) — Epic 01

### Home `/`
- **Purpose:** explain LibreNexus and drive sign-up.
- **Elements:** top nav (logo, links, Log in, Sign up), hero (headline,
  subhead, primary CTA "Get started free", secondary "See a demo booking page"),
  3–4 feature highlights with icons, a "why free / open-source" strip, footer.
- **Visual:** clean neutral canvas, one accent on the primary CTA, generous
  whitespace, a simple product visual (booking calendar mock). Calm, no clutter.

### Pricing `/pricing`
- **Purpose:** state that everything is free, MIT-licensed.
- **Elements:** a single "Free" plan card listing all features, an FAQ ("Is it
  really free?", "Can I self-host?"), CTA to sign up, link to open-source page.
- **Visual:** one centered plan card with a subtle accent border; reassuring,
  uncluttered.

### Docs / User manual `/docs`
- **Purpose:** setup + booking walkthrough (FR-PUBLIC-4).
- **Elements:** left section nav, prose content (Getting started → Add staff →
  Add services → Set availability → Share your link → Manage appointments),
  screenshots/callouts.
- **Visual:** documentation layout, readable prose width, sticky section nav.

### Open source `/open-source`
- **Purpose:** the proof page (FR-PUBLIC-3).
- **Elements:** GitHub repo link, MIT license link, CI badges, links to the
  quality report, coverage, mutation, security, and SBOM artifacts.
- **Visual:** badge row up top, a short "how this was built / how to reproduce
  (`make verify`)" section, evidence links as a tidy list.

### Privacy `/privacy` and Imprint `/imprint`
- **Purpose:** legal pages (FR-PUBLIC-5).
- **Visual:** simple prose layout, last-updated date, footer.

### Global footer (all 🌐 pages)
- Links: Pricing, Docs, Open source, Privacy, Imprint, GitHub repo. Logo +
  short tagline + "MIT licensed".

---

## Authentication (🌐 → 🔒) — Epic 02

(Most exist in the starter kit; restyle to LibreNexus brand.)

### Register `/register`, Login `/login`
- **Elements:** centered card, email/password fields, primary action, links to
  the other flow and to password reset; passkey option on login.
- **Visual:** minimal auth layout, logo, single card, accent primary button.

### Forgot password `/forgot-password`, Reset password `/reset-password`
- **Elements:** email field / new-password fields, confirmation messaging.

### Verify email `/verify-email`
- **Elements:** notice + resend button; blocks tenant management until verified.

### Two-factor challenge `/two-factor-challenge`
- **Elements:** code input + recovery-code option.

### Confirm password `/confirm-password`
- **Elements:** password prompt guarding sensitive settings.

---

## User settings (🔒) — Epic 02

### Profile `/settings/profile`
- **Elements:** name/email form (email change re-verifies), avatar/initials.

### Password & security `/settings/security`
- **Elements:** password change, 2FA setup (QR + recovery codes), passkeys list.

### Appearance `/settings/appearance`
- **Elements:** light/dark/system toggle (persists, FR-SETTINGS-2).

### Delete account
- **Elements:** danger zone with confirm modal requiring password.

- **Visual (settings):** the app shell with a settings sub-nav; each section a
  clean card with one primary action and a danger zone clearly separated.

---

## Tenant management (🔒 / 🏢) — Epic 03

### Tenant switcher (chrome)
- **Element:** dropdown in the header/sidebar to switch active tenant + "Create
  tenant".

### Create tenant (modal or `/tenants/create`)
- **Elements:** name, auto-suggested unique slug, timezone, contact email.

### Tenant settings `/{tenant}/settings`
- **Profile:** display name, slug, timezone, contact email, locale, currency
  (ISO 4217).
- **Booking policy (FR-TENANT-8):** minimum lead time, maximum booking horizon,
  cancellation cut-off, reminder lead time, and a "require booking approval"
  toggle (FR-BOOK-7), each with its default and units shown.
- **Members & access:** members list with role badges and row actions
  (change role, remove — FR-TENANT-7), transfer ownership (owner only), invite
  form, pending invitations with revoke.
- **Danger zone:** delete tenant (owner only, confirmed).
- **Visual:** sectioned cards (Profile, Booking policy, Members, Danger zone);
  members as a table; invite as an inline form; policy fields grouped with clear
  units and defaults.

### Accept invitation `/invitations/{invitation}/accept` (🔒)
- **Elements:** tenant name, assigned role, accept/decline.

---

## Staff & services (🏢) — Epic 04

### Staff list `/{tenant}/staff`
- **Elements:** table (name, email, color chip, services count, active toggle),
  "Add staff" primary action, row edit/deactivate.
- **Visual:** scannable table; color chips; empty state "Add your first staff
  member".

### Staff form (modal/page)
- **Elements:** name, email, optional linked user, color picker, active flag,
  assigned services (multi-select).

### Services list `/{tenant}/services`
- **Elements:** cards or table (name, duration, buffers, price, color, active),
  "Add service" action, edit/archive.
- **Visual:** each service shows duration + price prominently; archived hidden by
  default with a filter.

### Service form (modal/page)
- **Elements:** name, description, duration, buffer before/after, price, color,
  active.

---

## Availability (🏢) — Epic 05

### Availability `/{tenant}/staff/{staff}/availability`
- **Elements:** weekly grid (7 days × time ranges) to add/edit recurring rules in
  tenant timezone; time-off list with add (date/time range).
- **Visual:** a clear weekly schedule editor; time-off as a separate list below;
  timezone shown explicitly.

---

## Public booking (🌐) — Epic 06

### Booking page `/{tenant}` (steps under `/{tenant}/book/*`)
- **Routing:** the tenant-slug catch-all, registered last; static and
  authenticated tenant routes take precedence; slugs are reserved so they cannot
  shadow `/pricing`, `/login`, etc. (see
  [architecture.md](architecture.md) §ARCH-ROUTING).
- **Purpose:** the customer-facing booking flow; the product's shop window.
- **Elements (stepwise):**
  1. **Service** — list of bookable services with duration/price.
  2. **Staff** — choose a staff member or "Any available".
  3. **Slot** — date picker + available start times for the selection.
  4. **Details** — name, email, optional phone, notes.
  5. **Confirm** — summary + confirm button.
- **Visual:** tenant name/branding header, a calm multi-step layout with a
  progress indicator, mobile-first, large tap targets, clear availability;
  unavailable days/times visibly disabled. Must pass QG-A11Y + QG-PERF.

### Booking confirmation `/{tenant}/book/confirmed/{token}` (🎟️)
- **Elements:** success message, appointment summary, manage/cancel link, "add to
  calendar" (stretch).
- **Visual:** reassuring confirmation card with all details.

---

## Appointment management (🏢) — Epic 07

### Appointments list `/{tenant}/appointments`
- **Elements:** filters (staff, service, date range), table (time, customer,
  service, staff, status badge), row actions (view, reschedule, cancel),
  "New appointment" action.
- **Visual:** dense-but-readable table; status badges; sticky filters.

### Calendar / day view `/{tenant}/calendar`
- **Elements:** day (and optionally week) view with appointments as colored
  blocks per staff column; click to view/edit.
- **Visual:** time-grid with staff columns, service colors, current-time marker;
  legible on desktop, simplified on mobile.

### Appointment detail / edit (modal/drawer)
- **Elements:** customer info, service/staff/time, status control (validated
  transitions), reschedule, cancel (with customer-email note).

---

## Customer self-service (🎟️) — Epic 08

### Manage appointment `/manage/{token}`
- **Elements:** appointment summary, cancel button (disabled past cut-off with
  reason), reschedule to another slot.
- **Visual:** single focused card, no app chrome; clear primary/destructive
  actions; works without login.

---

## Admin dashboard & onboarding (🏢) — Epic 09

### Dashboard `/{tenant}/dashboard`
- **Elements:** today's appointments, upcoming count, recent bookings, per-staff
  load; quick links.
- **Onboarding (new tenant):** a checklist card (Add staff → Add service → Set
  availability → Share booking link) replacing metrics until setup is complete,
  with a copyable public booking link.
- **Visual:** metric cards on top, recent activity list below; onboarding is
  friendly and guided with one clear next step at a time.

---

## System

### Health check `/health` (🌐, machine) — Epic 00
- **Response:** JSON `{status, database, time}`; 200 healthy / 503 if DB down.
  Not a designed page.

---

## Page → gate coverage

QG-A11Y / QG-PERF are checked by different mechanisms depending on whether the
tooling can reach the page (see [quality-gates.md](quality-gates.md) and
[test-plan.md](test-plan.md) §Accessibility & performance per page):

- **Tool gate** = pa11y-ci + Lighthouse over `PUBLIC_URLS` (public URLs only).
- **E2E axe** = Pest 4 `assertNoAccessibilityIssues()` (bundled axe-core) inside
  the logged-in/tokened browser tests.
- **Query/perf** = N+1 / query-count tests + Performance review (NFR-PERF).

| Page group | Reach | a11y (QG-A11Y) | perf (QG-PERF) | Notes |
|------------|-------|----------------|----------------|-------|
| Public marketing/legal | public | Tool gate | Tool gate | Add each URL to `PUBLIC_URLS`. |
| Auth | public | Tool gate | Tool gate | Restyled starter pages; in `PUBLIC_URLS`. |
| Booking page + confirmation | public | Tool gate | Tool gate | Highest-traffic public flow; in `PUBLIC_URLS`. |
| Manage (tokened) | tokened | Tool gate via **seeded demo token** URL + E2E axe | Tool gate (seeded URL) | Stable demo token from the Epic 09 seeder. |
| Dashboard, lists, calendar, settings, tenant pages | authenticated | E2E axe | Query/perf (N+1 on lists/calendar/dashboard) | Cannot be reached by pa11y/Lighthouse; checked via E2E + review. |
