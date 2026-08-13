# UCP Server for WooCommerce

Turn any WooCommerce shop into a **Universal Commerce Protocol (UCP)** business server.

This plugin implements UCP release **2026-04-08** end to end: agentic platforms discover your
shop at `/.well-known/ucp`, negotiate capabilities, search your catalog, drive checkout
sessions backed by WooCommerce's own totals engine, and **pay through a pluggable payment
handler architecture** — including merchant-initiated charges on stored credentials/mandates
(human-not-present). Order lifecycle updates are pushed back to platforms as **RFC 9421-signed
webhooks** with automatic retries.

## Why this plugin

Most early UCP/agentic-commerce integrations stop at handing the buyer off to the web
checkout. This project implements the **full UCP payment architecture**:

- `complete_checkout` charges through a `PaymentHandlerInterface` you (or a PSP plugin) implement —
  no browser session required.
- Credentials are opaque to the plugin (tokens, encrypted payloads, or references to
  merchant-stored mandates such as recurring BLIK). They are never echoed back and never logged.
- When a handler cannot charge without the buyer (SCA challenge, expired mandate), the checkout
  degrades gracefully to `requires_escalation` with a `continue_url` into the classic web checkout.
- Signed order webhooks (`ES256`, keys published in the discovery profile) close the loop after purchase.
- Verified against the **official UCP conformance suite** in CI.

## Capabilities & transports

| | |
|---|---|
| Capabilities | `dev.ucp.shopping.checkout` (+ official `dev.ucp.shopping.fulfillment` extension), `dev.ucp.shopping.catalog.search`, `dev.ucp.shopping.catalog.lookup`, `dev.ucp.shopping.order` |
| Transports | REST (core) and MCP (JSON-RPC `tools/call`), both declared in the discovery profile |
| Protocol version | `2026-04-08` (pinned) |
| Payments | Pluggable handlers; bundled **mock handler** for tests/demos (non-production) |
| Storage | Custom tables (sessions, idempotency keys, platform registry) via `dbDelta`; HPOS compatible |

## Endpoint map

All REST paths live under `https://your-shop.example/wp-json/ucp/v1` (advertised as the
`rest` service endpoint in the profile). Pretty permalinks are required.

| Method | Path | Purpose |
|---|---|---|
| GET | `/.well-known/ucp` *(site root, plus a REST alias under the namespace)* | Business discovery profile |
| POST | `/checkout-sessions` | Create checkout session (201) |
| GET | `/checkout-sessions/{id}` | Get session |
| PUT | `/checkout-sessions/{id}` | Update session (full replacement) |
| POST | `/checkout-sessions/{id}/complete` | Charge + place the order |
| POST | `/checkout-sessions/{id}/cancel` | Cancel session |
| POST | `/catalog/search` | Product search (cursor pagination) |
| POST | `/catalog/lookup` | Batch lookup by product/variant id or SKU |
| POST | `/catalog/product` | Single product detail |
| GET | `/orders/{id}` | Order snapshot |
| PUT | `/orders/{id}` | Platform-authored fulfillment events / adjustments |
| POST | `/mcp` | MCP transport (JSON-RPC 2.0: `initialize`, `tools/list`, `tools/call`) |
| POST | `/testing/simulate-shipping/{id}` | Test-only shipping simulation (guarded by `Simulation-Secret`) |

Checkout sessions are backed by **WooCommerce draft orders** (`checkout-draft`, the Store API
model): item prices, coupons, taxes and shipping all come from WooCommerce's calculation
engine — this plugin never reimplements totals math. Amounts on the wire are integers in ISO
4217 minor units; timestamps are RFC 3339.

## Requirements

