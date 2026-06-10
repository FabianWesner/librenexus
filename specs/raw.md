Here is the condensed experiment brief, focused on the experiment itself and not the app implementation details.

# Verified App Experiment

## Core Idea

The experiment tests whether an AI coding agent can build a complete, production-quality application from an almost empty repository.

The starting repository contains only functional requirements, guardrails, quality rules, non-functional requirements, review checklists, and measurement criteria. It does not contain code, architecture, technical specifications, database design, or implementation guidance.

The central question is not whether an AI can generate code. The central question is whether an AI can produce a verifiable software artifact that satisfies functional requirements and passes predefined, measurable quality gates.

## Experiment Goal

The goal is to prove that a single high-quality prompt, combined with clear requirements and strict guardrails, can guide an agent through a full software delivery process:

* understand the requirements
* draft a roadmap
* define epics
* design the architecture
* implement incrementally
* test each feature
* run programmatic quality checks
* conduct structured reviews
* fix findings
* produce a final verified application

The output should not be judged by subjective claims like “the code is good”. It should be judged by public, reproducible evidence.

## Starting Point

The repository starts almost empty.

It should contain only files such as:

* functional requirements
* engineering principles
* architecture rules
* code quality rules
* security rules
* testing rules
* non-functional requirements
* quality gates
* measurement tools
* definition of done
* review checklists
* role definitions for review agents

The agent must create everything else.

## Key Principle

The experiment separates four concerns:

* functional requirements define what the application should do
* guardrails define how quality is protected
* quality gates define how quality is measured
* the prompt defines how the agent works

This prevents the experiment from becoming a vague “build me a good app” exercise.

## Prompt Strategy

The single prompt should instruct the agent to work in phases:

1. Discovery
2. Roadmap
3. Architecture
4. Quality plan
5. Epic-by-epic implementation
6. Verification
7. Final hardening
8. Final quality report

The agent must not start coding immediately. It first reads the requirements and guardrails, identifies assumptions, drafts a roadmap, defines epics, and creates the architecture.

For each epic, the agent must:

* restate the epic goal
* implement the smallest complete version
* add tests
* run quality tools
* conduct structured reviews
* fix blocking findings
* update documentation
* only then move to the next epic

## Review Agent Concept

The agent should simulate a senior engineering team with multiple virtual reviewers.

Suggested review roles:

* Product Reviewer
* Architecture Reviewer
* Security Reviewer
* QA Reviewer
* Performance Reviewer
* Code Quality Reviewer

Each reviewer needs precise checklists. They should not judge by taste. They should verify explicit rules.

Each review should produce a structured report containing:

* reviewed scope
* files reviewed
* flows reviewed
* tests reviewed
* tools executed
* checklist results
* findings
* severity
* required fixes
* final decision

Possible decisions:

* pass
* pass with warnings
* fail

Blocking findings must be fixed before an epic can be marked as done.

## Code Quality Rules

The code quality rules must be precise and measurable.

Important categories:

* formatting
* linting
* static analysis
* type safety
* complexity
* duplication
* dead code
* unused dependencies
* test quality
* architecture boundaries
* documentation quality

Each rule should have:

* rule ID
* clear rule statement
* review checklist
* programmatic tool
* pass/fail threshold
* remediation requirement

Examples:

* formatter must pass with exit code 0
* static analysis must pass without new baseline entries
* linter must pass
* duplicated lines must stay below a defined threshold
* critical domain logic must have high test coverage
* unused dependencies must be zero
* dead code findings must be zero
* no inline ignores without justification
* no TODOs unless tracked

## Security Rules

Security rules also need to be precise and checklist-driven.

Important categories:

* authentication
* authorization
* tenant isolation
* input validation
* injection prevention
* secrets management
* dependency security
* secure headers
* logging and error handling
* session security
* rate limiting
* file upload safety, if applicable

Security reviews must verify that protection is enforced server-side, not only in the UI.

