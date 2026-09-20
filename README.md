# Personalised E-Learning: a VARK-aware tutor agent for Moodle

A student opens an activity in Moodle. A notification appears with a link to an
extra resource chosen for that student's VARK learning style: a visual learner
gets a video, a read/write learner gets an article or PDF, for the same activity.

Two containers do the work. Moodle runs the plugin; a small Python service holds
the classifier and the content library.

```
docker compose
  db           postgres:18
  moodle       php:8.4-apache + Moodle 5.2, with the local_tutoragent plugin
  recommender  python:3.14-slim + FastAPI + scikit-learn
```

Moodle calls the recommender over the private compose network with a 1 second
connect and 2 second total timeout, and ignores any failure. If the recommender
is down, Moodle pages render normally and simply show no recommendation.

For how it works internally - the request path, the model, the plugin's hooks and
the boot sequence - see [ARCHITECTURE.md](ARCHITECTURE.md).

## Prerequisites

Docker Desktop (or Docker Engine) with Compose v2, and about 1.7GB of disk for the
images (Moodle 928MB, Postgres 479MB, recommender 264MB) plus room for the
database. Nothing else: no PHP, no Python, no Moodle on your machine.

## Running it

```sh
make up      # first run builds the images and installs Moodle - see below
make seed    # creates the demo course and the five students
make demo    # prints the logins and the demo script
```

`make up` copies `.env.example` to `.env` for you if it is missing. Nothing in
`.env` is secret; it is a throwaway local demo. Change `MOODLE_PORT` there if
8080 is already taken, and change `MOODLE_WWWROOT` to match.

**The first `make up` takes 10 to 25 minutes.** It downloads Moodle (86MB) and
scikit-learn, compiles the PHP extensions, then installs Moodle into Postgres.
Later runs take seconds. Watch it with `make logs`; it is ready when the log says
`[entrypoint] starting apache`.

Then open <http://localhost:8080>.

| Command | What it does |
|---|---|
| `make up` | Build and start everything |
| `make down` | Stop, keep the data |
| `make reset` | Delete everything and reinstall from scratch |
| `make seed` | Create the demo course and students |
| `make rehearse` | Clear `student.blank`'s style so you can run the demo again |
| `make purge` | Clear Moodle's caches |
| `make logs` | Follow the logs |
| `make demo` | Print the logins and the demo script |

## Published images

`.github/workflows/ci.yml` builds both images on every push to `main` and
publishes them to the GitHub Container Registry:

```
ghcr.io/thekiharani/tutor-agent/moodle
ghcr.io/thekiharani/tutor-agent/recommender
```

Each is tagged `latest` on `main`, `sha-<commit>` on every build, and
`1.2.3` / `1.2` when a `v1.2.3` tag is pushed. The packages inherit the
repository's visibility, so pulling needs a token with `read:packages`:

```sh
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <your-username> --password-stdin
docker pull ghcr.io/thekiharani/tutor-agent/recommender:latest
```

Pull requests build both images but publish nothing, so a broken Dockerfile
fails the check without reaching the registry.

The Moodle image carries the plugin: it builds from the repository root
(`moodle/Dockerfile` with context `.`) and copies `plugin/local/tutoragent`
into the tree, so a pulled image serves the plugin with no mount. The images
are `linux/amd64` only, since building the Moodle image for arm64 under
emulation costs far more CI time than a demo project justifies. `make up`
still builds natively on Apple Silicon.

## Production

`compose.prod.yml` is the deploy file. It differs from `compose.yml` in
three ways: it pulls the published images instead of building, it runs no `db`
service, and it joins the external `norialabs` network. Moodle publishes
`${MOODLE_PORT}` on every interface, so whatever fronts it does not have to
run on the host.

Postgres is expected to be an existing service named `postgres` on that
network. Create the database and its owner before the first start - Moodle's
installer creates tables, not the database. The recommender publishes no port
at all; Moodle reaches it at `http://recommender:8000` across the network.

