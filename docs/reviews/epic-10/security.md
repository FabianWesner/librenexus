# Review Report - Security Reviewer - Epic 10 (Hardening & quality report / final application state)

> Final-state review. Tenant isolation and token security re-verified by
> running the named suites; scanner outputs re-checked from `reports/`;
> per-epic security reviews (epic-00..09, all pass) relied on for unchanged
> areas.

## Reviewed scope

- **Epic / change:** Epic 10 hardening + final application state
- **Requirements/rules in scope:** SEC-TENANT-*, SEC-AUTHZ-*, SEC-AUTH-*,
  SEC-INPUT-*, SEC-TOKEN-*, SEC-SECRETS-*, SEC-DEPS-*, SEC-HEADERS-*,
  SEC-SESSION-*, SEC-RATE-*, SEC-LOG-*, SEC-UPLOAD (n/a)

## Files reviewed

- `app/Http/Middleware/SetSecurityHeaders.php` - CSP re-verification comment
  (Epic 10: dropping `unsafe-eval` fails 27/35 browser tests), nosniff,
  referrer policy, frame denial
- `config/auth.php:116` + `app/Providers/AppServiceProvider.php:66-71` -
  config-driven strict password policy (pwned check in strict mode), the
  Epic 02 deferral closed with a config-forced strict registration test
- `resources/views/pages/booking/⚡show.blade.php:175,338-343` - confirm
  throttle 10/min/IP unchanged; new step throttle 60/min/IP (Epic 06 deferral
  closed)
- `app/Models/TeamInvitation.php` - 64-char random single-use codes, 7-day
  expiry; stored plaintext (tracked Low)
- `app/Models/Appointment.php` - `findByManageToken` hash lookup unchanged
- `reports/gitleaks.json`, `reports/semgrep.json` - parsed: 0 findings each
- `database/seeders/DemoSeeder.php` posture per Epic 09 review (refuses
  production; non-secret demo credentials documented)

## Flows reviewed

- Cross-tenant access on every vector (route, URL ID, form field, query,
  relationship, create-time `team_id` spoof) - isolation suite re-run
- Booking concurrency including in-flight uncommitted conflicts - concurrency
  suite re-run
