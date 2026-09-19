"""POST /recommend with an activity name, its intro and a learning style;
returns one HTML link chosen for that style.

Three things make this work that did not in the 2022 original: the style token
reaches the classifier at all, topic aliases cover words the vocabulary lacks
(there is no stem for "control"), and the softmax is restricted to the stored
style, because the aliases otherwise swamp the single modality stem.

A fourth was needed to carry it from four topics to thirty-eight. The keyword
table decides when it matches, because a substring hit is evidence and the
softmax is an estimate, and an estimate from a network with 152 classes and
2,984 parameters is confidently wrong often enough to matter. Measured over the
152 seeded activity-and-style combinations: classifier alone 145, keyword table
alone 152, the two together 152. The README has the full account.
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
# carries both so the fallback cannot build a tag that does not exist. Keys are
# matched as substrings of the activity, longest first: "python function" has to
# beat "function", and "hash table" has to beat "array".
TOPICS: dict[str, tuple[str, str]] = {
    # The four supplied topics.
    "array": ("arrays", "array index declaration accessing elements"),
    "control": ("controls", "if/else selection statement for loop while do switch"),
    "function": ("functions", "function calling defining arguments recursion storage class"),
    "data type": ("data types", "data type basic derived enumerated void"),
    # Introduction to Programming in C.
    "introduction to c": (
        "intro c",
        "program compile main header printf statement syntax",
    ),
    "operator": (
        "operators",
        "operator arithmetic relational logical bitwise assignment precedence",
    ),
    # Data Structures.
    "linked list": (
        "linked lists",
        "linked list node pointer head traversal insert delete",
    ),
    "stack": ("stacks", "stack queue push pop enqueue dequeue lifo fifo"),
    "tree": ("trees", "tree binary node root leaf traversal inorder subtree"),
    "hash table": ("hash tables", "hash table bucket collision chaining key lookup"),
    # Algorithms and Complexity.
    "sorting": (
        "sorting",
        "sorting sort bubble insertion merge quicksort compare swap",
    ),
    "searching": ("searching", "searching search linear binary sorted midpoint lookup"),
    "recursion": (
        "recursion",
        "recursion recursive base case call stack factorial fibonacci",
    ),
    "complexity": (
        "complexity",
        "complexity big notation asymptotic growth worst case runtime",
    ),
    # Object-Oriented Programming.
    "classes and object": (
        "classes",
        "class object instance attribute method constructor",
    ),
    "inheritance": (
        "inheritance",
        "inheritance subclass superclass extend parent child override",
    ),
    "polymorphism": (
        "polymorphism",
        "polymorphism overriding overloading dynamic dispatch interface",
    ),
    "encapsulation": (
        "encapsulation",
        "encapsulation private public getter setter hiding access",
    ),
    # Databases and SQL.
    "relational model": (
        "relational model",
        "relational model relation row column primary key schema",
    ),
    "sql quer": (
        "sql queries",
        "sql query select from where order group filter statement",
    ),
    "join": ("joins", "join inner outer left right combine matching foreign key"),
    "normalisation": (
        "normalisation",
        "normalisation normal form redundancy dependency decompose anomaly",
    ),
    # Operating Systems.
    "process": (
        "processes",
        "process scheduling context switch state ready running cpu",
    ),
    "thread": (
        "threads",
        "thread concurrency lock mutex race condition parallel synchronisation",
    ),
    "memory": (
        "memory",
        "memory virtual paging page frame address allocation segmentation",
    ),
    # Computer Networks.
    "osi model": (
        "osi model",
        "osi layer model physical transport session presentation encapsulation",
    ),
    "tcp": ("tcp ip", "tcp ip packet address routing handshake reliable datagram"),
    "http": (
        "http dns",
        "http dns request response header method status domain resolve",
    ),
    # Web Development.
    "html": ("html", "html element tag attribute document markup heading nesting"),
    "css": ("css", "css selector property rule cascade box model layout flexbox"),
    "javascript": (
        "javascript",
        "javascript variable function event dom script browser interactive",
    ),
    "rest api": (
        "rest apis",
        "rest api endpoint resource json client server method payload",
    ),
    # Software Engineering Practice.
    "version control": (
        "version control",
        "version control commit branch merge repository history clone",
    ),
    "testing": (
        "testing",
        "testing test unit assertion coverage regression fixture suite",
    ),
    "design pattern": (
        "design patterns",
        "design pattern singleton factory observer strategy reusable solution",
    ),
    # Python Programming.
    "python basic": (
        "python basics",
        "python basic variable indentation print interpreter script type",
    ),
    "dictionar": (
        "dictionaries",
        "dictionary list key value append index mapping collection mutable",
    ),
    "python function": (
        "python functions",
        "python function def parameter return argument default keyword scope",
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
        # about pointers gets silence, not the nearest of a hundred and fifty
        # two tags.
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

    # A keyword hit is evidence; the softmax is an estimate. At four topics the
    # two rarely disagreed and the estimate was left to stand unless it was
    # unconfident. At thirty-eight they disagree on the four supplied topics,
    # whose patterns are frozen, and the estimate is confidently wrong when it
    # does. Measured over the 152 seeded combinations: classifier alone 145,
    # keyword alone 152, the two together 152.
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
