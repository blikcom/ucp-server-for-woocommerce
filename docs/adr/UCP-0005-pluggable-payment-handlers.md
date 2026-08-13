# ADR-UCP-0005: Pluggable payment handlers and opaque credentials

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

UCP separates payment **instruments** from **handlers**. Merchants use different PSPs; BLIK-R
human-not-present charges need stored-mandate references, not card data in the protocol.
Embedding one acquirer in this public plugin would couple Woo shops to a single vendor and
leak POC-specific PayU details into the open-source tree.

## Decision

1. Payments go through **`PaymentHandlerInterface`**, registered via `ucpws_payment_handlers`.
   The plugin owns advertisement, `handler_id` validation, session state, and idempotency; the
   handler owns **the charge**.
2. Credentials in `payment.instruments[].credential` are **opaque**: never persisted in
   session state, never logged, never echoed (stripped from all responses).
3. Handler outcomes map to protocol results:
   - `success` → WC `payment_complete()`, session `completed`, `order_placed` webhook
   - `declined` → transport error, session remains completable
   - `escalation` → `requires_escalation` + `continue_url` (default: WC order-pay URL)
4. **Human-not-present** (e.g. BLIK-R): the mandate/token reference is the opaque credential;
   consent and spending rules are captured in the **platform UX**, not vaulted by this plugin.
5. A **mock handler** ships for tests/demos/conformance and is **off by default**
   (`enable_mock_handler`). No production PayU/BLIK handler lives in this repository.

## Consequences

- Any PSP (including a future PayU BLIK-R gateway in the merchant image) integrates without
  forking the UCP server.
- At most one successful charge per checkout session (idempotency + terminal states).
- Escalation is a first-class unattended path: agents must notify the human and keep the task
  open — not treat it as a hard failure only.
- Double-charge protection still requires handlers to pass WC order / session ids as PSP
  idempotency keys (documented belt-and-braces).

## Alternatives considered

- **Built-in PayU/BLIK handler in this repo** — rejected: wrong license/boundary for a generic
  public plugin; merchant-specific secrets and contracts.
- **Redirect-only / web-checkout-only payments** — rejected: blocks true HNP agentic purchase.
- **Vault credentials inside the UCP plugin** — rejected: expands PCI/secret scope; UCP design
  keeps credentials opaque pass-through.

## Security notes

- Any path that persists, logs, or echoes `credential` is a vulnerability ([SECURITY.md](../../SECURITY.md)).
- Shop-side guardrails: valid advertised `handler_id`, credential strip, idempotent complete.
- Platform-side guardrails (amount ≤ mandate, product match, task not expired) are **out of
  this ADR** — see ADR-AGT-0002 (policy gate) and `docs/requirements-traceability.md` in
  `agentic-commerce-agent`.

## Related

- `src/Payments/PaymentHandlerInterface.php`, `HandlerRegistry.php`, `PaymentResult.php`
- [docs/payment-handlers.md](../payment-handlers.md)
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§7–8)
- ADR-UCP-0003 (draft order lifecycle on complete/escalation)
- ADR-UCP-0006 (webhooks after success)
