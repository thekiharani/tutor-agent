FROM python:3.14-slim

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY train.py app.py ./
COPY data ./data

# Train at build time, so the container starts instantly and never trains on a
# page view.  model.joblib is a build artifact and is gitignored.
RUN python train.py

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
