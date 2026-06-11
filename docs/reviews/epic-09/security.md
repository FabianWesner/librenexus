# Review Report — Security Reviewer — Epic 09 (Admin dashboard & onboarding)

## Reviewed scope

- **Epic / change:** Epic 09 (dashboard SFC, demo seeder with fixed manage token + demo owner credentials, public gate URLs)
- **Requirements/rules in scope:** SEC-TENANT-*, SEC-AUTHZ-*, SEC-TOKEN-*, SEC-SECRETS-*, SEC-DEPS-*, SEC-HEADERS/SESSION/RATE (regression only)

## Files reviewed

- `resources/views/pages/dashboard/⚡index.blade.php` — tenant scoping of every metric query, `#[Locked]` team, no raw output of user content
- `database/seeders/DemoSeeder.php` — fixed `demo-manage-token` (hash-stored), demo owner `demo@librenexus.test`/`password`, explicit `team_id` keys on all writes
- `database/seeders/DatabaseSeeder.php` — also seeds `test@example.com` (factory default password), unguarded for environment
- `docs/assumptions.md` §Tokens — documented intentionally non-secret demo credentials
- `app/Models/Appointment.php:104` — `findByManageToken` (hash lookup, raw token never stored), reused unchanged
- `routes/web.php` — dashboard inside `auth` + tenant-membership middleware group
- `.gitleaks.toml` — no new allowlist entries added for the demo data (none needed; scan clean)

## Flows reviewed

- Cross-tenant dashboard access — member of A requesting B's dashboard, and non-member access (404, not 403, per the documented choice)
- Metric data isolation — every count/list/aggregate runs on `BelongsToTenant`-scoped models with the tenant context set by middleware; no `withoutGlobalScopes` in the page
- Demo manage token — fixed token stored only as SHA-256 hash; resolves to exactly one appointment; forged-token rejection covered by the existing token suite
- Seeding on a populated DB — `firstOrCreate`/`updateOrCreate` only; no truncation/deletion anywhere in the seeder

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php:41` — tenant A member gets 404 on tenant B's dashboard (fresh run: tenancy suite 87/87)
- `tests/Feature/SelfService/TokenSecurityTest.php` — forged/tampered manage tokens 404 (part of the fresh full run)
- `tests/Feature/Ops/DemoSeederTest.php:99` — token appointment matched by hash via `findByManageToken`; `Auth::attempt` proves the demo credential is a normal hashed password
- `tests/Feature/DashboardTest.php:8` — guests redirected to login
- `tests/Feature/Dashboard/DashboardMetricsTest.php:145` — staff-role member sees tenant-wide figures (intended per FR-DASH-1; read access matrix unchanged)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` | pass | gitleaks: no leaks found (demo credentials are not flagged; they are plain fixture strings) |
| `make sast` | pass | Semgrep: 0 findings |
| `make audit` | pass | npm audit: 0 vulnerabilities |
| `make osv` | pass | osv-scanner: no issues |
| `php artisan test tests/Feature/Tenancy` | pass | 87/87 isolation/ownership/role suites |
| `make test` | pass | 462/462 incl. token security suite |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Isolation suite 87/87 fresh; dashboard 404 cross-tenant; all metric queries globally scoped; no new tenant-owned models |
| 2 | Authorization | ✅ | Read-only page gated by auth + membership middleware; no state-changing actions added; role read-access intentional and tested |
| 3 | Authentication | ✅ | Demo owner is a standard `Hash::make` user; verification timestamp set; no auth-path changes |
| 4 | Input & injection | ✅ | No user input on the page; no raw SQL beyond a constant `selectRaw('staff_id, count(*) …')` (⚡index.blade.php:226, no interpolation); no `{!! !!}` |
| 5 | Customer tokens | ✅ | Real tokens unchanged (high-entropy, hashed); the fixed demo token is hash-stored, single-appointment, and documented as intentionally non-secret (assumptions.md §Tokens) |
| 6 | Secrets | ⚠️ | `make secrets` clean; demo credentials are documented fixtures, but `db:seed --force` would create the known `demo@librenexus.test`/`password` owner (and `test@example.com`) on a production DB if an operator runs it there (F1) |
| 7 | Dependencies | ✅ | `make audit` + `make osv` clean; no dependency changes |
| 8 | SAST | ✅ | Semgrep clean, no new `nosemgrep` |
| 9 | Headers & transport | ✅ | Untouched; SecurityHeadersTest part of the fresh green run |
| 10 | Sessions & CSRF | ✅ | Untouched; dashboard adds no mutations |
| 11 | Rate limiting | ✅ | Untouched; demo pages inherit the existing booking/manage throttles |
| 12 | Logging & errors | ✅ | No logging of tokens/credentials added; seeder writes no log output |
| 13 | Uploads | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | SEC-SECRETS / FR-OPS hardening | `database/seeders/DatabaseSeeder.php`, `database/seeders/DemoSeeder.php:70-74` | The seeder chain is not environment-guarded: running `php artisan db:seed --force` on a production deployment creates a verified login with the publicly documented password `password` (owner of `demo-clinic`) plus the factory `test@example.com` user. Blast radius is limited to the demo tenant, the choice is documented in assumptions.md, and seeding production requires an explicit operator action, but a guard is cheap insurance | Defer to Epic 10 hardening: skip the demo owner (or the whole DemoSeeder) when `app()->isProduction()`, or document the operator warning in the ops/self-hosting docs |
| F2 | Low | SEC-TENANT (test depth) | `tests/Feature/Dashboard/DashboardMetricsTest.php` | No direct assertion that another tenant's appointments are excluded from the dashboard figures; isolation rests on the global scope (arch-tested) and the route-level 404 test. Structurally sound, but a one-line cross-tenant fixture would make the metric isolation explicit | Defer: add a second-tenant appointment to one metric test in Epic 10 |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: tenant isolation holds with fresh suite evidence, the demo token preserves the hash-only token model, all security gates are clean, and the only genuine risks are the unguarded demo credentials on a hypothetical production seed run (documented, deferred to Epic 10) and a test-depth nicety.
- Blocking findings remaining: 0