```sh
cp .env.prod.example .env      # then fill in every CHANGE_ME
docker compose -f compose.prod.yml up -d
docker compose -f compose.prod.yml exec -u www-data moodle \
    php /var/www/moodle/public/local/tutoragent/cli/seed_demo.php
```

### Upgrading a site seeded before the library was narrowed to C

**Read this before showing an existing deployment to anyone.** The seeder
creates and updates; it has never deleted. A site seeded by an earlier revision
still carries the nine courses this project no longer defines - Python
Programming, Data Structures, Databases and SQL and the rest - and every
activity in them now answers `204`, so a student opening one gets no
notification at all. That reads as a fault, not as a boundary, and it is the
first thing anyone clicking around will find.

Clear them once, after deploying the new image:

```sh
docker compose -f compose.prod.yml exec -u www-data moodle \
    php /var/www/moodle/public/local/tutoragent/cli/seed_demo.php --prune-retired
```

It deletes the nine retired courses and the four categories they leave empty,
and it is idempotent. **It deletes the courses and everything in them**, which
is why it is a flag rather than something every start does. The retired courses
are listed by code in `RETIRED_COURSES`, so a course anyone else made is never
matched. Without the flag the seeder deletes nothing, as before.

Verified by seeding the ten-course state from the previous revision and running
it: ten courses to one, six categories to two, and a second run a no-op.

Point your TLS proxy at `${MOODLE_PORT}` and make `MOODLE_WWWROOT` match the
public origin exactly. The container speaks plain HTTP on that port and it is
open to the network, so the host firewall has to be what keeps it off the
public internet.

Set `MOODLE_SSLPROXY=1` whenever something else terminates TLS - a Cloudflare
tunnel, nginx, Caddy. Without it Moodle sees a plain HTTP request, compares it
to an `https` wwwroot and redirects to https, which arrives as HTTP again:
`ERR_TOO_MANY_REDIRECTS`. A wwwroot that does not match the address in the
browser loops the same way, so check both before blaming the proxy.

`wwwroot` and `sslproxy` are rewritten from the environment on every start.
The installer writes them once and the entrypoint restores config.php from the
data volume on every recreate, so without that rewrite an edited
`MOODLE_WWWROOT` would silently do nothing.

Since the plugin now ships in the image, deploying a plugin change means
pulling a new image - and the entrypoint deliberately does not touch the
database beyond the first install, so a bumped `version.php` needs the upgrade
run by hand:

```sh
docker compose -f compose.prod.yml pull
docker compose -f compose.prod.yml up -d
docker compose -f compose.prod.yml exec -u www-data moodle \
    php /var/www/moodle/admin/cli/upgrade.php --non-interactive
```

## Logins

Every account uses the same password, `DEMO_PASSWORD` in `.env`, which defaults
to `Demo@2026!`.

| Username | Role |
|---|---|
| `admin` | site administrator, created by the installer |
| `demo.admin` | Lydia Muthoni, site administrator, created by the seed |
| `demo.teacher` | Miriam Wafula, editing teacher on the course |
| `student.visual` | Amara Otieno, pre-set style: visual |
| `student.aural` | Brian Kamau, pre-set style: auditory |
| `student.rw` | Chloe Wanjiru, pre-set style: read/write |
| `student.kines` | David Mwangi, pre-set style: kinesthetic |
| `student.blank` | student, no style yet - use this one to demo the questionnaire |
| `student.blank2` … `student.blank6` | five more with no style, for repeat runs or for someone else to try |

## The courses

Ten courses, with every seeded student enrolled in all of them, so any student
can open any activity without checking a roster mid-demo.

**Seeding runs by itself on every container start**, so a fresh stack comes up
with the courses already in it and you do not have to remember `make seed`. It is
safe to run repeatedly, and what it does depends on the object:

