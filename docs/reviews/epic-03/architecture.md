# Review Report - Architecture - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** ARCH-TENANCY-1..4, ARCH-ROUTING-1..5, ARCH-STRUCTURE-1/2, ARCH-DATA-1/2/5, ARCH-HTTP-*, ARCH-ASYNC-*, ARCH-TEST-3, ADR-0002

## Files reviewed

- `docs/adr/0002-tenant-scoping.md` - the required scoping ADR: CurrentTenant container + BelongsToTenant trait + fail-closed TenantScope, 404 for non-members, allowlist rationale, alternatives (manual scoping, RLS) considered
- `app/Data/CurrentTenant.php` + `app/Providers/AppServiceProvider.php:19` - request-scoped container binding (`$this->app->scoped(...)`), safe for queue/Octane reuse
- `app/Models/Scopes/TenantScope.php` - global scope `where team_id = current` with `whereRaw('1 = 0')` fail-closed branch (constant expression, no input)
- `app/Concerns/BelongsToTenant.php` - scope registration, `creating` autofill, `team()` relation
- `app/Models/TenantModel.php` - abstract base for Epics 04+ models
- `app/Http/Middleware/EnsureTeamMembership.php` - the single authenticated entry point that sets the tenant context after membership check; optional `:role` parameter fails closed on unknown roles
- `routes/web.php`, `routes/settings.php` - static routes first, `{current_team}` prefix group with `auth + verified + EnsureTeamMembership` (ARCH-ROUTING-1/2)
- `app/Rules/{TeamName,TeamSlug}.php`, `app/Concerns/GeneratesUniqueTeamSlugs.php` - reserved-slug strategy (ARCH-ROUTING-4/5)
- `app/Actions/Teams/{CreateTeam,TransferTeamOwnership,DeleteUserWithTenants}.php` - business logic in Actions with DB transactions
- `database/migrations/2026_01_27_000001_create_teams_table.php`, `2026_06_10_204507_*` - FKs with cascade, unique (team_id, user_id), unique slug/code; forward-only
- `resources/views/pages/teams/⚡*.blade.php` - Livewire components (logic placement check)

## Flows reviewed

- Tenant context lifecycle: middleware verifies membership -> sets CurrentTenant -> every BelongsToTenant query constrained; no context -> empty result set (probed in IsolationTest)
- Slug precedence: static marketing/auth routes registered before the `{current_team}` wildcard; `TeamName::routesPrefixes()` derives reserved names from registered routes plus the explicit static list (`book`, `manage`, `health`, `imprint`, `assets`, `up` present)
- Ownership transfer: transaction + `lockForUpdate` on the target membership, all other owners demoted atomically
- Account deletion: sole-owner guard then transactional membership/personal-team cleanup
- Async: `App\Notifications\Teams\TeamInvitation` implements ShouldQueue; expired invitations pruned by a scheduled command (tested via `schedule:run`)

## Tests reviewed

