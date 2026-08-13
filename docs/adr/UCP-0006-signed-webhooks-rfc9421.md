# ADR-UCP-0006: Signed order webhooks (RFC 9421 ES256)

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

After purchase (and on later lifecycle events), the platform must learn order state without
polling alone. Unsigned webhooks are forgeable. Shared HMAC secrets do not match UCP’s
discovery model of publishing verification material in the business profile. Delivery must
survive transient platform outages.

## Decision

1. The store learns the destination from the platform profile’s **order** capability
   `config.webhook_url` (fetched during negotiation).
2. Outgoing webhooks are signed with **HTTP Message Signatures (RFC 9421)** using **ES256**
   (EC P-256), with **Content-Digest** (RFC 9530) over the raw body.
3. Public keys are published as JWKs in `/.well-known/ucp` (`signing_keys`). Private keys are
   generated server-side, stored in a non-autoloaded option, or supplied via secret manager
   (`UCPWS_SIGNING_KEY_PATH` / `PEM` / `ID`); they never appear in responses.
4. Delivery: one synchronous attempt, then **Action Scheduler** retries with backoff
   `1m / 5m / 30m / 2h / 12h`. Headers include `X-Event-Type`, `Webhook-Id`,
   `Webhook-Timestamp`.
5. Platforms must verify signatures and **dedupe by `Webhook-Id`**; `GET /orders/{id}` is for
   reconciliation only.

## Consequences

- Platforms can verify authenticity without a pre-shared HMAC out of band.
- Minimal in-tree crypto (openssl) keeps the runtime dependency footprint small (one composer
  runtime dep overall).
- Key rotation is supported; verifiers must honor `kid` → JWK lookup from discovery.
- Slow or buggy platform endpoints generate retry load — operators should monitor Action
  Scheduler queues.

## Alternatives considered

- **HMAC shared secret** — rejected: poorer fit for discovery/JWK profile; secret distribution
  to every platform.
- **Unsigned webhooks** — rejected: trivial forgery.
- **Sync-only delivery** — rejected: loses events on platform downtime.
- **Heavy external signing library** — deferred: openssl + small custom signer meets the
  covered-components set we need; revisit if the signature surface grows.

## Security notes

- Private key leakage is critical; prefer secret-manager injection in production (ADR-UCP-0007).
- Reject webhooks client-side on digest/signature/`kid` mismatch.
- Do not put secrets in webhook payloads; order snapshots only.

## Related

- `src/Security/HttpSignature.php`, `SigningKeys.php`, `Jwk.php`
- `src/Orders/WebhookDispatcher.php`
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§10)
- [SECURITY.md](../../SECURITY.md)
- ADR-UCP-0005 (when `order_placed` fires)
