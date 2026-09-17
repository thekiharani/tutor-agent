# Build Brief: `local_tutoragent`

A Moodle plugin plus a recommender service that delivers VARK-personalised learning
resource links. This document is the complete specification. Follow it in order.

> **Revision 2 (2026-09-17).** Revision 1 targeted Moodle 4.5 LTS and a numpy-only
> recommender. This revision targets **Moodle 5.2** (current stable) and takes three
> deliberate simplifications agreed with the human: sklearn at runtime instead of a
> hand-written numpy forward pass, a regex tokeniser instead of `nltk.word_tokenize`,
> and `zlib.crc32` instead of `hash()`. Revision 1 is preserved in git history.
> Every acceptance gate from revision 1 survives unchanged in substance.

## 0. Ground rules

1. **Work in work packages (WP0 to WP4). Do not start a WP until the previous one's
   acceptance gate passes.** Each gate is a command or an observable behaviour, not
   your opinion that the code looks right.
2. **Verify version numbers and API signatures against the Moodle source in the
   container, and against current Moodle documentation, before using them.** This
   brief is written against Moodle 5.2 but the exact point release moves weekly.
   Where the brief and the live source disagree, the source wins. Tell the human
   what changed.
3. **Commit after each WP** with the WP number in the message. The repository is
   already initialised; commit `8ec2a3c` holds the unmodified inputs.
4. **Never invent a Moodle API.** If you are unsure whether a function exists, grep
   the Moodle source in the container: `grep -rn "function add_moduleinfo" /var/www/html/course/`
5. When something does not work, read the Moodle source rather than guessing a
   second variant of the same call.
6. **This is an undergraduate demo, built by someone who is not a developer.**
   Prefer the boring solution every time. Fewer moving parts beats elegance. If a
   feature is not needed for the five-step demo in WP4, it does not belong here.

## 1. Input files

Both files are already in the repository root and committed:

- `intents.json` - the content repository. 16 tags, 84 patterns, **100 responses**,
  4 topics x 4 modalities. Move to `recommender/data/intents.json` in WP1.
- `tutoragent.zip` - the original code from a 2022 thesis: `main.py`,
  `observer.php`, `recommend.php`. Unpack to `reference/` for reading only.

**The code in `tutoragent.zip` does not run and has never run.** Treat it as a
statement of intent, not a starting point. Read it to understand what was meant.
Do not copy from it. Appendix A lists the verified defects so you do not
reintroduce them.

`intents.json` is real content and you keep it, with the two cleanups in WP1.

### The questionnaire

Supplied 2026-09-17: the sixteen questions come from **Appendix A of the project
report** (`Project Report .docx`), which reproduces the VARK questionnaire. Each
question has four options, one per dimension, and the option order varies question
to question - that is how the instrument is written, so do not sort them or every
answer becomes the first checkbox.

Credit VARK Learn Limited on the page. The mapping of each option to V, A, R or K
follows the instrument's own scoring: diagrams, maps, charts and plans are V;
talking, listening and asking are A; written words, lists and reports are R; and
examples, demonstrations, trial and error and video of actions are K. Note that a
video of someone *doing* something is K, not V - that catches people out.

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
  db           postgres:18
  moodle       php:8.4-apache + Moodle 5.2.3+
  recommender  python:3.14-slim + FastAPI + scikit-learn