| | On every run |
|---|---|
| Courses and activities | **Created if missing, updated if they differ from `seed_demo.php`.** An activity's intro is what the recommender routes on, so letting the database drift from the file means recommendations quietly go to the wrong topic. |
| Categories | **Created if missing, renamed if they differ.** The top level adopts the installer's "Category 1" while it is still untouched, so the site is not left with an empty placeholder next to the real tree. |
| Course dates | **Set on creation, and repaired only if never set.** A course someone has dated deliberately is left alone. |
| Users, passwords, learning styles | **Created if missing. Names may be corrected; passwords and styles never are.** Rewriting a password would lock someone out mid-demo and rewriting a style would undo `make rehearse` or reset a student who has just taken the questionnaire. |
| Enrolments | Added if missing; Moodle does not duplicate them. |
| Self enrolment | **Opened on every seeded course, with no enrolment key**, unless `SEED_SELF_ENROL=0`. |
| The front page | **Set to the course catalogue**: Home turned on, categories and a course search box on it, and logging in lands there rather than on the Dashboard. |
| Anything you removed from `seed_demo.php` | **Never deleted.** |

Activities are keyed on a stable `idnumber` (`tutoragent:<course>:<activity>`), not
on their name, so renaming one in Moodle updates it rather than creating a second
beside it. A site seeded before this existed has its activities adopted on the
next run instead of duplicated.

Set `SEED_ON_START=0` in `.env` to turn the automatic seeding off. `make seed`
still runs it by hand at any time.

### Production seeds the catalogue but none of the people

`SEED_USERS` controls whether the demo teacher and the ten students are created.
`compose.yml` defaults it to `1` and `compose.prod.yml` to `0`, so a deployment
that never sets the variable still cannot put demo accounts on a real site.

With `SEED_USERS=0` the categories, courses and activities are seeded exactly as
before and **no people are**: no teacher, no students, no enrolments and no saved
learning styles. The only account is the administrator the installer creates from
`MOODLE_ADMIN_USER`. Real students sign in with their own accounts, enrol
themselves from the front page, and take the questionnaire the first time they
open a course, which is the point of the thing.

Turning it off never removes accounts that already exist. If a site was seeded
with them once, they stay until someone deletes them deliberately.

`php seed_demo.php --no-users` does the same for a one-off run.

The teacher is the exception, and deliberately so: it sits outside `SEED_USERS`
and is created in both environments, because a course with no teacher is the
other thing that gives a seeded site away. It comes from the environment rather
than from the seed file:

| Variable | |
|---|---|
| `MOODLE_TEACHER_USER` | The username. **Empty means no teacher at all.** |
| `MOODLE_TEACHER_FIRSTNAME` / `_LASTNAME` | Default to "Course Teacher". |
| `MOODLE_TEACHER_EMAIL` | Defaults to `<username>@example.com`. |
| `MOODLE_TEACHER_PASSWORD` | Falls back to `DEMO_PASSWORD`, so a deployment need only set one. |

Set these to a real person before deploying. As with every other account, an
existing one is never given a new password; only the name and address are
brought into line.

They sit in a category tree rather than in the installer's "Category 1", carry
course codes and real term dates, and have a teacher on them, because an empty
category called "Category 1" and a course starting on 1 January 1970 are the
first things anyone notices on a seeded site.

| Category | Code | Course | Activities |
|---|---|---|---|
| Programming Fundamentals | `CS 101` | Introduction to Programming in C | 6 |

One course, because the content library holds one course's worth of material.
The six activities are the six topics in `intents.json` - Introduction to C,
Data Types, Operators, Control Structures, Arrays and Functions - and the
seeder is a projection of that file rather than a list maintained beside it.
Seeding an activity the library cannot answer would only produce a 204.

It sits under **Programming Fundamentals**, inside one top-level category,
**School of Computing and Informatics**, which is the installer's placeholder
category renamed rather than a second category created beside it.

All 6 activities are covered by the recommender: every one returns a resource
for every one of the four styles, 24 combinations in total, and each activity's
four links are four different links in four different media. `make test` asserts
all of that on every build, and it was also checked by hand, by logging into the
running site as each of the four pre-set students.

