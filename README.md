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

## Prerequisites

Docker Desktop (or Docker Engine) with Compose v2, and about 3GB of disk. Nothing
else: no PHP, no Python, no Moodle on your machine.

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
| `make purge` | Clear Moodle's caches |
| `make logs` | Follow the logs |
| `make demo` | Print the logins and the demo script |

## Logins

Admin: `admin` / `Admin#2026demo` (from `.env`).

After `make seed`, five students, all with the password `Student#2026demo`:

| Username | Learning style |
|---|---|
| `student.visual` | visual |
| `student.aural` | auditory |
| `student.rw` | read/write |
| `student.kines` | kinesthetic |
| `student.blank` | none yet - use this one to demo the questionnaire |

## The demo

1. Log in as `student.blank`, open "Introduction to Programming in C".
   A notification invites you to take the VARK questionnaire.
2. Take it. The page names your learning style.
3. Open the activity "Arrays". A notification appears with a matching resource.
4. Log out, log in as `student.rw`, open **the same** Arrays activity.
5. A different link appears: an article or PDF instead of a video.

Steps 3 and 5 are the point of the project. `make demo` prints this at any time.

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
and trains a small neural network (41 -> 8 -> 8 -> 16, softmax) on the
bag-of-words vectors.

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

`intents.json` is the original author's content and the patterns and responses are
otherwise untouched. Two classes of defect were repaired:

- **Six of sixteen tags** carried stray leading or trailing spaces
  (`'functions visual '`, `' arrays auditory'`, and four more), which made the
  tag lookup miss.
- **Seven anchors were malformed** and rendered as plain text rather than links:
  three written `<a href>https://...'>` with the `='` missing, one with the
  opening `<` typed as a comma, one written `< a href=` with a space, and two
  closing tags written `>/a>` and `,/a>`. Every URL was intact; none was invented.

## Known limitations

State these before a panel member finds them.

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
This is the first thing to try, not the last.

**Port 8080 is in use.** Change `MOODLE_PORT` and `MOODLE_WWWROOT` in `.env`, then
`make down && make up`. The port in `MOODLE_WWWROOT` must match or Moodle
redirects in a loop.

**The recommender is down.** Moodle pages still render, without a recommendation.
That is by design. `docker compose logs recommender` to see why.

**The install half-finished, or the plugin failed to install.** Do not patch
forward; `make reset` and start clean.

**Everything looks broken after an edit.** `docker compose ps` shows which
container is unhealthy, `make logs` shows why.
