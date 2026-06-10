# Epic 02 — Authentication & accounts

## Goal

Deliver secure account lifecycle: registration, login/logout, password reset,
email verification, optional 2FA/passkeys, and user settings — building on the
Fortify + Livewire scaffolding already present.

## Requirements covered

FR-AUTH-1 … FR-AUTH-6, FR-SETTINGS-1, FR-SETTINGS-2.

## In scope

- Registration, login, logout, "remember me".
- Forgot/reset password via emailed link.
- Email verification gate before tenant management.
- Two-factor authentication (TOTP) and passkeys remain functional (already
  scaffolded; keep green).
- Rate limiting for login **and** password-reset requests (SEC-RATE-1); public
  booking throttling is owned by Epic 06.
- Settings: profile (name, email with re-verification on change), password
  change, appearance, account deletion.

## Out of scope

Tenant creation and roles (Epic 03). Social login.

## Acceptance criteria

- **AC-1** A visitor can register; password rules (SEC-AUTH) enforced
  server-side; duplicate email rejected.
- **AC-2** A user can log in, stay logged in with remember-me, and log out.
- **AC-3** Password reset works end to end via a tokened email link; tokens
  expire and are single-use.
- **AC-4** The email-verification gate (middleware) is in place: unverified users
  are redirected to a verification prompt and the verification link verifies and
  is single-use. The gate is applied to the `verified` route group; it protects
  the tenant-management screens once they exist in Epic 03 (a placeholder
  verified route is used here to test the gate).
- **AC-5** After N failed logins, and on repeated password-reset requests, the
  endpoint is throttled (SEC-RATE-1) with a clear message; covered by tests.
- **AC-6** Changing email re-triggers verification; deleting the account removes
  the user and all data they own at this stage. (No tenants exist yet — the
  sole-owner deletion block and personal-tenant cleanup of FR-TENANT-10 are added
  in Epic 03 when tenancy lands, and the deletion flow is extended there.)
- **AC-7** 2FA enable/disable/challenge and passkey register/verify flows work
  and are tested.

## Implementation notes

- Use Fortify actions/responses already in `app/`. Do not bypass framework auth.
- All authorization and validation server-side; never trust client state.
- Sessions: secure, http-only, same-site per [../security.md](../security.md).

## Required tests

- Feature tests for register, login (success + invalid), logout, reset,
  verification, throttling.
- Tests for profile update, email-change re-verification, password change,
  account deletion.
- Keep the existing 2FA/passkey tests green; add any missing coverage.
- Accessibility: axe assertions in the auth + settings E2E tests (QG-A11Y for
  authenticated pages, per test-plan.md §Accessibility & performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); SEC-AUTH and SEC-RATE
checks pass; auth coverage meets the per-module target in
[../test-plan.md](../test-plan.md).