Seeded addresses are all `@example.com`, which is reserved by RFC 2606 for
exactly this purpose. It is deliberate: a plausible-looking real domain would
mean a misconfigured site could email strangers.

### Students enrol themselves

A production site seeds no student enrolments, so without this a real student
signs in to "You're not enrolled in any courses" and has no way out of it: Moodle
gives every new course a self enrolment method and leaves it **disabled**, and a
fresh Moodle 5.2 also ships with the Home page turned off and the Dashboard as
the landing page, so there is not even a catalogue to browse.

Seeding fixes both, on every run:

- **Self enrolment is enabled on the course**, with no enrolment key, the
  student role, no capacity limit and the welcome email off (there is no mail
  server in the container). A signed-in student opens a course and gets "Enrol
  me"; the questionnaire gate and the recommendations then work exactly as they
  do for the demo students.
- **Home is turned on and the front page is the catalogue** - the category tree
  with its courses, and a course search box - and `defaulthomepage` sends people
  there when they log in instead of to an empty Dashboard.

`SEED_SELF_ENROL=0`, or `php seed_demo.php --no-self-enrol`, leaves every
course's enrolment methods exactly as they are. Use it if enrolment is meant to
be controlled by hand, because seeding runs on every container start: closing
self enrolment in the Moodle UI and leaving the variable at `1` means the next
restart opens it again.

Accounts are a separate question. Moodle's own self-registration is off, so
somebody still has to create student accounts, by hand or by CSV upload, unless
an administrator turns email-based registration on.

## The demo

1. Log in as `student.blank`, open "Introduction to Programming in C".
   A notification invites you to take the VARK questionnaire.
2. Take it. The page names your learning style.
3. Open `CS 101` and the activity "Arrays". A notification appears with a matching resource.
4. Log out, log in as `student.rw`, open **the same** Arrays activity.
5. A different link appears: an article instead of a video.

Steps 3 and 5 are the point of the project. `make demo` prints this at any time.
Any of the 38 activities shows the same contrast; if you are asked whether the
system only knows C, "Joins" in `CS 204` and "Sorting Algorithms" in `CS 202`
make the point quickly.

To run it again, `make rehearse` puts `student.blank` back to having no learning
style. Do this between rehearsals, or step 1 shows a recommendation instead of the
invitation.

The sixteen questions are the VARK questionnaire as reproduced in Appendix A of the
project report. More than one option may be selected per question, which the
instrument allows; the dominant style is the highest count, and a tie is broken in
the order V, A, R, K and recorded so the page can say it happened. The questions
live in one array in `plugin/local/tutoragent/classes/form/vark_form.php`.

The VARK questionnaire is copyright VARK Learn Limited. It is credited on the
questionnaire page, and it is used here for the educational purpose the report
describes. Check the terms before reusing it anywhere else.

## Requiring the questionnaire

A student with no learning style is redirected to the questionnaire whatever
page they ask for, and released once they answer. This is on by default; turn it
off under *Site administration > Plugins > Local plugins > Tutor agent*, or from
the command line if a page will not load:

```sh
docker compose exec -u www-data moodle \
  php /var/www/moodle/admin/cli/cfg.php \
  --component=local_tutoragent --name=forceredirect --set=0
```

The gate runs on every request, so it is built to fail open, never closed. Site
administrators are never redirected, which is the first way back in; the command
above is the second. Anything that throws inside the gate is swallowed and the
request continues normally. Login, logout, Moodle's own forced flows (site
policy, forced password change) and every stylesheet, script and file endpoint
are exempt - without those exemptions the questionnaire would render with no CSS
and no JavaScript, and its form would not submit.

With the gate off, students are invited to the questionnaire by a notification
on the course page instead, and can ignore it.

## Versions, and why these ones

| Layer | Version | Why |
|---|---|---|
| Moodle | 5.2.3+ (Build 20260916) | current stable release |
| PHP | 8.4 | Moodle 5.2 needs >= 8.3; see below |
| Postgres | 18 | Moodle 5.2 needs >= 16 |
| Python | 3.14 | newest with numpy and scikit-learn wheels |

