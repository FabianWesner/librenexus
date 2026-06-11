# Review Report - Product - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** FR-BOOK-1..7, FR-CUST-1..4, FR-APPT-4 (status model mechanism), Epic 06 AC-1, AC-1b, AC-2..AC-7, pages.md §Public booking, docs/assumptions.md §Booking

## Files reviewed

- `resources/views/pages/booking/⚡show.blade.php` - the 5-step public booking flow against FR-BOOK-2 and pages.md
- `resources/views/pages/booking/⚡confirmed.blade.php` - confirmation page with manage link (AC-1, FR-BOOK-4)
- `resources/views/pages/booking/⚡manage.blade.php` - view-only manage page (AC-1)
- `resources/views/components/booking/appointment-summary.blade.php` - shared summary copy/details
- `resources/views/layouts/booking.blade.php` - public layout, footer, skip link
- `resources/views/mail/appointments/confirmation.blade.php` - confirmation email content (FR-BOOK-4)
- `app/Actions/Booking/BookAppointment.php` - booking semantics incl. approval mode (FR-BOOK-7) and customer reuse (AC-1b)
- `routes/web.php` - tenant-slug URL (FR-BOOK-1), catch-all precedence
- `database/seeders/DemoSeeder.php` - demo booking page used by the public gates
- `specs/pages.md` §Public booking, `docs/assumptions.md` §Booking - expected elements and recorded decisions

## Flows reviewed

- Public booking happy path: `/{tenant}` -> Service (duration + price) -> Staff or "Any available" -> day picker + start times in tenant timezone ("Times are shown in :timezone time.") -> details (name, email, optional phone, notes) -> confirm summary -> redirect to `/{tenant}/book/confirmed/{token}` -> manage link resolves at `/manage/{token}` with no 404 (AC-1)
- Lost-race flow: another customer takes the slot between selection and confirm -> "This time is no longer available. Please pick another slot.", user returned to the slot step with refreshed days (AC-3 UX side)
- Repeat booking with the same email in the same tenant: customer record reused and name/phone updated; same email in a second tenant: separate record (AC-1b)
- Empty states: tenant with no bookable services shows "Online booking is not available yet."; day without slots shows "No open times on this day."; no open days at all shows "There are no open time slots right now."
- Approval mode (FR-BOOK-7, MAY): `requires_approval` tenant produces a Pending appointment, "Booking request received" confirmation copy, and a "request" email subject

## Tests reviewed

- `tests/Feature/Booking/BookingFlowTest.php` (12) - full happy path incl. mailed manage link, every validation failure persists nothing, lost race, honeypot, throttle, unknown slug 404
- `tests/Feature/Booking/CustomerDedupTest.php` (3) - AC-1b reuse + update, cross-tenant separation, case-insensitivity
- `tests/Feature/Booking/ManageTokenTest.php` (5) - manage page resolves, forged token 404s, confirmation page under own slug only
- `tests/Feature/Booking/PublicRoutingTest.php` (5) - static/auth route precedence over the slug catch-all, reserved names
- `tests/Feature/Booking/ConcurrencyTest.php` - AC-3, AC-5, AC-7 behavior incl. "exactly one wins" and deterministic any-available pick
- `tests/Browser/BookingSmokeTest.php` (2) - real click-through of all five steps to "Booking confirmed" plus the manage page, with axe assertions (AC-6)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 359/359, 1078 assertions |
| `vendor/bin/pest tests/Browser` | pass | 27/27, 98 assertions (booking smoke included) |
| `curl /demo-clinic`, `/manage/demo-manage-token`, `/pricing` | pass | all 200 on the Herd URL |
| Lighthouse artifacts `reports/lighthouse/` (2026-06-11 00:21) | pass | demo-clinic 0.94/1.0/1.0/0.91, manage 0.95/1.0/1.0/0.91 |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ✅ | AC-1 BookingFlowTest happy path + ManageTokenTest; AC-1b CustomerDedupTest; AC-2 page uses `GetBookableSlots` (⚡show.blade.php `timeSlots`/`refreshAvailableDates`); AC-3 ConcurrencyTest; AC-4 validation tests persist nothing; AC-5 "transitioning a held slot ... frees it immediately" (data-level); AC-6 Lighthouse + axe; AC-7 "any-available picks the lowest staff id deterministically" |
| 2 | MUST requirements | ✅ | FR-BOOK-1 slug route; FR-BOOK-2 five steps incl. "Any available"; FR-BOOK-3 DB constraint + suite; FR-BOOK-4 confirmation page + queued mail with manage link; FR-BOOK-5 server-side rules + engine re-validation; FR-CUST-1..3 TenantModel + dedup + token-only access; FR-BOOK-6 SHOULD met (mobile grid, gates); FR-BOOK-7 MAY implemented |
| 3 | Pages present | ✅ | `/{tenant}`, `/{tenant}/book/confirmed/{token}`, `/manage/{token}` all render 200 (tests + curl); elements match pages.md §Public booking |
| 4 | Happy path works | ✅ | BookingFlowTest full flow + BookingSmokeTest browser click-through |
| 5 | Validation & errors | ✅ | Required/format/max failures show field errors; race shows actionable slot message; throttle shows "Too many booking attempts. Please wait a minute and try again." |
| 6 | Empty/loading/error states | ✅ | `booking-empty-state`, `booking-no-slots` states present; wire:submit gives Livewire feedback |
| 7 | Copy | ✅ | Clear, action-oriented; an em-dash grep over `resources/views` finds none |
| 8 | Navigation & links | ✅ | Manage link resolves (no 404); "Powered by" footer in booking layout; confirmation route carries the tenant slug |
| 9 | Scope discipline | ✅ | View-only manage page (cancel/reschedule explicitly deferred to Epic 08 with on-page note); approval mode is a spec'd MAY; "any available" pick documented in assumptions.md §Booking |
| 10 | Onboarding/discoverability | n/a | No new admin-side surface in this epic |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | pages.md §Booking page | `resources/views/pages/booking/⚡show.blade.php` | Steps live in one Livewire page at `/{tenant}`; the parenthetical "steps under `/{tenant}/book/*`" URLs do not exist, so steps are not deep-linkable and browser back leaves the flow (the in-page Back button covers it) | None required for v1; record if a future epic wants step URLs |
| F2 | Low | FR-APPT-4 | UI | Status transition enforcement has no UI yet (by design: admin transitions are Epic 07); only the data-level mechanism ships here | Track to Epic 07 (already in its scope) |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: every in-scope MUST and all seven ACs (plus AC-1b) are demonstrably met with feature, concurrency, and browser evidence; both findings are Low UX/scope notes already covered by later epics.
- Blocking findings remaining: 0
