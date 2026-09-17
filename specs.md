# Build Brief: `local_tutoragent`

A Moodle plugin plus a recommender service that delivers VARK-personalised learning
resource links. This document is the complete specification. Follow it in order.

## 0. Ground rules

1. **Work in work packages (WP0 to WP4). Do not start a WP until the previous one's
   acceptance gate passes.** Each gate is a command or an observable behaviour, not
   your opinion that the code looks right.
2. **Verify version numbers and API signatures against current Moodle documentation
   before using them.** This brief was written against Moodle 4.5 LTS knowledge that
   may be stale. Where the brief and the live docs disagree, the docs win. Tell the
   human what changed.
3. **Commit after each WP** with the WP number in the message.
4. **Never invent a Moodle API.** If you are unsure whether a function exists, grep
   the Moodle source in the container: `grep -rn "function add_moduleinfo" /var/www/html/course/`
5. When something does not work, read the Moodle source rather than guessing a
   second variant of the same call.

## 1. Input files

The human will supply two files:

- `intents.json` - the content repository. 16 tags, 84 patterns, 4 topics x 4
  modalities. Place at `recommender/data/intents.json`.
- `tutoragent.zip` - the original code from a 2022 MSc thesis. Contains `main.py`,
  `observer.php`, `recommend.php`. Place at `reference/` for reading only.

**The code in `tutoragent.zip` does not run and has never run.** Treat it as a
statement of intent, not a starting point. Read it to understand what was meant.
Do not copy from it. Appendix A lists the verified defects so you do not
reintroduce them.

`intents.json` is real content and you keep it, with the three cleanups in WP1.

## 2. Goal

A student opens a course activity in Moodle. A notification appears with a link to
an extra resource matched to that student's VARK learning style. A visual learner
gets a video, a read/write learner gets a PDF or article, for the same activity.

This must be demonstrable in a 10 to 30 minute presentation, cold, from
`docker compose up`.

### Non-goals

Do not build: multi-course support, a teacher-facing UI, content authoring,
analytics, user-facing settings, or i18n beyond `en`. Do not "improve" the model
architecture. Scope creep here costs a defense.

## 3. Architecture

```
docker compose
  db           postgres:16
  moodle       php:8.2-apache + Moodle 4.5 LTS
  recommender  python:3.12-slim + FastAPI (numpy at runtime, sklearn at build only)
```

Moodle calls the recommender over the compose network via HTTP. There is no shared
filesystem between them and no Python on the Moodle container.

**Hard constraints:**

- **No `shell_exec`, `exec`, `system`, `passthru` or backticks anywhere.** The
  original used `shell_exec` with unquoted user-influenced input. If you find
  yourself reaching for it, you have taken a wrong turn.
- **The HTTP call from Moodle must have `CURLOPT_CONNECTTIMEOUT` 1s and
  `CURLOPT_TIMEOUT` 2s, and must fail silently.** If the recommender container is
  down, every Moodle page must still render normally and on time. This is the single
  most important non-functional requirement. A hung page kills the demo.
- **Use native PHP `curl_*` functions for this call, not Moodle's `\curl` wrapper.**
  Moodle's wrapper enforces `curlsecurityblockedhosts`, which blocks private IP
  ranges by default, and the recommender lives on a Docker private IP. Using the
  wrapper means fighting that setting. Add a code comment explaining this choice and
  note it in the README.

## 4. Repo layout

```
.
├── docker-compose.yml
├── .env.example
├── README.md
├── Makefile                          # up, down, reset, seed, logs, purge
├── moodle/
│   ├── Dockerfile
│   ├── php.ini
│   └── entrypoint.sh
├── recommender/
│   ├── Dockerfile
│   ├── requirements.txt
│   ├── app.py                        # FastAPI, numpy only
│   ├── train.py                      # build-time, sklearn
│   └── data/
│       ├── intents.json
│       └── model.npz                 # generated at image build, gitignored
├── plugin/
│   └── local/tutoragent/             # bind-mounted into the moodle container
│       ├── version.php
│       ├── settings.php
│       ├── lib.php
│       ├── vark.php
│       ├── db/
│       │   ├── install.xml
│       │   ├── events.php
│       │   └── access.php
│       ├── classes/
│       │   ├── observer.php
│       │   ├── vark.php
│       │   ├── recommender.php
│       │   ├── form/vark_form.php
│       │   └── privacy/provider.php
│       ├── cli/seed_demo.php
│       └── lang/en/local_tutoragent.php
└── reference/                        # the original thesis code, read-only
```

