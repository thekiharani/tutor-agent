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

FROM python:3.14-slim

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY train.py app.py ./
COPY data ./data
COPY --from=trainer /srv/data/model.npz ./data/model.npz

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
