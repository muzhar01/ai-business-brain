# 0020 Architecture tests + SSRF guard for tenant-chosen provider URLs
Status: accepted.
Context: ArchitectureTest was written to enforce conventions deterministically (no vendor LLM SDKs, Http only via UrlGuard outside fixed vendor hosts, jobs tenant-aware, tenant tables use BelongsToWorkspace with an explicit allowlist). Writing it exposed a real gap: a workspace's custom provider base_url went to Driver::http() via raw Http, so a tenant could point it at 169.254.169.254 or internal hosts.
Decision: validate base_url with UrlGuard::assertPublic on save; LlmManager flags workspace-supplied base_urls (guard_url) and Driver::http() uses UrlGuard::http (re-checks DNS and each redirect hop). Platform defaults (e.g. local Ollama) are untouched; BRAIN_ALLOW_PRIVATE_URLS still opts out for self-hosters.
Rejected: blocking all private URLs in Driver (breaks self-hosted Ollama defaults); allowlist of vendor hosts (breaks OpenAI-compatible gateways).