For a multi-tenant system, tenant isolation is a critical security rule. Users must never access data from another tenant unless they are explicitly assigned to that tenant. This must be tested.

## Non-Functional Requirements

The experiment should include non-functional requirements as first-class quality gates.

Important categories:

* performance
* reliability
* observability
* accessibility
* maintainability
* reproducibility
* documentation
* operational readiness

Examples:

* API response time budgets
* frontend Lighthouse budget
* accessibility checks
* health check endpoint
* structured logging
* correlation IDs
* error tracking readiness
* background job visibility
* clean checkout setup
* documented quality commands

## Programmatic Tooling

The experiment should use open-source tools wherever possible to produce measurable evidence.

Tool categories:

* formatter
* linter
* static analyzer
* test runner
* coverage tool
* mutation testing tool
* complexity checker
* duplication checker
* dead code checker
* unused dependency checker
* secret scanner
* dependency vulnerability scanner
* SAST tool
* DAST tool
* accessibility checker
* performance checker
* E2E testing tool
* container scanner
* SBOM generator
* license checker

Examples of suitable tools:

* Prettier
* Biome
* ESLint
* TypeScript compiler
* Laravel Pint
* PHP CS Fixer
* PHPStan
* Larastan
* Psalm
* Pest
* PHPUnit
* Infection
* Playwright
* jscpd
* PHPMD
* Composer Unused
* Semgrep
* CodeQL
* Gitleaks
* TruffleHog
* OSV-Scanner
* Trivy
* Grype
* OWASP ZAP
* axe-core
* Pa11y
* Lighthouse CI
* k6
* Syft
* CycloneDX tools
* OpenSSF Scorecard

## Verification Commands

The repository should expose simple commands such as:

* make setup
* make format-check
* make lint
* make static
* make test
* make coverage
* make mutation
* make e2e
* make security
* make accessibility
* make performance
* make verify

The most important command is:

* make verify

This command should run the full quality pipeline from a clean checkout.

## Definition of Done

An epic is only done when:

* all acceptance criteria are implemented
* tests pass
* formatter passes
* linter passes
* static analysis passes
* security checks pass
* no blocking review findings remain
* documentation is updated
* the app still works after the change

The full application is only done when the full verification pipeline passes.

## External Proof

The experiment must be provable to the outside world.

The claim should not be:

“This repo is perfect.”

The claim should be:

“This repo passed a predefined, public, reproducible quality benchmark.”

The public proof package should include:

* initial repository
* final repository
* full prompt
* requirements
* guardrails
* quality gates
* CI results
* test reports
* coverage reports
* mutation testing reports
* security reports
* review reports
* architecture decisions
* final quality report
* demo video
* public website
* link to repository
* MIT license

## Public Evidence

The final repository should provide evidence such as:

* formatter passing
* linter passing
* static analysis passing
* unit tests passing
* integration tests passing
* E2E tests passing
* coverage above threshold
* mutation score above threshold
* secret scan with zero findings
* dependency audit with no high or critical findings
* SAST with no high or critical findings
* accessibility check passing
* performance budget passing
* clean checkout verification passing

The README should show CI badges, and every badge should link to a public CI run.

## CI as Public Witness

The experiment should use public CI, for example GitHub Actions.

The CI pipeline should run the same verification commands that a user can run locally.

The public should be able to clone the repository and execute:

make verify

The result should be reproducible.

## Final Quality Report

The final quality report should include:

* implemented epics
* skipped or reduced scope
* assumptions
* architecture summary
* test summary
* tool results
* known limitations
* security notes
* performance notes
* accessibility notes
* remaining risks
* recommended next steps

The report must be honest. If something could not be checked, it must say so.

## Scoring Model

The experiment can use a scorecard instead of claiming literal perfection.

Suggested categories:

* functional completeness
* test quality
* code quality
* architecture
* security
* UX and accessibility
* performance and reliability
* documentation and reproducibility

Suggested levels:

* failed
* prototype
* solid MVP
* production-quality
* exceptional

This makes the experiment harder to dismiss.

## Naming and Positioning

The serious external framing should avoid overclaiming “perfect”.

Suggested experiment names:

* Verified App Challenge
* Verified App Benchmark
* Agentic App Benchmark
* Empty Repo to Verified App

The campaign headline can still use the stronger wording:

* Perfect Prompt Challenge

Recommended positioning:

Most AI coding demos prove that an agent can produce code. This experiment proves whether an agent can produce a verifiable software artifact.

## Technology Stack Decision

The selected technology stack should be chosen based on objective benchmark criteria, not personal preference.

Relevant criteria:

* speed of implementation
* strong quality tooling
* reproducibility
* security defaults
* testability
* simplicity
* low architectural noise
* suitability for a workflow-heavy web application

The recommended first benchmark stack was:

* Laravel
* PHP 8.3+
* PostgreSQL
* Blade and Livewire
* Tailwind CSS
* Pest and PHPUnit
* Playwright
* Docker Compose
* GitHub Actions

Reasons:

* the target app is workflow-heavy, not frontend-heavy
* Laravel provides built-in primitives for auth, validation, policies, queues, mail, migrations, testing, CSRF protection, and rate limiting
* a monolith avoids unnecessary frontend/backend complexity
* server-rendered pages reduce test noise
* PostgreSQL provides production-like concurrency and transaction semantics
* the PHP/Laravel ecosystem has strong measurable tooling

Alternative stacks discussed:

* Next.js, Prisma, PostgreSQL
* Django, PostgreSQL
* Ruby on Rails, PostgreSQL
* NestJS, React, PostgreSQL

These are valid, but for the first few-hour benchmark Laravel was considered the strongest default because it maximizes delivery speed and measurable quality while minimizing architectural overhead.

## Experiment Use Case Requirements

The use case must be:

* buildable in a few hours
* complex enough not to be dismissed as trivial
* familiar enough that people understand it immediately
* rich enough to test security, workflow, domain logic, validation, permissions, and edge cases

Avoid trivial examples such as:

* todo app
* notes app
* simple blog
* calculator
* weather dashboard

Candidate use cases discussed:

* appointment scheduling system
* internal purchase approval tool
* inventory reorder assistant
* small CRM with follow-up automation
* return management portal

The selected first use case is an appointment scheduling system, because it includes real complexity such as availability calculation, double-booking prevention, cancellation tokens, admin/public separation, date and time handling, email flows, and concurrency checks.

## Public Product Completeness

The generated application should look like a real product, not just a backend demo.

The app should include:

* MIT license
* public website
* signup
* login
* pricing page
* free plan explanation
* public repository link
* user manual
* documentation
* open-source page
* privacy page
* imprint page
* footer links
* public proof/quality report links

The app should be fully free and MIT licensed.

## Multi-Tenancy

The planned app should be multi-tenant.

The experiment must therefore include tenant isolation as a critical architectural and security requirement.

Tenant isolation must be enforced in the backend and covered by automated tests.

Users may belong to one or more tenants. Tenant-owned data must not leak across tenants.

## Planned App: LibreNexus

LibreNexus is the planned benchmark application. It is a free, MIT-licensed, multi-tenant appointment scheduling system for small offices. The coding agent should define and implement the details. Required headline areas are: public website, signup, login, pricing page, user manual, open-source page, MIT license, repository link, tenant management, staff management, services, availability rules, public booking page, appointment management, cancellations, customer communication, admin dashboard, tenant isolation, security, tests, documentation, and public quality evidence.

# Published

The experiement needs to be properly documented. (This will be a part of my website https://agentic-engineers.dev/ later on. Changing that side is out of scope, but we need to prepare everything)
The LibreNexus application will be published on Github, later deployed via Forge on a Hetzner (librenexus.com) and maybe actually be used (so this must be production-ready). The infrastructure setup is not part of the experiment.