Bind-mount `plugin/local/tutoragent` to `/var/www/html/local/tutoragent` so the
human can edit and review without rebuilding the image.

---

## WP0: Infrastructure

Deliver `docker-compose.yml`, both Dockerfiles, `php.ini`, `entrypoint.sh`,
`Makefile`, `README.md`.

### Moodle container

Clone Moodle from `https://github.com/moodle/moodle.git` at branch
`MOODLE_405_STABLE`. **Verify this branch exists and is the current LTS before
using it.** Use `--depth 1`.

PHP extensions to install: `gd intl mbstring opcache zip pgsql pdo_pgsql soap exif`.
System libs needed first: `libpng-dev libjpeg-dev libfreetype6-dev libicu-dev
libxml2-dev libzip-dev libpq-dev`.

`php.ini` must set:

```ini
memory_limit = 256M
max_input_vars = 5000
post_max_size = 64M
upload_max_filesize = 64M

opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 60
opcache.validate_timestamps = 1
```

`max_input_vars = 5000` is a hard Moodle requirement and a common install blocker.
`opcache.max_accelerated_files` default of 10000 is below Moodle's file count.
Keep `validate_timestamps = 1` so the human's plugin edits take effect without a
restart.

### moodledata

Must live **outside** the web root, in a named Docker volume mounted at
`/var/moodledata`, owned by `www-data`. Putting it under the served directory is a
data leak.

### entrypoint.sh

1. Wait for Postgres to accept connections.
2. If `/var/www/html/config.php` is absent, run the unattended install:

```sh
php admin/cli/install.php --non-interactive --agree-license \
  --wwwroot="${MOODLE_WWWROOT}" --dataroot=/var/moodledata \
  --dbtype=pgsql --dbhost=db --dbname="${POSTGRES_DB}" \
  --dbuser="${POSTGRES_USER}" --dbpass="${POSTGRES_PASSWORD}" \
  --fullname="Personalised E-Learning" --shortname="PEL" \
  --adminuser=admin --adminpass="${MOODLE_ADMIN_PASS}" \
  --adminemail="${MOODLE_ADMIN_EMAIL}"
```

3. Run `php admin/cli/purge_caches.php`.
4. `exec apache2-foreground`.

Run the install as `www-data`, not root, or file ownership breaks in ways that are
tedious to unpick.

### Development debug settings

Append to `config.php` after install (in the entrypoint, guarded so it happens once):

```php
$CFG->debug = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;
```

These must be on for the whole build. They are how you catch the observer output
bug in WP3.

### Makefile

```
make up      # docker compose up -d --build
make down    # docker compose down
make reset   # docker compose down -v && make up   (full clean reinstall)
make seed    # run the seed CLI script
make purge   # purge Moodle caches
make logs    # follow all logs
```

`make purge` will be used constantly. Moodle caches aggressively and a code change
with no visible effect is almost always a stale cache.

### Gate WP0

- `make up` from a clean clone brings up three healthy containers.
- Moodle login page renders at `http://localhost:8080`.
- Admin can log in with the credentials from `.env`.
- `make reset` reproduces all of the above from scratch.

---

## WP1: Recommender service

**Build this before touching Moodle.** It contains the only genuinely risky logic
and it is fully testable with `curl`.

### Clean `intents.json` first

Three fixes, applied to the file in place:

1. **Strip whitespace from tags.** Six of sixteen have leading or trailing spaces:
   `'functions visual '`, `'data types visual '`, `'functions kinesthetic  '`,
   `' data types kinesthetic'`, `' arrays auditory'`, `' data types auditory'`.
2. **Fix five malformed anchors.** Pattern `<a href>https://...` is missing `='`.
   They render as plain text. Find them with
   `grep -o "<a href>[^<]*" intents.json`.
3. **Leave the patterns and responses otherwise untouched.** This is the student's
   content.

### `train.py` (build time, sklearn)

1. Load `intents.json`.
2. Tokenize each pattern with `nltk.word_tokenize`, stem with
   `nltk.stem.lancaster.LancasterStemmer`.
