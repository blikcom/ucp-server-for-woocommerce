# ADR-UCP-0010: Buyer identity on checkout (no WC login session binding)

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

Agent wiki CHAT-02 / TRUST-02 require the chat to act for an authenticated store customer with
isolation between users. UCP requests authenticate the **platform** (API key + profile), not
the end-shopper’s WordPress login cookie. The plugin must still attach enough buyer data for
orders, email, and address book.

## Decision

1. Checkout carries a UCP **`buyer`** object (email, name, phone, …). The adapter maps it onto
   WooCommerce order **billing** fields (`apply_buyer`).
2. Address book entries are keyed by **buyer email**, not by WordPress `user_id`.
3. Draft/completed orders are **not** automatically attached to a logged-in WC customer
   session. There is no “impersonate WP user cookie” from the platform key.
4. **End-user authentication and authorization** (shop login → agent session → which mandate /
   cart / tasks) is the **platform’s** responsibility (TRUST-02). The shop trusts the
   registered platform not to mix customers.
5. POC may simplify to logged-in-only chat (wiki allows this) without changing this plugin
   contract.

## Consequences

- Orders remain fulfilable and emailable without WP account linkage.
- Account-specific WC benefits (saved payment methods in WC, customer order history by user
  id) need an explicit future bridge (set `customer_id` from a verified platform assertion).
- Security model: **platform isolation** (ADR-UCP-0004) + **agent session isolation** (ADR-AGT-0008);
  not shared WP cookies across agent Cloud Run and storefront.

## Alternatives considered

- **Require WC JWT / application password per shopper on every UCP call** — deferred: heavy
  for agents; not required by UCP 2026-04-08 checkout shape.
- **Map buyer email → WC customer automatically always** — deferred: account-takeover risk if
  platform asserts arbitrary emails without proof.

## Security notes

A compromised platform API key can place orders for any buyer email it asserts. Mitigations:
registry auth, rate limits, merchant allow-list of one POC platform, agent-side signed
customer tokens. Mandate credentials still never enter LLM context (ADR-UCP-0005).

## Related

- `src/Checkout/CheckoutService.php` (`apply_buyer`), `src/Checkout/AddressBook.php`
- ADR-UCP-0004, ADR-UCP-0005
- Agent ADR: chat session ↔ store customer binding
