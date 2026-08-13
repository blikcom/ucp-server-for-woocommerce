# ADR-UCP-0004: Registry auth and profile-bound API keys

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

UCP permits permissionless discovery, but merchants must control who can create checkouts and
charge. Every request carries a platform identity (`UCP-Agent` profile URL). The official
conformance suite needs an `open` posture. Production POC and real shops need a locked-down
default.

## Decision

1. Defaults: **`auth_mode=registry`**, **`negotiation_mode=strict`**.
2. In registry mode, every request requires an API key (`X-API-Key` or `Authorization: Bearer`)
   issued in the platform registry. The key is **bound 1:1 to a `profile_url`**. A valid key
   with a different `UCP-Agent` profile → **403** (`profile_not_trusted`).
3. Keys are stored as **SHA-256 hashes** only; plaintext shown once at creation
   (`ucpws_pk_` + 48 hex chars).
4. **`auth_mode=open`** and **`negotiation_mode=lenient`** exist for the conformance suite and
   demos — they must stay off in production.
5. Outbound platform-profile fetch is hardened (HTTPS-only, no redirects, size/time limits,
   private hosts blocked) with a discovery budget for unrecognized platforms and per-client
   rate limiting.

## Consequences

- Merchant controls the allow-list; multi-platform genericity is “register another profile +
  key”, not a built-in aggregator.
- Open mode widens the attack surface (anonymous complete paths if misconfigured) — operator
  docs and defaults push registry/strict.
- Profile URL rotation requires issuing a new key binding; casual URL changes break clients.

## Alternatives considered

- **Always open** — rejected for production merchants.
- **Single global shared key** — rejected: no per-platform revocation or identity binding.
- **OAuth2 / mTLS** — deferred: heavier ops for Woo shops; API keys match current UCP agent
  practice and the registry model.
- **Strict-only negotiation** — rejected: suite and some reference servers need lenient
  profile-fetch failure behavior.

## Security notes

- Key material never logged; hash-only at rest.
- SSRF controls on profile fetch; never enable `allow_private_hosts` /
  `allow_insecure_profiles` / `dev_url_rewrites` in production (see ADR-UCP-0007).
- Registry binding is the shop-side identity guardrail; spending limits and task matching
  remain the **platform (agent)** responsibility — see ADR-AGT-0002 (policy gate) and the
  Q&A table in this folder’s README.

## Related

- `src/Http/Auth.php`, `src/Storage/Platforms.php`, `src/Support/Config.php`
- `src/Negotiation/ProfileFetcher.php`, `src/Http/RateLimiter.php`
- [SECURITY.md](../../SECURITY.md)
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§1–2)
- ADR-UCP-0007 (gated escape hatches)
