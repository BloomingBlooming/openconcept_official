FROM python:3.12-slim-bookworm@sha256:4427763a1ba36f5aa8f656a03e5d00f3b8d61f5dd950c73df6c14f8c7640f8ab

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /service
COPY requirements.txt ./requirements.txt
RUN pip install --index-url https://download.pytorch.org/whl/cpu torch==2.13.0+cpu \
    && pip install --requirement requirements.txt
COPY app.py ./app.py

RUN useradd --create-home --uid 10001 rag \
    && mkdir -p /models \
    && chown -R rag:rag /service /models

USER rag
EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--no-access-log"]