3. **Build the vocabulary with `sorted(set(...))`.** The original used
   `sorted(list(words))` with no dedupe, producing 328 slots for 41 distinct stems.
   The verified vocabulary is exactly 41 stems.
4. Bag-of-words encode. 84 samples, 16 classes.
5. Train `MLPClassifier(hidden_layer_sizes=(8, 8), activation='relu',
   solver='adam', max_iter=1000, random_state=42)`. This matches the thesis
   architecture (41 -> 8 -> 8 -> 16, softmax).
6. Export to `data/model.npz`: `vocab`, `labels`, and the weights and biases from
   `clf.coefs_` and `clf.intercepts_`.
7. Print training accuracy and assert it is 1.0. It will be. All 84 input vectors
   are unique with zero class collisions, so the model memorises the table exactly.
   This is expected, not a bug, but see the note in section 6.

Run `train.py` in the Dockerfile. `model.npz` is a build artifact, gitignored.
Download `punkt_tab` at image build so runtime never touches the network.

### `app.py` (runtime, numpy only)

No sklearn import at runtime. Load `model.npz` and do the forward pass in numpy:
two ReLU layers then softmax. Tokenize and stem exactly as `train.py` does, using
the same nltk calls. Train/serve tokenization must be identical or the whole thing
silently degrades.

**Endpoints:**

```
GET  /health
     -> {"status": "ok", "classes": 16, "vocab": 41}

POST /recommend
     {"module_name": "Arrays", "module_intro": "Introduction to arrays in C",
      "style": "visual"}
     -> {"tag": "arrays visual", "confidence": 0.94,
         "html": "<a href='...'>...</a>", "source": "model"}
```

`style` is one of `visual`, `auditory`, `read_write`, `kinesthetic`. Reject anything
else with a 422.

### The two fixes that make this actually work

Without both of these the system returns the same link for every student and every
module. This is the core of the job.

**Fix 1: the style token must reach the classifier.**

The classifier input string is `f"{module_name} {module_intro} {style}"`. The
original hardcoded `$vark = 0` on the PHP side, so the modality token never arrived
and the model could not discriminate. Verified behaviour of the original:

```
'Arrays Introduction to arrays in C 0'         -> 'arrays auditory'
'Arrays Introduction to arrays in C visual'    -> 'arrays auditory'
'Arrays Introduction to arrays in C auditory'  -> 'arrays auditory'
```

The four modality stems `vis`, `audit`, `read_write`, `kinesthet` are in the
vocabulary. They just never got sent.

**Fix 2: topic alias expansion.**

The 41-stem vocabulary is:

```
access an and argu array assign audit bas c cal class dat decl defin der do el
enum for funct if/else in index is kinesthet loop of or read_write recurs select
stat stor swic the typ valu vis void what whil
```

Note it contains `if/else`, `loop`, `swic`, `whil` but **not** `control`. A Moodle
activity named "Control Structures" matches nothing. Apply a synonym expansion to
the input text before encoding:

```python
TOPIC_ALIASES = {
    "array":     "array index declaration accessing elements",
    "control":   "if/else selection statement for loop while do switch",
    "function":  "function calling defining arguments recursion storage class",
    "data type": "data type basic derived enumerated void",
}
```

If a key appears in the lowercased `module_name + module_intro`, append its
expansion to the classifier input. This is a legitimate, explainable layer, not a
hack, and it is defensible in a viva.

### Confidence gate and fallback

If `max(softmax) < RECOMMENDER_THRESHOLD` (env var, default `0.45`), fall back to a
deterministic lookup: derive the topic by keyword match against the four topics,
build the tag as `f"{topic} {modality}"`, and return a response from that tag.
Set `"source": "fallback"` so this is visible in testing.

**Response selection must be deterministic**, not `random.choice`. Use
`hash(module_name + style) % len(responses)`. The demo must show the same link on
every rehearsal.

### Gate WP1

Moodle is not involved. With the container up:

```sh
curl -s localhost:8000/health

for s in visual auditory read_write kinesthetic; do
  curl -s -X POST localhost:8000/recommend \
    -H 'Content-Type: application/json' \
    -d "{\"module_name\":\"Arrays\",\"module_intro\":\"Introduction to arrays in C\",\"style\":\"$s\"}"
  echo
done
```

