# Security Reviewer — Checklist

**Verifies against:** [../security.md](../security.md) (SEC-*),
[../quality-gates.md](../quality-gates.md) (security gates).

**Mission:** confirm protection is enforced **server-side**, tenant isolation
holds, and no security gate regresses. Tenant isolation is the highest priority.

## Checklist

1. **Tenant isolation (SEC-TENANT)** — the named isolation suite covers every
   new tenant-owned model; a member of A cannot read/mutate B via route, URL ID,
   form field, query, or relationship; non-member access returns the documented
   403/404. **Run it and cite results.**
2. **Authorization (SEC-AUTHZ)** — every state-changing action and data read is
   policy/gate-checked; role matrix (owner/admin/staff) enforced; IDOR tested
   (guessing IDs fails).
3. **Authentication (SEC-AUTH)** — password hashing, policy, verification gate,
   single-use time-limited reset/verify tokens; 2FA/passkeys intact.
4. **Input & injection (SEC-INPUT)** — server-side validation everywhere; no raw
   interpolated SQL; no `{!! !!}` on user content; mass assignment controlled.
5. **Customer tokens (SEC-TOKEN)** — manage/cancel tokens are high-entropy,
   hashed, constant-time compared, single-appointment scoped; forged/cross
   tokens rejected (tested).
6. **Secrets (SEC-SECRETS)** — `make secrets` clean; no secrets in code; `.env`
   ignored.
7. **Dependencies (SEC-DEPS)** — `make audit` + `make osv` clean; SBOM generated.
8. **SAST** — `make sast` (Semgrep) clean; any `nosemgrep` justified.
9. **Headers & transport (SEC-HEADERS)** — nosniff, referrer-policy, frame
   protection, CSP suitable for Blade/Livewire; cookies Secure/HttpOnly/SameSite
   (asserted by a test).
10. **Sessions & CSRF (SEC-SESSION)** — session regenerated on login, CSRF on all
    mutations, logout invalidates session.
11. **Rate limiting (SEC-RATE)** — login, reset, and public booking throttled;
    tested.
12. **Logging & errors (SEC-LOG)** — no secrets/tokens/PII in logs; generic error
    responses in prod; correlation ID present but no sensitive payloads.
13. **Uploads (SEC-UPLOAD)** — n/a in v1; if added, validated and non-executable.

## Tools to run

`make secrets`, `make sast`, `make audit`, `make osv`, plus the isolation and
token test suites.

## Decision rule

- **Fail (Critical)** for any tenant data leak, missing server-side authz, IDOR,
  injection, secret exposure, or token weakness.
- **Pass with warnings** only for Medium hardening items that are tracked.
- **Pass** when all SEC-* in scope hold and security gates are green.
