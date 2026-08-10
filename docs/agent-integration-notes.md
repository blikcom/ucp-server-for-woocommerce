# Building an AI shopping agent against this UCP adapter — 10 things to get right

Audience: the team/model building the AI agent (a UCP **platform**) that browses inventory,
discusses it in chat, schedules conditional purchases ("buy when the price drops to X",
"buy when size 44 appears") and pays human-not-present via BLIK-R/PayU. The store side is this
adapter, speaking **UCP release 2026-04-08** — pinned; requests declaring a newer version are
rejected with HTTP 422.

## 1. The agent is a UCP *platform* — it must have an identity document

Every single request must carry the agent's profile URL: HTTP header
`UCP-Agent: profile="https://agent.example/profile.json"` (REST), or
`arguments.meta["ucp-agent"].profile` (MCP). That URL must serve a valid UCP platform profile —
`ucp.version: "2026-04-08"`, the capabilities the agent supports, and crucially
`dev.ucp.shopping.order` with `config.webhook_url` — because **that profile is how the store
learns where to send order webhooks**. Host it on stable HTTPS, no redirects,
`Cache-Control: public, max-age >= 60`. The store fetches and caches it (60 s floor), so profile
changes are not instant.

## 2. Authentication: an API key bound to that exact profile

In production the store runs in registry mode: the merchant issues an API key that is bound to
one profile URL. Send it as `X-API-Key` (or `Authorization: Bearer`). A valid key with a
*different* `UCP-Agent` profile is rejected (403) — key and profile are one identity, don't mix
environments or rotate profile URLs casually. Missing/unknown key → 401.

## 3. Start every integration from discovery, not from hardcoded URLs

`GET https://shop.example/.well-known/ucp` returns the service endpoints (REST base is the
first `dev.ucp.shopping` service entry, MCP endpoint the second), the capability list, the
advertised **payment handlers**, and the store's **signing keys** (JWKs — you need them to
verify webhooks). Cache it, refresh respecting Cache-Control. Paths append directly to the
endpoint: `{endpoint}/checkout-sessions`, `{endpoint}/catalog/search`, `{endpoint}/orders/{id}`.

## 4. Catalog is for browsing and watching; checkout is the only truth

`POST /catalog/search` (free text + filters + cursor pagination), `POST /catalog/lookup`
(batch by product/variant id or SKU, with `inputs[]` telling you which of your ids matched
what), `POST /catalog/product` (single product, supports `selected` option narrowing — the way
to ask "does size 44 exist and is it available?"). Variants carry
`availability {available, status}` and `price {amount, currency}`. For watch tasks, poll
lookup/get_product on your scheduler — but treat catalog data as **non-binding**: prices and
availability are only committed inside a checkout session. Trigger logic on catalog data,
**verify on checkout totals before paying**.

## 5. Money is integer minor units in the *store's* currency

All amounts are integers in ISO 4217 minor units (grosze, cents), and the checkout dictates the
currency — the store decides it, not the agent. A task like "buy if ≤ 50 USD" needs explicit
handling: the shop may sell in PLN; convert for comparison and be precise with the user about
whether their cap means item price or the checkout **total** (shipping + tax included). Totals
arrays have exactly one `subtotal` and one `total`; verify `sum(other entries) == total` and
never auto-complete a checkout whose totals violate the user's mandate.

## 6. Drive checkout as a state machine, with full-replacement updates

`POST /checkout-sessions` (201) → `PUT /checkout-sessions/{id}` (send the **whole** resource
each time, not a diff) → `POST …/complete`. Obey `status`: act only on `ready_for_complete`;
on `incomplete` read `messages[]` as your to-do list (`severity: recoverable` → fix via API and
re-PUT; `requires_buyer_input`/`requires_buyer_review` → hand the human the `continue_url`;
`unrecoverable` → start over). Physical goods require fulfillment: PUT a destination address,
the store responds with server-computed delivery `options[]` (ids and prices are authoritative,
cheapest first), PUT the chosen `selected_option_id` — totals change accordingly. Sessions
expire (`expires_at`, default 6 h): for scheduled tasks **create the checkout at trigger time**,
never days in advance.