**Four different links, each matching its modality.** A YouTube link for `visual`
and `auditory`, an article or PDF for `read_write`, a practical video for
`kinesthetic`. Then repeat with `"module_name":"Control Structures"` and confirm
the topic is recognised as controls, not arrays. If any two styles return the same
link for the same module, WP1 has failed. Do not proceed.

---

## WP2: Plugin scaffolding and the VARK questionnaire

### Files and the things that go wrong in them

**`version.php`** - `$plugin->component = 'local_tutoragent';`,
`$plugin->version` as `YYYYMMDDXX`, `$plugin->requires` set to the Moodle 4.5
version integer. Look that integer up in `/var/www/html/version.php` rather than
guessing it.

**`classes/observer.php`** - must declare `namespace local_tutoragent;` and
`class observer`. The original used a non-namespaced `local_tutoragent_observer`
in a root-level file, which Moodle's autoloader will not find.

**`db/install.xml`** - use Appendix B verbatim. Invalid XMLDB is a common failure
and it fails in a way that leaves the DB half-upgraded, needing `make reset` to
retry cleanly.

**`classes/privacy/provider.php`** - the plugin stores per-user data, so a
`null_provider` is not acceptable. Implement
`\core_privacy\local\metadata\provider` and
`\core_privacy\local\request\plugin\provider`. Without it the admin page shows a
warning, which you do not want on screen during a defense.

**`settings.php`** - one admin setting, `recommenderurl`, defaulting to
`http://recommender:8000`. Do not hardcode the URL in the observer.

**`vark.php` and `classes/form/vark_form.php`** - the 16-question VARK
questionnaire using Moodle's Forms API (`moodleform`). Questions are in Appendix A
of the source thesis; the human will supply them if they are not in the zip. Each
question has four options mapping to V, A, R, K. Multi-select is allowed by the
VARK instrument.

**Scoring:** count selections per dimension. The dominant style is the highest
score. On a tie, prefer the order V, A, R, K and record it. Store the full score
breakdown in the `scores` column as JSON so the result is explainable when a panel
asks.

**Style string mapping** to the recommender vocabulary:

```
Visual      -> visual
Aural       -> auditory
Read/Write  -> read_write
Kinesthetic -> kinesthetic
```

After submission, show the detected style with a short description
(this is Figure 5.2 of the thesis) and a link back to the course.

### Do NOT build a global redirect yet

The thesis describes force-redirecting first-time users to the questionnaire. A
global redirect from `lib.php` is one bad conditional away from locking the admin
out of the site. It is deferred to WP4 as optional. For now, discovery happens
through a navigation link plus the notification added in WP3.

### Gate WP2

- Plugin appears under Site administration > Plugins > Local plugins and installs
  with **zero debug warnings** (debug is on `DEVELOPER`, so any "Coding error"
  banner is a failure).
- A student can reach `/local/tutoragent/vark.php`, complete the questionnaire, and
  see their style.
- `select * from mdl_local_tutoragent_vark;` shows one row with a correct style and
  a populated `scores` JSON.
- Re-submitting updates the existing row rather than inserting a duplicate.
- Admin can still navigate the site normally.

---

## WP3: Observer wiring

### `db/events.php`

```php
$observers = [
    [
        'eventname' => '\core\event\course_module_viewed',
        'callback'  => '\local_tutoragent\observer::course_module_viewed',
    ],
];
```

Moodle dispatches to observers registered on parent event classes, so this should
catch `\mod_page\event\course_module_viewed` and friends. **Verify empirically in
the gate.** If it does not fire, fall back to observing `*` and filtering with
`instanceof \core\event\course_module_viewed`.

### `classes/observer.php`

```
1. Get $event->userid. If not a real logged-in student, return.
2. Look up the user's VARK style. No row -> emit a notification linking to
   vark.php, then return.
3. Fetch the module record:
   $DB->get_record($event->objecttable, ['id' => $event->objectid],
                   'name, intro', IGNORE_MISSING)
4. Strip tags from name and intro.
5. POST to the recommender. On any error or timeout, return silently.
6. \core\notification::info($response['html']);
```

### Four things that must be right

1. **Never `echo`, `print`, or `var_dump` in an observer.** Moodle dispatches events
   mid-request. Output there corrupts headers and trips "Coding error: unexpected
   output". The original did `echo $response;`. Use `\core\notification::info()`.

