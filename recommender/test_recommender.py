"""Everything that can be checked about the recommender without a browser.

One file, because it is one area. Content integrity, the topic keyword table,
the trained model, and the HTTP surface all describe the same thing: whether a
student who answered the questionnaire gets the right link.

    docker compose run --rm recommender python -m pytest test_recommender.py -q

The link-liveness checks reach the network and are marked `network`; they are
deselected by default because a demo machine may be offline and a 403 from a
bot-protected host is not a broken link. Run them deliberately:

    python -m pytest test_recommender.py -m network -q
"""

import json
import re
import urllib.error
import urllib.parse
import urllib.request

import pytest
from fastapi.testclient import TestClient

import app as recommender
from train import bag_of_words, forward, load_intents, load_model

TOPICS = ["intro c", "operators", "arrays", "controls", "functions", "data types"]
MODALITIES = ["visual", "auditory", "read_write", "kinesthetic"]

# What each modality is allowed to hand a student. The whole project rests on
# these four being different kinds of thing rather than four framings of the
# same video, so it is a test rather than a convention.
MEDIUM = {
    "auditory": re.compile(r"ocw\.mit\.edu|cs50\.harvard|stanford\.edu"),
    "kinesthetic": re.compile(
        r"learn-c|online-compiler|onlinegdb|hackerrank|exercism"
        r"|c_exercises|compile_c_online|jdoodle|replit"
    ),
}
NOT_C = re.compile(
    r"\bpython\b|\bjava\b(?!script)|\bc#\b|\bexcel\b|\bvba\b"
    r"|\bmatlab\b|hack audio|\bprocessing\b|c\+\+",
    re.I,
)
ANCHOR = re.compile(r"<a\s+href\s*=\s*'([^']+)'\s*>.*?</a>", re.S)

INTENTS = load_intents()
BY_TAG = {intent["tag"]: intent for intent in INTENTS}
client = TestClient(recommender.app)


def urls_of(tag):
    return [m.group(1) for r in BY_TAG[tag]["responses"] for m in ANCHOR.finditer(r)]


def every_tag():
    return [f"{topic} {modality}" for topic in TOPICS for modality in MODALITIES]


# --- the content library -------------------------------------------------


def test_library_holds_exactly_the_c_topics():
    assert sorted(BY_TAG) == sorted(every_tag())
    assert len(INTENTS) == 24


@pytest.mark.parametrize("tag", every_tag())
def test_every_tag_can_answer(tag):
    assert BY_TAG[tag]["responses"], f"{tag} would 500 on a student who reaches it"


@pytest.mark.parametrize("tag", every_tag())
def test_every_response_is_one_well_formed_link(tag):
    for response in BY_TAG[tag]["responses"]:
        assert "<a" in response, f"{tag}: response has no link at all"
        assert len(ANCHOR.findall(response)) == 1, f"{tag}: malformed anchor: {response}"


@pytest.mark.parametrize("tag", every_tag())
def test_nothing_teaches_another_language(tag):
    for response in BY_TAG[tag]["responses"]:
        found = NOT_C.search(response)
        assert not found, f"{tag} sends a C student to {found.group(0)}: {response}"


@pytest.mark.parametrize("tag", [t for t in every_tag() if t.rsplit(" ", 1)[1] in MEDIUM])
def test_modality_serves_its_own_medium(tag):
    modality = tag.rsplit(" ", 1)[1]
    for url in urls_of(tag):
        assert MEDIUM[modality].search(url), f"{tag} is not {modality}: {url}"


@pytest.mark.parametrize("tag", every_tag())
def test_no_tag_offers_the_same_link_twice(tag):
    """A repeated URL inside one tag is dead weight: pick_response is a modulo
    over the list, so a duplicate silently doubles that link's odds."""
    urls = urls_of(tag)
    assert len(urls) == len(set(urls)), f"{tag} repeats a link"


@pytest.mark.parametrize("topic", TOPICS)
def test_no_url_serves_two_modalities_of_one_topic(topic):
    """pick_response shows one link per activity and style. A URL under two
    modalities can hand the same link to two students who answered the
    questionnaire differently, which is the comparison the project exists to
    demonstrate."""
    seen = {}
    for modality in MODALITIES:
        for url in urls_of(f"{topic} {modality}"):
            assert url not in seen, f"{url} is in both {seen[url]} and {modality}"
            seen[url] = modality