```

**Moodle 5.2 serves from a `public/` subdirectory.** This is new in 5.x and it
changes every path in this brief:

```
/var/www/moodle/                 <- code root, holds config.php
  admin/cli/*.php                <- CLI shims; run these
  public/                        <- Apache DocumentRoot
    config.php                   <- shim that requires ../config.php
    local/tutoragent/            <- our plugin is bind-mounted here
```

Serving the code root instead of `public/` throws a deliberate `rootdirpublic`
exception, so the DocumentRoot must be `public/`. `moodledata` stays at
`/var/moodledata`, outside both.

Moodle calls the recommender over the compose network via HTTP. There is no shared
filesystem between them and no Python on the Moodle container.

### Version policy

"Latest" means **latest stable that the layer above actually supports**, resolved in
this order and verified at WP0, not assumed:

1. **Moodle**: current stable release, 5.2 at the time of writing. Confirm at
   <https://download.moodle.org/releases/latest/>.
2. **PHP**: the newest version Moodle 5.2 supports. The authoritative answer is
   `admin/environment.xml` in the downloaded Moodle source - read it, do not guess.
   Moodle's installer hard-blocks on an unsupported PHP version.
3. **Postgres**: the newest version listed as supported in the same file.
4. **Python**: the newest version with binary wheels for numpy and scikit-learn.
   Wheels lag new Python releases by months; if `pip install` starts compiling from
   source, step back one minor version.

**Resolved at WP0 on 2026-09-17** (verified, not assumed):

| Layer | Version | Where the constraint came from |
|---|---|---|
| Moodle | 5.2.3+ (Build 20260916), `$version = 2026042003.01` | current stable; `stable503` returns 404 |
| PHP | 8.4 | `composer.json` requires `>=8.3.0`, no upper bound - but see below |
| Postgres | 18 | `environment.xml` requires `>= 16` |
| Python | 3.14 | newest with numpy/scikit-learn wheels |

**PHP 8.5 was tried first and rejected on evidence.** Moodle 5.2 installs fine on
it, but core emits about 120 deprecation notices under PHP 8.5 - non-canonical
casts, `case ...;`, the backtick operator, `xml_parser_free()` - in
`bigbluebuttonbn`, `lib/antivirus/clamav`, `lib/adminlib.php`, `lib/moodlelib.php`,
`mod/workshop` and `group/lib.php`. With `debugdisplay` on those render in the
browser, and they would make the WP2 and WP3 gates ("zero debug warnings", "page
source is clean") impossible to read. PHP 8.4 produces **zero** deprecations and
zero warnings on the same install. Re-test 8.5 when Moodle clears them.

Record these versions in the README. The human needs to be able to state them to a
panel.

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
├── .gitignore
├── README.md
├── Makefile                          # up, down, reset, seed, purge, logs, demo
├── scripts/demo.sh                   # what `make demo` prints
├── specs.md                          # this file
├── moodle/
│   ├── Dockerfile
│   ├── php.ini
│   └── entrypoint.sh
├── recommender/
│   ├── Dockerfile
│   ├── requirements.txt
│   ├── train.py                      # tokeniser + build-time training
│   ├── app.py                        # FastAPI, imports the tokeniser from train.py
│   └── data/
│       ├── intents.json
│       └── model.joblib              # generated at image build, gitignored
├── plugin/
│   └── local/tutoragent/             # bind-mounted into the moodle container
│       ├── version.php
│       ├── settings.php
│       ├── lib.php                   # navigation + VARK scoring + the HTTP call
│       ├── vark.php                  # the questionnaire page
│       ├── db/
│       │   ├── install.xml
│       │   ├── events.php
│       │   └── access.php
│       ├── classes/
│       │   ├── observer.php
│       │   ├── form/vark_form.php
│       │   └── privacy/provider.php
│       ├── cli/seed_demo.php
│       └── lang/en/local_tutoragent.php
└── reference/                        # the original thesis code, read-only
```

Bind-mount `plugin/local/tutoragent` to `/var/www/moodle/public/local/tutoragent`
(note the `public/`) so the human can edit and review without rebuilding the image.

Revision 1 also specified `classes/vark.php` and `classes/recommender.php`. Scoring
and the HTTP call are about sixty lines between them and both now live in `lib.php`.
`observer.php`, `vark_form.php` and `privacy/provider.php` stay as separate files
because Moodle's autoloader requires one class per file in `classes/`.

---

## WP0: Infrastructure

Deliver `docker-compose.yml`, both Dockerfiles, `php.ini`, `entrypoint.sh`,
`Makefile`, `.env.example`, `.gitignore`, `README.md`.

### Moodle container

Download the current stable Moodle tarball rather than cloning the git repository:

```
https://download.moodle.org/download.php/direct/stable502/moodle-latest-502.tgz
```

**Verified 2026-09-17**: this URL resolves (86MB), `stable503` returns 404, and the
extracted `public/version.php` reports `5.2.3+ (Build: 20260916)`. Re-check before
a fresh build; if 5.3 has shipped, use that series and say so. The tarball is roughly
80MB against roughly 1GB for a `--depth 1` clone, which matters on a slow
connection.

**PHP extensions.** `public/admin/environment.xml` for 5.2 requires: `iconv`,
`mbstring`, `curl`, `openssl`, `ctype`, `zip`, `zlib`, `gd`, `simplexml`, `spl`,
`pcre`, `dom`, `xml`, `xmlreader`, `intl`, `json`, `hash`, `fileinfo`, `sodium`,
`filter`; optional: `tokenizer`, `soap`, `exif`. `sodium` is newly required in 5.x.

`php:8.4-apache` already ships most of them. Build **only** the missing ones:

```
gd intl zip pgsql pdo_pgsql soap exif
```

Asking `docker-php-ext-install` for an extension the image already has (`mbstring`,
`sodium`, `opcache`) fails with `cp: cannot stat 'modules/*'`, and with `-j` in
play the error does not name the culprit. Check `php -m` in the base image first.

System libs needed first: `libpng-dev libjpeg-dev libfreetype6-dev libicu-dev
libxml2-dev libzip-dev libpq-dev libsodium-dev libonig-dev`.

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
5.2's `environment.xml` asks only for `memory_limit >= 96M` and checks
`max_input_vars` through a custom check, so these values clear it comfortably. `opcache.max_accelerated_files` default of 10000 is below Moodle's file count.
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

Verified against `admin/cli/install.php --help` in 5.2: every flag above exists.
Note there is only one installer, the shim at the **code root**, and it writes
`config.php` to the code root (`dirname(__DIR__, 2)`), not into `public/`.

3. Run `php admin/cli/purge_caches.php`.
4. `exec apache2-foreground`.

Run the install as `www-data`, not root, or file ownership breaks in ways that are
tedious to unpick.

### Development debug settings

Append to `config.php` after install (in the entrypoint, guarded so it happens once):

```php
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;
```

Revision 1 said `(E_ALL | E_STRICT)`. `E_STRICT` was removed in PHP 8.4 and `E_ALL`
is what Moodle's `DEBUG_DEVELOPER` constant means anyway.

These must be on for the whole build. They are how you catch the observer output
bug in WP3. **Turn `debugdisplay` off before the final demo run** so a stray notice
cannot appear on screen during the presentation.

### Makefile

```
make up      # docker compose up -d --build
make down    # docker compose down
make reset   # docker compose down -v && make up   (full clean reinstall)
make seed    # run the seed CLI script
make purge   # purge Moodle caches
make logs    # follow all logs
make demo    # print the credentials table and the five demo steps
```

`make purge` will be used constantly. Moodle caches aggressively and a code change
with no visible effect is almost always a stale cache.

`make demo` prints, it does not do anything. It exists so the student can recover
the demo script and the logins from the terminal without opening the README
mid-presentation.

### Gate WP0

- `make up` from a clean clone brings up three healthy containers.
- Moodle login page renders at `http://localhost:8080`.
- Admin can log in with the credentials from `.env`.
- `make reset` reproduces all of the above from scratch.
- The README states the four resolved versions (Moodle, PHP, Postgres, Python).
- **No PHP deprecation notices or warnings in `docker compose logs moodle`.**
  On the right PHP version this count is zero; a non-zero count means the PHP
  version is wrong, not that Moodle is broken.

---

## WP1: Recommender service

**Build this before touching Moodle.** It contains the only genuinely risky logic
and it is fully testable with `curl`.

### Clean `intents.json` first

Two fixes, applied to the file in place. Every URL below is intact in the source -
no link needs to be invented.

**1. Strip whitespace from tags.** Six of sixteen have leading or trailing spaces:
`'functions visual '`, `'data types visual '`, `'functions kinesthetic  '`,
`' data types kinesthetic'`, `' arrays auditory'`, `' data types auditory'`.

**2. Fix seven broken anchors.** Revision 1 said "five malformed anchors" found with
`grep -o "<a href>[^<]*"`; that grep returns three, and there are seven genuinely
broken anchors in four different shapes. All seven, exhaustively:

| Tag | Response # | Defect |
|---|---|---|
| `arrays read_write` | 2 | `<a href>https://www.tutorialspoint.com/...c_arrays.htm'>` - missing `='` |
| `arrays read_write` | 4 | `<a href>http://ee.hawaii.edu/~tep/EE160/Book/PDF/Chapter7.pdf'>` - missing `='` |
| `arrays read_write` | 5 | `<a href>https://www.slideshare.net/kaushal_kush/array-ppt'>` - missing `='` |
| `arrays kinesthetic` | 3 | `, a href = '...F2oF4G9ouYg'>` - opening `<` replaced by a comma |
| `functions kinesthetic` | 5 | `< a href=  '...dJMjfsjnOpA'>` - space after `<`, so it is not a tag |
| `data types kinesthetic` | 0 | closing tag written `>/a>` |
| `arrays auditory` | 3 | closing tag written `,/a>` |

There is an eighth oddity, `<A HREF = '...oOMqMZWFYbw'>` in `data types kinesthetic`
#3. Uppercase is valid HTML and renders correctly. Normalise it for tidiness or
leave it; either is acceptable.

**3. Leave the patterns and responses otherwise untouched.** This is the student's
content. Record the edits in the README so the change is disclosed rather than
discovered.

### `train.py` (build time)

1. Load `intents.json`, stripping whitespace from every tag on load.
2. **Define the tokeniser here, once**, and have `app.py` import it:

```python
STEMMER = LancasterStemmer()

def tokenize(text):
    return [STEMMER.stem(t) for t in re.findall(r"[a-z_/]+", text.lower())]
```

Revision 1 used `nltk.word_tokenize`, which needs the `punkt_tab` data downloaded at
image build. The regex does the same job on this corpus, keeps `if/else` and
`read_write` as single tokens, and removes a network dependency from the build. The
Lancaster stemmer needs no data file, so the write-up can still accurately say
"Lancaster stemming".

Put the training run behind `if __name__ == "__main__":` so `app.py` can import the
tokeniser without triggering training. **Train and serve must tokenise through this
one function.** If they ever diverge the system degrades silently.

3. **Build the vocabulary with `sorted(set(...))`.** The original used
   `sorted(list(words))` with no dedupe, producing 328 slots for 41 distinct stems.
4. Bag-of-words encode. 84 samples, 16 classes.
5. Train `MLPClassifier(hidden_layer_sizes=(8, 8), activation='relu',
   solver='adam', max_iter=1000, random_state=42)`. This matches the thesis
   architecture (input -> 8 -> 8 -> 16, softmax).
6. `joblib.dump({"clf": clf, "vocab": vocab, "labels": labels}, "data/model.joblib")`.
   Revision 1 exported raw weights to `model.npz` and reimplemented the forward pass
   in numpy. Serialising the fitted estimator removes that whole class of bugs.
7. Print the vocabulary size, the training accuracy, and assert accuracy is 1.0. It
   will be. All 84 input vectors are unique with zero class collisions, so the model
   memorises the table exactly. This is expected, not a bug, but see section 6.
   **Verified**: the regex tokeniser yields exactly the 41 stems revision 1 listed,
   the same set `word_tokenize` gave, and training accuracy is 1.0. The vocabulary
   is: `access an and argu array assign audit bas c cal class dat decl defin der do
   el enum for funct if/else in index is kinesthet loop of or read_write recurs
   select stat stor swic the typ valu vis void what whil`.

Run `train.py` in the Dockerfile. `model.joblib` is a build artifact, gitignored.

### `app.py` (runtime)

`from train import tokenize`. Load `model.joblib` once at startup and classify with
`clf.predict_proba()`.

**Endpoints:**

```
GET  /health
     -> {"status": "ok", "classes": 16, "vocab": <the number train.py printed>}

POST /recommend
     {"module_name": "Arrays", "module_intro": "Introduction to arrays in C",
      "style": "visual"}
     -> {"tag": "arrays visual", "confidence": 0.94,
         "html": "<a href='...'>...</a>", "source": "model"}
```

`style` is one of `visual`, `auditory`, `read_write`, `kinesthetic`. Reject anything
else with a 422 (a pydantic `Literal` gives you this for free).

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

The four modality stems (`vis`, `audit`, `read_write`, `kinesthet`) are in the
vocabulary. They just never got sent.

**Fix 2: topic alias expansion.**

The vocabulary contains `if/else`, `loop`, `swic` and `whil` but **not** `control`.
A Moodle activity named "Control Structures" matches nothing. Apply a synonym
expansion to the input text before encoding.

Note the tags in `intents.json` use plural topics (`arrays`, `controls`,
`functions`, `data types`) while the natural alias keys are singular. One table
carries both, so the fallback cannot build a tag that does not exist:

```python
TOPICS = {
    "array":     ("arrays",     "array index declaration accessing elements"),
    "control":   ("controls",   "if/else selection statement for loop while do switch"),
    "function":  ("functions",  "function calling defining arguments recursion storage class"),
    "data type": ("data types", "data type basic derived enumerated void"),
}
```

Scan the lowercased `module_name + module_intro` against the keys **in the order
listed and take the first match only**, appending that one expansion to the
classifier input. First-match-only keeps the result deterministic and stops an intro
that mentions two topics from blurring into both. This is a legitimate, explainable
layer, not a hack, and it is defensible in a viva.

**Fix 3: restrict the classifier to the student's known style.**

Fixes 1 and 2 are both necessary and neither is sufficient. Expansion is what makes
an unknown topic resolvable, but it also swamps the single modality stem: the
expanded input lights up 11 vocabulary entries where a training vector lights up 7,
and the topic stems outvote the one modality stem.

Measured over 12 activity variants x 4 styles:

| | wrong |
|---|---|
| expansion, unrestricted argmax | 5 / 48 (wrong modality) |
| restriction, no expansion | 10 / 48 (wrong topic) |
| both | **0 / 48** |

So mask the softmax to the four classes ending in the requested style and take the
best of those. The student's style is stored data, not something to infer; what the
classifier is actually being asked is which topic the activity is about. Keep
sending the style token in the input string - Fix 1 stands, and without it the
unrestricted model cannot discriminate at all - but do not leave the modality to
chance when it is already known.

This is a disclosed layer, like the alias expansion, and it belongs in the README
under "Known limitations" rather than being quietly folded into "the model".

### Confidence gate and fallback

Report confidence **renormalised over the four masked candidates**:
`p[best] / sum(p[candidates])`. That is "how sure are we of the topic, given this
style", which is the only question the classifier is being asked. The raw 16-way
probability is not a usable number here - correct answers routinely score 0.06 on
it because the model is confident about a different modality.

If that confidence is below `RECOMMENDER_THRESHOLD` (env var, default `0.45`), fall
back to the deterministic lookup: take the topic from the first-match scan, build
the tag as `f"{topic} {style}"`, and return `"source": "fallback"` so it is visible
in testing.

**Return HTTP 204 No Content when the activity is not recognised at all**, checked
*before* classifying: no alias key matches and no stem of the activity text is in
the vocabulary, ignoring the four modality stems and the function words
`an and in is of or the what`. Confidence alone is not a usable out-of-domain test
- renormalised over four candidates, "Pointers" scores 0.56 and would be served
data-type content. The observer shows no notification on a 204.

Activities whose topic appears only in the intro still work: "Week 4" with the
intro "if else and while loops in C" classifies as `controls` at 0.99 even though
no alias key matches its title. The classifier is doing real work, not just
echoing the alias table.

**Response selection must be deterministic**, not `random.choice`:

```python
index = zlib.crc32((module_name + style).encode()) % len(responses)
```

Revision 1 said `hash(module_name + style)`. Python randomises `hash()` for strings
per process, so that would have changed the chosen link on every container restart -
the exact failure the requirement exists to prevent. `crc32` is stable across
processes and machines. The demo must show the same link on every rehearsal.

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

Also confirm: a nonsense module name (`"module_name":"Pointers"`) returns 204 rather
than an error or a confident wrong answer, and that repeating the whole loop after
`docker compose restart recommender` returns byte-identical links.

**Verified 2026-09-17**: `/health` returns `{"status":"ok","classes":16,"vocab":41}`;
all sixteen module/style combinations return the correct tag with confidence >=
0.999 and 16 distinct links, 4 distinct per module; `Pointers`,
`Weekly announcements`, `Structs and unions` and `Course introduction` all return
204; an invalid style returns 422; the fallback branch returns `"source":
"fallback"` when forced; and the responses are byte-identical across a container
restart.

---

## WP2: Plugin scaffolding and the VARK questionnaire

### Files and the things that go wrong in them

**`version.php`** - `$plugin->component = 'local_tutoragent';`,
`$plugin->version` as `YYYYMMDDXX`, `$plugin->requires` set to the Moodle 5.2
version integer. Look that integer up in `/var/www/html/version.php` rather than
guessing it.

**`classes/observer.php`** - must declare `namespace local_tutoragent;` and
`class observer`. The original used a non-namespaced `local_tutoragent_observer`
in a root-level file, which Moodle's autoloader will not find.

**`db/install.xml`** - use Appendix B as the starting point, but **validate it
against `lib/xmldb/xmldb.xsd` in the 5.2 source** before installing. XMLDB is
stable, so it should apply unchanged; invalid XMLDB fails in a way that leaves the
DB half-upgraded and needs `make reset` to retry cleanly.

**`classes/privacy/provider.php`** - the plugin stores per-user data, so a
`null_provider` is not acceptable. Implement
`\core_privacy\local\metadata\provider` and
`\core_privacy\local\request\plugin\provider`. Without it the admin page shows a
warning, which you do not want on screen during a defense.

**`settings.php`** - one admin setting, `recommenderurl`, defaulting to
`http://recommender:8000`. Do not hardcode the URL in the observer.

**`lib.php`** - three things: the navigation hook that surfaces the questionnaire
link, the VARK scoring function, and the `curl_*` call to the recommender with the
timeouts from section 3.

**`vark.php` and `classes/form/vark_form.php`** - the 16-question VARK
questionnaire using Moodle's Forms API (`moodleform`). Each question has four
options mapping to V, A, R, K. Multi-select is allowed by the VARK instrument.

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

After submission, show the detected style with a short description and a link back
to the course.

### Do NOT build a global redirect

The thesis describes force-redirecting first-time users to the questionnaire. A
global redirect from `lib.php` is one bad conditional away from locking the admin
out of the site the night before a defense. Revision 1 deferred it to WP4 as
optional; this revision cuts it. Discovery happens through the navigation link plus
the notification added in WP3, which is enough for the demo.

### What the navigation actually needs in 5.2

The `local_<plugin>_extend_navigation` callback in `lib.php` still exists and still
runs - `global_navigation::load_local_plugin_navigation()` calls it - but nothing
it adds reaches the rendered navigation drawer. Use the hook instead: register
`\core\hook\navigation\primary_extend` in `db/hooks.php` and add the node to
`$hook->get_primaryview()`. That puts the questionnaire in the top bar, where a
presenter can point at it.

Two smaller things: `db/access.php` is not needed, because the questionnaire wants
`require_login()` and no capability of its own, and an unused capability file is
worse than no file. And `recommenderurl` must be `PARAM_RAW_TRIMMED`, not
`PARAM_URL`: the default is a Docker service name with no dot in it, which
`PARAM_URL` rejects outright.

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

**Verified 2026-09-17**: installs via `admin/cli/upgrade.php` with zero warnings and
creates `mdl_local_tutoragent_vark` with a unique index on `userid`; the settings
page renders with the default URL; the questionnaire renders 16 questions and 64
checkboxes; submitting all-Read/Write stores `read_write` with
`{"V":0,"A":0,"R":16,"K":0}`; resubmitting all-Visual leaves **one** row, updated to
`visual`; zero debug blocks on the page and zero warnings in the log.

With the real questionnaire in place, re-verified: answering consistently in one
dimension yields that style in all four cases (`{"V":16}` -> visual, `{"A":16}` ->
auditory, `{"R":16}` -> read_write, `{"K":16}` -> kinesthetic), multi-select within a
question is counted, a deliberate 1-1 tie stores `"tiebreak":"V,A"` and the page
says "Tied on V,A; resolved in the order Visual, Aural, Read/Write, Kinesthetic",
and an all-zero submission is rejected with "Please answer at least one question"
and stores nothing.

---

## WP3: Observer wiring

### `db/events.php`

```php
$observers = [
    [
        'eventname' => '\core\event\course_module_viewed',
        'callback'  => '\local_tutoragent\observer::course_module_viewed',
    ],
    [
        'eventname' => '\core\event\course_viewed',
        'callback'  => '\local_tutoragent\observer::course_viewed',
    ],
];
```

`course_viewed` was not in revision 1 and the demo needs it: the script starts with
"open the course", and without this observer the invitation only appears once the
student opens an activity, which is a chicken and egg they should not have to solve
on stage. On a course page there is no module to recommend for, so that observer
only ever emits the invitation.

**After editing `db/events.php`, purge.** The observer list is cached, and a version
bump plus `admin/cli/upgrade.php` is not enough to refresh it. This cost real time:
the callback was correct and simply never ran.

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
5. POST to the recommender. On any error, timeout, or 204, return silently.
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

### A timing caveat to know about

`\core\notification::info()` queues into the session and renders in the page header.
`mod_page` triggers its view event *before* the header is output, so the
notification appears on the same page load - which is what the demo needs, and WP4
seeds only `mod_page` activities. Other module types trigger the event after the
header and the notification would land on the *next* page load. Say this in the
README rather than implying it works identically everywhere.

### Gate WP3

- Student with a stored style clicks an activity, notification appears with a link
  matching their style.
- **Two students with different styles see different links for the same activity.**
  This is the acceptance criterion. "It returned something" is not the test.
- Page source is clean, no stray output, no debug banner.
- `docker compose stop recommender` then reload: page renders normally and on time.
- A student with no style sees the questionnaire prompt.

**Verified 2026-09-17**, five students opening the same Arrays activity (cmid 2):

| Student | Link |
|---|---|
| `student.rw` | tutorialspoint.com/cprogramming/c_arrays.htm |
| `student.visual` | youtube.com/watch?v=6tjYC86iV5E |
| `student.aural` | youtube.com/watch?v=0EgpeYB115s |
| `student.kines` | youtube.com/watch?v=RiVFAVqpo34 |
| `student.blank` | questionnaire invitation |

All four links distinct; the same holds for the other three activities, with
read/write getting articles and visual getting video throughout. Page source clean,
zero warnings in the log.

Timeouts, measured: with the recommender container stopped the page renders in
0.09-0.13s (the host does not resolve, so curl fails in about 2ms - faster than
with the service up). The timeout that matters is a host that accepts nothing and
never answers, so point `recommenderurl` at `http://10.255.255.1:8000` and reload:
1.12-1.17s, page complete, no debug output, no link. That is the 1s connect timeout
plus a normal page, exactly as specified.

---

## WP4: Seed data and demo run-through

### `cli/seed_demo.php`

Creates a course "Introduction to Programming in C" with four `mod_page`
activities: Arrays, Control Structures, Functions, Data Types.

Give each a `name` and `intro` that the topic aliases match - and **keep each intro
topically clean**. Alias matching is substring-based on `name + intro`, so an Arrays
intro that mentions "loops" would match the `control` key first and misclassify the
activity. One topic per intro.

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
`enrol_try_internal_enrol()` from `lib/enrollib.php`. **Check each signature against
the 5.2 source before calling it.**

**`add_moduleinfo()` needs a more complete object than its signature suggests.** In
4.5 it wanted `modulename`, `course`, `section`, `name`, `intro`, `introformat`,
`content`, `contentformat`, `visible`, `visibleoncoursepage`, `cmidnumber`,
`groupmode`, `completion`, `completionview`, `showdescription`. Treat that as a
starting list for 5.2, not gospel. Expect to iterate.

**If `add_moduleinfo()` costs you more than 30 minutes, switch approach:** have the
human build the course once in the UI, export it with `admin/cli/backup.php`, commit
the resulting `.mbz` to `fixtures/`, and restore it in the seed script with
`admin/cli/restore_backup.php`. That is deterministic and avoids the API entirely.
Flag the switch to the human rather than grinding.

The script must be idempotent, or detect an existing seed and refuse with a clear
message.

It also takes `--reset-blank`, exposed as `make rehearse`, which clears
`student.blank`'s style so the demo can be run from the top again. Without it the
second rehearsal starts with a student who already has a style, and step 1 shows a
recommendation instead of the invitation. Deleting that row by hand before every
run is exactly the "manual fix" the WP4 gate forbids.

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

**Verified 2026-09-17.** `make reset` brings the site up healthy in about 90
seconds with zero warnings and the plugin installed by the site installer itself;
`make seed` takes 1.2 seconds and `add_moduleinfo()` worked first time, so the
backup/restore fallback was not needed. Both demo runs produced, identically:
invitation on the course page, "Kinesthetic" after the questionnaire, a
youtube.com/watch?v=RiVFAVqpo34 link on Arrays for the kinesthetic student, and
tutorialspoint.com/cprogramming/c_arrays.htm on the same activity for
`student.rw`. Zero warnings across both runs.

---

## 5. README

The README must get a reader from clone to working demo with no prior knowledge.
Write it for someone who does not write code: exact commands to copy, what each one
should print, and what to do when it does not.

Required sections: prerequisites; `.env` setup; `make up`; `make seed`; the demo
script above; the credentials table; the four resolved version numbers; the
disclosed `intents.json` edits; troubleshooting (stale cache, port conflicts,
recommender down, first build is slow); the architecture in five lines; and the
known limitations from section 6.

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
- Two layers sit around the classifier and both are deliberate and disclosed: the
  topic alias expansion, because the vocabulary has no stem for "control", and the
  style mask, because the student's modality is stored data rather than something
  to infer. Quote the 5/48, 10/48, 0/48 measurements: they show both layers are
  load-bearing rather than decoration.
- The VARK learning-styles matching hypothesis is contested in the education
  literature. The correct position is that this implements VARK as specified by the
  source thesis, not that matching has been shown to improve outcomes.

---

## Appendix A: verified defects in `tutoragent.zip`

Confirmed by reading the code in the zip. Do not reintroduce any of these.

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
| 13 | `main.py` | `random.choice(responses)` - a different link on every identical request. |
| 14 | `intents.json` | 6 of 16 tags carry stray leading or trailing whitespace. |
| 15 | `intents.json` | 7 of 100 responses have broken anchor markup, rendering as plain text. Enumerated in WP1. |

## Appendix B: starting-point `db/install.xml`

Validate against `lib/xmldb/xmldb.xsd` in the 5.2 source before use.

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
   trying to patch forward. Get `install.xml` right the first time.
3. **Event observers run mid-request.** No output, ever.
4. **`moodledata` outside the web root.** Always.
5. **`max_input_vars = 5000`.** Moodle refuses to install below this.
6. **Moodle's `\curl` wrapper blocks private IPs** via `curlsecurityblockedhosts`.
   Use native curl for the internal service call.
7. **Table names are length-limited.** `local_tutoragent_vark` is fine at 21 chars.
8. **Run CLI scripts as `www-data`**, or file ownership in `moodledata` breaks:
   `docker compose exec -u www-data moodle php /var/www/moodle/admin/cli/purge_caches.php`
9. **The web root is `public/`, the code root is its parent.** Plugins live in
   `public/local/`, `config.php` lives in the parent, CLI shims live in the
   parent's `admin/cli/`. Getting this wrong gives a `rootdirpublic` exception.
10. **`postgres:18` moved `PGDATA`** to `/var/lib/postgresql/18/docker` and
    declares its volume at `/var/lib/postgresql`. Mount the parent or the data
    does not persist.
11. **Do not rebuild PHP extensions the base image already has.** See WP0.
12. **Event observers are cached.** After editing `db/events.php`, a version bump
    and `admin/cli/upgrade.php` do not refresh the observer list. `make purge`.
13. **`local_*_extend_navigation` in lib.php runs but surfaces nothing** in 5.2.
    Use the `\core\hook\navigation\primary_extend` hook.
14. **FastAPI cannot build a response model from a union.** A handler returning
    either a dict or a bare `Response` needs `response_model=None` on the
    decorator, or the app refuses to start.

## Appendix D: final self-check before handing back

Run all of these and report the output. Do not summarise, paste it.

```sh
# no shell execution anywhere
grep -rnE "shell_exec|passthru|\bexec\(|system\(" plugin/ recommender/ || echo "CLEAN"

# no empty get_record conditions
grep -rn "get_record(" plugin/ | grep "array()" || echo "CLEAN"

# no output in the observer
grep -nE "echo|print|var_dump" plugin/local/tutoragent/classes/observer.php || echo "CLEAN"

# no randomness in response selection
grep -rn "random\.\|hash(" recommender/ || echo "CLEAN"

# recommender url not hardcoded outside settings/defaults
grep -rn "recommender:8000" plugin/ | grep -v "settings.php" || echo "CLEAN"

# cold run
make reset && make seed && curl -s localhost:8000/health
```

Then state plainly which acceptance gates pass and which do not. If a gate does not
pass, say so. Do not report a gate as passing on the basis that the code looks
correct.
