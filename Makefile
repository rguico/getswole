PORT ?= 8934
DB := getswole.db
SCHEMA := schema.sql
DEPLOY_HOST := lpangelrob_rguico@ssh.nyc1.nearlyfreespeech.net
DEPLOY_PATH := getswole/

BACKUP_DIR := backups

.PHONY: serve db seed parse export schema data deploy backup overwrite

serve:
	php -S localhost:$(PORT)

backup:
	mkdir -p $(BACKUP_DIR)
	scp $(DEPLOY_HOST):$(DEPLOY_PATH)$(DB) $(BACKUP_DIR)/getswole-$$(date +%Y%m%d-%H%M%S).db

$(DB): $(SCHEMA)
	rm -f $(DB)
	sqlite3 $(DB) < $(SCHEMA)

db: $(DB)

seed: db
	python3 scripts/seed_machines.py

parse: seed
	python3 scripts/parse_getswole.py

export: parse
	python3 scripts/export_data.py

schema: db
	sqlite3 $(DB) ".schema"

data: parse
	@for t in $$(sqlite3 $(DB) ".tables"); do \
		echo "== $$t =="; \
		sqlite3 -header -column $(DB) "SELECT * FROM $$t;"; \
		echo; \
	done

deploy:
	rsync -r --exclude $(DB) . $(DEPLOY_HOST):$(DEPLOY_PATH)

overwrite:
	scp $(DB) $(DEPLOY_HOST):$(DEPLOY_PATH)$(DB)
