# 0016 — Widget offline / poor-network resilience

Status: accepted (2026-10-30)

## Options
1. Keep single fetch with generic error. Rejected: message lost UX, hangs on stalled networks.
2. Service worker / IndexedDB queue: heavy, needs host-origin install. Deferred.
3. In-script: 20 s AbortController timeout, one automatic retry on network error/5xx, accessible Retry button, wait for `online` event when navigator.onLine is false. **Chosen**.

## Security
No new endpoint; messages are retried only in memory; strings set with textContent. Chat endpoint already throttled server-side, so retries (max 1 automatic) add negligible load. Retrying a POST may duplicate a message if the first reached the server; accepted trade-off.
