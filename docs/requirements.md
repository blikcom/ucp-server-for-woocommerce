# UCP Server for WooCommerce — Requirements

Requirements met by the current code (`feature/ucp-server-v1`). Unmet/future requirements are
tracked separately.

## Technical requirements

1. Ships as a standard WordPress plugin for WordPress ≥ 6.6 with WooCommerce ≥ 9.0 (tested with WP 7.0 / WC 10.9), running on PHP 7.4–8.4.
2. Runs unchanged on stock WordPress and on composer-managed installs (Bedrock); every setting is configurable through environment variables / PHP constants, with an optional admin screen.
3. HPOS-compatible: all order access goes through WooCommerce CRUD APIs, never direct database queries.
4. WooCommerce's own engine computes all prices, taxes, shipping and discounts — checkout sessions are backed by WooCommerce draft orders, so the adapter never re-implements money math.
5. Protocol state (sessions, idempotency records, platform registry) lives in dedicated database tables with a clean activation, upgrade and uninstall lifecycle.
6. No file writes at runtime and no HTTP requests to its own site — safe for read-only, containerized hosting.
7. Asynchronous work (webhook retries, lifecycle events) runs on Action Scheduler; caches use standard WordPress transients.
8. Minimal footprint: one runtime composer dependency, requiring only the `json` and `openssl` PHP extensions.

## Business requirements

1. Implements the **Universal Commerce Protocol, release 2026-04-08** (pinned): discovery, checkout with fulfillment, catalog search/lookup, and orders with lifecycle webhooks, over both REST and MCP transports.
2. Agent-driven purchases become real WooCommerce orders: the merchant stays merchant of record, and standard admin, fulfillment, reporting and confirmation-email flows apply.
3. Payments are pluggable: any PSP integrates through a documented handler interface, including charges on stored credentials/mandates without the buyer present, and buyers are never double-charged.
4. When the API flow cannot finish (missing input, payment challenge, incompatibility), the buyer is handed off to the regular web checkout — no lost sales.
5. The merchant controls which platforms may transact: an access registry with per-platform keys and a global off switch.
6. The whole flow can be demonstrated and tested without any PSP contract via a bundled, clearly non-production mock payment handler.

## Security requirements

1. Platforms authenticate with per-platform API keys; each key is bound to exactly one platform identity and requests with mismatched identities are rejected.
2. API keys are stored only as hashes and shown exactly once at creation; platforms can be disabled or removed at any time.
3. All outgoing order webhooks are cryptographically signed (ES256), with public keys published for verification.
4. Private signing keys are generated server-side, never leave the server, and support rotation; they can be supplied from a secret manager instead of the database.
5. Payment credentials are treated as secrets: never stored, never logged, never echoed back in any response.
6. Outbound profile fetching is hardened against SSRF: HTTPS-only, redirects refused, size and time limits, private hosts blocked.
7. All endpoints are rate-limited, with an additional bounded budget for discovery requests from unknown platforms.
8. All input is sanitized and validated; database access uses prepared statements; admin actions require capability checks and nonces.
9. The adapter fails closed: unexpected errors return a generic response to the client while details go only to server logs.
10. Test-only surfaces (mock payment handler, shipping-simulation endpoint) are disabled by default and secret-gated when enabled.

## Code quality requirements

1. The entire codebase passes WordPress Coding Standards.
2. Static analysis (PHPStan level 6 with WordPress/WooCommerce stubs) passes with zero errors.
3. Protocol-critical logic is covered by unit tests, green on PHP 7.4 through 8.4.
4. CI runs all quality gates on every push and pull request; the developer toolchain is fully containerized (Docker only, no host PHP).
5. Work follows a feature-branch workflow with conventional commits, and the project ships operator/integrator documentation (README, payment-handler guide, contributing and security policies).
