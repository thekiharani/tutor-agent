SHELL := /bin/sh
COMPOSE := docker compose
MOODLE_ROOT := /var/www/moodle

.PHONY: up down reset seed rehearse purge logs demo test test-links

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

# The tests stage runs pytest during the build, so a failing test fails the
# build. It needs no running stack and no network.
test:
	docker build --target tests -t tutoragent-tests ./recommender

# Opens every link in intents.json. Separate because it needs the internet and
# takes about a minute; 403 passes, because Cloudflare answers a script that way
# on hosts a student's browser reaches without trouble.
test-links: test
	docker run --rm tutoragent-tests python -m pytest test_recommender.py -m network -q
