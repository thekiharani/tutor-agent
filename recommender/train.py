"""Trains the model at image build time, and holds the code that serves it.

app.py imports tokenize() and forward() from here, so training and serving
cannot drift apart. main() proves it: after fitting, it asserts forward()
reproduces scikit-learn's predict_proba, and the build fails if it does not.
That is what lets the runtime image ship without scikit-learn or scipy.

Architecture per the project report: bag-of-words -> 8 -> 8 -> softmax, 16 classes.
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

# A regex split rather than nltk.word_tokenize, which would need the punkt_tab
# corpus downloaded at build time. Keeps "if/else" and "read_write" whole -
# both are real vocabulary entries.
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
    """Hidden layers through ReLU, output through softmax: what MLPClassifier
    does at predict time, written out."""
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
    # Imported here, not at module scope: the runtime image has no scikit-learn.
    from sklearn.neural_network import MLPClassifier

    intents = load_intents()

    patterns, tags = [], []
    for intent in intents:
        for pattern in intent["patterns"]:
            patterns.append(pattern)
            tags.append(intent["tag"])

    # sorted(set(...)): the original skipped the dedupe and got 328 slots for
    # 41 distinct stems.
    vocab = sorted({stem for pattern in patterns for stem in tokenize(pattern)})

    features = np.array([bag_of_words(pattern, vocab) for pattern in patterns])

    classifier = MLPClassifier(
        hidden_layer_sizes=(8, 8),
        activation="relu",
        solver="adam",
        max_iter=1000,
        # The report's appendix: n_epoch=1000, batch_size=8. sklearn would
        # otherwise go full-batch on 84 samples.
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

    # Every input vector is unique with no class collisions, so the network
    # memorises the table exactly. See "Known limitations" in the README.
    assert accuracy == 1.0, f"expected perfect fit on the intent table, got {accuracy}"

    np.savez(
        MODEL_PATH,
        vocab=np.array(vocab),
        classes=classifier.classes_,
        **{f"W{layer}": weight for layer, weight in enumerate(classifier.coefs_)},
        **{f"b{layer}": bias for layer, bias in enumerate(classifier.intercepts_)},
    )

    # Read back exactly what was written and prove the served forward pass
    # reproduces the fitted model. The demo inputs are checked too: they carry
    # the alias expansion and look nothing like a training row.
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
