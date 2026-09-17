"""VARK-aware recommender.

POST /recommend with a module name, its intro text and a learning style, and
this returns one HTML link chosen for that style.

Two things make this work that did not work in the 2022 original:

1. The style token reaches the classifier.  Every training pattern embeds its
   modality ("Array index visual"), so the modality stem is what separates
   "arrays visual" from "arrays auditory".  The original hardcoded the style to
   0 on the PHP side, so the token never arrived and the model could not
   discriminate at all.

2. Topic alias expansion.  The vocabulary has if/else, loop, swic and whil but
   no stem for "control", so a Moodle activity called "Control Structures"
   matches nothing on its own.

The expansion is what makes an unknown topic resolvable, but it also swamps the
single modality stem: measured over 12 module variants x 4 styles, expanding
alone gets the modality wrong 5 times.  So the softmax is restricted to the
four classes matching the student's stored style, and the classifier chooses
the topic among them.  The style is data we hold, not something to guess.
Expansion without the restriction fails 5/48; the restriction without expansion
fails 10/48; together they are 0/48.
"""

import json
import os
import zlib
from typing import Literal

from fastapi import FastAPI, Response
from pydantic import BaseModel

import joblib

from train import MODEL_PATH, bag_of_words, load_intents, tokenize

THRESHOLD = float(os.environ.get("RECOMMENDER_THRESHOLD", "0.45"))

# Alias key -> (tag topic, expansion text).  The tags in intents.json use plural
# topics while the natural keys are singular, so one table carries both and the
# fallback can never build a tag that does not exist.
TOPICS: dict[str, tuple[str, str]] = {
    "array": ("arrays", "array index declaration accessing elements"),
    "control": ("controls", "if/else selection statement for loop while do switch"),
    "function": ("functions", "function calling defining arguments recursion storage class"),
    "data type": ("data types", "data type basic derived enumerated void"),
}

# Stems that say nothing about the topic: the four modalities, plus the
# function words that survived stemming.  What is left is the set of stems that
# indicate this activity is something the library actually covers.
_MODALITY_STEMS = {"vis", "audit", "read_write", "kinesthet"}
_FUNCTION_STEMS = {"an", "and", "in", "is", "of", "or", "the", "what"}

_bundle = joblib.load(MODEL_PATH)
CLASSIFIER = _bundle["clf"]
VOCAB = _bundle["vocab"]
LABELS = _bundle["labels"]
RESPONSES = {intent["tag"]: intent["responses"] for intent in load_intents()}
TOPIC_STEMS = set(VOCAB) - _MODALITY_STEMS - _FUNCTION_STEMS

app = FastAPI(title="tutoragent recommender")


class RecommendRequest(BaseModel):
    module_name: str
    module_intro: str = ""
    style: Literal["visual", "auditory", "read_write", "kinesthetic"]


def find_topic(text: str) -> tuple[str, str] | None:
    """First match wins, in the order TOPICS is declared.

    Taking only the first keeps the result deterministic and stops an intro
    that mentions two topics from blurring into both.
    """
    lowered = text.lower()
    for key, topic in TOPICS.items():
        if key in lowered:
            return topic
    return None


def recognises(text: str) -> bool:
    """True if anything in the activity text is in the model's vocabulary.

    "Pointers" or "Weekly announcements" light up nothing, and the library has
    no content for them.  Without this test the classifier still returns its
    best of sixteen and the student gets data-type material for a lecture on
    pointers.
    """
    return bool(TOPIC_STEMS.intersection(tokenize(text)))


def pick_response(tag: str, module_name: str, style: str) -> str:
    """Deterministic choice.

    The original picked a response at random, so the same request gave a
    different link every time. crc32 is used rather than Python's built-in
    string hash, which is salted per process: that would hand out a different
    link after every container restart, which is the opposite of what a
    rehearsed demo needs.
    """
    responses = RESPONSES[tag]
    return responses[zlib.crc32(f"{module_name}{style}".encode()) % len(responses)]


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "classes": len(LABELS), "vocab": len(VOCAB)}


# response_model=None because this returns either a dict or a bare 204
# Response, and FastAPI cannot build one response model from that union.
@app.post("/recommend", response_model=None)
def recommend(request: RecommendRequest) -> Response | dict:
    activity = f"{request.module_name} {request.module_intro}"
    topic = find_topic(activity)

    if topic is None and not recognises(activity):
        # Nothing in the library covers this activity.  Say nothing rather than
        # recommend something wrong: the observer shows no notification.
        return Response(status_code=204)

    text = f"{activity} {request.style}"
    if topic:
        text = f"{text} {topic[1]}"

    probabilities = CLASSIFIER.predict_proba([bag_of_words(text, VOCAB)])[0]

    # Restrict the choice to the four classes for the requested style; the
    # classifier is left to decide the topic.  See the module docstring.
    candidates = [
        index
        for index, label in enumerate(CLASSIFIER.classes_)
        if str(label).endswith(request.style)
    ]
    best = max(candidates, key=lambda index: probabilities[index])
    tag = str(CLASSIFIER.classes_[best])

    # Renormalise over the four candidates.  The reported confidence is then
    # "how sure are we of the topic, given this style", which is the only
    # question the classifier is being asked, and it is what THRESHOLD gates.
    total = float(sum(probabilities[index] for index in candidates))
    confidence = float(probabilities[best]) / total if total else 0.0
    source = "model"

    if confidence < THRESHOLD:
        if topic is None:
            return Response(status_code=204)
        # The classifier cannot separate the topics, but the activity name
        # named one outright.  Use it.
        tag = f"{topic[0]} {request.style}"
        source = "fallback"

    return {
        "tag": tag,
        "confidence": round(confidence, 4),
        "html": pick_response(tag, request.module_name, request.style),
        "source": source,
    }
