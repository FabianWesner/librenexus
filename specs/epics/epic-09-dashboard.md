# Epic 09 — Admin dashboard & onboarding

## Goal

Give tenant users a useful landing dashboard and guide new tenants through setup
with empty states, plus a demo seeder so the app is explorable immediately.

## Requirements covered

FR-DASH-1, FR-DASH-2, FR-OPS-3.

## In scope

- Dashboard for the active tenant: today's appointments, upcoming count, recent
  bookings, per-staff load.
- Empty-state onboarding: a guided checklist (add staff → add service → set
  availability → share booking link) shown until setup is complete.
- A demo seeder creating a realistic tenant (staff, services, availability,
  sample appointments) for instant exploration after `make setup`, including a
  demo appointment with a **deterministic** manage token so its public
  `/manage/{token}` URL is stable.
- Add the demo tenant's public booking URL and the demo manage-token URL to
  `PUBLIC_URLS` so they are covered by the pa11y/Lighthouse tool gates
  (test-plan.md §Accessibility & performance per page).

## Out of scope

Advanced analytics/charts (stretch goal).

## Acceptance criteria

- **AC-1** Dashboard shows correct, tenant-scoped figures; queries free of N+1
  (NFR-PERF).
- **AC-2** A brand-new tenant sees the onboarding checklist; completed steps tick
  off; once complete the normal dashboard shows.
- **AC-3** `php artisan db:seed` (run by `make setup`) creates a demo tenant that
  lets a reviewer book an appointment end to end without manual data entry.
- **AC-4** Dashboard meets QG-A11Y via axe in its browser (E2E) test and QG-PERF
  via the N+1/query-count check (it is an authenticated page; see test-plan.md
  §Accessibility & performance per page). The public demo booking and
  manage-token URLs are in `PUBLIC_URLS` and pass the pa11y/Lighthouse gates.

## Implementation notes

- Aggregate dashboard metrics with efficient queries (counts/group-by), not
  per-row loops.
- The seeder must be idempotent-enough for repeated local runs and must not run
  destructive operations on a populated production DB.

## Required tests

- Dashboard metric-correctness tests with seeded data.
- Onboarding-state tests (incomplete vs complete tenant).
- Seeder test asserting a bookable demo tenant + deterministic demo manage token
  exist after seeding.
- Accessibility: axe assertions in the dashboard E2E test; N+1 check on the
  dashboard query; public demo URLs confirmed in `PUBLIC_URLS`.

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); QG-A11Y and QG-PERF
green for the dashboard.