- WordPress ≥ 6.6 with **pretty permalinks** and HTTPS
- WooCommerce ≥ 9.0 (HPOS supported and tested)
- PHP ≥ 7.4 (WooCommerce's current supported minimum) with `ext-openssl`
- Works on stock WordPress and composer-managed installs (Bedrock): every option can be
  provided via constants or environment variables (see below)

## Install

From a release build, install like any plugin. From source:

```bash
git clone https://github.com/blikcom/ucp-server-for-woocommerce wp-content/plugins/ucp-server-for-woocommerce
cd wp-content/plugins/ucp-server-for-woocommerce
make install          # composer install, in Docker
```

Activate **UCP Server for WooCommerce** in wp-admin. Activation creates the custom tables,
generates an EC P-256 signing key (the private key never leaves the server) and registers the
`/.well-known/ucp` rewrite.

Then open **WooCommerce → UCP Server** to:

1. register platforms (each API key is identity-bound to exactly one platform profile URL),
2. review/rotate signing keys,
3. adjust auth/negotiation posture.

## Quickstart (against your own environment)

Deploy the plugin into any WordPress + WooCommerce environment you run (this repo
deliberately ships no environment of its own). On a Bedrock install, require it via composer
or symlink it into `web/app/plugins/`; on stock WordPress, drop it into `wp-content/plugins/`.

After activating, sanity-check discovery and create a first checkout the way a platform would:

```bash
curl -s https://your-shop.example/.well-known/ucp | jq .ucp.version   # -> "2026-04-08"
```

```bash
curl -s -X POST https://your-shop.example/wp-json/ucp/v1/checkout-sessions \
  -H 'Content-Type: application/json' \
  -H 'UCP-Agent: profile="https://platform.example/profile.json"' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"line_items":[{"id":"li_1","item":{"id":"YOUR_SKU"},"quantity":1}]}' | jq .
```

`item.id` accepts WooCommerce product/variation IDs or SKUs. For ad-hoc testing without a
real PSP, enable the mock payment handler (`UCPWS_ENABLE_MOCK_HANDLER=1`, non-production) and
complete with `{"credential":{"type":"token","token":"success_token"}}`.

### Code QA commands (Docker only, no environment needed)

```bash
make install          # composer install (Docker)
make lint             # PHPCS (WordPress Coding Standards) + PHPStan, in Docker
make test-unit        # unit tests on PHP 7.4 (Docker)
make check            # all of the above
```

### Running the official UCP conformance suite

The plugin implements everything the [official conformance suite](https://github.com/Universal-Commerce-Protocol/conformance)
exercises for its declared capabilities, including the suite's operational hooks (the
`/testing/simulate-shipping/{id}` endpoint gated by `simulation_secret`, and the mock payment
handler). To run it against your environment:

1. Seed the flower-shop dataset the suite expects (see its `test_data/flower_shop` folder):
   products with SKUs `bouquet_roses` ($35, in stock) and `gardenias` ($20, stock 0), coupons
   `10OFF`/`WELCOME20` (percent) and `FIXED500` ($5 fixed), taxes off, currency USD, and
   shipping rates surfaced as options `std-ship` ($5) / `exp-ship-us` ($15, US) /
   `exp-ship-intl` ($25, non-US) — the `ucpws_fulfillment_option_id` filter maps your
   WooCommerce rate IDs to those stable names.
2. Set the plugin posture: `auth_mode=open`, `negotiation_mode=lenient`,
   `enable_mock_handler=1`, `simulation_secret=<value you pass to the suite>`.
3. Run the suite (Python ≥ 3.10, uv; clone `conformance` with `python-sdk` as a sibling):

```bash
uv run protocol_test.py --server_url=https://your-shop.example/wp-json/ucp/v1 \
  --simulation_secret=<secret> --conformance_input=<your conformance_input.json>
```

Note the suite spins up local mock servers (platform profile on :8285, webhook receiver on
:8284) that your WordPress host must be able to reach; the `dev_url_rewrites` config exists
for containerized setups where `localhost` URLs need remapping.

## Configuration

Everything is configurable three ways, highest precedence first — **PHP constant**, **environment
variable**, admin setting — and every resolved value passes through a `ucpws_config_{key}` filter.
Constants/env use the upper-cased key with the `UCPWS_` prefix, e.g. in a Bedrock
`config/application.php`:

```php
Config::define( 'UCPWS_AUTH_MODE', 'registry' );
Config::define( 'UCPWS_RATE_LIMIT', 600 );
Config::define( 'UCPWS_SIGNING_KEY_PATH', '/run/secrets/ucp-signing-key.pem' );
```

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Master switch for all UCP endpoints |
| `auth_mode` | `registry` | `registry`: require a registered platform API key (`X-API-Key` or `Authorization: Bearer`) identity-bound to the `UCP-Agent` profile. `open`: accept anonymous platforms (spec-permitted; required for the conformance suite) |
| `negotiation_mode` | `strict` | `strict`: full spec discovery errors (`invalid_profile_url` 400, `profile_unreachable` 424, `profile_malformed`/`version_unsupported` 422, `capabilities_incompatible` 200). `lenient`: mirror the reference implementation — profile fetch problems are logged and the request proceeds with the shop's own capability set |
| `rate_limit` / `rate_limit_window` | `300` / `60` | Fixed-window rate limit per client (0 disables); 429 + `Retry-After` |
| `profile_cache_ttl` | `300` | Platform profile cache TTL (60s floor per spec) |
| `discovery_budget` / `discovery_budget_window` / `discovery_backoff` | `20` / `60` / `120` | Fixed discovery footprint for unrecognized platforms + failure backoff (registered platforms are exempt) |
| `session_ttl` | `21600` | Checkout session TTL (spec default: 6 hours) |
| `idempotency_ttl` | `172800` | Idempotency record retention (spec minimum 24h) |
| `webhook_max_attempts` / `webhook_timeout` | `6` / `10` | Webhook delivery attempts (backoff: 1m/5m/30m/2h/12h via Action Scheduler) and per-request timeout |
| `enable_mock_handler` | `false` | Registers the bundled mock payment handler. **Non-production** |
| `validate_requests` / `validate_responses` | `true` | JSON-Schema validation hooks (responses validated against the bundled official schemas, composed with active extensions) |
| `simulation_secret` | *(empty)* | Enables `/testing/simulate-shipping/{id}` when set. **Test environments only** |
| `allow_insecure_profiles` / `allow_private_hosts` / `dev_url_rewrites` | off | Dev/test escape hatches for containerized environments. **Never enable in production** |
| `UCPWS_SIGNING_KEY_PATH` / `UCPWS_SIGNING_KEY_PEM` / `UCPWS_SIGNING_KEY_ID` | — | Provide the signing key from a secret manager instead of the database |

## Payments: writing a handler

See **[docs/payment-handlers.md](docs/payment-handlers.md)** for the full guide. The short version:

```php
use UCPWS\Payments\PaymentHandlerInterface;
use UCPWS\Payments\PaymentResult;

class My_PSP_Handler implements PaymentHandlerInterface {
    public function get_name(): string { return 'com.my-psp.tokenizer'; }   // reverse-domain registry key
    public function get_id(): string   { return 'my_psp_prod'; }            // matched against instrument.handler_id
    public function get_version(): string { return '2026-04-08'; }
    public function get_spec_url(): ?string { return 'https://my-psp.example/ucp-handler'; }
    public function get_schema_url(): ?string { return 'https://my-psp.example/ucp-handler/schema.json'; }
    public function get_available_instruments(): array {
        return [ [ 'type' => 'card', 'constraints' => [ 'brands' => [ 'visa', 'mastercard' ] ] ] ];
    }
    public function get_config( ?\WC_Order $order = null, ?\UCPWS\Negotiation\NegotiationContext $context = null ): array {
        return [ 'public_key' => get_option( 'my_psp_pk' ), 'environment' => 'production' ];
    }
    public function is_available( \WC_Order $order, $context ): bool { return 'PLN' === $order->get_currency(); }

    public function charge( \WC_Order $order, array $instrument, array $request, $context ): PaymentResult {
        $credential = $instrument['credential']; // opaque: token, encrypted payload, or mandate reference
        $response   = my_psp_charge( $order->get_total(), $order->get_currency(), $credential, $order->get_id() );

        if ( $response->approved ) {
            return PaymentResult::success( $response->transaction_id );
        }
        if ( $response->needs_sca ) {
            // Human-not-present charge impossible: platform falls back to web checkout.
            return PaymentResult::escalation( 'Bank requires verification.', $response->challenge_url, 'requires_3ds' );
        }
        return PaymentResult::declined( 'Payment Failed: ' . $response->reason );
    }
}

add_filter( 'ucpws_payment_handlers', function ( array $handlers ) {
    $handlers[] = new My_PSP_Handler();
    return $handlers;
} );
```

The server advertises your handler in the discovery profile and in every checkout response's
`ucp.payment_handlers`, validates `instrument.handler_id` against the advertised set before
charging, and guarantees at most one successful charge per checkout session (idempotency keys +
terminal session states). **Never log or echo credentials** — the plugin strips `credential`
from all responses and persists instruments without it.

## Protocol behavior highlights

- **Discovery**: `Cache-Control: public, max-age≥60`, HTTPS, no redirects; profile shape follows
  the official discovery schema (bundled for validation).
- **Negotiation**: `UCP-Agent` is parsed per RFC 8941 (`profile="…"`, optional `version="…"`);
  platform profiles are fetched HTTPS-only without redirects, cached with a 60s TTL floor, under
  a fixed discovery budget with failure backoff; the capability intersection algorithm (highest
  mutual version, orphaned-extension pruning) selects the active set; every response carries the
  negotiated `ucp` block with response-relevant capabilities (checkout responses include
  resolved `payment_handlers`).
- **Idempotency**: `Idempotency-Key` on create/update/complete/cancel — replays return the stored
  response verbatim; same key with a different payload → 409. Records are kept ≥ 24h.
- **Errors**: business outcomes are HTTP 200 envelopes with `messages[]` (`ucp.status: "error"`
  when no resource exists); transport errors use the spec's bare `{code, content, continue_url}`
  body with the exact status codes; 429 carries `Retry-After`. Over MCP, JSON-RPC error codes
  (-32001 discovery, -32000 protocol, -32603 internal) ride on mirrored HTTP statuses.
- **continue_url**: always the most contextually relevant URL — checkout page for sessions, the
  order-pay URL for `requires_escalation`, product/storefront for catalog errors.

## Development

The repo ships a Docker-based toolchain (`make help`) and CI (GitHub Actions) running PHPCS
(WordPress Coding Standards), PHPStan (level 6 with WP/WC stubs) and the unit suite on PHP
7.4 + 8.3. Integration and conformance verification run against your own WordPress +
WooCommerce environment (see Quickstart above); the plugin is HPOS-compatible and declares it
via `FeaturesUtil`.

Bundled official UCP JSON Schemas (`resources/schemas/2026-04-08`, Apache-2.0, © UCP Authors)
are used to validate responses and the discovery profile; one relative `$ref` in
`discovery/profile_schema.json` is normalized so the bundle resolves offline.

Architecture decision records: **[docs/adr/](docs/adr/)**.

## License

[GPL-3.0-or-later](LICENSE) — matching WooCommerce (GPLv3) and eligible for
distribution via wordpress.org. Bundled UCP JSON Schemas remain Apache-2.0
(© UCP Authors), which is GPL-compatible.
