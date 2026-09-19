# How this works, end to end

A developer's tour. The README tells you how to run it; this tells you what
happens and why it is built the way it is.

## The one-sentence version

A Moodle event observer notices a student opening an activity, asks a small
FastAPI service for a link matched to that student's VARK learning style, and
shows the answer as a Moodle notification.

---

## 1. The request path

This is the part worth understanding. Everything else serves it.

```
student opens /mod/page/view.php?id=2
        │
        │  Moodle triggers \mod_page\event\course_module_viewed
        ▼
db/events.php ──► observer::course_module_viewed()          plugin
        │
        │  1. should_act()?  not CLI/AJAX/web service, logged in,
        │                    not a guest, and the event is the viewer's own
        │  2. does this user have a stored style?
        │       no  ──► notification inviting them to the questionnaire, stop
        │  3. $DB->get_record($event->objecttable, ['id' => $event->objectid])
        │       ──► the activity's name and intro
        ▼
local_tutoragent_request_recommendation()                   lib.php
        │
        │  native curl_*, 1s connect / 2s total, every failure returns null
        ▼
POST http://recommender:8000/recommend                      private network
{"module_name": "Arrays", "module_intro": "...", "style": "read_write"}
        │
        ▼
recommend()                                                 app.py
        │
        │  1. find_topic()  longest of the 38 keys found in the text wins,
        │                    appends its expansion
        │       none ──► 204 No Content
        │  2. forward()     numpy pass over the trained weights
        │  3. mask to the four classes ending in this style; argmax picks
        │                    the topic; renormalise for confidence
        │  4. disagrees with the key ──► the key wins, "source": "keyword"
        │     agrees but below THRESHOLD ──► "source": "fallback"
        │  5. pick_response()  crc32(module + style) % len(responses)
        ▼
{"tag": "arrays read_write", "confidence": 0.99,
 "html": "<a href='...'>this link</a>", "source": "model"}
        │
        ▼
\core\notification::add($html, NOTIFY_INFO)                 observer
        │
        ▼
the notification renders at the top of the activity page
```

Two students with different styles hit the same activity and get different
links. That contrast is the entire point of the project.

### Why a 204 exists

If the activity is not something the content library covers — "Pointers",
"Weekly announcements" — the service says nothing rather than returning its
best of sixteen. Without that check a student reading about pointers is handed
material on data types, and it looks like the system is guessing. Which it is.

---

## 2. The three fixes

The 2022 original could not personalise at all. Three things make this one work,
and all three are disclosed rather than hidden, because each is a layer around
the model rather than the model itself.

**1. The style token reaches the classifier.** Every training pattern embeds its
modality — `"Array index visual"`, `"Array index auditory"` — so the modality
stem is the only thing separating `arrays visual` from `arrays auditory`. The
original hardcoded `$vark = 0` on the PHP side, so that token never arrived and
the model could not discriminate at all.

**2. Topic alias expansion.** The 41-stem vocabulary contains `if/else`, `loop`,
`swic` and `whil` but no stem for `control`, so an activity called "Control
Structures" matches nothing. Four alias entries map a keyword to a topic and an
expansion appended to the classifier input.

**3. The softmax is restricted to the student's stored style.** Expansion alone
swamps the single modality stem: an expanded input lights up 11 vocabulary
entries where a training vector lights up 7, and the topic stems outvote the one
modality stem. Measured over 12 activity variants x 4 styles:

| | wrong |
|---|---|
| expansion, unrestricted argmax | 5 / 48 (wrong modality) |
| restriction, no expansion | 10 / 48 (wrong topic) |
| both | **0 / 48** |

The student's style is stored data, not something to infer. What the classifier
is actually asked is *which topic is this activity about*.

---

## 3. The model

`recommender/train.py` runs once, at image build time, in a stage that has
scikit-learn. It:

1. reads `data/intents.json` — 152 tags (38 topics x 4 modalities), 900
   patterns, 372 responses
2. tokenises with `tokenize()`: lowercase, `re.findall(r"[a-z_/]+")`, Lancaster
   stem. The regex keeps `if/else` and `read_write` whole; both are real
   vocabulary entries
3. builds the vocabulary with `sorted(set(...))` — **363 stems**
4. trains `MLPClassifier(hidden_layer_sizes=(8, 8), activation="relu",
   solver="adam", max_iter=1000, batch_size=8, random_state=0)`, matching the
   project report. Only the seed is not the report's: at 152 classes seed 42
   leaves one pattern of 900 misfitted and step 6 fails the build
5. saves the weights to `data/model.npz`
6. **asserts `forward()` reproduces `predict_proba`** on every training vector
   and on the demo's own inputs, and fails the build on any disagreement

