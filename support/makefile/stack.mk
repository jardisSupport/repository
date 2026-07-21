<---stack-------->: ## -----------------------------------------------------------------------
start: ## Start all databases and wait until healthy
	@echo "Starting databases..."
	@$(DOCKER_COMPOSE) --profile test up -d mysql mariadb postgres
	@echo "Waiting for databases to be healthy..."
	@for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do \
		HEALTHY_COUNT=0; \
		if $(DOCKER_COMPOSE) ps --format json 2>/dev/null | grep -q '"Health":"healthy"'; then \
			HEALTHY_COUNT=$$($(DOCKER_COMPOSE) ps --format json 2>/dev/null | grep -c '"Health":"healthy"' || echo "0"); \
		elif $(DOCKER_COMPOSE) ps 2>/dev/null | grep -q "(healthy)"; then \
			HEALTHY_COUNT=$$($(DOCKER_COMPOSE) ps 2>/dev/null | grep -c "(healthy)" || echo "0"); \
		fi; \
		if [ "$$HEALTHY_COUNT" -ge 3 ]; then \
			echo "✓ All databases are healthy!"; \
			exit 0; \
		fi; \
		echo "  Waiting... ($$i/20) - $$HEALTHY_COUNT/3 databases healthy"; \
		sleep 3; \
	done; \
	echo "✗ Timeout: Not all databases became healthy in time"; \
	echo "Container status:"; \
	$(DOCKER_COMPOSE) ps; \
	echo "Container logs:"; \
	$(DOCKER_COMPOSE) logs --tail=50; \
	exit 1
.PHONY: start

stop: ## Stop and remove all containers
	@echo "Stopping and removing all containers..."
	@$(DOCKER_COMPOSE) --profile test down --remove-orphans
	@echo "All containers stopped and removed."
.PHONY: stop

restart: stop start ## Restart all containers
.PHONY: restart

status: ## Show status of all containers
	@echo "Container status:"
	@$(DOCKER_COMPOSE) ps -a
.PHONY: status

logs: ## Show logs from all containers
	$(DOCKER_COMPOSE) logs -f
.PHONY: logs
