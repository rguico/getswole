PORT ?= 8934
DB := getswole.db
SCHEMA := schema.sql
DEPLOY_HOST := lpangelrob_rguico@ssh.nyc1.nearlyfreespeech.net
DEPLOY_PATH := getswole/

BACKUP_DIR := backups

.PHONY: help serve db seed parse export schema data deploy backup overwrite

.DEFAULT_GOAL := serve

help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## /{printf "%-10s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

serve: ## Run the local PHP dev server
	php -S localhost:$(PORT)

backup: ## Download a timestamped copy of the deployed database
	mkdir -p $(BACKUP_DIR)
	scp $(DEPLOY_HOST):$(DEPLOY_PATH)$(DB) $(BACKUP_DIR)/getswole-$$(date +%Y%m%d-%H%M%S).db

$(DB): $(SCHEMA)
	rm -f $(DB)
	sqlite3 $(DB) < $(SCHEMA)

db: $(DB) ## Create the local database from the schema

seed: db ## Seed the database with machine data
	python3 scripts/seed_machines.py

parse: seed ## Parse source workout data into the database
	python3 scripts/parse_getswole.py

export: parse ## Export parsed data
	python3 scripts/export_data.py

schema: db ## Print the database schema
	sqlite3 $(DB) ".schema"

data: parse ## Dump the contents of every table
	@for t in $$(sqlite3 $(DB) ".tables"); do \
		echo "== $$t =="; \
		sqlite3 -header -column $(DB) "SELECT * FROM $$t;"; \
		echo; \
	done

deploy: ## Rsync the project to the deploy host, deleting remote-only files (excludes the db)
	rsync -r --delete --exclude '$(DB)*' . $(DEPLOY_HOST):$(DEPLOY_PATH)

overwrite: ## Push the local database to the deploy host
	scp $(DB) $(DEPLOY_HOST):$(DEPLOY_PATH)$(DB)
