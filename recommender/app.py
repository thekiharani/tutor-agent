"""WP0 placeholder. WP1 replaces this with the real classifier."""

from fastapi import FastAPI

app = FastAPI(title="tutoragent recommender")


@app.get("/health")
def health():
    return {"status": "ok", "stage": "WP0 placeholder"}
