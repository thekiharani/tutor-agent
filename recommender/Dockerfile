# Fits the model; nothing ships from here except data/model.npz.
FROM python:3.14-slim AS trainer

WORKDIR /srv
COPY requirements-train.txt .
RUN pip install --no-cache-dir -r requirements-train.txt

COPY train.py .
COPY data ./data

# Fails the build unless the numpy forward pass reproduces predict_proba,
# which is what makes it safe to leave scikit-learn behind.
RUN python train.py

# Runtime: no scikit-learn, no scipy. Saves about 211MB.
FROM python:3.14-slim

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY train.py app.py ./
COPY data ./data
COPY --from=trainer /srv/data/model.npz ./data/model.npz

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
