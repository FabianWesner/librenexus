# Review Report - Security Reviewer - Epic 07 (Appointment management, admin side)

## Reviewed scope

- **Epic / change:** Epic 07 (appointment views, manual write actions, status lifecycle, cancellation mail)
- **Requirements/rules in scope:** SEC-TENANT-1..4, SEC-AUTHZ-1..3, SEC-INPUT, SEC-TOKEN (reschedule interaction), SEC-SECRETS, SEC-DEPS, SAST, SEC-LOG

## Files reviewed

- `app/Policies/AppointmentPolicy.php` - viewAny/view/create/update abilities, role matrix
- `resources/views/pages/appointments/⚡index.blade.php` - `Gate::authorize` on mount + every action; `#[Locked]` IDs; query-level staff restriction; `ensureOwnStaffRecord`
- `resources/views/pages/appointments/⚡calendar.blade.php` - viewAny on mount, view on detail, `visibleStaff()` restriction
- `app/Actions/Appointments/TransitionAppointmentStatus.php`, `RescheduleAppointment.php` - server-side matrix/terminal enforcement
- `app/Models/Appointment.php` - tenant scope (TenantModel), hashed token, scoped queries
- `app/Mail/AppointmentCancellationMail.php` - scalar capture, no token in the mail
- `app/Actions/Booking/BookAppointment.php` - token generation unchanged (48 random alnum + 16 hex, SHA-256 stored)

## Flows reviewed

- Cross-tenant access: route slug, Livewire mount, and direct appointment IDs from another tenant (read + mutate)
- Intra-tenant IDOR: staff-role member targeting another staff member's appointment via `openDetail`/`transitionStatus`/`openRescheduleModal`/`openCancelModal`
- Filter widening: `staffFilter` is only applied when `canManage`; the own-staff `where` is unconditional for non-managers, so a staff-role member cannot widen visibility via the URL parameter (`⚡index.blade.php:331-332`)
- Create restriction: staff role can only create for the linked record (`ensureOwnStaffRecord`, validated against tenant-scoped `Rule::exists`)
- Reschedule + token: same row updated, `cancellation_token_hash` untouched, no re-issue, no token logged or rendered

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php::appointment management isolation (Epic 07)` - 4 new tests: 404 on tenant B pages, forbidden mounts, ModelNotFound on view/transition/reschedule/cancel of tenant B IDs **with data-unchanged assertions**, empty list/calendar queries
- `tests/Feature/Appointments/ManualBookingTest.php` - staff-role 403 on all four actions against a colleague's appointment (data + mail unchanged); staff cannot create for another record; unlinked member forbidden from the create form
- `tests/Feature/Appointments/AppointmentViewsTest.php` - server-proof visibility via `appointments->modelKeys()` (query-level, not DOM-level)
- `tests/Feature/Appointments/StatusTransitionTest.php` - invalid transitions rejected server-side regardless of UI
- `tests/Feature/Appointments/ManualBookingTest.php::rescheduling moves the appointment atomically` - token hash unchanged after reschedule

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` (incl. isolation + concurrency suites) | pass | 407/407, 1253 assertions |
| `make secrets` | pass | gitleaks: no leaks found (14 MB scanned) |
| `make sast` | pass | Semgrep p/php + p/security-audit: 46 rules, 364 files, 0 findings, no nosemgrep |
| `composer audit` / `npm audit --audit-level=high` | pass | 0 advisories / 0 vulnerabilities |
| `make osv` | pass | 177 composer + 447 npm packages, no issues |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Appointment is a `TenantModel`; isolation suite extended with the Epic 07 block and ran green; cross-tenant reads/mutations yield 404/ModelNotFound and leave data unchanged (IsolationTest:412-481) |
| 2 | Authorization | ✅ | Every action re-fetches and authorizes (`Gate::authorize('view'/'update'/'create')`); role matrix per FR-TENANT-5 (admin/owner all, staff own-linked-record only); the UI hiding of buttons is cosmetic on top of these checks |
| 3 | Authentication | ✅ | Routes under `auth` + `verified` + `EnsureTeamMembership` (routes/web.php:29-38); no auth surface changed |
| 4 | Input & injection | ✅ | All create inputs validated server-side with tenant-scoped `exists` rules; status arrives as enum `tryFrom` with rejection; dates regex-validated before parsing; no raw SQL, no `{!! !!}` in any new view |
| 5 | Customer tokens | ✅ | Unchanged generation/storage; reschedule keeps the hash (tested); cancellation mail contains no manage token |
| 6 | Secrets | ✅ | gitleaks clean; no secrets in new code |
| 7 | Dependencies | ✅ | composer/npm audit + OSV clean; no dependency changes in this epic |
| 8 | SAST | ✅ | Semgrep 0 findings, no suppressions |
| 9 | Headers & transport | n/a | Unchanged this epic (Epic 00 middleware + tests still in suite) |
| 10 | Sessions & CSRF | ✅ | Livewire actions carry CSRF by framework; no session-flow changes |
| 11 | Rate limiting | n/a | No new public endpoints; admin actions are behind auth (booking throttle unchanged) |
| 12 | Logging & errors | ✅ | No tokens/PII logged by new code; constraint violations are translated to a generic "slot no longer available" message, raw 23P01 details never reach the user |
| 13 | Uploads | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | SEC-AUTHZ-1 (defense in depth) | `⚡index.blade.php::ensureOwnStaffRecord` | The staff-role "create only for your own record" rule is enforced in the component, not the policy, because `create()` has no target model. It is documented, validated against tenant-scoped input, and tested, but lives one layer further out than the other checks | Acceptable; if a second create surface appears (e.g. calendar quick-create), move the check into a dedicated gate/ability so it cannot be forgotten |
| F2 | Low | SEC-AUTHZ-2 (test depth) | `tests/Feature/Appointments/AppointmentViewsTest.php` | No explicit test sets `staffFilter` to a foreign staff id as a staff-role member. The code makes widening impossible (the filter clause requires `canManage`), but the regression is unguarded | Add a one-line test in the QA follow-up (see QA review F4) |

## Required fixes (blocking)

- None.

## Re-review after fixes (2026-06-11)

The QA blocking fixes (23505 retry tests, detail-modal axe test) do not change
the security surface; re-verified by reading them. The only production change
is `BookAppointment::attempt()` widening from private to protected as a test
seam (`BookAppointment.php:51`): it gains no new production caller and sits
behind the same policy-gated entry points. The new browser test exercises the
policy-checked `openDetail` flow. Full suite re-ran green: `make test` 410/410
(isolation + concurrency suites included), `make e2e` 31/31. F1/F2 remain Low
and tracked (F2 via QA review F4). Decision unchanged.

## Final decision

**PASS**

- Rationale: tenant isolation and the role matrix are enforced server-side on every read and mutation, proven by the extended isolation suite plus intra-tenant IDOR tests with data-unchanged assertions; all security tool gates ran clean in this review.
- Blocking findings remaining: 0