That assertion is what lets the runtime image ship without scikit-learn or
scipy — 211MB it would otherwise never use. The two paths agree to `0.000e+00`,
measured over 20,002 vectors including all-zero, all-ones and random
out-of-domain inputs, because `predict_proba` is the same matrix
multiplications through the same numpy.

`app.py` imports `tokenize()` and `forward()` **from `train.py`**, so training
and serving cannot drift apart. That is the whole reason the import direction
looks backwards.

### Determinism

`pick_response()` uses `zlib.crc32`, not Python's built-in string hash, which is
salted per process and would hand out a different link after every container
restart. The demo shows the same link on every rehearsal.

### Honest framing

2,984 parameters, 900 training examples — 3.3 per example. All 900 input vectors
are unique with no class collisions, so 100% training accuracy is arithmetic, not
achievement. On the 152 seeded activity-and-style combinations the classifier
alone is right 145 times and the keyword table 152; the keyword table decides
where they differ. The defensible description is "a classifier over a curated intent
table", not "a model that generalises". See *Known limitations* in the README;
that section is the one to read before a viva.

---

## 4. The plugin

```
plugin/local/tutoragent/
├── version.php            component, version, requires
├── settings.php           recommenderurl, forceredirect
├── lib.php                VARK scoring + the HTTP call
├── vark.php               the questionnaire page
├── db/
│   ├── install.xml        local_tutoragent_vark
│   ├── events.php         course_module_viewed, course_viewed
│   └── hooks.php          primary_extend, after_config
├── classes/
│   ├── observer.php       event → recommendation
│   ├── hook_callbacks.php navigation link + the gate
│   ├── form/vark_form.php the 16 questions and the form
│   └── privacy/provider.php
├── cli/seed_demo.php      the ten demo courses, admin and students;
│                          idempotent, run on every container start, and
│                          SEED_USERS=0 in production seeds no people
└── lang/en/…
```

### Storage

One table, one row per user:

```sql
local_tutoragent_vark(id, userid UNIQUE, style, scores TEXT, timemodified)
```

`style` is one of `visual`, `auditory`, `read_write`, `kinesthetic` — the strings
the recommender expects. `scores` is JSON holding the per-dimension counts, the
dominant letter and any tie-break, so the result can be *explained* rather than
asserted when a panel asks.

### Scoring

`local_tutoragent_score()` counts selections per dimension. Highest wins; a tie
breaks in the order V, A, R, K and is recorded. An entirely empty submission is
rejected by the form's `validation()` — it would otherwise score zero everywhere
and hand back "Visual" by tie-break, which is a lie rather than a result.

### The gate

`hook_callbacks::after_config()` runs on **every request** and sends a student
with no stored style to the questionnaire, whatever page they asked for. It is
modelled on how Moodle gates its own site policy in `require_login()` and on
`tool_mfa`, which uses the same hook.

Three things keep it safe:

1. **It fails open.** The whole callback is wrapped in try/catch. A throw here
   is a site outage, not a page bug.
2. **Site admins and "log in as" are never gated.** First way back in.
3. **`forceredirect` can be cleared without loading a page** — the command is in
   the setting's own description:
   `php admin/cli/cfg.php --component=local_tutoragent --name=forceredirect --set=0`

The exemptions are an **allow-list**, not a deny-list: the questionnaire itself,
`/login/`, `/user/policy.php`, and the asset endpoints (`/pluginfile.php`,
`/theme/`, `/lib/javascript.php`, …). The asset paths are not cosmetic — without
them the questionnaire is served with no CSS and no JavaScript, and its form
cannot submit.

`qualified_me()` is stashed in `$SESSION->wantsurl` before redirecting, so the
result page can offer a Continue button back to where the student was going.

---

## 5. Containers and boot

```
db           postgres:18                                479MB
moodle       php:8.4-apache + Moodle 5.2.3+             928MB
recommender  python:3.14-slim + FastAPI                 264MB
```

The Moodle image copies `plugin/local/tutoragent` in at build time, so a pulled
image serves the plugin unaided; `compose.yml` bind-mounts the same
directory over the top so local edits apply without rebuilding.
`compose.prod.yml` omits the mount.

### Moodle 5.2 serves from `public/`

```
/var/www/moodle/          code root: config.php and the CLI shims
  admin/cli/*.php         run these
  public/                 Apache DocumentRoot
    local/tutoragent/     the plugin: copied in at build, mounted over in dev
```

Serving the code root throws a deliberate `rootdirpublic` exception. Every path
in older Moodle documentation moves accordingly.

### entrypoint.sh, in order

1. wait for Postgres (a PHP `pg_connect` loop — no extra packages needed)
2. restore `config.php` from `/var/moodledata/.config.php` if the container is
   new but the database is already populated
