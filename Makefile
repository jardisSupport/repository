SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c
MAKEFLAGS += --warn-undefined-variables
DOCKER_COMPOSE := docker compose

.DEFAULT_GOAL := help

include .env
include support/makefile/stack.mk
include support/makefile/composer.mk
include support/makefile/qa-stack.mk
include support/makefile/docker.mk
include support/makefile/ssh.mk
include support/makefile/hooks.mk

help:
	@echo -e "\033[0;32m Usage: make [target] "
	@echo
	@echo -e "\033[1m targets:\033[0m"
	@egrep '^(.+):*\ ##\ (.+)' ${MAKEFILE_LIST} | sed 's|^[^:]*:||' | column -t -c 2 -s ':#'
.PHONY: help
