# Review Report - Architecture - Epic 02 (Authentication & accounts)

## Reviewed scope

- **Epic / change:** Epic 02 (Authentication & accounts), current working tree
- **Requirements/rules in scope:** ARCH-STRUCTURE-1/2, ARCH-HTTP-1..4, ARCH-ASYNC-1/3, ARCH-CONFIG-1/2, ARCH-FRONTEND-1, ARCH-DATA-1, ARCH-TEST-3; NFR-OPS-2/3, NFR-MAINT. ARCH-TENANCY/ARCH-DATA-3 (double-booking) are owned by Epics 03/06

## Files reviewed

- `app/Providers/FortifyServiceProvider.php` - limiter registration, view bindings, response contract singletons; private helper for invitation context
- `app/Providers/AppServiceProvider.php` - `Password::defaults` with production branching (see F2)
- `app/Models/User.php` - `MustVerifyEmail` + `PasskeyUser` contracts, attribute-based `#[Fillable]`/`#[Hidden]`, casts
- `app/Actions/Fortify/{CreateNewUser,ResetUserPassword}.php` - write operations as Actions, constructor promotion, DB transaction around user + personal team creation
- `app/Concerns/{PasswordValidationRules,ProfileValidationRules}.php` - shared validation rules reused by Fortify actions and Livewire components
- `app/Http/Responses/{Login,Register,VerifyEmail,TwoFactorLogin}Response.php` + `Concerns/RedirectsToCurrentTeam` - Fortify response overrides
- `routes/web.php` - forgot-password re-registration with `throttle:password-reset` and an explanatory comment; `verified` group
- `routes/settings.php` - settings routes, conditional `password.confirm` middleware
- `config/fortify.php` - limiters map (`login`, `two-factor`), features
- `database/migrations/2026_06_10_*` (two-factor columns, passkeys table) - forward-only, FK to users
- `resources/views/pages/settings/⚡security.blade.php`, `⚡profile.blade.php`, `⚡delete-user-modal.blade.php` - logic placement in Livewire SFCs
- `resources/views/flux/{text,sidebar/group,button/index}.blade.php` - published Flux stub overrides (contrast fixes)

## Flows reviewed

- POST /forgot-password - the app route replaces the Fortify package route by identical URI + method so the `throttle:password-reset` limiter applies; verified live via `php artisan route:list --path=forgot-password` (single POST route, `password.email`) and by the 429 feature test
- Registration - validation via shared concerns, user + personal team created atomically in `DB::transaction` inside the `CreateNewUser` action (no logic in views)
- Email verification + password reset emails - dispatched via framework notifications inline in the request; **not queued** (F1)
- Login/verify redirects - response overrides delegate to `RedirectsToCurrentTeam`; named routes used throughout

## Tests reviewed

