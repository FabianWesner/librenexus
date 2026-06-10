# LibreNexus — Security Rules

Security rules are checklist-driven and verified by the **Security reviewer**
([review-checklists/security-reviewer.md](review-checklists/security-reviewer.md)).
The guiding principle: **protection is enforced server-side, never only in the
UI.** Each rule has an ID, a statement, and how it is verified (automated test
and/or tool gate from [quality-gates.md](quality-gates.md)).

For a multi-tenant system, **tenant isolation (SEC-TENANT) is the most critical
rule** and must be proven by automated tests.

## SEC-AUTH — Authentication

- **SEC-AUTH-1** Passwords are hashed with bcrypt/argon (framework default);
  never stored or logged in plaintext.
- **SEC-AUTH-2** Password policy enforced server-side: minimum length (≥ 8),
  rejected against a common-password/compromised check where available.
- **SEC-AUTH-3** Email verification required before tenant management (FR-AUTH-4).
- **SEC-AUTH-4** Password reset and email-verification links are tokened,
  single-use, and time-limited.
- **SEC-AUTH-5** 2FA (TOTP) and passkeys remain functional and tested.
- *Verified by:* auth feature tests, QG-SECRETS (no leaked secrets), Security
  review.

## SEC-AUTHZ — Authorization

- **SEC-AUTHZ-1** Every state-changing action and every data read is authorized
  server-side via policies/gates; UI hiding is cosmetic only.
- **SEC-AUTHZ-2** Roles owner/admin/staff grant exactly the permissions in
  FR-TENANT-5; staff cannot manage other staff/services or others' appointments.
- **SEC-AUTHZ-3** Direct object references (IDs in URLs/forms) are authorized;
  guessing another record's ID does not grant access.
- *Verified by:* per-role authorization tests, IDOR tests, Security review.

## SEC-TENANT — Tenant isolation (critical)

- **SEC-TENANT-1** Every tenant-owned model is scoped so a user only accesses
  data of tenants they are a member of.
- **SEC-TENANT-2** Cross-tenant access is impossible via route, URL ID, form
  field, query parameter, or relationship traversal.
- **SEC-TENANT-3** The scoping mechanism is centralized (global scope / base
  query) so new models cannot accidentally leak; an `arch()`/scope test enforces
  that tenant-owned models opt in.
- **SEC-TENANT-4** Non-member access to a tenant resource returns 403/404
  (consistent, documented) and never leaks data or existence beyond that choice.
- *Verified by:* the named isolation suite
  `tests/Feature/Tenancy/IsolationTest.php` (Epic 03), extended for every
  tenant-owned model in later epics; Security review.

## SEC-INPUT — Input validation & injection prevention

- **SEC-INPUT-1** All input is validated server-side with Form Requests / rules;
  never trust client-provided IDs, prices, durations, or slot availability.
- **SEC-INPUT-2** Use Eloquent/the query builder with bindings; no raw string-
  interpolated SQL. Any raw expression uses parameter bindings.
- **SEC-INPUT-3** Output is escaped by Blade by default; `{!! !!}` is forbidden
  for user-controlled content.
- **SEC-INPUT-4** Mass assignment is controlled (explicit `fillable`/validated
  DTOs); no blanket `guarded = []` on tenant data.
- *Verified by:* validation tests, QG-SAST (Semgrep), Security review.

## SEC-TOKEN — Customer tokens

- **SEC-TOKEN-1** Cancellation/manage tokens are ≥ 32 bytes of CSPRNG entropy,
  stored hashed, compared in constant time, and scoped to a single appointment.
- **SEC-TOKEN-2** Raw tokens never appear in logs or analytics.
- **SEC-TOKEN-3** A token grants only view/cancel/reschedule of its one
  appointment — no tenant or cross-customer data.
- *Verified by:* token security tests (Epic 08), Security review.

## SEC-SECRETS — Secrets management

- **SEC-SECRETS-1** No secrets in the repository; configuration via environment.
  `.env` is git-ignored; `.env.example` holds non-secret defaults.
- **SEC-SECRETS-2** `APP_KEY` and tokens are environment-provided in production.
- *Verified by:* QG-SECRETS (gitleaks).

## SEC-DEPS — Dependency security

- **SEC-DEPS-1** No dependencies with known high/critical advisories.
- **SEC-DEPS-2** An SBOM is published; lockfiles are committed.
- *Verified by:* QG-DEPS-VULN (`composer audit`, `npm audit`, osv-scanner), SBOM.

## SEC-HEADERS — Secure headers & transport

- **SEC-HEADERS-1** Security headers set: `X-Content-Type-Options: nosniff`,
  a restrictive `Referrer-Policy`, `X-Frame-Options`/frame-ancestors, and a
  Content-Security-Policy appropriate to a server-rendered Blade/Livewire app.
- **SEC-HEADERS-2** Cookies are `Secure` (in prod), `HttpOnly`, and `SameSite`.
- **SEC-HEADERS-3** HTTPS assumed in production; no mixed content.
- *Verified by:* a feature test asserting key response headers; Security review.

## SEC-SESSION — Session security

- **SEC-SESSION-1** Session fixation prevented (regenerate on login).
- **SEC-SESSION-2** CSRF protection on all state-changing requests (framework
  default; not disabled).
- **SEC-SESSION-3** Logout invalidates the session.
- *Verified by:* auth tests, Security review.

## SEC-RATE — Rate limiting

- **SEC-RATE-1** Login, password-reset, and public booking endpoints are
  rate-limited to resist brute force and abuse.
- **SEC-RATE-2** Limits return clear, non-leaky errors and do not break
  accessibility.
- *Verified by:* throttling tests (Epic 02, Epic 06), Security review.

## SEC-LOG — Logging & error handling

- **SEC-LOG-1** No secrets, tokens, passwords, or full PII in logs.
- **SEC-LOG-2** Errors shown to users are generic; stack traces never leak in
  production responses.
- **SEC-LOG-3** Structured logs carry a correlation ID (NFR-OBS) but not
  sensitive payloads.
- *Verified by:* log-content review, error-response test, Security review.

## SEC-UPLOAD — File upload safety

- **SEC-UPLOAD-1** v1 has no user file uploads. If added (e.g. tenant logo for a
  stretch goal): validate MIME/type and size, store outside the webroot or on a
  disk, and never execute uploaded content.
- *Verified by:* upload tests if the feature exists; otherwise documented as
  not-applicable.

---

## Verification summary

| Area | Primary automated proof |
|------|-------------------------|
| Tenant isolation | `tests/Feature/Tenancy/IsolationTest.php` (+ per-model extensions) |
| Authorization / IDOR | per-role + ID-guessing feature tests |
| Input/injection | validation tests + QG-SAST (Semgrep) |
| Tokens | token security tests |
| Secrets | QG-SECRETS (gitleaks) |
| Dependencies | QG-DEPS-VULN (`composer audit`, `npm audit`, osv-scanner) + SBOM |
| Headers/session/CSRF | header + session feature tests |
| Rate limiting | throttling tests |

Any rule that cannot be checked automatically must be verified in the Security
review and noted honestly in the final quality report.
