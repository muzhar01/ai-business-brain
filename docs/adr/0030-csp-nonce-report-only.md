# 0030 CSP nonce + report-only strict policy
Status: accepted. Enforced CSP still allows 'unsafe-inline' because ~25 inline on* handlers exist. Enforcing a nonce would break them (nonce disables unsafe-inline for scripts).
Decision: per-request nonce on every inline <script>; ship a strict nonce policy as Content-Security-Policy-Report-Only so violations (the remaining handlers) are visible in browser consoles. Rejected: unsafe-hashes (does not cover dynamic attributes), flipping enforcement now (breaks UI).
Next slices: replace on* handlers with data-attributes + one delegated listener, then enforce.
Security: nonce is 128-bit random per request, never cached (authenticated pages are no-store).
