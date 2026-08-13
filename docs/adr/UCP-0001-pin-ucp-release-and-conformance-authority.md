# ADR-UCP-0001: Pin UCP 2026-04-08 and conformance as authority

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

UCP versions are date-stamped (`YYYY-MM-DD`). The protocol evolves; platforms and shops must
agree on a release. Contributors need a clear rule when the suite, the prose spec, and a
reference implementation disagree.

## Decision

1. This plugin implements **exactly one** protocol release: **`2026-04-08`**, pinned in code,
   discovery profile, bundled JSON schemas, and docs.
2. Behavior questions are settled in this order (see CONTRIBUTING):
   **official conformance suite → spec text → reference merchant server**.
3. Requests that declare a newer unsupported version are rejected (HTTP 422 /
   `version_unsupported` under strict negotiation).

## Consequences

- Predictable surface for agents and for CI against the official suite.
- No runtime multi-version profile matrix; supporting a new UCP release is a deliberate
  upgrade (new schemas, tests, possibly a major plugin bump).
- Newer clients cannot silently negotiate; they must wait for a plugin release that pins
  their version.

## Alternatives considered

- **Floating “latest”** — rejected: non-reproducible conformance and surprise breaks for
  merchants.
- **Multi-profile `supported_versions` at runtime** — deferred: valuable later, but out of
  scope for v0.1; increases negotiation and schema burden without a second consumer yet.

## Related

- [CONTRIBUTING.md](../../CONTRIBUTING.md)
- [docs/requirements.md](../requirements.md) (business requirement 1)
- Bundled schemas: `resources/schemas/2026-04-08/`
- Discovery: `src/Discovery/ProfileBuilder.php`
