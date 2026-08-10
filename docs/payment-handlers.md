# Writing a UCP payment handler

A payment handler is the bridge between UCP's payment architecture and your PSP integration.
The plugin owns everything protocol-shaped (advertisement, validation, idempotency, session
state, escalation responses); your handler owns exactly one hard thing: **the charge**.

## The contract

Implement `UCPWS\Payments\PaymentHandlerInterface` and register it:

```php
add_filter( 'ucpws_payment_handlers', function ( array $handlers ) {
    $handlers[] = new My_Handler();
    return $handlers;
} );
```

| Method | Used for |
|---|---|
| `get_name()` | Reverse-domain registry key in `ucp.payment_handlers` (e.g. `com.acme.tokenizer`). The namespace must match your spec/schema URL origins. |
| `get_id()` | Handler instance id. Platforms send it back as `payment.instruments[].handler_id`; the server rejects instruments whose `handler_id` is not advertised. |
| `get_version()` | Handler version (`YYYY-MM-DD`). |
| `get_spec_url()` / `get_schema_url()` | Where platforms find your handler spec + JSON Schema. |
| `get_available_instruments()` | Instrument types/constraints you support (empty array = unrestricted). The `ucpws_resolve_available_instruments` filter is the hook point for platform/cart intersection. |
| `get_config( $order, $context )` | Handler config. Called with `null` order for the discovery profile, and with the draft order for checkout responses (runtime config: public keys, merchant ids, tokenization specs). |
| `is_available( $order, $context )` | Dynamic filtering (currency, cart contents, shipping country). Unavailable handlers are omitted from that checkout's advertisement. |
| `charge( $order, $instrument, $request, $context )` | The charge. Returns a `PaymentResult`. |

## PaymentResult semantics

| Factory | Checkout outcome |
|---|---|
| `PaymentResult::success( $transaction_id )` | `payment_complete()` runs on the WooCommerce order (status `processing`/`completed`, stock reduced, confirmation email sent), the checkout responds `status: completed` with the `order` confirmation, and the signed `order_placed` webhook fires. |
| `PaymentResult::declined( $message, $code = 'payment_failed', $http = 402 )` | Transport error (`402`/`403`) with your message; the session stays completable so the platform can retry with another instrument. The stock reservation is released. |
| `PaymentResult::escalation( $message, $continue_url = null, $code = 'requires_3ds' )` | The session becomes `requires_escalation` with a `requires_buyer_input` message and a `continue_url` (defaults to the WooCommerce order-pay URL, where your regular gateway can take over). |

## Credentials, mandates, human-not-present

`$instrument['credential']` arrives verbatim from the platform and is **opaque**: a single-use
token, an encrypted payload, or a reference to something you already store — e.g. a recurring
**BLIK mandate id** vaulted during a prior linking flow.

- If the credential lets you charge without the buyer (stored mandate, network token with
  merchant-initiated rights), charge and return `success()` — this is the human-not-present
  path; no browser, no redirect.
- If the issuer demands interaction (SCA/3DS, expired mandate), return `escalation()` — the
  platform opens `continue_url` for the buyer and retries completion afterwards.

Rules the plugin enforces for you, and that your handler must not undo:

- **Never echo credentials.** The server strips `credential` from every response and never
  persists it in session state. Don't put credential material into order meta, notes, or logs.
- **Never log credentials.** Log decisions (`declined: insufficient funds`), not inputs.
- **Idempotent charging.** `complete_checkout` is idempotency-key protected and a completed
  session can never charge twice. Belt-and-braces: pass `$order->get_id()` (or the UCP session
  id from `_ucpws_session_id` order meta) to your PSP as its idempotency key.

## Testing your handler

Install the plugin in your WordPress + WooCommerce environment, register your handler, and
drive a checkout end to end over REST (create → update address/option → complete with your
handler id). The mock handler (`docs` above) is a working reference for all three outcome
paths — success, decline, escalation.

## The bundled mock handler

`dev.ucpws.mock` / id `mock_payment_handler` (enable with `UCPWS_ENABLE_MOCK_HANDLER=1`).
**Non-production** — it charges nothing and exists for tests, demos and the conformance suite.

| Credential | Outcome |
|---|---|
| `{"type":"token","token":"success_token"}` | approved |
| `{"type":"token","token":"fail_token"}` | declined, 402, "Payment Failed: Insufficient Funds (Mock)" |
| `{"type":"token","token":"fraud_token"}` | declined, 403 |
| `{"type":"token","token":"escalate_token"}` | `requires_escalation` + continue_url |
| `{"type":"token","token":"mandate_*"}` | approved (simulated stored-mandate charge, human-not-present) |
| `{"type":"card", ...}` | approved |