- Session fixation - session id asserted to change across login
- Invitation decline - wrong recipient rejected, decline never joins the team

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php` - re-run green (part of 40/40)
- `tests/Feature/Booking/ConcurrencyTest.php` - re-run green (part of 40/40)
- `tests/Feature/Auth/AuthHardeningTest.php:67` - session id regenerated on
  login (Epic 02 deferral closed)
- `tests/Feature/Teams/TeamInvitationTest.php:159,185` - decline coverage
  (Epic 03 deferral closed)
- `tests/Feature/SelfService/TokenSecurityTest.php` - forged/cross-tenant
  manage tokens 404 (green in the 469-test verify run)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `pest IsolationTest + ConcurrencyTest` | pass | 40 tests, 91 assertions (this review, fresh run) |
| gitleaks (`reports/gitleaks.json`) | pass | 0 findings |
| Semgrep (`reports/semgrep.json`) | pass | 0 results |
| composer/npm audit + osv-scanner | pass | via `/tmp/claude/verify.log` security stage; verify exits `ALL QUALITY GATES PASSED` |
| SBOM | pass | `reports/sbom.cdx.json` present (syft) |
| CI security job (run 27322534001) | **fail** | tool-install step broke at HEAD (gitleaks install.sh 404, `~/.local/bin` missing); scanners never ran in that CI run. Fixed in the uncommitted ci.yml |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Named suite re-run green; fail-closed scope, arch-test enforced; 404 for non-members (documented choice) |
| 2 | Authorization | ✅ | Policies on every mutation per epics 02-09 reviews; IDOR vectors in the isolation suite; role matrix tested |
| 3 | Authentication | ✅ | Bcrypt, config-driven strict policy + pwned check, single-use expiring reset/verify tokens, 2FA + passkeys tested. Note: at HEAD the 2FA challenge **page** 500s (missing view registration, fix uncommitted); this fails closed (no auth bypass) but breaks the 2FA login flow at HEAD |
| 4 | Input & injection | ✅ | Server-side validation everywhere; Semgrep 0; no `{!! !!}` on user content; `team_id` spoof rejected at the scope |
| 5 | Customer tokens | ✅ | 64-char CSPRNG, SHA-256 stored, exact-index lookup, single-appointment scope; forged/cross tokens 404; raw token proven absent from logs by test |
| 6 | Secrets | ✅ | gitleaks 0; `.env` ignored; demo credentials documented non-secret, seeder refuses production |
| 7 | Dependencies | ✅ | audit + osv clean in local verify; SBOM generated. CI counterpart pending the pushed fix |
| 8 | SAST | ✅ | Semgrep 0 findings, no `nosemgrep` markers |
| 9 | Headers & transport | ⚠️ | All headers asserted by test; CSP retains `unsafe-inline`/`unsafe-eval` for the Livewire/Alpine stack, re-verified empirically in Epic 10 and documented (accepted Medium) |
| 10 | Sessions & CSRF | ✅ | Session regeneration asserted (AuthHardeningTest:67); CSRF on mutations; logout invalidates |
| 11 | Rate limiting | ✅ | Login/2FA/reset throttled (Fortify); booking confirm 10/min + step actions 60/min; manage actions 20/min; all with clear messages |
| 12 | Logging & errors | ✅ | Correlation-ID JSON logging (Epic 00); no tokens/PII in logs (tested); generic prod errors |
| 13 | Uploads | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | SEC-HEADERS-2 | SetSecurityHeaders.php:26 | CSP `unsafe-inline`/`unsafe-eval` retained; empirically required by Alpine/Livewire (27/35 browser tests fail without it); documented in code, assumptions log, and quality report | Accepted; revisit when the stack ships CSP-compatible builds |
| F2 | Low | SEC-SESSION / hardening | TeamInvitation.php:43, accept-invitation page mount | Invitation codes stored plaintext and acceptance fires on GET (mount). Mitigations: 64-char CSPRNG, single-use, 7-day expiry, requires a logged-in user with a matching email; worst case an attacker with the link cannot benefit | Accepted Low; optionally hash codes and require a POST confirm |
| F3 | Low | SEC-LOG | Manage capability URLs | Tokens appear in mail bodies and access logs by design (tokened-link pattern); GETs are read-only, entropy makes guessing infeasible; documented in quality report limitation 3 | Accepted |
| F4 | High (cross-ref) | App DoD #2 | CI run 27322534001 | The CI security job at HEAD never executed the scanners (install-step bug) and the 2FA challenge page 500s at HEAD; both fixes are uncommitted. Tracked as the Product report's blocking F1 | Commit/push; confirm a green security job in public CI |

## Required fixes (blocking)

- None owned by this review. F4 was the shared publication blocker
  (product F1); resolved, see re-review below. All SEC-* rules hold on the
  final state, proven by fresh local runs and the green CI security job.

## Re-review after fixes (2026-06-11)

- F4 resolved: the CI install-step fix and the 2FA challenge view
  registration were committed (`a5965ae`) and pushed. CI run 27323217271 on
  main (commit `214272c`) is green, including the
  "security (secrets, sast, audit, osv, sbom)" job, so the scanners now run
  publicly on the default branch (verified via `gh run view 27323217271
  --json conclusion,jobs`).
- F1 (CSP trade-off), F2, and F3 remain documented accepted risks; F2's
  context is unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: tenant isolation and token security re-proven by fresh runs of
  their named suites; all scanners clean on the final tree and now also in
  the public CI security job (run 27323217271); every previously deferred
  security item (password policy, session fixation, step throttles, decline
  coverage, CSP re-check) was genuinely closed in Epic 10. Warnings are the
  documented CSP trade-off and two accepted Low items.
- Blocking findings remaining: 0
