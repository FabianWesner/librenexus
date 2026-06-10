# Review Report - Code Quality - Epic 02 (Authentication & accounts)

## Reviewed scope

- **Epic / change:** Epic 02 (Authentication & accounts), current working tree
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-IGNORE, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `app/Providers/FortifyServiceProvider.php` - private methods per concern (`configureActions`/`configureViews`/`configureRateLimiting`), typed helper with array-shape PHPDoc (`array{code: string, teamName: string}|null`)
- `app/Providers/AppServiceProvider.php` - typed closure return (`?Password`), immutable dates
- `app/Models/User.php` - attribute-based `#[Fillable]`/`#[Hidden]`, full property PHPDoc block, typed `casts()`/`initials()`
- `app/Actions/Fortify/CreateNewUser.php` - PHP 8 constructor promotion (`private CreateTeam $createTeam`), shared concerns reused instead of duplicated rules
- `app/Concerns/{PasswordValidationRules,ProfileValidationRules}.php` - single source of validation rules used by Fortify actions and Livewire components
- `app/Http/Responses/*.php` + `Concerns/RedirectsToCurrentTeam` - shared redirect logic extracted into a trait rather than copy-pasted across the four responses
- `routes/web.php` - non-obvious route replacement documented with a precise inline comment
- `resources/views/flux/{text,sidebar/group,button/index}.blade.php` - published stub overrides; minimal targeted diffs from upstream (contrast values), structure matches Flux conventions
- `tests/Feature/Auth/AuthHardeningTest.php`, `tests/Browser/AuthSettingsSmokeTest.php` - readable, descriptive test names, dataset usage for the URL matrix

## Flows reviewed

- New code vs siblings: AuthHardeningTest follows the existing Pest test style (test() + expect()/assert mix matching the suite); Livewire SFC pages match the starter's single-file component format; flux stubs keep upstream layout including the no-trailing-newline contract noted in `text.blade.php`
- Gate-gaming check: no new ignores, baselines, or threshold changes; `phpstan.neon` has no baseline; `.jscpd.json`/`composer-unused.php` filters unchanged and reason-annotated; no `nosemgrep`

## Tests reviewed

