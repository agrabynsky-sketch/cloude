"""In-process rate limiting and per-token file counting.

State lives in memory, so run a single worker process (or move these two
classes onto Redis before scaling out). nginx limit_req in front adds a
second, process-independent limit; see deploy/nginx.conf.
"""

import time
from collections import deque


class RateLimiter:
    """Sliding-window limit of `limit` events per `window` seconds per key."""

    def __init__(self, limit, window=60.0, max_keys=100_000):
        self.limit = limit
        self.window = window
        self.max_keys = max_keys
        self._events = {}

    def allow(self, key, now=None):
        now = time.monotonic() if now is None else now
        events = self._events.get(key)
        if events is None:
            if len(self._events) >= self.max_keys:
                self._evict(now)
            events = self._events[key] = deque()
        while events and events[0] <= now - self.window:
            events.popleft()
        if len(events) >= self.limit:
            return False
        events.append(now)
        return True

    def _evict(self, now):
        for key in [k for k, ev in self._events.items() if not ev or ev[-1] <= now - self.window]:
            del self._events[key]
        if len(self._events) >= self.max_keys:
            self._events.clear()


class TokenUsage:
    """Counts files accepted per token id until the token expires."""

    def __init__(self):
        self._used = {}  # token id -> (count, expires_at)

    def used(self, token_id):
        return self._used.get(token_id, (0, 0))[0]

    def try_reserve(self, token_id, max_files, expires_at, now=None):
        now = time.time() if now is None else now
        if len(self._used) > 10_000:
            self._used = {k: v for k, v in self._used.items() if v[1] > now}
        count = self.used(token_id)
        if count >= max_files:
            return False
        self._used[token_id] = (count + 1, expires_at)
        return True

    def release(self, token_id):
        count, expires_at = self._used.get(token_id, (0, 0))
        if count > 0:
            self._used[token_id] = (count - 1, expires_at)
