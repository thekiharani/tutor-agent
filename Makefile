# The four commands anyone needs:  make up, make seed, make demo, make purge.
SHELL := /bin/sh
COMPOSE := docker compose
MOODLE_ROOT := /var/www/moodle

.PHONY: up down reset seed rehearse purge logs demo

.env:
	@cp .env.example .env
	@echo "Created .env from .env.example"

up: .env                ## Build and start all three containers
	$(COMPOSE) up -d --build
	@echo
	@echo "Moodle is starting. First run installs the database and takes a"
	@echo "couple of minutes. Watch it with:  make logs"

down:                   ## Stop the containers, keep the data
	$(COMPOSE) down

reset: .env             ## Delete everything and reinstall from scratch
	$(COMPOSE) down -v
	$(MAKE) up

seed:                   ## Create the demo course and the five students
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/public/local/tutoragent/cli/seed_demo.php

rehearse:               ## Clear student.blank's style so you can run the demo again
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/public/local/tutoragent/cli/seed_demo.php --reset-blank

purge:                  ## Clear Moodle's caches (first thing to try)
	$(COMPOSE) exec -u www-data moodle \
		php $(MOODLE_ROOT)/admin/cli/purge_caches.php

logs:                   ## Follow the logs of all containers
	$(COMPOSE) logs -f

demo: .env              ## Print the logins and the demo script
	@sh ./scripts/demo.sh