- `tests/Unit/ArchTest.php` - enforces no debug helpers (`dd`/`dump`/`ray`/`var_dump`/`print_r`/`die`/`exit`/`eval`), strict equality, model/enum/middleware conventions; green in the fresh run

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make format-check` | pass | Pint, zero diffs - fresh run |
| `make static` | pass | PHPStan/Larastan level 7, 0 errors, no baseline - fresh run |
| `make complexity` | pass | PHPMD (complexity, unusedcode, design) over app,config,database,routes: 0 violations - fresh run |
| `make duplication` | pass | jscpd 1.80% duplicated lines (< 3%), 0 new clones - fresh run |
| `make unused` | pass | composer-unused: 0 unused; filters carry reasons (flux/blaze/chisel) - fresh run |
| `make require-check` | pass | composer-require-checker: no unknown symbols - fresh run |
| `grep TODO/FIXME` over app, routes, views | clean | QG-NO-TODO holds - fresh run |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | `make format-check` exit 0 |
| 2 | Static | ✅ | Level 7, 0 errors, no baseline, no new inline ignores |
| 3 | Complexity | ✅ | PHPMD 0 violations; longest new method (`teamInvitation`) is a short guarded query |
| 4 | Dead code | ✅ | PHPMD unusedcode clean for app code; one leftover in tests (F2, PHPMD does not scan `tests/`) |
| 5 | Duplication | ✅ | 1.80% < 3%; shared logic genuinely extracted (validation concerns, `RedirectsToCurrentTeam`), not hidden by config |
| 6 | Dependencies | ✅ | unused + require-check both clean; no dependency changes this epic |
| 7 | Idioms | ✅ | Constructor promotion, explicit return types and param hints throughout the new code, PHPDoc array shapes (`array{code: string, teamName: string}`), descriptive names (`configureRateLimiting`, `resendVerificationNotification`) |
| 8 | Laravel way | ✅ | Fortify contracts bound in `register()`, named routes + `route()`, config-driven limiter mapping, `Password::defaults` instead of ad-hoc rules |
| 9 | Reuse | ✅ | Existing concerns/components reused (PasswordValidationRules in three call sites; Flux components; settings layout partial) before anything new was written |
| 10 | No debug/leftovers | ✅ | Arch test + grep: no dd/dump/ray/var_dump, no commented-out blocks, no TODO/FIXME |
| 11 | Consistency | ✅ | New tests/components/responses mirror sibling structure and naming; `/* @chisel-* */` markers in the security SFC are the starter kit's own convention, kept consistent |
| 12 | Docs | ✅ | The one non-obvious decision (forgot-password route replacement) has a precise inline comment; no ADR-level decision introduced; specs untouched |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | test hygiene / NFR-MAINT | `tests/Feature/Settings/SecurityTest.php:7` | Empty `beforeEach(function () {});` is dead code (PHPMD does not cover `tests/`, so no gate catches it) | Delete the empty closure. **Status: RESOLVED (verified in re-review)** |
| F2 | Low | NFR-MAINT | `tests/Feature/Settings/SecurityTest.php:44` | Empty test body; the quality dimension (dead/misleading code) is noted here, while the coverage consequence is the QA/Product High finding | Implement or remove together with the QA F1 fix. **Status: RESOLVED (verified in re-review)** |
| F3 | Low | consistency | `resources/views/flux/*` | Published Flux stub overrides will drift from upstream on package updates; diffs are currently minimal and purposeful (contrast fixes) | Re-diff the stubs against upstream on Flux upgrades; consider documenting the changed lines |

## Required fixes (blocking)

- None. No Critical/High code-quality findings; the empty-test issue blocks via the QA review, not this gate set.

## Initial decision (2026-06-10, first pass)

**PASS WITH WARNINGS**

- Rationale: every code-quality gate passes on fresh runs with no baselines, ignores, or threshold changes; the new code is idiomatic, typed, and reuses shared concerns instead of duplicating them. Warnings are three Low maintainability nits (two leftovers in one test file, stub-drift watch), tracked for the QA fix and Epic 10.
- Blocking findings remaining: 0

## Re-review after fixes (2026-06-10)

Re-reviewed the fix code and re-ran every gate fresh after the AC-7/async fixes landed:

- **F1 and F2 closed.** The empty `beforeEach` is deleted and the empty test body is now a real, commented behavioral test (`SecurityTest.php:41-57`).
- New code reviewed: `app/Notifications/Auth/{QueuedVerifyEmail,QueuedResetPassword}.php` are minimal, correctly typed subclasses with rule-citing PHPDoc; the `User` overrides carry PHPDoc and match framework signatures; `tests/Feature/Auth/TwoFactorAndPasskeyTest.php` uses typed helper functions with an array-shape PHPDoc (`array{user: User, secret: string, recoveryCode: string}`), descriptive test names, and follows sibling test style. The previously unbound `TwoFactorLoginResponse` is now wired in `FortifyServiceProvider::register()`, removing latent dead code.
- Fresh runs: `make format-check` pass, `make static` 0 errors (level 7, no baseline), `make complexity` 0 violations, `make duplication` 1.78% (< 3%), `make unused` + `make require-check` clean. No new ignores, baselines, or threshold changes.
- F3 (Flux stub drift watch) remains Low/tracked. New Low note F4: tests use `PragmaRX\Google2FA` directly, which is a transitive dependency via `laravel/fortify`; acceptable for test code (require-checker scopes app code) but worth making explicit in `require-dev` if usage grows.

## Final decision

**PASS WITH WARNINGS**

- Rationale: all code-quality gates remain green on fresh post-fix runs and the fix code is idiomatic and consistent with its siblings; the two test-hygiene nits are resolved, leaving only the Low stub-drift watch and the transitive-test-dependency note.
- Blocking findings remaining: 0
