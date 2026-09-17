"""Builds the classifier that the recommender serves.

Runs once, at image build time.  app.py imports tokenize() from this module so
that training and serving tokenise identically: if the two ever diverge the
system keeps answering but stops being right, and nothing tells you.

The architecture matches the source thesis: bag-of-words -> 8 -> 8 -> softmax
over 16 classes.
"""

import json
import os
import re

import joblib
import numpy as np
from nltk.stem.lancaster import LancasterStemmer
from sklearn.neural_network import MLPClassifier

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")
INTENTS_PATH = os.path.join(DATA_DIR, "intents.json")
MODEL_PATH = os.path.join(DATA_DIR, "model.joblib")

_STEMMER = LancasterStemmer()

# The original used nltk.word_tokenize, which needs the punkt_tab corpus
# downloaded at build time.  On this content a regex split does the same job,
# needs no network, and keeps "if/else" and "read_write" as single tokens -
# both of which are real vocabulary entries.
_WORD_RE = re.compile(r"[a-z_/]+")


def tokenize(text: str) -> list[str]:
    """Lowercase, split into words, Lancaster-stem. The only tokeniser."""
    return [_STEMMER.stem(word) for word in _WORD_RE.findall(text.lower())]


def load_intents() -> list[dict]:
    with open(INTENTS_PATH, encoding="utf-8") as handle:
        return json.load(handle)["intents"]


def bag_of_words(text: str, vocab: list[str]) -> np.ndarray:
    stems = set(tokenize(text))
    return np.array([1.0 if word in stems else 0.0 for word in vocab])


def main() -> None:
    intents = load_intents()

    patterns, tags = [], []
    for intent in intents:
        for pattern in intent["patterns"]:
            patterns.append(pattern)
            tags.append(intent["tag"])

    # sorted(set(...)), not sorted(list(...)).  The original skipped the dedupe
    # and ended up with 328 slots for 41 distinct stems.
    vocab = sorted({stem for pattern in patterns for stem in tokenize(pattern)})
    labels = sorted(set(tags))

    features = np.array([bag_of_words(pattern, vocab) for pattern in patterns])

    classifier = MLPClassifier(
        hidden_layer_sizes=(8, 8),
        activation="relu",
        solver="adam",
        max_iter=1000,
        random_state=42,
    )
    classifier.fit(features, tags)

    accuracy = classifier.score(features, tags)
    print(f"patterns:  {len(patterns)}")
    print(f"vocabulary: {len(vocab)} stems")
    print(f"classes:   {len(labels)}")
    print(f"training accuracy: {accuracy:.4f}")
    print(f"vocabulary: {' '.join(vocab)}")

    # Expected: every input vector is unique and no two classes share one, so
    # the network memorises the table exactly.  See "Known limitations".
    assert accuracy == 1.0, f"expected perfect fit on the intent table, got {accuracy}"

    joblib.dump({"clf": classifier, "vocab": vocab, "labels": labels}, MODEL_PATH)
    print(f"wrote {MODEL_PATH}")


if __name__ == "__main__":
    main()
