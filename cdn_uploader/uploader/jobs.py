"""Background processing for uploads that take long (videos).

The upload request returns 202 with a job id as soon as the body is on
disk; the UI polls GET /api/v1/jobs/<id> for progress and the final link.
State is in memory, like the rest of the per-process bookkeeping.
"""

import logging
import secrets
import threading
import time
from concurrent.futures import ThreadPoolExecutor

from .validators import ValidationError

log = logging.getLogger("uploader")


class Job:
    def __init__(self, owner):
        self.id = secrets.token_urlsafe(18)
        self.owner = owner
        self.status = "queued"        # queued -> processing -> done | error
        self.progress = 0.0
        self.result = None
        self.error = None             # (code, message)
        self.finished_at = None

    def set_progress(self, value):
        self.progress = max(self.progress, min(float(value), 1.0))

    def public(self):
        body = {"job": self.id, "status": self.status, "progress": round(self.progress, 3)}
        if self.status == "done":
            body["file"] = self.result
        elif self.status == "error":
            body["error"], body["message"] = self.error
        return body


class JobQueue:
    def __init__(self, workers, max_pending, keep_seconds=3600):
        self._pool = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="upload-job")
        self._jobs = {}
        self._lock = threading.Lock()
        self.max_pending = max_pending
        self.keep_seconds = keep_seconds

    def submit(self, owner, fn):
        """Run fn(job) in the background; None if too many jobs are waiting."""
        with self._lock:
            now = time.time()
            for job_id in [j.id for j in self._jobs.values()
                           if j.finished_at and now - j.finished_at > self.keep_seconds]:
                del self._jobs[job_id]
            pending = sum(1 for j in self._jobs.values() if j.status in ("queued", "processing"))
            if pending >= self.max_pending:
                return None
            job = Job(owner)
            self._jobs[job.id] = job
        self._pool.submit(self._run, job, fn)
        return job

    def _run(self, job, fn):
        job.status = "processing"
        try:
            job.result = fn(job)
            job.progress = 1.0
            job.status = "done"
        except ValidationError as exc:
            job.error = (exc.code, exc.message)
            job.status = "error"
        except Exception:
            log.exception("background job %s failed", job.id)
            job.error = ("internal_error", "Processing failed")
            job.status = "error"
        finally:
            job.finished_at = time.time()

    def get(self, job_id, owner):
        job = self._jobs.get(job_id)
        return job if job is not None and job.owner == owner else None