## 7. Payment = advertised handler + opaque credential; BLIK-R is the human-not-present path

Checkout responses advertise `ucp.payment_handlers`; on complete, send
`payment.instruments[{id, handler_id, type, selected, credential}]` where `handler_id` **must**
match an advertised handler id — anything else is rejected before any charge. The credential is
opaque to the protocol: for BLIK-R, that's the stored-mandate reference vaulted with PayU during
a prior consent flow (the merchant-side PayU handler charges it merchant-initiated). Never send
raw card data; credentials are never echoed back in any response, so don't expect to read them
again. Capture the user's mandate consent (and spending rules) up front, in your own UX — the
store only sees the reference.

## 8. Handle all three completion outcomes — especially escalation, on a schedule

`complete_checkout` ends one of three ways: **(a)** 200, `status: completed`, with
`order {id, permalink_url}` — done, save both; **(b)** 402/403 decline (`payment_failed`) — the
session stays completable, you may retry with another instrument; **(c)** 200 with
`status: requires_escalation` + `continue_url` — the bank/issuer demands buyer interaction
(e.g. SCA). For unattended scheduled purchases (c) is a first-class path, not an error: notify
the human immediately with the `continue_url` (it's the store's payment page for that exact
order) and keep the task open — the session remains resumable.

## 9. Idempotency keys are your crash armor — mandatory for a scheduler

Send a fresh UUID `Idempotency-Key` on every create/update/complete/cancel (over MCP:
`meta["idempotency-key"]`, required for complete/cancel). **Persist the key with the task
attempt**; on timeout/crash/retry, resend the *same key with the same payload* — you get the
stored response replayed verbatim, guaranteeing no duplicate order and no double charge. Same
key with a changed payload → 409 (that's a bug on your side). Also respect `429 + Retry-After`
from rate limiting — your polling loops must back off; watch-task polling frequency should be
minutes, not seconds.

## 10. Webhooks are the order-tracking channel — verify their signatures

After purchase, the store POSTs the **full order snapshot** to your profile's `webhook_url`
with `X-Event-Type` (`order_placed`, `order_shipped`, `order_updated`), `Webhook-Id`,
`Webhook-Timestamp`. Every webhook is signed per RFC 9421 (ES256): take `keyid` from
`Signature-Input`, find the JWK in the shop's `/.well-known/ucp` `signing_keys`, check
`Content-Digest` (SHA-256 over the raw body bytes), verify the signature over the covered
components — reject on any mismatch, and confirm the order id is one of yours. Respond 2xx
fast (process async); failed deliveries are retried with backoff, so **dedupe by `Webhook-Id`**.
Use webhooks as the primary update channel and `GET /orders/{id}` only for reconciliation.
For chat UX, `permalink_url` is the buyer-facing order page — always give it to the user.

---

### Two cross-cutting habits worth wiring in from day one

- **Two error shapes.** Transport errors are 4xx/5xx with a bare `{code, content, continue_url?}`
  body; business outcomes (out of stock, incompatibility, not found) are **HTTP 200** envelopes
  with `ucp.status: "error"` and `messages[]`. Branch on both, and always surface `continue_url`
  to the human as the graceful fallback into the normal web shop.
- **MCP or REST, same behavior.** Everything above is also available as MCP `tools/call`
  (`create_checkout`, `update_checkout`, `complete_checkout`, `search_catalog`, `lookup_catalog`,
  `get_product`, `get_order`, …) with the payload in `structuredContent`; JSON-RPC errors ride on
  mirrored HTTP status codes. Pick one transport per flow and don't assume MCP-only clients can
  skip the profile/auth rules — they can't.