# --- the model -----------------------------------------------------------


def test_model_matches_the_library():
    model = load_model()
    assert sorted(model["classes"]) == sorted(every_tag())


def test_classifier_fits_every_training_pattern():
    """Not an achievement at this size, but a regression guard: if a pattern
    stops fitting, the table and the weights have drifted apart."""
    model = load_model()
    vocab, classes = model["vocab"], model["classes"]
    for intent in INTENTS:
        for pattern in intent["patterns"]:
            probabilities = forward(
                bag_of_words(pattern, vocab), model["weights"], model["biases"]
            )[0]
            assert classes[int(probabilities.argmax())] == intent["tag"], pattern


# --- the HTTP surface ----------------------------------------------------


def test_health_reports_the_pruned_library():
    body = client.get("/health").json()
    assert body == {"status": "ok", "classes": 24, "vocab": len(recommender.VOCAB)}


@pytest.mark.parametrize("topic", TOPICS)
@pytest.mark.parametrize("modality", MODALITIES)
def test_each_style_gets_its_own_topic_and_medium(topic, modality):
    activity = {
        "intro c": ("Introduction to C", "Your first C program and the main function."),
        "operators": ("Operators", "Arithmetic, relational and logical operators."),
        "arrays": ("Arrays", "Declaring an array and accessing elements."),
        "controls": ("Control Structures", "Selection with if/else and switch."),
        "functions": ("Functions", "Defining and calling functions."),
        "data types": ("Data Types", "Basic, derived and enumerated data types."),
    }[topic]
    response = client.post(
        "/recommend",
        json={"module_name": activity[0], "module_intro": activity[1], "style": modality},
    )
    assert response.status_code == 200
    body = response.json()
    assert body["tag"] == f"{topic} {modality}"
    url = ANCHOR.search(body["html"]).group(1)
    if modality in MEDIUM:
        assert MEDIUM[modality].search(url), f"{modality} was handed {url}"


def test_two_styles_on_one_activity_get_different_links():
    seen = {}
    for modality in MODALITIES:
        body = client.post(
            "/recommend",
            json={"module_name": "Arrays", "module_intro": "Array index.", "style": modality},
        ).json()
        url = ANCHOR.search(body["html"]).group(1)
        assert url not in seen, f"{modality} and {seen[url]} got the same link"
        seen[url] = modality


def test_an_uncovered_activity_says_nothing():
    for name in ["Pointers", "Weekly announcements", "Normalisation", "SQL Queries"]:
        response = client.post(
            "/recommend", json={"module_name": name, "module_intro": "", "style": "visual"}
        )
        assert response.status_code == 204, f"{name} should not be answered"


def test_an_unknown_style_is_rejected():
    response = client.post(
        "/recommend", json={"module_name": "Arrays", "module_intro": "", "style": "tactile"}
    )
    assert response.status_code == 422


def test_the_same_request_always_gets_the_same_link():
    payload = {"module_name": "Functions", "module_intro": "Return values.", "style": "read_write"}
    first = client.post("/recommend", json=payload).json()["html"]
    for _ in range(5):
        assert client.post("/recommend", json=payload).json()["html"] == first


def test_the_keyword_table_agrees_with_the_library():
    for key, (topic, _) in recommender.TOPICS.items():
        assert topic in TOPICS, f"{key} maps to {topic}, which the library does not hold"
        for modality in MODALITIES:
            assert f"{topic} {modality}" in BY_TAG


# --- the links themselves (network) --------------------------------------


def _status(url):
    request = urllib.request.Request(
        url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                                   "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"}
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code
    except Exception:
        return 0


@pytest.mark.network
@pytest.mark.parametrize("url", sorted({u for t in every_tag() for u in urls_of(t)}))
def test_every_link_resolves(url):
    """403 is allowed: Cloudflare answers a script that way on hosts a student's
    browser reaches without trouble. A dead link is 404 or a refused
    connection."""
    if "youtube.com/watch" in url:
        endpoint = "https://www.youtube.com/oembed?format=json&url=" + urllib.parse.quote(url, safe="")
        status = _status(endpoint)
        assert status == 200, f"video is gone or private: {url}"
    else:
        assert _status(url) in (200, 403), f"dead link: {url}"
