# Review Report - Security - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** SEC-TENANT-1..4 (critical), SEC-AUTHZ-1..3, SEC-AUTH-3 (verified gate on tenant management), SEC-INPUT-1..4, SEC-SECRETS, SEC-DEPS, QG-SECRETS/SAST/DEPS-VULN. SEC-TOKEN arrives with Epic 08; SEC-UPLOAD n/a

## Files reviewed

- `app/Data/CurrentTenant.php`, `app/Models/Scopes/TenantScope.php`, `app/Concerns/BelongsToTenant.php`, `app/Models/TenantModel.php` - the SEC-TENANT mechanism (fail-closed scope, autofill, request-scoped container)
- `app/Http/Middleware/EnsureTeamMembership.php` - membership verified before context is set; 404 for non-members (existence not probeable), 403 for insufficient role; unknown role parameter fails closed
- `app/Policies/TeamPolicy.php`, `app/Enums/{TeamRole,TeamPermission}.php`, `app/Data/TeamPermissions.php` - role matrix; UI permission DTO derives from the same enum the policy uses (one source of truth)
- `resources/views/pages/teams/⚡{edit,invite-member-modal,remove-member-modal,transfer-ownership-modal,delete-team-modal,cancel-invitation-modal,accept-invitation,pending-invitations-modal}.blade.php` - every mutating action begins with `Gate::authorize(...)`; invitation acceptance enforces case-insensitive email match, expiry, and single-use server-side
- `app/Actions/Teams/{TransferTeamOwnership,DeleteUserWithTenants}.php` - transactional, membership-checked, sole-owner guard server-side
- `app/Models/{Team,Membership,TeamInvitation}.php` - explicit `#[Fillable]`, invitation `code` = 64-char CSPRNG `Str::random`, unique-indexed
- `app/Rules/{TeamSlug,TeamName,UniqueTeamInvitation}.php` - server-side validation; bound parameters in raw LOWER() comparisons
- `routes/{web,settings}.php` - tenant routes behind `auth + verified + EnsureTeamMembership`; accept-invitation behind `auth` only (F3)
- `docs/adr/0002-tenant-scoping.md`, `docs/assumptions.md` §Tenancy - documented 404 choice and Membership/TeamInvitation allowlist rationale

## Flows reviewed

