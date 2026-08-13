# ADR-UCP-0003: Checkout backed by WooCommerce draft orders (POC cart model)

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1); POC cart posture aligned with agent wiki 2026-08

## Context

Agentic checkout must produce real merchant orders with correct prices, taxes, shipping, and
coupons. Re-implementing WooCommerce’s totals engine in the adapter would be wrong and
unmaintainable.

The agent requirements (CART-01…03) ask for a prompt-managed cart that is the **store’s own
cart** across chat and website. This plugin does not implement `dev.ucp.shopping.cart`. The
POC deliberately uses one controlled merchant and must ship HNP purchase without waiting for
a full shared-session cart integration.

## Decision

1. Each UCP checkout session is backed by a WooCommerce **draft order** (`checkout-draft`,
   `created_via=ucp`), following the Store API model.
2. The adapter **never recalculates money** — it feeds line items, coupons, addresses, and
   selected shipping into WC and reads totals back via CRUD (HPOS-compatible).
3. **Custom tables** hold only protocol state: sessions, idempotency records, platform
   registry.
4. **POC cart model (resolves CART vs plugin):**
   - **CART-01 / CART-02** are satisfied by driving the **checkout session** from chat
     (add/remove/quantity/variant via create/PUT; itemised totals including shipping options
     from WC).
   - **CART-03** (“one cart across chat and website”) is a **proposed POC gap** (needs team
     review): chat does **not** mutate the shopper’s classic WooCommerce session cart /
     Store API cart. Storefront and chat may diverge until a later cart-capability or
     session-bridge landing.
   - Rationale: Teams POC scope is one controlled merchant and a true HNP BLIK-R transaction
     first; shared-cart engineering is months-class work relative to Empik-scale integrations.
5. Stock around charge: reserve on complete path; release on decline/escalation; agents must
   still re-verify price/stock at trigger time (merchant model §6).

## Consequences

- Merchant of record stays WooCommerce after `payment_complete()`.
- Session TTL (~6 h) → scheduled tasks **create checkout at trigger time**, never days ahead.
- Product/UX docs and the agent must not claim “same cart as the website” for the POC.
- Post-POC options: implement `dev.ucp.shopping.cart`, or bridge UCP line items into the
  logged-in customer’s WC session cart (separate ADR).

## Alternatives considered

- **Custom totals engine** — rejected (CONTRIBUTING).
- **Shadow cart in custom tables** — rejected: worse than draft orders for money truth.
- **Block POC until CART-03** — rejected: blocks the media/HNP goal for limited gain.
- **Scrape/storefront HTML or raw WC REST from the agent** — rejected (SYS-02 / API etiquette).

## Related

- `src/Checkout/DraftOrders.php`, `src/Checkout/CheckoutService.php`
- `src/Storage/Sessions.php`, `src/Bootstrap/Installer.php`
- [docs/requirements.md](../requirements.md), [docs/agent-integration-notes.md](../agent-integration-notes.md) (§4–6)
- ADR-UCP-0002, ADR-UCP-0008, ADR-UCP-0009, ADR-UCP-0010
- Agent wiki CART-*; ADR-AGT-0006
