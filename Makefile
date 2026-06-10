# LibreNexus quality pipeline.
#
# `make verify` runs every quality gate from a clean checkout and is the
# single public, reproducible benchmark command (see specs/quality-gates.md).
# CI runs exactly these targets (.github/workflows/ci.yml).

SHELL := /bin/bash
.SHELLFLAGS := -o pipefail -c

# Local default is the Herd domain; CI overrides with the artisan serve URL.
APP_URL ?= http://librenexus.test
COVERAGE_MIN ?= 80
MUTATION_MIN ?= 70

# Public pages checked for accessibility and performance budgets.
# Extend PUBLIC_PATHS as public pages are added (see specs/pages.md);
# PUBLIC_URLS derives from APP_URL so CI only needs to override APP_URL.
PUBLIC_PATHS ?= / /pricing /docs /open-source /privacy /imprint
PUBLIC_URLS ?= $(foreach path,$(PUBLIC_PATHS),$(APP_URL)$(path))

# Chrome binary for pa11y and Lighthouse. Locally falls back to the
# puppeteer-managed Chrome; CI uses the runner's installed browser.
CHROME_PATH ?= $(shell ls "$(HOME)"/.cache/puppeteer/chrome/*/chrome-mac-arm64/Google\ Chrome\ for\ Testing.app/Contents/MacOS/Google\ Chrome\ for\ Testing 2>/dev/null | head -1)

# Coverage and mutation need a PHP with pcov or Xdebug. Herd's bundled PHP has
# neither, so locally we fall back to a Homebrew PHP that does; CI installs pcov
# on the default php. Override COVERAGE_PHP to point at any PHP with a driver.
COVERAGE_PHP ?= $(shell php -m 2>/dev/null | grep -qiE 'pcov|xdebug' && echo php || echo /opt/homebrew/opt/php@8.4/bin/php)

COMPOSER_UNUSED := tools/composer-unused.phar
COMPOSER_UNUSED_VERSION := 0.9.6
PHPMD := tools/phpmd.phar
PHPMD_VERSION := 2.15.0

.PHONY: setup format format-check lint static complexity duplication unused require-check \
	test coverage mutation e2e secrets sast audit osv security accessibility performance \
	sbom verify reports-dir help

help: ## List available targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-16s %s\n", $$1, $$2}'

reports-dir:
	@mkdir -p reports

setup: ## Install dependencies, set up env, migrate, build assets
	composer install
	@test -f .env || cp .env.example .env
	@grep -q "^APP_KEY=base64" .env || php artisan key:generate
	php artisan migrate --force
	npm ci
	npm run build
	npx playwright install chromium

format: ## Fix code style (Pint)
	vendor/bin/pint --parallel

format-check: ## QG-FORMAT: code style must pass with zero diffs
	vendor/bin/pint --parallel --test

lint: complexity ## QG-COMPLEXITY + QG-DEADCODE: PHPMD rules

complexity: $(PHPMD) ## PHPMD: complexity, dead code, design rules
	php -d error_reporting=24575 $(PHPMD) app,config,database,routes text phpmd.xml

$(PHPMD):
	@mkdir -p tools
	curl -sL "https://github.com/phpmd/phpmd/releases/download/$(PHPMD_VERSION)/phpmd.phar" -o $(PHPMD)

static: ## QG-STATIC: PHPStan/Larastan level 7, zero errors, no baseline
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

duplication: reports-dir ## QG-DUPLICATION: duplicated lines below threshold (jscpd)
	npx jscpd --config .jscpd.json

unused: $(COMPOSER_UNUSED) ## QG-DEPS-UNUSED: zero unused composer dependencies
	php $(COMPOSER_UNUSED) --no-progress

require-check: ## QG-DEPS-IMPLICIT: no implicit (transitive) dependency usage
	vendor/bin/composer-require-checker check --config-file=composer-require-checker.json

$(COMPOSER_UNUSED):
	@mkdir -p tools
	curl -sL "https://github.com/composer-unused/composer-unused/releases/download/$(COMPOSER_UNUSED_VERSION)/composer-unused.phar" -o $(COMPOSER_UNUSED)

test: ## QG-TESTS: full Pest suite (unit + feature)
	php artisan test --compact

coverage: ## QG-COVERAGE: line coverage >= $(COVERAGE_MIN)%
	$(COVERAGE_PHP) vendor/bin/pest --coverage --min=$(COVERAGE_MIN) --compact

mutation: ## QG-MUTATION: mutation score >= $(MUTATION_MIN)% (Pest --mutate)
	$(COVERAGE_PHP) vendor/bin/pest --mutate --covered-only --parallel --min=$(MUTATION_MIN) --compact

e2e: ## QG-E2E: Pest 4 browser tests (Playwright)
	@if [ -d tests/Browser ]; then \
		php artisan test --compact tests/Browser; \
	else \
		echo "WARNING: tests/Browser does not exist yet - E2E gate is REQUIRED before the app is done (specs/test-plan.md)"; \
	fi

secrets: reports-dir ## QG-SECRETS: zero secrets in the working tree (gitleaks)
	gitleaks dir . --config .gitleaks.toml --redact --report-path reports/gitleaks.json

sast: reports-dir ## QG-SAST: zero Semgrep findings
	semgrep scan --config p/php --config p/security-audit --error --metrics=off \
		--exclude=vendor --exclude=node_modules --exclude=storage --exclude=public/build \
		| tee reports/semgrep.txt

audit: ## QG-DEPS-VULN: no known vulnerabilities in php + js dependencies
	composer audit
	npm audit --audit-level=high

osv: ## QG-DEPS-VULN: OSV scan of lockfiles
	osv-scanner scan source --recursive --no-ignore=false . || osv-scanner -r .

security: secrets sast audit osv ## All security gates

accessibility: ## QG-A11Y: WCAG2AA, zero errors on public pages (pa11y)
	npx pa11y-ci --config .pa11yci $(PUBLIC_URLS)

performance: reports-dir ## QG-PERF: Lighthouse budgets on public pages
	CHROME_PATH="$(CHROME_PATH)" npx lhci autorun $(foreach url,$(PUBLIC_URLS),--collect.url=$(url))

sbom: reports-dir ## Generate CycloneDX SBOM (syft)
	syft dir:. -o cyclonedx-json=reports/sbom.cdx.json -q

verify: format-check complexity static duplication unused require-check test coverage mutation e2e security accessibility performance sbom ## Full quality pipeline (the public benchmark)
	@echo ""
	@echo "================================================"
	@echo " make verify: ALL QUALITY GATES PASSED"
	@echo "================================================"