- Cross-tenant probes (member of A against B): dashboard URL, settings URL, member role update, member removal, invitation create, invitation revoke, ownership transfer - all denied server-side (404 route level, 403 Livewire action level)
- Allowlist reasoning (Membership/TeamInvitation outside the scope): verified every access path - memberships only via `$team->memberships()` after Gate checks or `$user->teamMemberships()`; invitations via `$team->invitations()` after Gate checks, by unguessable 64-char code plus email-match, or `LOWER(email) = auth user` in the pending modal. The rationale (fabric must be queryable before a context exists) holds; accepted
- IDOR: memberId/invitationCode are client-settable Livewire properties but always resolved through the team-bound relation of an authorized team; foreign IDs delete/match nothing
- Form-field spoofing on create: **gap found** - the BelongsToTenant `creating` hook honors a pre-set `team_id` (F2)
- Privilege escalation in-tenant: **gap found** - invitation role accepts `owner` (F1)
- Co-owner semantics: promote-to-owner and demote are owner-only (`UpdateMember`); transfer demotes other owners transactionally; last-owner invariant enforced in updateMember, removeMember, and DeleteUserWithTenants - consistent with FR-TENANT-5/9 and documented in assumptions
- Mass assignment: explicit `#[Fillable]` everywhere; `Team` fillable includes profile/policy fields but every write path validates first; no `guarded = []` outside the test probe

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php` (named SEC-TENANT suite, 11 tests) - all cross-tenant probes above + scope-mechanism probes (scoped read, fail-closed empty, autofill, create-without-context throws); carries `covers()` for mutation
- `tests/Unit/TenantScopingTest.php` - arch rule: any model with `team_id` must use BelongsToTenant (allowlist exactly Membership + TeamInvitation)
- `tests/Feature/Tenancy/RolePermissionTest.php` - full owner/admin/staff x ability matrix incl. non-member denial of every ability
- `tests/Feature/Teams/TeamInvitationTest.php` - foreign-email acceptance refused, expired refused, member-role invite refused
- `tests/Feature/Tenancy/TeamMembershipMiddlewareTest.php` - guest/non-member 404, low-role 403, unknown-role fail-closed

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test tests/Feature/Tenancy tests/Unit/TenantScopingTest.php ...` | pass | 105/105 tenancy suites incl. the named isolation suite - fresh run |
| `make secrets` | pass | gitleaks: no leaks found - fresh run |
| `make sast` | pass | Semgrep p/php + p/security-audit: 46 rules, 288 files, 0 findings - fresh run |
| `make audit` | pass | composer audit 0 advisories; npm audit 0 vulnerabilities - fresh run |
| `make osv` | pass | osv-scanner on both lockfiles: no issues - fresh run |
| `make mutation` | pass | 13 mutations on TenantScope/BelongsToTenant/CurrentTenant, score 100% - fresh run |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation (SEC-TENANT) | ❌ | SEC-TENANT-1/3/4 ✅ (suite green, fail-closed scope, centralized + arch-enforced, consistent documented 404); SEC-TENANT-2 ⚠️/❌: route, URL ID, query, and relationship traversal vectors are closed (fail-closed scope makes cross-tenant traversal return nothing), but the **form-field vector on create is not closed by construction** (F2) and has no isolation-suite probe |
| 2 | Authorization (SEC-AUTHZ) | ❌ | Policy on every action, matrix tested incl. non-members, IDOR attempts resolve through team-bound relations; but the invitation role accepts `owner`, letting an admin escalate to ownership (F1) |
| 3 | Authentication (SEC-AUTH) | ⚠️ | Epic 02 suites still green; `verified` applied to teams index/settings and `{current_team}` routes as the epic requires; accept-invitation route is `auth`-only, so an unverified account can join a tenant (F3, Medium) |
| 4 | Input & injection (SEC-INPUT) | ✅ | Server-side validation on all inputs; raw fragments are constant (`1 = 0`) or bound (`LOWER(email) = ?`); no `{!! !!}` on user content; explicit fillable |
| 5 | Customer tokens (SEC-TOKEN) | n/a | Epic 08; invitation codes are 64-char CSPRNG, unique, single-use, email-bound (good baseline) |
| 6 | Secrets (SEC-SECRETS) | ✅ | gitleaks clean; no new config/secrets |
| 7 | Dependencies (SEC-DEPS) | ✅ | composer/npm audit + osv clean - fresh runs |
| 8 | SAST | ✅ | Semgrep 0 findings; no `nosemgrep` markers in epic files |
| 9 | Headers & transport | ✅ | Epic 00 SetSecurityHeaders + tests unchanged and green (regression) |
| 10 | Sessions & CSRF | ✅ | Livewire mutations CSRF-protected by framework; no session handling changed |
| 11 | Rate limiting (SEC-RATE) | n/a | No new public endpoints; Epic 02 throttles unchanged |
| 12 | Logging & errors (SEC-LOG) | ✅ | No invitation codes or emails logged; 404/403 responses carry no data |
| 13 | Uploads (SEC-UPLOAD) | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | SEC-AUTHZ-2 / FR-TENANT-5 / AC-2 | `resources/views/pages/teams/⚡invite-member-modal.blade.php` (`'inviteRole' => Rule::enum(TeamRole::class)`) | In-tenant privilege escalation: admins hold `CreateInvitation`, the role field accepts any enum value including `owner` (the UI only offers Admin/Staff but the Livewire property is client-settable), and `accept-invitation` creates the membership with the invitation's role verbatim. An admin can invite an accomplice (or a second own account) as Owner and gain transfer/delete powers reserved for owners | Validate against assignable roles only (`Rule::in` on `TeamRole::assignable()` values or `Rule::enum(...)->except(TeamRole::Owner)`); add a test that an `owner` invite role is rejected with 422 |
| F2 | High | SEC-TENANT-2/3 | `app/Concerns/BelongsToTenant.php:25-27` | The central mechanism leaves the create-time form-field vector open: a pre-set `team_id` short-circuits the autofill with no membership/context check. Today no scoped model marks `team_id` fillable (Membership/TeamInvitation are allowlisted and written via team-bound relations), so there is **no current exploit**, but the first Epic 04+ model that passes request data with `team_id` fillable writes straight into a foreign tenant, and the global scope does not constrain INSERTs. The uncovered branch (line 26, 0 coverage; mutation ran `--covered-only`) proves no test would catch it | In the `creating` hook, when a tenant context exists and `team_id` is set but differs, throw (provide an explicit system bypass for seeding); extend IsolationTest with a spoofed-`team_id` create probe |
| F3 | Medium | SEC-AUTH-3 | `routes/web.php` (`invitations.accept` under `auth` only) | An unverified account can accept an invitation and join a tenant; the epic applies the `verified` gate to tenant management and joining a tenant is a membership mutation. Mitigated: the invite email proves mailbox access and all tenant screens remain `verified`-gated | Add `verified` to the accept route; track if deferred |
| F4 | Low | hardening | `⚡accept-invitation.blade.php` `mount()` | Acceptance is a GET side effect: a logged-in invitee's link prefetcher/mail scanner can auto-accept. Impact limited to joining a team they were genuinely invited to | Require a POST/confirm step (the pending-invitations modal already provides one); defer |
| F5 | Low | SEC-TOKEN-style hardening | `app/Models/TeamInvitation.php` | Invitation codes stored in plaintext (a DB leak makes them usable until expiry). Out of SEC-TOKEN scope (customer tokens) and email-match limits abuse | Consider hashing codes when touching invitations next; defer |