PHP 8.5 was tried first. Moodle 5.2 installs and runs on it, but its core code
raises about 120 PHP 8.5 deprecation notices (non-canonical casts, `case ...;`,
the backtick operator, `xml_parser_free()`). With developer debugging on those
appear in the browser. PHP 8.4 produces none.

Moodle 5.2 serves from a `public/` subdirectory: the code root holds `config.php`
and the CLI scripts, and Apache's document root is `<root>/public`. The plugin
therefore lives at `/var/www/moodle/public/local/tutoragent`. The image copies
it in at build time; `compose.yml` also bind-mounts `plugin/local/tutoragent`
over the top so you can edit it without rebuilding. `compose.prod.yml` drops
that mount and runs what the image carries.

## How a recommendation is chosen

The content library is `recommender/data/intents.json`: 24 tags (the 6 topics of
the C language x 4 modalities), 132 example phrasings, 151 responses over 96
distinct URLs. At image build time `train.py` stems every phrasing with the
Lancaster stemmer, builds a 68-word vocabulary, and trains the feed-forward
network the project report describes - two hidden layers of 8 units,
68 -> 8 -> 8 -> 24 with a softmax output, `batch_size=8` and up to 1000
iterations, matching the report's appendix. The architecture is the report's
unchanged; only `random_state` moved, from 42 to 0, kept from when the library
was larger and a worse seed left a pattern misfitted.

Training needs scikit-learn; serving does not. `train.py` saves the fitted
weights to `model.npz` and then asserts that the numpy forward pass in the same
file reproduces scikit-learn's `predict_proba` exactly - on all 132 training
vectors and on the demo's own inputs - before the build is allowed to succeed.
The runtime image therefore carries numpy and nltk but neither scikit-learn nor
scipy, which is 211MB it would otherwise never use. Measured over 20,002
vectors including all-zero, all-ones and random out-of-domain inputs, the two
paths agree to 0.000e+00, and the numpy path is about six times faster per
request.

At request time the service receives the activity's name, its intro text and the
student's stored style, and:

1. **Looks the activity up in the topic table.** `TOPICS` in `app.py` maps 6
   keywords to topics. The keys are matched as substrings of the activity's name
   and intro, **longest first**, so a more specific topic beats a more general one
   that is a substring of the same text. If nothing matches, the service returns
   `204 No Content` and Moodle shows nothing: the library covers the 6 topics of
   the C language and an activity about pointers gets silence, not the nearest of
   24 tags.
2. **Expands topic synonyms.** The vocabulary contains `if/else`, `loop`, `swic`
   and `whil` but no stem for "control", so an activity called "Control
   Structures" would match nothing on its own. Every topic carries an alias entry.
3. **Classifies, restricted to the student's style.** The style is stored data,
   not something to infer, so the softmax is masked to the four classes for that
   modality and the network chooses the topic among them.
4. **Lets the keyword match settle any disagreement.** A substring hit is
   evidence; the softmax is an estimate. Where they differ the keyword wins and
   the response is marked `"source": "keyword"`. This is the one step that is not
   in the original design, and the reason for it is measured below.
5. **Picks a response deterministically**, `crc32(module + style) % len(responses)`,
   so the same activity shows the same link on every rehearsal.

Try it without Moodle:

```sh
curl -s localhost:8000/health
curl -s -X POST localhost:8000/recommend -H 'Content-Type: application/json' \
  -d '{"module_name":"Arrays","module_intro":"Introduction to arrays in C","style":"visual"}'
```

`style` must be one of `visual`, `auditory`, `read_write`, `kinesthetic`;
anything else is rejected with 422.

## Changes made to the supplied content

The supplied content is the **first 16 tags** of `intents.json`, which cover
arrays, control structures, functions and data types. Its patterns and tags are
untouched and no URL in it was changed or invented; the 136 tags that follow it
are new and are marked `"source": "demo"`, so the two are never confused. The
first 16 objects are also byte-identical to the file as supplied.

