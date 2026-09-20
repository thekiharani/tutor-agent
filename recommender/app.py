"""POST /recommend with an activity name, its intro and a learning style;
returns one HTML link chosen for that style.

Three things make this work that did not in the 2022 original: the style token
reaches the classifier at all, topic aliases cover words the vocabulary lacks
(there is no stem for "control"), and the softmax is restricted to the stored
style, because the aliases otherwise swamp the single modality stem.

A fourth keeps them honest: the keyword table decides when it matches, because a
substring hit is evidence and the softmax is an estimate. The README has the
full account, with the measured numbers.

The library covers the six topics of the C language and nothing else, because
those are the topics intents.json holds. An activity it does not cover gets a
204 rather than the nearest of twenty-four tags.
"""

import json
import os
import zlib
from typing import Literal

from fastapi import FastAPI, Response
from pydantic import BaseModel

from train import bag_of_words, forward, load_intents, load_model

THRESHOLD = float(os.environ.get("RECOMMENDER_THRESHOLD", "0.45"))

# intents.json uses plural topics, the natural keys are singular; one table
# carries both so the fallback cannot build a tag that does not exist.
TOPICS: dict[str, tuple[str, str]] = {
    # The six topics of the C language, and the only topics intents.json holds.
    # Keys are matched as substrings of the activity, longest first, so
    # "introduction to c" has to beat nothing here and "data type" must not be
    # found inside another key's activity text.
    "array": ("arrays", "array index declaration accessing elements"),
    "control": ("controls", "if/else selection statement for loop while do switch"),
    "function": ("functions", "function calling defining arguments recursion storage class"),
    "data type": ("data types", "data type basic derived enumerated void"),
    "introduction to c": (
        "intro c",
        "program compile main header printf statement syntax",
    ),
    "operator": (
        "operators",
        "operator arithmetic relational logical bitwise assignment precedence",
    ),
}

_MODEL = load_model()
VOCAB = _MODEL["vocab"]
LABELS = _MODEL["classes"]
WEIGHTS = _MODEL["weights"]
BIASES = _MODEL["biases"]
RESPONSES = {intent["tag"]: intent["responses"] for intent in load_intents()}

app = FastAPI(title="tutoragent recommender")


class RecommendRequest(BaseModel):
    module_name: str
    module_intro: str = ""
    style: Literal["visual", "auditory", "read_write", "kinesthetic"]


def find_topic(text: str) -> tuple[str, str] | None:
    """Longest match wins, so a more specific topic beats a more general one that
    happens to be a substring of the same activity."""
    lowered = text.lower()
    best: tuple[int, tuple[str, str]] | None = None
    for key, topic in TOPICS.items():
        if key in lowered and (best is None or len(key) > best[0]):
            best = (len(key), topic)
    return best[1] if best else None


def pick_response(tag: str, module_name: str, style: str) -> str:
    """crc32, not Python's string hash, which is salted per process and would
    change the link after every restart."""
    responses = RESPONSES[tag]
    return responses[zlib.crc32(f"{module_name}{style}".encode()) % len(responses)]


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "classes": len(LABELS), "vocab": len(VOCAB)}


# response_model=None: FastAPI cannot model the dict-or-204 union.
@app.post("/recommend", response_model=None)
def recommend(request: RecommendRequest) -> Response | dict:
    activity = f"{request.module_name} {request.module_intro}"
    topic = find_topic(activity)

    if topic is None:
        # The library covers the topics in TOPICS and nothing else. An activity
        # about pointers gets silence, not the nearest of twenty-four tags.
        return Response(status_code=204)

    text = f"{activity} {request.style} {topic[1]}"

    probabilities = forward(bag_of_words(text, VOCAB), WEIGHTS, BIASES)[0]

    # The classifier decides only the topic; see the module docstring.
    candidates = [
        index for index, label in enumerate(LABELS) if label.endswith(request.style)
    ]
    best = max(candidates, key=lambda index: probabilities[index])
    tag = LABELS[best]

    # Renormalised, so confidence means "how sure of the topic, given the style".
    total = float(sum(probabilities[index] for index in candidates))
    confidence = float(probabilities[best]) / total if total else 0.0
    source = "model"

    # A keyword hit is evidence; the softmax is an estimate. Where they disagree
    # the keyword wins, because a substring of the activity text is a fact about
    # the activity and the softmax is a guess about it. The README records how
    # often each is right on its own.
    expected = f"{topic[0]} {request.style}"
    if tag != expected:
        tag = expected
        source = "keyword"
    elif confidence < THRESHOLD:
        source = "fallback"

    return {
        "tag": tag,
        "confidence": round(confidence, 4),
        "html": pick_response(tag, request.module_name, request.style),
        "source": source,
    }
