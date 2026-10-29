FROM python:3.12-slim

WORKDIR /app
COPY pyproject.toml README.md ./
COPY src ./src
RUN pip install --no-cache-dir . && useradd --create-home adapter && mkdir /data && chown adapter /data

USER adapter
ENV DATABASE_URL=sqlite:////data/exely_adapter.db
VOLUME /data
EXPOSE 8000

# One worker: ARI revisions come from an in-process clock.
CMD ["uvicorn", "exely_adapter.app:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers"]