2. **`get_record` must have a real conditions array.** The original passed
   `array()`, which returns an arbitrary row. Grep your own output:
   `grep -rn "get_record(" plugin/ | grep "array()"` must return nothing.

3. **Every failure path returns silently.** Wrap the HTTP call in try/catch, check
   the status code, validate the JSON shape. A dead recommender must produce a
   normal page, not a warning and not a delay.

4. **Timeouts as specified in section 3.** Test this: `docker compose stop
   recommender`, then load a course page and confirm it renders in normal time with
   no error.

### Gate WP3

- Student with a stored style clicks an activity, notification appears with a link
  matching their style.
- **Two students with different styles see different links for the same activity.**
  This is the acceptance criterion. "It returned something" is not the test.
- Page source is clean, no stray output, no debug banner.
- `docker compose stop recommender` then reload: page renders normally and on time.
- A student with no style sees the questionnaire prompt.

---

## WP4: Seed data and demo run-through

### `cli/seed_demo.php`

Creates a course "Introduction to Programming in C" with four `mod_page`
activities: Arrays, Control Structures, Functions, Data Types. Give each a `name`
and `intro` that the topic aliases will match.

Then five users, all enrolled as students, all with a known password from `.env`:

| Username | Pre-seeded style |
|---|---|
| `student.visual` | visual |
| `student.aural` | auditory |
| `student.rw` | read_write |
| `student.kines` | kinesthetic |
| `student.blank` | none |

Insert the four styles directly into `local_tutoragent_vark`. `student.blank` has
no row and is used to demo the questionnaire live.

Use `create_course()` from `course/lib.php`, `add_moduleinfo()` from
`course/modlib.php`, `user_create_user()` from `user/lib.php`, and
`enrol_try_internal_enrol()` from `lib/enrollib.php`.

**`add_moduleinfo()` needs a more complete object than its signature suggests.**
It will want `modulename`, `course`, `section`, `name`, `intro`, `introformat`,
`content`, `contentformat`, `visible`, `visibleoncoursepage`, `cmidnumber`,
`groupmode`, `completion`, `completionview`, `showdescription`. Expect to iterate.

**If `add_moduleinfo()` costs you more than 30 minutes, switch approach:** have the
human build the course once in the UI, export it with
`admin/cli/backup.php`, commit the resulting `.mbz` to `fixtures/`, and restore it
in the seed script with `admin/cli/restore_backup.php`. That is deterministic and
avoids the API entirely. Flag the switch to the human rather than grinding.

The script must be idempotent, or detect an existing seed and refuse with a clear
message.

### Optional: the forced redirect

Only if everything above is green and time remains. Behind an admin setting
`forceredirect`, **default off**. Must skip: site admins, the questionnaire page
itself, login and logout pages, AJAX requests (`AJAX_SCRIPT`), CLI (`CLI_SCRIPT`),
and users who already have a style. Test by logging in as admin immediately after
enabling it. If you get bounced, the guard is wrong.

### Gate WP4

Cold, from nothing:

```sh
make reset && make seed
```

Then execute the demo script end to end, twice:

1. Log in as `student.blank`. Open the course. See the questionnaire prompt.
2. Complete the questionnaire. See the detected style.
3. Open Arrays. See a recommendation matching that style.
4. Log out. Log in as `student.rw`. Open **the same** Arrays activity.
5. See a **different** link, appropriate to read/write.

Total elapsed under two minutes. If it needs a manual fix at any point, WP4 is not
done.

---

## 5. README

The README must get a reader from clone to working demo with no prior knowledge:
prerequisites, `.env` setup, `make up`, `make seed`, the demo script above, the
credentials table, a troubleshooting section (stale cache, port conflicts,
recommender down), and the architecture in five lines.

## 6. Honest framing for the defense

Put this in the README under "Known limitations". The student should say it before
a panel member finds it.

- The model is trained on 84 patterns across 16 classes and fits them at 100%.
  Training data was reused as test data in the source thesis. All 84 input vectors
  are unique with zero class collisions, so the network memorises the table exactly.
  The honest description is "a classifier over a curated intent table", not "a
  model that generalises". Reporting 100% accuracy as a result without this context
  invites a hard question.
