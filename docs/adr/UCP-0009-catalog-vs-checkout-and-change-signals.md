# ADR-UCP-0009: Catalog vs checkout truth; order vs catalog change signals

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

Agents watch prices and variations (“buy when ≤ X”, “when size 44 appears”). Two different
notification channels are easy to conflate:

1. **UCP order webhooks** (this plugin, ADR-UCP-0006) — post-purchase lifecycle to the platform.
2. **Catalog / inventory change signals** — needed for TASK-06/09 watch evaluation.

Catalog responses are informative; checkout totals are binding for payment.

## Decision

1. **Catalog** (`search` / `lookup` / `product`) is for browsing and **watch triggers** only.
   Amounts and availability there are **non-binding**.
2. **Checkout** is the only **payment truth**: before complete, platforms must verify totals
   (and stock via session/messages) against the user’s confirmed mandate/limit.
3. This plugin does **not** emit catalog/price/stock push webhooks. Inventory change detection
   for watches is the platform’s job via:
   - **Polling** UCP catalog (POC default; ≥ 30–60 s locally, ≤ 5 min per TASK-06), and/or
   - **Merchant-side** WooCommerce webhooks / Demo Control triggers (Action Scheduler ≈ 60 s
     in the test merchant) — outside this plugin’s UCP order-webhook path.
4. Order tracking after purchase uses **signed UCP order webhooks** (ADR-UCP-0006); `GET /orders/{id}`
   for reconciliation only.

## Consequences

- Clear split: ADR-UCP-0006 ≠ price-drop notifications.
- “As soon as” in the test merchant means **about one minute**, not sub-second (merchant model §3).
- Agents must re-fetch catalog on evaluate and re-verify on checkout at execution (never trust
  webhook payload prices alone if they add Woo hooks later).

## Alternatives considered

- **UCP catalog change webhooks in this plugin** — deferred: not in pinned capability set for
  v0.1; polling meets POC latency.
- **Treat catalog price as payable** — rejected: breaks tax/shipping truth.

## Related

- Catalog services under `src/` (search/lookup/product), ADR-UCP-0003, ADR-UCP-0006
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§3–4, §10)
- Agent wiki TASK-06/09; merchant model §3
