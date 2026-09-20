FROM python:3.14-slim AS trainer

WORKDIR /srv
COPY requirements-train.txt .
RUN pip install --no-cache-dir -r requirements-train.txt

COPY train.py .
COPY data ./data

# Also a gate: the build fails unless the numpy forward pass reproduces
# scikit-learn's predict_proba, which is what makes it safe for the runtime
# stage below to ship neither scikit-learn nor scipy (~211MB).
RUN python train.py

# Not built by `docker build .`, which stops at the last stage. BuildKit skips
# it unless it is asked for by name:  docker build --target tests .
# `make test` does that. It fails the build on a failing test, the same way the
# trainer stage fails it on a model that does not reproduce.
FROM python:3.14-slim AS tests

WORKDIR /srv
COPY requirements.txt requirements-test.txt ./
RUN pip install --no-cache-dir -r requirements.txt -r requirements-test.txt

COPY train.py app.py pytest.ini test_recommender.py ./
COPY data ./data
COPY --from=trainer /srv/data/model.npz ./data/model.npz

RUN python -m pytest test_recommender.py -q

FROM python:3.14-slim

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY train.py app.py ./
COPY data ./data
COPY --from=trainer /srv/data/model.npz ./data/model.npz

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