3. otherwise run `admin/cli/install.php`, with `--skip-database` if the database
   already has tables
4. keep a fresh copy of `config.php` on the data volume
5. set `debug` and `debugdisplay` through `admin/cli/cfg.php`
6. purge caches
7. `exec apache2-foreground`

Steps 5 and 6 are explicitly **non-fatal**. A site that starts without debug
settings is a nuisance; a site that will not start is a lost demo.

> **Never edit `config.php` from the entrypoint.** An earlier revision inserted
> the debug settings with `sed`. The insert collapsed onto one line beginning
> `//`, so the settings never applied; and with both markers then on that one
> line, the next boot's `/start/,/end/d` range ran to end of file and deleted
> `require_once(setup.php)`. `config.php` ended up empty, `$CFG` undefined,
> Apache dead, and `restart: unless-stopped` looping forever.

### What survives what

| | database | moodledata | sessions | config.php |
|---|---|---|---|---|
| `docker compose restart` | ✅ | ✅ | ✅ | ✅ |
| `make down && make up` | ✅ | ✅ | ✅ | restored from the volume |
| `make reset` | ❌ | ❌ | ❌ | recreated by the installer |

---

## 6. Changing things

**Add a topic.** Add its four tags to `intents.json`, add an entry to `TOPICS` in
`app.py` mapping a keyword to `(tag topic, expansion)`, and add the activity to
`seed_demo.php`. Rebuild; the vocabulary and the model rebuild themselves. Three
things have to hold, and all three have bitten:

- Keep the tag naming consistent, because the keyword path builds tags as
  `f"{topic} {style}"`.
- The new key must not be a longer substring of another topic's activity text,
  and no other key may be longer inside its own. `recursion` inside the Functions
  intro and `process` inside the Threads intro both had to be worded away.
- Give each tag one pattern in the exact shape the server sends
  (`name intro style alias`). Without it the classifier routes 82 of 152
  activities correctly instead of 145.

**Change the questions.** One array: `vark_form::QUESTIONS`. Keep `dim` as one of
V, A, R, K, and do not sort the options — the order varies per question by
design, and sorting would put every correct answer in the same checkbox position.

**Change the confidence threshold.** `RECOMMENDER_THRESHOLD` in `.env`.

**Point Moodle at a different recommender.** *Site administration → Plugins →
Local plugins → Tutor agent*, or `admin/cli/cfg.php --component=local_tutoragent
--name=recommenderurl`.

**Edit the plugin.** `compose.yml` bind-mounts the directory, so just
edit and reload —
but **`make purge` after touching `db/events.php`, `db/hooks.php` or any lang
file.** Observers and strings are cached, and a version bump plus
`admin/cli/upgrade.php` does not refresh the observer list.

---

## 7. Failure modes

| Symptom | Cause |
|---|---|
| No notification, page otherwise fine | recommender down, or it returned 204. By design — every failure path returns null |
| Page hangs ~1s then renders | the recommender host resolves but does not answer; `CURLOPT_CONNECTTIMEOUT` doing its job |
| Code change has no effect | cached. `make purge` |
| Questionnaire renders unstyled | an asset path is missing from the gate's allow-list |
| Everyone including admins is redirected | the gate's admin exemption broke. `admin/cli/cfg.php --name=forceredirect --set=0` |
| "Coding error: unexpected output" | something in the observer wrote to the output buffer. Nothing there may |

---

## 8. Moodle 5.2 specifics worth knowing

- `local_*_extend_navigation()` in `lib.php` still runs but nothing it adds
  reaches the rendered navigation. Use the `\core\hook\navigation\primary_extend`
  hook.
- `PARAM_URL` rejects `http://recommender:8000` — no dot in the hostname — and
  stores an empty string silently. Use `PARAM_RAW_TRIMMED`.
- Event observers are cached; `make purge` after editing `db/events.php`.
- Do **not** delete Moodle's `tests/` directories to save space.
  `lib/mlbackend/python/classes/processor.php` requires a file under
  `analytics/tests/`, so a fresh install dies in `admin_apply_default_settings()`
  and the container restarts half-installed. It only reproduces on a *fresh*
  install, which is how it gets missed.
- `course/view.php` calls `course_view()` *after* `$OUTPUT->header()`, yet
  notifications still render on that same load. `mod_page` triggers its view
  event before the header. Other module types may differ.

---

## 9. Where to look next

- `README.md` — running it, the demo script, credentials, known limitations
- `specs.md` — the build brief, every acceptance gate, and Appendix C's list of
  Moodle traps
- `reference/` — the original 2022 thesis code, kept unmodified. Appendix A of
  `specs.md` lists its verified defects; several of the design choices here exist
  specifically to avoid repeating them
