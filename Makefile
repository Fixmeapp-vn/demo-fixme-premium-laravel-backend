# Thin wrappers around composer / artisan. Run `make help` for the list.
PHP      ?= php
COMPOSER ?= composer

.DEFAULT_GOAL := help
.PHONY: help setup build run-api run-metrics run-worker test test-parallel fmt fmt-check lint check

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## First-time setup: install deps, create .env and APP_KEY
	@test -f .env || cp .env.example .env
	$(COMPOSER) install
	$(PHP) artisan key:generate

build: ## Install production deps and cache config/routes
	$(COMPOSER) install --no-dev --optimize-autoloader
	$(PHP) artisan optimize

run-api: ## Run the API on HTTP_ADDR
	$(PHP) artisan platform:serve

run-metrics: ## Run the internal metrics listener on METRICS_ADDR
	$(PHP) artisan platform:serve --metrics

run-worker: ## Run the queue worker (graceful on SIGTERM/SIGINT)
	$(PHP) artisan platform:worker

test: ## Run the test suite (Pest)
	$(PHP) artisan config:clear
	$(PHP) artisan test

test-parallel: ## Run the test suite in parallel
	$(PHP) artisan config:clear
	$(PHP) artisan test --parallel

fmt: ## Format code (Laravel Pint)
	$(PHP) vendor/bin/pint

fmt-check: ## Check formatting without changing files
	$(PHP) vendor/bin/pint --test

lint: ## Static analysis (Larastan / PHPStan)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

check: fmt-check lint test ## Formatting, static analysis and tests
