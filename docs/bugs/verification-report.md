# LibreNexus Chrome QA Verification Report

**Date:** 2026-06-11
**Tester:** verifier agent
**Environment:** Chrome (Playwright), macOS, Laravel Herd at http://librenexus.test
**Login fixture:** demo@librenexus.test / password

---

## Coverage Summary

| Area | Result | Notes |
|---|---|---|
| Marketing pages (home, features, pricing, docs) | PASS | No console errors |
| Public booking - specific staff | PASS | Full 5-step flow completed |
| Public booking - "Any available" | PASS | Staff assignment works |
| Manage page - reschedule | PASS | Time slot changes persist |
| Manage page - cancel | PASS | Status transitions to Cancelled |
| Manage page - forged token | PASS | Returns 404 |
| Auth - unverified email gate | PASS | Redirects to verify-email |
| Auth - non-existent team slug | PASS | Returns 404 |
| Auth - public slug redirect | PASS | /demo-clinic redirects to booking |
| Dashboard | PASS | Stats, per-staff chart, upcoming list render |
| Staff - create | PASS | New staff appears in list |
| Staff - availability (weekly rules) | PASS | Day rules add and persist |
| Staff - time off | PASS | Date range entry and save |
| Staff - edit modal | PASS | Fields populate; save works |
| Staff - deactivate | PASS | Staff removed from active list |
| Services - create | PASS | Service appears in list |
| Services - archive | **PASS** | Fix verified (BUG-001) |
| Appointments list - filters | PASS | Status and date range filters work |
| Appointments list - pagination | PASS | Page 2 loads correctly |
| Appointments list - manual create | PASS | New appointment appears |
| Appointments list - reschedule (admin) | PASS | Reschedule modal works |
| Appointments list - status transitions | PASS | Pending -> Confirmed -> Completed |
| Calendar day view | **PASS** | Fix verified (BUG-002) |
| Tenant settings - booking policy | PASS | Buffer/max-advance save |
| Tenant settings - members list | PASS | Member roles visible |
| Tenant settings - invite member | PASS | Invite sent (mail queued) |
| Team switcher | PASS | Menu opens; navigates between teams |
| User settings - profile | PASS | Name save with toast confirmation |
| User settings - security (password confirm gate) | PASS | Redirects to confirm-password; unlocks after confirm |
| User settings - security page | PASS | Password form, 2FA, Passkeys sections present |
| User settings - appearance | PASS | Light/Dark/System radio; selection persists |
| Auth - CSRF / forged POST | PASS | 419 response; no data change |
| Mobile 390x844 - booking page | PASS | Layout correct; no overflow |
| Mobile 390x844 - dashboard | PASS | Cards stack; hamburger visible |

**Total areas checked:** 33
**Passed:** 33
**Failed:** 0

---

## Bug Index

| ID | Severity | Title | Status |
|---|---|---|---|
| BUG-001 | High | Service archive confirmation modal never opens | Verified fixed |
| BUG-002 | High | Calendar day view grid renders at 0x0 pixels | Verified fixed |

---

## Re-verification Summary (2026-06-11)

Both bugs filed in the initial pass were fixed and re-verified in Chrome:

**BUG-001**: Created a new service "BUG001 Reverify Service", clicked its archive button - the confirmation modal opened immediately. Confirmed archive - "Service archived." toast, service removed from active list. Same fix was confirmed on the staff deactivate flow (created "BUG001 Reverify Staff", deactivate modal opened, confirmed deactivation - "Staff member deactivated." toast, row status changed to Inactive).

**BUG-002**: Measured calendar grid at 1280px viewport - 930x1040 px with 4 appointment blocks visible. Screenshot confirmed time ruler, now-marker, and coloured appointment blocks. Day navigation (next + previous) kept grid at 930x1040. Mobile 390x844 correctly shows a list view (desktop grid is hidden at small widths).

---

## Notable Non-Bugs

- **Queued mail not delivered** (invitation email, manage-page link email): expected - no queue worker running in development.
- **Passkeys "not supported in this browser"**: expected in Playwright headless Chrome.
- **2FA enable flow not exercised**: intentionally skipped to avoid locking the demo account out.
- **Delete account button present but not tested**: destructive action; excluded from automated QA pass.
