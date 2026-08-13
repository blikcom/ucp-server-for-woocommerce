# ADR-UCP-0008: Idempotency for checkout mutations

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

Human-not-present execution retries on timeouts, scheduler crashes, and concurrent
poll+webhook triggers (agent wiki TASK-08). Without durable idempotency, platforms double-order
or double-charge. UCP requires idempotency records retained ≥ 24 h.

## Decision

1. Idempotency store: custom table; scope `{operation}:{resource}`; payload fingerprint =
   SHA-256 of canonical key-sorted JSON; same key + same payload → **replay stored response**;
   same key + different payload → **409**; TTL default 48 h (floor 24 h).
2. **REST:** when `Idempotency-Key` is present, enforce the above on create/update/complete/cancel.
3. **MCP:** `meta["idempotency-key"]` is **required** for `complete_checkout` and
   `cancel_checkout`.
4. Completed sessions cannot charge twice (terminal state + idempotency). Handlers should also
   pass WC order id / UCP session id to the PSP as its idempotency key (belt-and-braces).
5. **Platform duty (not enforced here):** persist the attempt key with each scheduled-task
   attempt and reuse it on retry (agent TRUST / TASK-08).

## Consequences

- Safe crash-retry for well-behaved clients.
- REST clients that omit the header on complete can still double-submit under races —
  **POC agent must always send keys** (stricter than the plugin’s REST minimum).
- **Future opportunity:** require the header on REST complete/cancel (minor breaking change).

## Alternatives considered

- **No idempotency store** — rejected: unsafe for HNP.
- **Require key on all REST mutations from day one** — deferred for v0.1 ergonomics /
  conformance probes; POC agent policy closes the gap.
- **Only PSP-level idempotency** — rejected: still need protocol-level replay for create/update.

## Security notes

Idempotency is a shop-side half of exactly-once. The other half is the agent policy gate
(task not already executed, distributed lock). Both are required for TASK-08.

## Related

- `src/Storage/IdempotencyStore.php`, `src/Http/RestServer.php`, `src/Mcp/McpServer.php`
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§9)
- ADR-UCP-0002, ADR-UCP-0005
- Agent ADR: policy gate + task attempt keys
