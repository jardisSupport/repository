<---qa tools----->: ## -----------------------------------------------------------------------
phpunit: start ## Run all tests
	$(DOCKER_COMPOSE) run --rm phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests
.PHONY: phpunit

phpunit-reports: start ## Run all tests with reports
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-clover tests/reports/clover.xml --coverage-xml tests/reports/coverage-xml
.PHONY: phpunit-reports

phpunit-coverage: start ## Run all tests with coverage text
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-text
.PHONY: phpunit-coverage

phpunit-coverage-html: start ## Run all tests with HTML coverage
	$(DOCKER_COMPOSE) run --rm -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-html tests/reports/coverage-html
.PHONY: phpunit-coverage-html

phpstan: ## Run PHPStan analysis
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpstan analyse /app/src -c phpstan.neon
.PHONY: phpstan

phpcs: ## Run coding standards
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpcs /app/src
.PHONY: phpcs
