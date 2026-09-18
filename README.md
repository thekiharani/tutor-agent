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

Two things the published images do not carry. The plugin is bind-mounted in
`docker-compose.yml`, not baked in, so the Moodle image is stock Moodle until
that mount is present; and the images are `linux/amd64` only, since building
the Moodle image for arm64 under emulation costs far more CI time than a
demo project justifies. `make up` still builds natively on Apple Silicon.

## Logins

Every account uses the same password, `DEMO_PASSWORD` in `.env`, which defaults
to `Demo@2026!`.

| Username | Role |
|---|---|
| `admin` | site administrator, created by the installer |
| `demo.admin` | site administrator, created by `make seed` |
| `student.visual` | student, pre-set style: visual |
| `student.aural` | student, pre-set style: auditory |
| `student.rw` | student, pre-set style: read/write |
| `student.kines` | student, pre-set style: kinesthetic |
| `student.blank` | student, no style yet - use this one to demo the questionnaire |
| `student.blank2` … `student.blank6` | five more with no style, for repeat runs or for someone else to try |

## The demo

1. Log in as `student.blank`, open "Introduction to Programming in C".
   A notification invites you to take the VARK questionnaire.
2. Take it. The page names your learning style.
3. Open the activity "Arrays". A notification appears with a matching resource.
4. Log out, log in as `student.rw`, open **the same** Arrays activity.
5. A different link appears: an article or PDF instead of a video.

Steps 3 and 5 are the point of the project. `make demo` prints this at any time.

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
therefore lives at `/var/www/moodle/public/local/tutoragent`, bind-mounted from
`plugin/local/tutoragent` so you can edit it without rebuilding the image.

## How a recommendation is chosen

The content library is `recommender/data/intents.json`: 16 tags (4 topics x 4
modalities), 84 example phrasings, 100 responses. At image build time `train.py`
stems every phrasing with the Lancaster stemmer, builds a 41-word vocabulary,
and trains the feed-forward network the project report describes - two hidden
layers of 8 units, 41 -> 8 -> 8 -> 16 with a softmax output, `batch_size=8` and
up to 1000 iterations, matching the report's appendix.

Training needs scikit-learn; serving does not. `train.py` saves the fitted
weights to `model.npz` and then asserts that the numpy forward pass in the same
file reproduces scikit-learn's `predict_proba` exactly - on all 84 training
vectors and on the demo's own inputs - before the build is allowed to succeed.
The runtime image therefore carries numpy and nltk but neither scikit-learn nor
scipy, which is 211MB it would otherwise never use. Measured over 20,002
vectors including all-zero, all-ones and random out-of-domain inputs, the two
paths agree to 0.000e+00, and the numpy path is about six times faster per
request.

At request time the service receives the activity's name, its intro text and the
student's stored style, and:

1. **Checks it recognises the activity at all.** If nothing in the text is in the
   vocabulary and no topic keyword matches, it returns `204 No Content` and
   Moodle shows nothing. An activity about pointers gets silence, not
   a recommendation about data types.
2. **Expands topic synonyms.** The vocabulary contains `if/else`, `loop`, `swic`
   and `whil` but no stem for "control", so an activity called "Control
   Structures" would match nothing on its own. Four alias entries fix that.
3. **Classifies, restricted to the student's style.** The style is stored data,
   not something to infer, so the softmax is masked to the four classes for that
   modality and the network chooses the topic among them.
4. **Picks a response deterministically**, `crc32(module + style) % len(responses)`,
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

`intents.json` is the original author's content. The patterns and the tags that
drive the model are untouched, and no URL was changed or invented. Four classes of
defect were repaired, all of which showed on screen:

- **Six of sixteen tags** carried stray leading or trailing spaces
  (`'functions visual '`, `' arrays auditory'`, and four more), which made the
  tag lookup miss.
- **Seven anchors were malformed** and rendered as plain text rather than links:
  three written `<a href>https://...'>` with the `='` missing, one with the
  opening `<` typed as a comma, one written `< a href=` with a space, and two
  closing tags written `>/a>` and `,/a>`.
- **Twelve responses ran text straight into the link**, rendering as
  "see the following practical**in this link**". A space was added before the
  anchor.
- **Twenty-one spelling errors** in the prose: "stotage", "folllowing",
  "progarm", "poinetrs", "pactical", "Declaringand" and others. Only spelling
  changed; no sentence was reworded.

## Known limitations

State these before a panel member finds them.

- The network has **552 parameters and 84 training examples**: 6.6 parameters
  per example. Perfect training accuracy is arithmetic, not evidence. The report
  calls this a deep neural network, using the established sense of more than one
  hidden layer; it is two hidden layers of 8 units, and it would not be called
  deep learning today. The architecture is reproduced exactly as the report
  specifies rather than improved, because the system demonstrated has to be the
  system described.
- The model is trained on 84 patterns across 16 classes and fits them at 100%.
  In the source thesis the training data was also the test data. All 84 input
  vectors are unique and no two classes collide, so the network memorises the
  table exactly. The honest description is "a classifier over a curated intent
  table", not "a model that generalises". Quoting 100% accuracy as a result
  without that context invites a hard question.
- Two layers sit around the classifier and both are deliberate, not hidden. The
  alias expansion adds topic synonyms because the vocabulary has no stem for
  "control". The style mask restricts the output to the student's stored
  modality. Measured over 12 activity variants and 4 styles: expansion alone
  gets the modality wrong 5 times in 48, the mask alone gets the topic wrong 10
  times in 48, and together they are correct 48 times in 48.
- Recommendations are limited to the four topics in `intents.json`: arrays,
  control structures, functions and data types.
- The VARK learning-styles matching hypothesis is contested in the education
  literature. The defensible claim is that this implements VARK as specified by
  the source thesis, not that style matching has been shown to improve outcomes.

## Troubleshooting

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
