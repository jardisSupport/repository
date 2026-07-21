<---hooks-------->: ## -----------------------------------------------------------------------
install-hooks: ## Install git hooks (pre-commit + pre-push)
	@echo '#!/bin/bash' > .git/hooks/pre-commit
	@echo 'bash ./support/pre-commit-hook.sh' >> .git/hooks/pre-commit
	@chmod +x .git/hooks/pre-commit
	@echo '#!/bin/bash' > .git/hooks/pre-push
	@echo '# Jardis Pre-Push Hook — Quality Gate' >> .git/hooks/pre-push
	@echo 'set -e' >> .git/hooks/pre-push
	@echo 'echo "=== Jardis Pre-Push Quality Gate ==="' >> .git/hooks/pre-push
	@echo '# Doku-Fast-Path: keine *.php geaendert -> PHP-Gates ueberspringen (kein --no-verify, der Hook entscheidet korrekt)' >> .git/hooks/pre-push
	@echo 'base="$$(git rev-parse --abbrev-ref --symbolic-full-name @{u} 2>/dev/null || echo origin/develop)"' >> .git/hooks/pre-push
	@echo 'changed="$$(git diff --name-only "$$base"...HEAD 2>/dev/null)"' >> .git/hooks/pre-push
	@echo 'if ! echo "$$changed" | grep -q "[.]php$$"; then' >> .git/hooks/pre-push
	@echo '  echo ">>> Nur Doku/Metadaten geaendert (keine *.php) — phpcs/phpstan/phpunit uebersprungen"' >> .git/hooks/pre-push
	@echo '  echo "=== Quality Gate (Doku-Fast-Path) bestanden ==="' >> .git/hooks/pre-push
	@echo '  exit 0' >> .git/hooks/pre-push
	@echo 'fi' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpcs"' >> .git/hooks/pre-push
	@echo 'make phpcs || { echo "PHPCS fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'echo ">>> make phpstan"' >> .git/hooks/pre-push
	@echo 'make phpstan || { echo "PHPStan fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'if [ -d "tests" ]; then' >> .git/hooks/pre-push
	@echo '  echo ">>> make phpunit"' >> .git/hooks/pre-push
	@echo '  make phpunit || { echo "PHPUnit fehlgeschlagen — Push abgebrochen"; exit 1; }' >> .git/hooks/pre-push
	@echo 'else' >> .git/hooks/pre-push
	@echo '  echo ">>> make phpunit: uebersprungen (Interface-Projekt)"' >> .git/hooks/pre-push
	@echo 'fi' >> .git/hooks/pre-push
	@echo 'echo "=== Quality Gate bestanden ==="' >> .git/hooks/pre-push
	@chmod +x .git/hooks/pre-push
	@echo "Hooks installed."
.PHONY: install-hooks
