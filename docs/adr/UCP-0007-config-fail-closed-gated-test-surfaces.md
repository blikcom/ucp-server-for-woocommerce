# ADR-UCP-0007: Config precedence, fail-closed, gated test surfaces

- Status: Proposed (needs team review)
- Date: 2026-08-13
- Deciders: UCP plugin authors (v0.1)

## Context

The plugin must run on stock WordPress (options + wp-admin) and on Bedrock/12-factor hosts
(constants and environment variables). Test-only surfaces required by the conformance suite
must never be reachable in production by default. Unexpected failures must not leak internals
to agents.

## Decision

1. **Config precedence** (highest first): PHP constant `UCPWS_*` → environment `UCPWS_*` →
   `ucpws_settings` option → code defaults → `ucpws_config_{key}` filter
   (`src/Support/Config.php`).
2. **Fail closed**: unexpected throwables yield a generic client error; details go only to
   server logs.
3. **Test / escape surfaces are off or secret-gated by default:**
   - `enable_mock_handler` — off
   - `/testing/simulate-shipping/{id}` — requires `simulation_secret`
   - `allow_insecure_profiles`, `allow_private_hosts`, `dev_url_rewrites` — off; **never
     production**
4. Signing private keys are **externalizable** via `UCPWS_SIGNING_KEY_PATH` / `PEM` / `ID`
   for secret managers; DB storage remains the zero-config default for simple installs.

## Consequences

- Bedrock merchants can pin posture in `config/application.php` without wp-admin.
- Pure “env-only” 12-factor purists see an extra options layer — accepted WP-ecosystem
  compromise.
- Mis-enabling open auth (ADR-UCP-0004) or mock/dev SSRF flags in production is an operator
  failure mode; docs and defaults bias safe.
- Conformance and local Docker setups can turn the knobs without code forks.

## Alternatives considered

- **Environment variables only** — rejected: stock WP hosts lack clean env injection; admin
  UI is expected.
- **wp-config / options only** — rejected: blocks immutable Bedrock/container deploys.
- **Always-on mock and simulation endpoints** — rejected: unsafe and non-compliant with
  security requirements.

## Security notes

Never enable in production:

| Flag / surface | Risk if on |
| --- | --- |
| `auth_mode=open` | Anonymous platform traffic |
| `enable_mock_handler` | Fake “paid” orders |
| `simulation_secret` set publicly | Shipping simulation abuse |
| `allow_private_hosts` / `allow_insecure_profiles` / `dev_url_rewrites` | SSRF / MITM on profile fetch |

See [SECURITY.md](../../SECURITY.md).

## Related

- `src/Support/Config.php`, `src/Http/Responder.php`
- `src/Payments/MockHandler.php`, testing routes in REST server
- [docs/requirements.md](../requirements.md) (security 6–10, technical 2)
- ADR-UCP-0004 (auth defaults), ADR-UCP-0005 (mock handler), ADR-UCP-0006 (external signing keys)
