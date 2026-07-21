<---docker----->: ## -----------------------------------------------------------------------
shell: ## Run a shell inside the phpcli container
	$(DOCKER_COMPOSE) run --rm --no-deps -it phpcli sh
.PHONY: shell

clean: ## Stop containers and clean up volumes
	@echo "Cleaning up containers and volumes..."
	@$(DOCKER_COMPOSE) down -v --remove-orphans
	@echo "Cleanup complete."
.PHONY: clean

remove: ## Stops and removes containers, images, network and caches
	@echo "Removing all Docker resources..."
	@$(DOCKER_COMPOSE) down --volumes --remove-orphans --rmi "all"
	@docker images --filter dangling=true -q 2>/dev/null | xargs -r docker rmi 2>/dev/null || true
	@echo "Complete removal done."
.PHONY: remove
