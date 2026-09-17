# ---------------------------------------------------------------------------
# Trainer stage. Fits the model with scikit-learn and writes the weights.
# Nothing from this stage ships except data/model.npz.
# ---------------------------------------------------------------------------
FROM python:3.14-slim AS trainer

WORKDIR /srv
COPY requirements-train.txt .
RUN pip install --no-cache-dir -r requirements-train.txt

COPY train.py .
COPY data ./data

# Trains, then asserts the numpy forward pass reproduces predict_proba on
# every training vector and on the demo's own inputs. The build fails on any
# disagreement, which is what makes it safe to leave scikit-learn behind.
RUN python train.py

# ---------------------------------------------------------------------------
# Runtime. numpy and nltk, no scikit-learn, no scipy.
# ---------------------------------------------------------------------------
FROM python:3.14-slim

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY train.py app.py ./
COPY data ./data
COPY --from=trainer /srv/data/model.npz ./data/model.npz

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