- Recommendations are limited to the content in `intents.json`.
- The VARK learning-styles matching hypothesis is contested in the education
  literature. The correct position is that this implements VARK as specified by the
  source thesis, not that matching has been shown to improve outcomes.

---

## Appendix A: verified defects in `tutoragent.zip`

Confirmed by reading and running the code. Do not reintroduce any of these.

| # | File | Defect |
|---|---|---|
| 1 | `main.py` | Uses `sys.argv[1]`, never imports `sys`. Instant `NameError`. The file has never run. |
| 2 | `main.py` / `recommend.php` | PHP sends a concatenated string, Python calls `json.loads`. Guaranteed `JSONDecodeError`. |
| 3 | `recommend.php` | Argument unquoted, so the shell splits it and `argv[1]` gets one word. Also a command injection hole; `escapeshellcmd` does not quote. |
| 4 | `recommend.php` | Invokes `python`, which does not exist on modern distros. |
| 5 | all | Relative paths (`require("recommend.php")`, `open("intents.json")`) resolve against the web root under Moodle, not the plugin dir. |
| 6 | `observer.php` | `$DB->get_record($table, array(), ...)` with empty conditions returns an arbitrary row. |
| 7 | `observer.php` | `echo $response;` inside an event observer. |
| 8 | `observer.php` | `$vark = 0;` hardcoded. The learning style never reaches the classifier. This is why the original cannot personalise at all. |
| 9 | `main.py` | `tflearn` requires TensorFlow 1.x, which has no wheel above Python 3.7. Not installable on a current Python. |
| 10 | `main.py` | Trains at import time; would retrain on every page view. |
| 11 | `main.py` | Vocabulary built with `sorted(list(words))`, no dedupe: 328 slots for 41 distinct stems. |
| 12 | `main.py` | `nltk.word_tokenize` used with no data download. |
| 13 | `intents.json` | 6 of 16 tags carry stray leading or trailing whitespace. |
| 14 | `intents.json` | 5 of 98 anchors malformed as `<a href>https://...`, rendering as plain text. |

## Appendix B: known-good `db/install.xml`

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="local/tutoragent/db" VERSION="20260917" COMMENT="Tutor agent tables"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="../../../lib/xmldb/xmldb.xsd"
>
  <TABLES>
    <TABLE NAME="local_tutoragent_vark" COMMENT="Stores each user's VARK learning style">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="style" TYPE="char" LENGTH="20" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="scores" TYPE="text" NOTNULL="false" SEQUENCE="false"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="userid" TYPE="foreign-unique" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
      </KEYS>
    </TABLE>
  </TABLES>
</XMLDB>
```

## Appendix C: Moodle pitfalls that will cost you time

1. **Caches.** A code change with no visible effect is a stale cache. `make purge`.
   Make this your first reflex, not your last.
2. **Failed plugin install leaves the DB half-upgraded.** `make reset` rather than
   trying to patch forward. Get `install.xml` right the first time using Appendix B.
3. **Event observers run mid-request.** No output, ever.
4. **`moodledata` outside the web root.** Always.
5. **`max_input_vars = 5000`.** Moodle refuses to install below this.
6. **Moodle's `\curl` wrapper blocks private IPs** via `curlsecurityblockedhosts`.
   Use native curl for the internal service call.
7. **Table names are length-limited.** `local_tutoragent_vark` is fine at 21 chars.
8. **Run CLI scripts as `www-data`**, or file ownership in `moodledata` breaks:
   `docker compose exec -u www-data moodle php admin/cli/purge_caches.php`

## Appendix D: final self-check before handing back

Run all of these and report the output. Do not summarise, paste it.

```sh
# no shell execution anywhere
grep -rnE "shell_exec|passthru|\bexec\(|system\(" plugin/ recommender/ || echo "CLEAN"

# no empty get_record conditions
grep -rn "get_record(" plugin/ | grep "array()" || echo "CLEAN"

# no output in the observer
grep -nE "echo|print|var_dump" plugin/local/tutoragent/classes/observer.php || echo "CLEAN"

# recommender url not hardcoded outside settings/defaults
grep -rn "recommender:8000" plugin/ | grep -v "settings.php" || echo "CLEAN"

# cold run
make reset && make seed && curl -s localhost:8000/health
```

Then state plainly which acceptance gates pass and which do not. If a gate does not
pass, say so. Do not report a gate as passing on the basis that the code looks
correct.
