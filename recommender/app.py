"""VARK-aware recommender.

POST /recommend with an activity name, its intro and a learning style; returns
one HTML link chosen for that style.

Three things make this work that did not work in the 2022 original:

1. The style token reaches the classifier. Every training pattern embeds its
   modality, so that stem is what separates "arrays visual" from "arrays
   auditory". The original hardcoded the style to 0, so it never arrived.
2. Topic alias expansion, because the vocabulary has if/else, loop, swic and
   whil but no stem for "control".
3. The softmax is restricted to the student's stored style, because expansion
   alone swamps the single modality stem. Measured over 12 activity variants
   x 4 styles: expansion alone is wrong 5/48, the restriction alone 10/48,
   together 0/48.

Inference is a numpy forward pass over weights trained at build time; train.py
asserts it reproduces scikit-learn's predict_proba before they ship.
"""

import json
import os
import zlib
from typing import Literal

from fastapi import FastAPI, Response
from pydantic import BaseModel

from train import bag_of_words, forward, load_intents, load_model, tokenize

THRESHOLD = float(os.environ.get("RECOMMENDER_THRESHOLD", "0.45"))

# Alias key -> (tag topic, expansion text). intents.json uses plural topics and
# the natural keys are singular, so one table carries both and the fallback can
# never build a tag that does not exist.
TOPICS: dict[str, tuple[str, str]] = {
    "array": ("arrays", "array index declaration accessing elements"),
    "control": ("controls", "if/else selection statement for loop while do switch"),
    "function": ("functions", "function calling defining arguments recursion storage class"),
    "data type": ("data types", "data type basic derived enumerated void"),
}

# Stems that say nothing about the topic. What is left marks an activity the
# library actually covers.
_MODALITY_STEMS = {"vis", "audit", "read_write", "kinesthet"}
_FUNCTION_STEMS = {"an", "and", "in", "is", "of", "or", "the", "what"}

_MODEL = load_model()
VOCAB = _MODEL["vocab"]
LABELS = _MODEL["classes"]
WEIGHTS = _MODEL["weights"]
BIASES = _MODEL["biases"]
RESPONSES = {intent["tag"]: intent["responses"] for intent in load_intents()}
TOPIC_STEMS = set(VOCAB) - _MODALITY_STEMS - _FUNCTION_STEMS

app = FastAPI(title="tutoragent recommender")


class RecommendRequest(BaseModel):
    module_name: str
    module_intro: str = ""
    style: Literal["visual", "auditory", "read_write", "kinesthetic"]


def find_topic(text: str) -> tuple[str, str] | None:
    """First match wins, in declaration order: deterministic, and an intro that
    mentions two topics does not blur into both."""
    lowered = text.lower()
    for key, topic in TOPICS.items():
        if key in lowered:
            return topic
    return None


def recognises(text: str) -> bool:
    """True if anything in the activity text is in the vocabulary.

    Without this, "Pointers" still returns the best of sixteen and a student
    gets data-type material for a lecture on pointers.
    """
    return bool(TOPIC_STEMS.intersection(tokenize(text)))


def pick_response(tag: str, module_name: str, style: str) -> str:
    """Deterministic choice. crc32, not Python's built-in string hash, which is
    salted per process and would change the link after every restart."""
    responses = RESPONSES[tag]
    return responses[zlib.crc32(f"{module_name}{style}".encode()) % len(responses)]


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "classes": len(LABELS), "vocab": len(VOCAB)}


# response_model=None: FastAPI cannot build one response model from the
# dict-or-204-Response union.
@app.post("/recommend", response_model=None)
def recommend(request: RecommendRequest) -> Response | dict:
    activity = f"{request.module_name} {request.module_intro}"
    topic = find_topic(activity)

    if topic is None and not recognises(activity):
        # Nothing covers this activity: say nothing rather than guess.
        return Response(status_code=204)

    text = f"{activity} {request.style}"
    if topic:
        text = f"{text} {topic[1]}"

    probabilities = forward(bag_of_words(text, VOCAB), WEIGHTS, BIASES)[0]

    # Restrict to the four classes for the requested style; the classifier
    # decides only the topic. See the module docstring.
    candidates = [
        index for index, label in enumerate(LABELS) if label.endswith(request.style)
    ]
    best = max(candidates, key=lambda index: probabilities[index])
    tag = LABELS[best]

    # Renormalise over the four candidates: confidence then means "how sure of
    # the topic, given this style", which is what THRESHOLD gates.
    total = float(sum(probabilities[index] for index in candidates))
    confidence = float(probabilities[best]) / total if total else 0.0
    source = "model"

    if confidence < THRESHOLD:
        if topic is None:
            return Response(status_code=204)
        # The classifier cannot separate the topics, but the name gave one.
        tag = f"{topic[0]} {request.style}"
        source = "fallback"

    return {
        "tag": tag,
        "confidence": round(confidence, 4),
        "html": pick_response(tag, request.module_name, request.style),
        "source": source,
    }