- `tests/Unit/ArchTest.php` - no debug helpers, models in App\Models extend Model, enums are enums, middleware shape, strict equality (green in fresh 105/105 run)
- `tests/Feature/Auth/AuthHardeningTest.php` - proves the limiter wiring (login + reset 429s) and the verification gate, i.e. the route-level architecture works
- `tests/Feature/Auth/EmailVerificationTest.php` - signed-URL verification + idempotent revisit

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make static` | pass | PHPStan/Larastan level 7, 0 errors, no baseline - fresh run |
| `make complexity` | pass | PHPMD over app,config,database,routes, 0 violations - fresh run |
| `php artisan test --compact` | pass | 105/105 incl. ArchTest - fresh run |
| `php artisan route:list --path=forgot-password` | verified | exactly one POST route; the throttled app route owns `password.email` |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in established homes: `app/Http/Responses`, `app/Concerns`, `app/Actions/Fortify`, `resources/views/pages/**`; no new top-level `app/` folder |
| 2 | Logic placement | ✅ | Writes go through Fortify Actions (`CreateNewUser`, `ResetUserPassword`) and Fortify's own actions for 2FA; Livewire SFCs orchestrate and reuse validation concerns; no business logic in Blade |
| 3 | Tenant scoping | n/a | No tenant-owned models added; central scoping lands in Epic 03 |
| 4 | No leaky queries | ✅ | Only query added is the invitation-context lookup in FortifyServiceProvider (code-scoped, unaccepted, unexpired); no tenant-owned data read without constraint |
| 5 | Data | ✅ | Two-factor + passkeys migrations forward-only with FKs (ARCH-DATA-1); no money/time math in scope; no raw SQL anywhere (Semgrep clean) |
| 6 | Double-booking | n/a | Epic 06; strategy already recorded in `docs/adr/0003-double-booking-constraint.md` |
| 7 | HTTP | ✅ | Validation in Fortify actions/Livewire rules via shared concerns; thin components; auth/`verified`/`password.confirm` middleware on settings routes; named routes + `route()` everywhere |
| 8 | Async | ❌ | `VerifyEmail` and `ResetPassword` notifications are sent inline in the request; only `App\Notifications\Teams\TeamInvitation` implements `ShouldQueue` (repo-wide grep). Violates ARCH-ASYNC-1 ("Emails sent via queued Mailables/Notifications"), ARCH-HTTP-4 and NFR-OPS-2 (F1) |
| 9 | Config/secrets | ⚠️ | Env-driven, no secrets in code (gitleaks clean). `Password::defaults` branches on `app()->isProduction()` instead of config, against ARCH-CONFIG-2/NFR-OPS-3 in spirit; the production policy is also never exercised by tests (F2) |
| 10 | Frontend | ✅ | Server-rendered Blade/Livewire + Flux; published stub overrides are the supported customization path; appearance toggle uses Alpine `$flux.appearance`, no CSP-breaking inline scripts added |
| 11 | Arch tests | ✅ | `tests/Unit/ArchTest.php` green; debug-helper, model, enum, middleware, strict-equality rules enforced |
| 12 | ADRs | ✅ | No new structural decision rises to ADR level; the forgot-password route-replacement rationale is documented inline in `routes/web.php` and pinned by a test |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | ARCH-ASYNC-1 / ARCH-HTTP-4 / NFR-OPS-2 | registration, email-change, resend-verification, forgot-password flows | Email verification and password-reset notifications are sent synchronously inside the request; with a real SMTP mailer these requests block on email, which the architecture spec forbids ("Emails sent via queued Mailables/Notifications") | Send queued notifications (e.g. override `sendEmailVerificationNotification`/`sendPasswordResetNotification` with `ShouldQueue` notifications, or `toMailUsing` + queued wrappers) and assert queueing with `Notification::fake()`/queue assertions. **Status: RESOLVED (verified in re-review)** |
| F2 | Medium | ARCH-CONFIG-2 / NFR-OPS-3 / SEC-AUTH-2 | `app/Providers/AppServiceProvider.php:41-46` | Password policy differs by environment branching (`isProduction()` -> min 12 + uncompromised, else framework min 8). Behavior differences should come from config, and the production rule set is dead code in every test run (60% file coverage; lines 41-46 uncovered) | Drive the policy from config (e.g. `config('auth.password_policy')`) and add a test that exercises the strict rule set; defer to Epic 10 with a tracked note |
| F3 | Low | ARCH-HTTP / maintainability | `routes/web.php:19-23` | The throttled forgot-password route relies on replacing the package route by identical URI + method; a Fortify URI change would silently decouple them | Acceptable: the inline comment explains it and the 429 test pins the behavior; re-check on Fortify upgrades |

## Required fixes (blocking)

- F1: queue the verification and password-reset notifications (ARCH-ASYNC-1) and prove it with a test. *(Fixed - see re-review below.)*

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: structure, boundaries, data and HTTP patterns are clean and verified by green arch/static gates, but auth emails are sent inline in the request, a direct violation of ARCH-ASYNC-1/NFR-OPS-2 that this epic introduced by activating verification and reset flows; the fix is small and well-defined.
- Blocking findings remaining: 1 (F1)

## Re-review after fixes (2026-06-10)

Verified by reading the new code and re-running the gates fresh:

- **F1 resolved.** `app/Notifications/Auth/QueuedVerifyEmail.php` and `QueuedResetPassword.php` extend the framework notifications and implement `ShouldQueue` + `Queueable` (each with a PHPDoc citing ARCH-ASYNC-1/NFR-OPS-2); `User::sendEmailVerificationNotification()` and `sendPasswordResetNotification()` (app/Models/User.php:63-76) dispatch them. Proven by `TwoFactorAndPasskeyTest::the verification notification is queued` and `::the password reset notification is queued`, both asserting the notification `instanceof ShouldQueue`; `PasswordResetTest` and `AuthHardeningTest` updated to the new classes. The new classes follow ARCH-STRUCTURE-1 (`app/Notifications/<Domain>`). Checklist item 8 (Async) is now ✅; failed deliveries land in the standard `failed_jobs` path (ARCH-ASYNC-3).
- Side benefit: the previously dead `TwoFactorLoginResponse` is now actually bound in `FortifyServiceProvider::register()` (line 32) and exercised by the challenge tests, removing a latent dead-code/consistency smell.
- Fresh runs: `make static` 0 errors, `make complexity` 0 violations, `php artisan test --compact` 116/116 (0 risky) incl. ArchTest.
- F2 (Medium, `Password::defaults` environment branching vs ARCH-CONFIG-2) and F3 (Low, route-replacement fragility) remain open and tracked for Epic 10.

## Final decision

**PASS WITH WARNINGS**

- Rationale: the blocking async violation is fixed the right way (queued notification subclasses in the established directory, asserted by tests); boundaries, structure and data patterns were already clean. Remaining items are one Medium config-vs-environment-branching smell and one Low fragility note, both tracked.
- Blocking findings remaining: 0
