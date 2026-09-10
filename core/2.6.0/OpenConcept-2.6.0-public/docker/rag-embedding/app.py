from __future__ import annotations

import hmac
import os
from contextlib import asynccontextmanager
from threading import Lock
from typing import Any

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, ConfigDict, Field
from sentence_transformers import SentenceTransformer


MODEL_ID = os.getenv("OPENCONCEPT_BGE_M3_MODEL", "BAAI/bge-m3").strip() or "BAAI/bge-m3"
MODEL_REVISION = os.getenv("OPENCONCEPT_BGE_M3_REVISION", "").strip() or None
MODEL_DEVICE = os.getenv("OPENCONCEPT_BGE_M3_DEVICE", "cpu").strip() or "cpu"
API_KEY = os.getenv("OPENCONCEPT_BGE_M3_API_KEY", "")
BATCH_SIZE = max(1, min(256, int(os.getenv("OPENCONCEPT_BGE_M3_BATCH_SIZE", "16"))))
MAX_INPUTS = 256
MAX_CHARACTERS = 100_000

model: SentenceTransformer | None = None
model_lock = Lock()
dimensions: int | None = None


class EmbeddingRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    model: str
    input: str | list[str]
    encoding_format: str = Field(default="float", pattern="^float$")


@asynccontextmanager
async def lifespan(_: FastAPI):
    global model, dimensions
    kwargs: dict[str, Any] = {
        "device": MODEL_DEVICE,
        "cache_folder": os.getenv("HF_HOME", "/models/huggingface"),
        "trust_remote_code": False,
    }
    if MODEL_REVISION is not None:
        kwargs["revision"] = MODEL_REVISION
    model = SentenceTransformer(MODEL_ID, **kwargs)
    dimensions = int(model.get_sentence_embedding_dimension())
    yield
    model = None


app = FastAPI(title="OpenConcept BGE-M3 Embedding Service", version="1.0.0", lifespan=lifespan)


@app.get("/health")
def health() -> dict[str, Any]:
    if model is None or dimensions is None:
        raise HTTPException(status_code=503, detail="model_not_ready")
    return {
        "status": "ready",
        "model": MODEL_ID,
        "revision": MODEL_REVISION,
        "dimensions": dimensions,
        "device": MODEL_DEVICE,
    }


@app.post("/v1/embeddings")
def embeddings(request: EmbeddingRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    if API_KEY:
        expected = f"Bearer {API_KEY}"
        if authorization is None or not hmac.compare_digest(authorization, expected):
            raise HTTPException(status_code=401, detail="authentication_required")
    if request.model != MODEL_ID:
        raise HTTPException(status_code=422, detail="model_not_supported")
    inputs = [request.input] if isinstance(request.input, str) else request.input
    if not inputs or len(inputs) > MAX_INPUTS:
        raise HTTPException(status_code=422, detail="invalid_input_count")
    if any(not value.strip() or len(value) > MAX_CHARACTERS for value in inputs):
        raise HTTPException(status_code=422, detail="invalid_input")
    if model is None:
        raise HTTPException(status_code=503, detail="model_not_ready")
    with model_lock:
        vectors = model.encode(
            inputs,
            batch_size=BATCH_SIZE,
            convert_to_numpy=True,
            normalize_embeddings=True,
            show_progress_bar=False,
        )
    return {
        "object": "list",
        "model": MODEL_ID,
        "data": [
            {"object": "embedding", "index": index, "embedding": vector.tolist()}
            for index, vector in enumerate(vectors)
        ],
        "usage": {"prompt_tokens": 0, "total_tokens": 0},
    }
