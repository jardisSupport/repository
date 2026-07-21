<---composer----->: ## -----------------------------------------------------------------------
install: ## Run composer install
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli composer install --no-cache
.PHONY: install

update: ## Run composer update
	$(DOCKER_COMPOSE) run --rm --no-deps -e XDEBUG_MODE=off phpcli composer update
.PHONY: update

autoload: ## Run composer dump-autoload
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli composer dumpautoload
.PHONY: autoload