## Required fixes (blocking)

- F1: reject `owner` as an invitation role server-side (privilege escalation).
- F2: make BelongsToTenant fail on a mismatching pre-set `team_id` at create and prove it in the isolation suite (the mechanism is this epic's deliverable and must close all SEC-TENANT-2 vectors by construction).

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: read isolation is genuinely solid - fail-closed central scope, arch-enforced opt-in, 404 consistency, a green named suite with mechanism probes, and all tool gates clean - but SEC-TENANT review cannot pass with an open in-tenant privilege escalation (admin-minted owner invitations) and an unclosed create-time form-field vector in the very mechanism later epics must trust. Both fixes are small and testable.
- Blocking findings remaining: 2 (F1, F2)

## Re-review after fixes (2026-06-10)

Verified by reading the changed code/tests and fresh runs:

- **F1 resolved.** `inviteRole` is now validated with `Rule::enum(TeamRole::class)->except(TeamRole::Owner)`; the escalation attempt is regression-tested (`an invitation can never carry the owner role`: an **admin** - the privileged-but-not-owner role that made this exploitable - sets `inviteRole = owner`, gets a 422-style validation error, and `assertDatabaseMissing` proves no invitation row exists). Ownership is now grantable only via the owner-gated transfer flow and the owner-gated `updateMember` promote, matching FR-TENANT-5 and the documented co-owner semantics.
- **F2 resolved.** The SEC-TENANT-2 form-field vector on create is closed by construction in `BelongsToTenant::creating`: with a context, a differing pre-set `team_id` throws and a matching one (int or string, `(int)` cast) is overwritten from the context; without a context, explicit `team_id` is reserved for trusted code (factories/seeders) and creating with neither throws. The isolation suite now probes the spoof (exception + `withoutGlobalScopes` proof of zero persisted rows), the match (both scalar types), the trusted path, and the `team()` relation. Coverage on the trait is 100% and mutation grew from 13 to 19 mutations at a 100% score (the prior escapees - AlwaysReturnNull on `CurrentTenant::get`, RemoveIntegerCast on the spoof check - are killed by explicit assertions).
- **F3 resolved.** `invitations.accept` now sits in an `auth + verified` group; tested (`an unverified user is routed to verification before accepting an invitation` asserts the redirect to `verification.notice` **and** that no membership was created). SEC-AUTH-3 checklist item moves to ✅.
- **F4 / F5 (Low)** remain open and tracked: acceptance is still a GET side effect in `mount()` (mitigated by auth + verified + email match + single-use), and invitation codes are stored in plaintext (64-char CSPRNG, email-bound).
- New residual (Low, accepted): the trusted no-context create path assumes requests always establish a tenant context via middleware/resolvers; the fail-closed read scope contains the blast radius of any future violation. Flagged to re-check when Epic 04 introduces the first real tenant-owned models.
- Checklist updates: items 1 (SEC-TENANT) and 2 (SEC-AUTHZ) now ✅; item 3 (SEC-AUTH) now ✅.
- Fresh runs: full suite 206/206 (602 assertions) incl. the named isolation suite (now 16 tests); `make secrets` no leaks; `make sast` 46 rules / 294 files / 0 findings; `composer audit` + `npm audit` 0; `make osv` no issues; `make mutation` 19/19 = 100%.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every SEC-TENANT and SEC-AUTHZ rule in scope now holds with test evidence - both blockers are closed by construction and pinned by regression tests, the verified gate covers invitation acceptance, and all security tool gates are green on fresh runs. Remaining items are Low hardening notes (GET-accept ergonomics, plaintext invite codes, trusted-path invariant), tracked for Epic 04/10.
- Blocking findings remaining: 0