- `tests/Unit/TenantScopingTest.php` - the ARCH-TENANCY-3 / SEC-TENANT-3 enforcement test: every non-abstract model with a `team_id` column must use BelongsToTenant; allowlist = Membership + TeamInvitation with written rationale
- `tests/Feature/Tenancy/IsolationTest.php` §tenant scope mechanism - scoped query, fail-closed empty result, autofill on create, create-without-context throws (probe model against a real table)
- `tests/Feature/Tenancy/TeamMembershipMiddlewareTest.php` - 404 non-member, 403 insufficient role, fail-closed unknown role parameter, context switch on visit
- `tests/Unit/ArchTest.php` - no debug helpers, models in App\Models, scopes implement Scope, enums are enums, invokable middleware

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 193/193 incl. all arch/scope tests - fresh run |
| `vendor/bin/phpstan analyse` (L7) | pass | 0 errors, no baseline - fresh run |
| `make complexity` (PHPMD) | pass | 0 findings - fresh run |
| tinker probe (`Str::slug("Pricing!")`) | **bypass confirmed** | reserved slug `pricing` mintable at creation (F2) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | New code in existing dirs (`app/Data`, `app/Concerns`, `app/Models/Scopes`, `app/Actions/Teams`, `app/Rules`); no new top-level folders |
| 2 | Logic placement | ⚠️ | Transfer/delete-user/create flow through Actions; but `pages/teams/⚡edit.blade.php` carries updateTeam/updateBookingPolicy/updateMember persistence inline (F3, Medium); slot engine n/a |
| 3 | Tenant scoping | ⚠️ | Central mechanism exists, is documented in ADR-0002, and is enforced by the scope test; but the `creating` hook honors a pre-set `team_id` without checking it against the active tenant, leaving a create-time gap in the mechanism future models inherit (F1) |
| 4 | No leaky queries | ✅ | All tenant-owned reads go through the global scope; Membership/TeamInvitation (allowlisted fabric) accessed via policy-guarded relations or email-matched queries; no `withoutGlobalScopes` outside the test probe |
| 5 | Data | ✅ | Forward-only migrations with FKs + unique constraints; CarbonImmutable + UTC storage, tenant timezone stored for later tz math (ARCH-DATA-2); no string-interpolated SQL (`whereRaw('1 = 0')` is constant; `LOWER(email) = ?` is bound) |
| 6 | Double-booking | n/a | Epic 06; ADR-0003 exists |
| 7 | HTTP | ✅ | Validation via rules in components, `Gate::authorize` on every mutating action, named routes, middleware role parameter |
| 8 | Async | ✅ | Invitation mail queued (ShouldQueue, tested); prune job scheduled and tested |
| 9 | Config/secrets | ✅ | No new env/secrets; no environment branching in domain logic |
| 10 | Frontend | ✅ | Server-rendered Livewire + Flux components, existing settings layout reused; no inline scripts added |
| 11 | Arch tests | ✅ | TenantScopingTest + ArchTest green (fresh run) |
| 12 | ADRs | ✅ | ADR-0002 records mechanism, fail-closed choice, 404-vs-403, allowlist; assumptions §Tenancy records co-owner semantics and slug stability |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | ARCH-TENANCY-2 / SEC-TENANT-2 | `app/Concerns/BelongsToTenant.php:25-27` | The central scoping mechanism's create path is not closed by construction: when `team_id` is already set (e.g. mass-assigned from a form field on a future model with `team_id` fillable, or bound to a Livewire property), the hook returns early and writes into the foreign tenant. The whole point of the mechanism (ADR-0002: "new models cannot accidentally leak") is defeated for creates by two foreseeable mistakes in a later epic. Coverage confirms the branch is never tested (line 26 uncovered) | When a tenant context exists and `team_id` is pre-set, throw on mismatch (or always overwrite); allow explicit system/factory bypass; cover with an isolation-suite probe |
| F2 | High | ARCH-ROUTING-5 / AC-8 | `app/Concerns/GeneratesUniqueTeamSlugs.php` | "All slug generation runs through the reserved-name check" does not hold: generation only de-duplicates. `TeamName` validates the raw name, not its slugged form, so "Pricing!" generates reserved slug `pricing` (probe-confirmed). Static-first routing keeps `/pricing` serving the marketing page, so no route is shadowed today, but the tenant's future booking URL is silently unreachable and the ARCH rule is violated | Check generated slugs against `TeamName::reservedNames()` and suffix/reject; test the creation path with a punctuated reserved name |
| F3 | Medium | ARCH-STRUCTURE-2 | `resources/views/pages/teams/⚡edit.blade.php` | The settings page component carries three persistence flows (profile, policy, member role) plus data shaping (~230 lines of PHP). Acceptable for thin validate-and-update, but the last-owner demote rule is business logic living in a view file | Extract member-role update (and its last-owner invariant) into an Action alongside TransferTeamOwnership; track for Epic 04 touch |
| F4 | Low | ARCH-DATA-1 | `database/migrations/2026_01_27_000001_*` | `UniqueTeamInvitation` enforces one pending invite per email per team in the app layer only; no partial unique index backs it, so a race can create duplicates (harmless: both join the same team) | Optional partial unique index on (team_id, lower(email)) where accepted_at is null; defer |

## Required fixes (blocking)

- F1: close the create-time `team_id` override in BelongsToTenant (the decision rule treats any tenant-scoping gap as failing).
- F2: route generated slugs through the reserved-name check (ARCH-ROUTING-5).

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: the architecture is exactly what ADR-0002 promises (single mechanism, fail-closed, arch-test enforced, context set in one middleware) and boundaries otherwise hold, but the mechanism itself has a create-time scoping gap and slug generation bypasses the reserved-name rule; both undermine guarantees later epics will rely on and must be fixed while the surface is still small.
- Blocking findings remaining: 2 (F1, F2)

## Re-review after fixes (2026-06-10)

Verified by reading the changed code/tests and fresh runs:

- **F1 resolved.** `BelongsToTenant::creating` now enforces the invariant by construction: with a tenant context, a pre-set `team_id` that differs from the active tenant throws (`Refusing to create [...] for a tenant other than the active one.`), a matching value is accepted via an `(int)` cast (string form input handled) and `team_id` is then set from the context regardless; without a context, an explicit `team_id` is permitted only for trusted code paths (factories/seeders; requests on tenant routes always carry a context, per the inline comment) and creating with neither still throws. The isolation suite gained four mechanism probes: spoof rejected with `withoutGlobalScopes` proof that nothing persisted, explicit match accepted (int and string), trusted no-context path, and `team()` relation resolution. ARCH-TENANCY-2 now holds for reads and creates.
- **F2 resolved.** `GeneratesUniqueTeamSlugs::generateUniqueTeamSlug` consults `TeamName::isReserved()` (new public static accessor; the reserved list stays single-source per ARCH-ROUTING-4) and falls through to a numeric suffix, with a while-loop guard for suffixed collisions. Probe re-run confirms "Pricing!" -> `pricing-1` while `acme-clinic` is untouched; the dataset test in TeamTest pins five reserved names. ARCH-ROUTING-5 now holds: every generation path runs through the reserved check.
- Residual (Low, accepted): the no-context trusted-create path rests on the documented invariant that HTTP requests touching tenant-owned models always run behind `EnsureTeamMembership` (or the future public-booking resolvers) which set the context; the fail-closed read scope still hides any record created outside it. Worth restating in docs/assumptions.md when Epic 04 adds the first real tenant-owned models.
- F3 (Medium: extract the member-role update + last-owner invariant from `⚡edit.blade.php` into an Action) and F4 (Low: partial unique index for pending invitations) remain open and tracked.
- Fresh runs: `php artisan test --compact` 206/206; PHPStan L7 0 errors; PHPMD 0 findings.

## Final decision

**PASS WITH WARNINGS**

- Rationale: both blocking gaps in the scoping mechanism and the slug strategy are closed by construction and pinned by tests; the remaining items are a Medium logic-placement smell and Low hardening notes, tracked for the next touch (Epic 04).
- Blocking findings remaining: 0
