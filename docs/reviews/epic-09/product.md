# Review Report — Product Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard metrics + onboarding checklist replacing the placeholder dashboard, demo seeder extension with sample data and demo owner login, README setup paragraph, PUBLIC_URLS gate wiring)
- **Requirements/rules in scope:** FR-DASH-1, FR-DASH-2, FR-OPS-3, AC-1..AC-4, pages.md §Admin dashboard

## Files reviewed

- `resources/views/pages/dashboard/⚡index.blade.php` — the new dashboard SFC: metrics, onboarding checklist, quick links, preserved invitations modal + AC-7 staff-link callout
- `database/seeders/DemoSeeder.php` — demo tenant, staff, services, availability, ~25 sample appointments, deterministic token appointment, demo owner
- `database/seeders/DatabaseSeeder.php` — calls DemoSeeder, idempotent test-user guard
- `Makefile:18,45-50` — `make setup` runs `php artisan db:seed --force`; `/demo-clinic` and `/manage/demo-manage-token` in PUBLIC_PATHS
- `README.md:13-26` — setup paragraph documenting seeding and demo credentials
- `docs/assumptions.md` §Tokens — demo owner credentials documented as intentionally non-secret
- `resources/views/marketing/home.blade.php:22` — homepage secondary CTA (checked against the Epic 01 assumption that promised a swap in Epic 09)

## Flows reviewed

- New-tenant landing — onboarding checklist with four steps, one highlighted current step, step links to staff/services/availability pages, copyable booking link with aria-live confirmation
- Step progression — staff created ticks step 1 and moves "current" to services; service ticks step 2; availability rule completes setup and swaps in the metrics
- Complete-tenant landing — today count, upcoming (7-day) count, per-staff load bars, today list with times in tenant timezone, recent bookings, quick links incl. the public booking page
- Reviewer exploration after `make setup` — seeded `/demo-clinic` booking page with open slots (DemoSeederTest proves the slot engine returns slots for a demo service), demo owner login, stable `/manage/demo-manage-token`
- Staff-role user without staff record — AC-7 callout preserved on the new dashboard

## Tests reviewed

- `tests/Feature/Dashboard/OnboardingTest.php` (6 tests) — checklist shown to a brand-new tenant, steps tick off in order, current-step highlighting, copy button + booking URL, metrics replace the checklist once complete, quick links resolve
- `tests/Feature/Dashboard/DashboardMetricsTest.php` (7 tests) — metric correctness incl. tenant-timezone day boundary (Pacific/Auckland), reserving-only statuses, 7-day window edges, recent ordering, per-staff grouping, staff-role access
- `tests/Feature/Ops/DemoSeederTest.php` (5 tests) — double-seed idempotency, explorable data spread, genuinely bookable demo service via `GetBookableSlots`, deterministic token appointment, demo owner `Auth::attempt`
- `tests/Feature/DashboardTest.php` — guest redirect, AC-7 staff-link callout shown/hidden per role and link state
- `tests/Browser/DashboardSmokeTest.php` (2 tests) — both dashboard states render in a real browser without JS errors

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 462/462, 1469 assertions (fresh run) |
| `php artisan test tests/Feature/Dashboard tests/Feature/Ops/DemoSeederTest.php` | pass | 18/18, 69 assertions |
| `make e2e` | pass | 35/35 browser tests incl. dashboard smoke |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1 DashboardMetricsTest (timezone window, reserving-only, query no-growth); AC-2 OnboardingTest (all transitions); AC-3 DemoSeederTest (bookable via slot engine, demo owner login) + Makefile:50; AC-4 DashboardSmokeTest axe + PUBLIC_PATHS (Makefile:18) |
| 2 | MUST requirements | ✅ | FR-DASH-1 (MUST): today count + list, upcoming count, recent bookings, per-staff load all present and tested; FR-DASH-2/FR-OPS-3 (SHOULD) met |
| 3 | Pages present | ✅ | `/{tenant}/dashboard` matches pages.md §Admin dashboard: metric cards on top, activity lists below, onboarding card replacing metrics, copyable booking link, quick links; route name `dashboard` unchanged (routes/web.php:32) |
| 4 | Happy path works | ✅ | DashboardSmokeTest both states; DemoSeederTest slot-engine proof + BookingSmokeTest cover the reviewer's end-to-end booking after `make setup` |
| 5 | Validation & errors | ✅ | Read-only page; copy action degrades gracefully (`navigator.clipboard?.…catch`) and confirms via aria-live |
| 6 | Empty / loading / error states | ✅ | Onboarding card for brand-new tenants; "No appointments today…", "No bookings yet…", "No bookable staff yet." empty states with next-step copy |
| 7 | Copy | ✅ | Action-oriented ("Set up your booking page", "Copy link"); no em-dashes (grep clean); consistent glossary terms |
| 8 | Navigation & links | ⚠️ | All dashboard links are named routes carrying the slug (OnboardingTest asserts each); but the homepage demo CTA promised for this epic was not swapped (F1) |
| 9 | Scope discipline | ✅ | No charts/analytics sneaked in; sample-data design decisions documented in seeder PHPDoc and assumptions.md |
| 10 | Onboarding / discoverability | ✅ | FR-DASH-2: one clear current step at a time, direct action buttons, share-link step always visible as the final step |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | FR-PUBLIC-1 / assumptions accuracy | `resources/views/marketing/home.blade.php:22`, `docs/assumptions.md` §Public site | assumptions.md states the homepage secondary CTA was "Swapped to the seeded demo booking URL in Epic 09", but the CTA still links to `route('docs')#booking`. The link does not 404, so no flow is broken, but a documented Epic 09 commitment is unfulfilled and the assumptions log is now inaccurate | Defer (track for Epic 10): swap the CTA to `route('booking.show', ['tenant' => 'demo-clinic'])` (the demo tenant only exists after seeding, so keep the docs link as fallback or accept seeding as a setup precondition), or correct the assumptions entry |
| F2 | Low | FR-DASH-1 | `⚡index.blade.php:231` (`staffLoad`) | Per-staff load lists only `bookable()` staff; upcoming appointments assigned to a deactivated staff member count toward the upcoming metric but appear in no load row. Defensible (load = bookable staff), slightly inconsistent totals possible | Defer: note in assumptions or include inactive staff with a marker if reviewers object in Epic 10 |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all four ACs and the FR-DASH-1 MUST are implemented and demonstrable with passing tests and browser evidence; the two findings are a documentation/link inconsistency and a minor metric-presentation nuance, not broken flows.
- Blocking findings remaining: 0
