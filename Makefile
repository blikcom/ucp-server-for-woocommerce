# UCP Server for WooCommerce - developer entry points.
#
# All PHP tooling runs inside Docker containers; the only host requirement is
# Docker. Deployment/testing against a live WordPress + WooCommerce environment
# is left to your own infrastructure.

COMPOSER_IMAGE ?= composer:2
PHP_MIN_IMAGE  ?= php:7.4-cli
PHP_IMAGE      ?= php:8.3-cli
DOCKER_RUN     := docker run --rm -v "$(CURDIR)":/app -w /app
COMPOSER_CACHE := $(HOME)/.cache/ucpws-composer

.PHONY: help hooks install update dump-autoload phpcs phpcbf phpstan test-unit test-unit-modern lint check

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

hooks: ## Enable versioned git hooks (conventional commits)
	git config core.hooksPath .githooks
	@echo "Hooks enabled (core.hooksPath = .githooks)."

install: ## composer install (in Docker)
	@mkdir -p $(COMPOSER_CACHE)
	$(DOCKER_RUN) -v $(COMPOSER_CACHE):/tmp/cache $(COMPOSER_IMAGE) composer install --no-interaction

update: ## composer update (in Docker)
	@mkdir -p $(COMPOSER_CACHE)
	$(DOCKER_RUN) -v $(COMPOSER_CACHE):/tmp/cache $(COMPOSER_IMAGE) composer update --no-interaction

dump-autoload: ## composer dump-autoload (in Docker)
	$(DOCKER_RUN) $(COMPOSER_IMAGE) composer dump-autoload

phpcs: ## WordPress Coding Standards check
	$(DOCKER_RUN) $(PHP_IMAGE) php -d memory_limit=1G vendor/bin/phpcs -p

phpcbf: ## Auto-fix coding standards
	$(DOCKER_RUN) $(PHP_IMAGE) php -d memory_limit=1G vendor/bin/phpcbf -p || true

phpstan: ## Static analysis (level 6, WP/WC stubs)
	$(DOCKER_RUN) $(PHP_IMAGE) php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

test-unit: ## Unit tests on the minimum supported PHP (7.4)
	$(DOCKER_RUN) $(PHP_MIN_IMAGE) vendor/bin/phpunit -c phpunit-unit.xml.dist

test-unit-modern: ## Unit tests on modern PHP
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpunit -c phpunit-unit.xml.dist

lint: phpcs phpstan ## phpcs + phpstan

check: lint test-unit ## Everything: coding standards, static analysis, unit tests
