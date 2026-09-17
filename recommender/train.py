"""Builds the classifier that the recommender serves, and the code that serves it.

Two things live here on purpose:

  - tokenize(), so training and serving tokenise through one function. If those
    ever diverge the system keeps answering and stops being right, and nothing
    tells you.
  - forward(), the numpy forward pass app.py uses at request time. main()
    trains with scikit-learn and then asserts that this exact function
    reproduces the fitted model's predict_proba on every training vector. The
    build fails if it does not, so the served path can never drift from the
    trained one.

Training runs once, at image build time, in a stage that has scikit-learn.
The runtime image has numpy and nltk only: sklearn and scipy are 211MB that
nothing needs once the weights are on disk.

The architecture matches the source thesis: bag-of-words -> 8 -> 8 -> softmax
over 16 classes.
"""

import json
import os
import re

import numpy as np
from nltk.stem.lancaster import LancasterStemmer

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")
INTENTS_PATH = os.path.join(DATA_DIR, "intents.json")
MODEL_PATH = os.path.join(DATA_DIR, "model.npz")

_STEMMER = LancasterStemmer()

# The original used nltk.word_tokenize, which needs the punkt_tab corpus
# downloaded at build time. On this content a regex split does the same job,
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


def forward(features: np.ndarray, weights: list, biases: list) -> np.ndarray:
    """Hidden layers through ReLU, output layer through softmax.

    This is what MLPClassifier does at predict time for a multiclass problem,
    written out. main() proves the two agree before the model ships.
    """
    activations = np.atleast_2d(features)

    for weight, bias in zip(weights[:-1], biases[:-1]):
        activations = np.maximum(0.0, activations @ weight + bias)

    logits = activations @ weights[-1] + biases[-1]
    exponentials = np.exp(logits - logits.max(axis=1, keepdims=True))
    return exponentials / exponentials.sum(axis=1, keepdims=True)


def load_model(path: str = MODEL_PATH) -> dict:
    data = np.load(path, allow_pickle=False)
    depth = sum(1 for name in data.files if name.startswith("W"))

    return {
        "vocab": [str(word) for word in data["vocab"]],
        "classes": [str(label) for label in data["classes"]],
        "weights": [data[f"W{layer}"] for layer in range(depth)],
        "biases": [data[f"b{layer}"] for layer in range(depth)],
    }


def main() -> None:
    # Imported here, not at module scope: app.py imports this module for
    # tokenize() and forward(), and the runtime image has no scikit-learn.
    from sklearn.neural_network import MLPClassifier

    intents = load_intents()

    patterns, tags = [], []
    for intent in intents:
        for pattern in intent["patterns"]:
            patterns.append(pattern)
            tags.append(intent["tag"])

    # sorted(set(...)), not sorted(list(...)). The original skipped the dedupe
    # and ended up with 328 slots for 41 distinct stems.
    vocab = sorted({stem for pattern in patterns for stem in tokenize(pattern)})

    features = np.array([bag_of_words(pattern, vocab) for pattern in patterns])

    classifier = MLPClassifier(
        hidden_layer_sizes=(8, 8),
        activation="relu",
        solver="adam",
        max_iter=1000,
        # The report's appendix specifies n_epoch=1000, batch_size=8.
        # sklearn's default would be full-batch here (84 samples). Same 100%
        # fit either way; this matches the document being defended.
        batch_size=8,
        random_state=42,
    )
    classifier.fit(features, tags)

    accuracy = classifier.score(features, tags)
    print(f"patterns:   {len(patterns)}")
    print(f"vocabulary: {len(vocab)} stems")
    print(f"classes:    {len(classifier.classes_)}")
    print(f"training accuracy: {accuracy:.4f}")
    print(f"vocabulary: {' '.join(vocab)}")

    # Expected: every input vector is unique and no two classes share one, so
    # the network memorises the table exactly. See "Known limitations".
    assert accuracy == 1.0, f"expected perfect fit on the intent table, got {accuracy}"

    np.savez(
        MODEL_PATH,
        vocab=np.array(vocab),
        classes=classifier.classes_,
        **{f"W{layer}": weight for layer, weight in enumerate(classifier.coefs_)},
        **{f"b{layer}": bias for layer, bias in enumerate(classifier.intercepts_)},
    )

    # The whole reason the runtime can drop scikit-learn: read back exactly
    # what was written and prove the served forward pass reproduces the fitted
    # model. Checked on every training vector and on the demo's own inputs,
    # because those carry the alias expansion and look nothing like a training
    # row.
    model = load_model()
    demo = np.array([
        bag_of_words(f"{name} {intro} {style} {expansion}", vocab)
        for name, intro, expansion in [
            ("Arrays", "Introduction to arrays in C",
             "array index declaration accessing elements"),
            ("Control Structures", "Selection and looping in C",
             "if/else selection statement for loop while do switch"),
            ("Functions", "Defining and calling functions in C",
             "function calling defining arguments recursion storage class"),
            ("Data Types", "Basic and derived data types in C",
             "data type basic derived enumerated void"),
        ]
        for style in ("visual", "auditory", "read_write", "kinesthetic")
    ])

    for label, sample in (("training vectors", features), ("demo inputs", demo)):
        expected = classifier.predict_proba(sample)
        actual = forward(sample, model["weights"], model["biases"])
        largest = float(np.abs(expected - actual).max())
        assert np.allclose(expected, actual, rtol=0, atol=1e-12), (
            f"numpy forward pass disagrees with scikit-learn on {label}: "
            f"largest difference {largest}"
        )
        print(f"forward pass matches predict_proba on {len(sample)} {label} "
              f"(largest difference {largest:.2e})")

    assert list(model["classes"]) == list(classifier.classes_)
    assert model["vocab"] == vocab
    print(f"wrote {MODEL_PATH}")


if __name__ == "__main__":
    main()
