# ADR-UCP-0002: Dual transport (REST primary, MCP secondary), shared domain services

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

UCP allows REST and MCP (JSON-RPC `tools/call`) bindings for the same capabilities. Agent
stacks differ: some prefer HTTP resources, others tool-calling over MCP. Duplicating commerce
logic per transport would diverge and break conformance.

UCP also defines a cart capability; WooCommerce already has Store API carts and draft-order
checkout. Implementing a second cart model risks shadow state. The agent wiki (CART-01…03)
asks for prompt-managed cart that is “the store’s own cart” shared with the website.

## Decision

1. **One domain layer** (checkout, catalog, orders, negotiation, auth, idempotency) shared by
   both transports.
2. **REST is primary** in the discovery profile (first `dev.ucp.shopping` service entry); MCP
   is the second entry and mirrors the same operations.
3. **`dev.ucp.shopping.cart` is not implemented** in this plugin. Mutable commerce state for
   agents is the **checkout session** (draft order). See ADR-UCP-0003 for the POC resolution
   against wiki CART-03.
4. Idempotency rules for transports: see **ADR-UCP-0008** (MCP requires keys on complete/cancel;
   REST applies when the header is present).

## Consequences

- Two thin bindings to maintain (`RestServer`, `McpServer`) but one source of truth for money
  and protocol state.
- Agents may pick either transport per flow; they must not assume MCP skips profile/auth
  rules — it does not.
- POC agent should prefer **REST** for clarity and curl/ops parity (ADR-AGT-0008); MCP remains
  available for tool-calling stacks and conformance.

## Alternatives considered

- **REST only** — rejected: MCP is part of UCP’s agent story and the conformance story.
- **MCP only** — rejected: REST is the core transport in the spec and easier for curl/ops.
- **Separate implementations per transport** — rejected: drift risk.
- **Implement cart capability now** — rejected for v0.1; see ADR-UCP-0003 Known gap vs CART-03.

## Related

- `src/Http/RestServer.php`, `src/Mcp/McpServer.php`, `src/Mcp/ToolCatalog.php`
- [docs/agent-integration-notes.md](../agent-integration-notes.md) (§10)
- ADR-UCP-0003 (checkout / CART-03), ADR-UCP-0008 (idempotency)