Four classes of defect were repaired in the supplied content, all of which showed
on screen:

- **Six of sixteen tags** carried stray leading or trailing spaces
  (`'functions visual '`, `' arrays auditory'`, and four more), which made the
  tag lookup miss.
- **All nine malformed anchors were repaired.** They rendered as plain text
  rather than links: three written `<a href>https://...'>` with the `='`
  missing, two closing tags written `>/a>` and `,/a>`, one `<a ahref = '...'>`,
  one closing its opening tag with `'<this link` instead of `'>`, and two written
  `<a href = https://...'>` with the opening quote missing. Only the markup
  changed; no URL was touched by the repair.
- **Twelve responses ran text straight into the link**, rendering as
  "see the following practical**in this link**". A space was added before the
  anchor.
- **Twenty-one spelling errors** in the prose: "stotage", "folllowing",
  "progarm", "poinetrs", "pactical", "Declaringand" and others. Only spelling
  changed; no sentence was reworded.

## The thirty-eight-topic detour, and why it was undone

An earlier revision of this project answered a request for a catalogue students
could enrol in by adding 34 topics beyond the supplied four - data structures,
algorithms, OOP, SQL, operating systems, networking, web, software engineering,
Python - reaching 38 topics, 152 tags and ten courses. That content was real and
checked: 272 links, every URL requested, none invented.

It was the wrong shape for the brief. The client's requirement is that a student
picks a topic **in C** and is recommended content for their learning style, and
thirty-two of those thirty-eight topics were not C at all. A student signing in
found a Python course and an SQL course sitting beside the one the project is
about.

All of it has been removed: 128 tags from `intents.json`, 32 keys from `TOPICS`
in `app.py`, and nine courses from `seed_demo.php`. What survives is the six
topics of the C language, which is what the content library was always for. The
useful residue of the detour is the method it forced - templated responses per
topic and modality, one medium per modality, verified structural URLs - which is
the method the six C topics are now held to, by tests rather than by care.

## The C content, and one medium per modality

The six C-language topics - introduction to C, data types, operators, control
structures, arrays and functions - are the ones the project is actually about,
and they are the only topics whose materials were ever C: the data-structures
and algorithms topics that used to sit beside them carried no C-specific link
between them. 84 resources were added across their 24 tags.

**Each modality now serves exactly one kind of resource**, which is the claim
the whole project rests on and the one it previously could not make:

| Modality | What it serves |
|---|---|
| visual | videos, animations, a step-through visualiser, illustrated walkthroughs |
| auditory | lecture courses: MIT 6.087 and 6.S096, CS50, Stanford CS107 |
| read/write | the C language reference, Beej's Guide, the C wikibook, articles |
| kinesthetic | interactive lessons, online compilers, exercise sets, practice tracks |

Getting there meant removing, not only adding. In the supplied content visual,
auditory and kinesthetic were all YouTube and only the framing sentence differed
- a kinesthetic student was handed a video to watch, which is the one thing
kinesthetic is not. **47 responses were removed**: 16 videos from kinesthetic
tags, 31 non-lecture videos from auditory tags. The videos that remain are all
under visual, where a video belongs. `test_recommender.py` asserts this per tag,
so it cannot drift back.

Two further rules the file holds, both tested:

- **No URL sits under two modalities of the same topic.** `pick_response` shows
  one link per activity and style, so a shared URL can put the *same* link in
  front of a visual learner and a kinesthetic one, which is precisely the
  comparison the demo exists to show.
- **No tag repeats a link.** `pick_response` is a modulo over the list, so a
  duplicate silently doubles that link's odds. Two were found in
  `functions visual` and removed.

**No YouTube video IDs were invented.** An invented 11-character ID still returns
HTTP 200 on the watch page, so a wrong one cannot be caught by requesting it;
every video in the file is verified through YouTube's oEmbed endpoint, which
returns the real title for a live video and 404 for a dead one.

## Every link is checked

