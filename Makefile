SHELL := /bin/sh
COMPOSE := docker compose
MOODLE_ROOT := /var/www/moodle

.PHONY: up down reset seed rehearse purge logs demo

# Strips the explanatory comments and the blank runs they leave behind.
.env:
	@sed '/^[[:space:]]*#/d' .env.example | cat -s | sed '/./,$$!d' > .env
	@echo "Created .env from .env.example"

up: .env
	$(COMPOSE) up -d --build
	@echo
	@echo "Moodle is starting. First run installs the database and takes a"
	@echo "couple of minutes. Watch it with:  make logs"

down:
	$(COMPOSE) down

reset: .env
	$(COMPOSE) down -v
	$(MAKE) up

seed:
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/public/local/tutoragent/cli/seed_demo.php

rehearse:
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/public/local/tutoragent/cli/seed_demo.php --reset-blank

purge:
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/admin/cli/purge_caches.php

logs:
	$(COMPOSE) logs -f

demo: .env
	@sh ./scripts/demo.sh