A student clicks these links to learn from them, so a dead one is a dead lesson,
and a link to the wrong language is worse than a dead one because it fails
silently. Every URL in `intents.json` is opened by `make test-links`.

**What that found in the supplied content.** Thirteen problems, every one of
them present in the 2022 file as imported - traceable in git, none introduced by
this project:

- **Three URLs were dead.** `ee.hawaii.edu/~tep/EE160/Book/PDF/Chapter7.pdf`
  returns a hard 403 (*"Server unable to read htaccess file, denying access to
  be safe"*), and `trytoprogram.com` and `fresh2refresh.com` both 404.
- **Three YouTube videos no longer exist.** An HTTP check cannot find these: a
  deleted video's watch page still returns 200. They were caught through oEmbed.
  Two were deleted; one is private.
- **Seven links taught the wrong language.** Six resolved perfectly and their
  video titles gave them away: a *Processing* tutorial and a *MATLAB*
  audio-programming video under `arrays`, a *Java* exercise and a *VBA* lesson
  under `arrays kinesthetic`, an *Excel* IF-statement video under
  `controls kinesthetic`, a *C#* enumeration example under
  `data types kinesthetic`. The seventh, `cs.fsu.edu/~myers/c++/`, is a C++
  course page - *"In C++, these are the types of selection statements"*, 16 uses
  of `cout`.

All thirteen were replaced with C material of the same modality.

**Judge the page, not the navigation.** A first pass that scanned page bodies
for other language names flagged 50 links of 137. Nearly all were false
positives from site chrome: W3Schools lists Excel and Python in its sidebar,
learn-c.org links sibling learn-python and learn-java sites, Programiz's nav
names Rust and Kotlin. Two were real, both GeeksforGeeks pages titled *"in C"*
that serve C++ in their multi-language tabs. `test_recommender.py` therefore
tests the link text rather than the destination's chrome, and the destination is
checked by hand when it is added.

**Where it stands.** 96 distinct URLs, all resolving: every video live through
oEmbed, every page 200, nothing in another language. One URL,
`exercism.org/tracks/c`, answers 403 to a script because of Cloudflare's
challenge and was confirmed by hand in a browser, where it renders as "C on
Exercism"; the test allows 403 for that reason.

Link rot is not a one-time fix. `make test-links` is the thing to run before a
demo.

## Tests

```sh
make test         # 147 checks, no network, no running stack
make test-links   # 96 more: opens every URL in the library
```

`make test` builds the `tests` stage of the recommender image, which runs pytest
during the build. A failing test fails the build, in the same way the trainer
stage fails it on a model that does not reproduce - so a broken library cannot
be built into a runnable image. The runtime image is unaffected and still ships
neither pytest nor scikit-learn.

What it holds the project to:

- the library holds exactly the 24 C tags, every one able to answer
- every response is exactly one well-formed anchor
- no response names another language
- auditory serves only lectures, kinesthetic only hands-on material
- no URL under two modalities of one topic, no URL twice in one tag
- the model's classes match the library, and every training pattern still fits
- each of the 24 topic-and-style combinations returns its own tag and medium
- four styles on one activity get four different links
- an uncovered activity gets 204, an unknown style gets 422
- the same request always returns the same link
- `TOPICS` in `app.py` and the library agree

## Known limitations

State these before a panel member finds them.

- The network has **840 parameters and 132 training examples**: 6.4 parameters
  per example. Perfect training accuracy is arithmetic, not evidence. The report
  calls this a deep neural network, using the established sense of more than one
  hidden layer; it is two hidden layers of 8 units, and it would not be called
  deep learning today. The architecture is reproduced exactly as the report
  specifies rather than improved, because the system demonstrated has to be the
  system described.
- The model is trained on 132 patterns across 24 classes and fits them at 100%.
  In the source thesis the training data was also the test data. All 132 input
  vectors are unique and no two classes collide, so the network memorises the
  table exactly. The honest description is "a classifier over a curated intent
  table", not "a model that generalises". Quoting 100% accuracy as a result
  without that context invites a hard question.
- **The classifier is right 24 times out of 24, and that is a smaller claim than
  it sounds.** At six topics the style mask leaves six candidates, and a network
  that memorises its table handles that comfortably: classifier alone 24/24,
  keyword table alone 24/24, shipped system 24/24. It was not always so. An
  earlier revision carried thirty-eight topics, and there the classifier alone
  managed 82 of 152 before per-tag training patterns lifted it to 145, with the
  keyword table right 152 of 152 and deciding every disagreement. The keyword
  table is kept for two reasons: it is what makes "24/24" checkable rather than
  self-reported, and the failure it was written for returns the moment the
  library grows again.
- Two further layers sit around the classifier and both are deliberate, not
  hidden. The alias expansion adds topic synonyms because the vocabulary has no
  stem for "control". The style mask restricts the output to the student's stored
  modality. Measured over 12 activity variants and 4 styles on the original four
  topics: expansion alone gets the modality wrong 5 times in 48, the mask alone
  gets the topic wrong 10 times in 48, and together they are correct 48 times
  in 48.
- Topic lookup is substring matching, not understanding. It is correct for all 24
  seeded combinations. But an activity called "Wave functions and the Schrodinger
  equation" contains the whole word "functions" and will be offered C material. A
  teacher writing their own activity gets a recommendation only if its name or
  intro happens to contain one of the 6 keys; otherwise they get silence, which is
  the safe failure but not an intelligent one.
- Recommendations are limited to the 6 topics in `intents.json`, and the seeded
  course is a projection of exactly those. One course with six activities is a
  demonstration, not a syllabus: a real C course would have dozens of activities
  and would need the library extended before any of them recommended anything.
- The VARK learning-styles matching hypothesis is contested in the education
  literature. The defensible claim is that this implements VARK as specified by
  the source thesis, not that style matching has been shown to improve outcomes.

## Troubleshooting

**"Don't miss out on important updates and security alerts" on every admin page.**
That is core Moodle asking you to register the site with moodle.org, shown only
to users who can configure the site. It is off by default here:
`MOODLE_REGISTRATION_PROMPT=0` makes the entrypoint write
`$CFG->site_is_public = false`, which is the flag `site_is_public()` checks
before anything else. In core that flag gates nothing but registration - the
banner, the registration page, the prompt and the registration cron - so turning
it off costs nothing else. Set `MOODLE_REGISTRATION_PROMPT=1` if you do want to
register. On a site already running an older image, one command does the same
thing without redeploying:

```sh
docker compose -f compose.prod.yml exec -u www-data moodle \
    php /var/www/moodle/admin/cli/cfg.php --name=site_is_public --set=0
```

**A change to the plugin has no effect.** Moodle caches aggressively. `make purge`.
This is the first thing to try, not the last. It applies to event observers and
language strings too, not just code: after editing `db/events.php` the old
observer list stays cached until you purge.

**You want to see PHP errors while editing.** Set `MOODLE_DEBUG=1` in `.env` and
`make down && make up`. It is off by default because during a presentation a stray
notice on screen is worse than a silent one in the log.

**Port 8080 is in use.** Change `MOODLE_PORT` and `MOODLE_WWWROOT` in `.env`, then
`make down && make up`. The port in `MOODLE_WWWROOT` must match or Moodle
redirects in a loop.

**The recommender is down.** Moodle pages still render, without a recommendation.
That is by design. `docker compose logs recommender` to see why.

**The install half-finished, or the plugin failed to install.** Do not patch
forward; `make reset` and start clean.

**Restarting containers.** `make down && make up`, `docker compose restart`, a
Docker Desktop restart or a reboot all keep the site and keep you logged in. Only
`make reset` wipes anything, and it wipes everything. Moodle's sessions live in
the `moodledata` volume and `config.php` is kept there too, so a recreated
container picks both up again.

**Everything looks broken after an edit.** `docker compose ps` shows which
container is unhealthy, `make logs` shows why.
