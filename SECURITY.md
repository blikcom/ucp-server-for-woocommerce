# Security Policy

## Reporting a vulnerability

Email **security@example.com** (PGP available on request) or use GitHub's private vulnerability
reporting on this repository. Please do not open public issues for security reports.

You can expect an acknowledgment within 3 business days. Coordinated disclosure preferred.

## Scope notes for researchers

- **Payment credentials** are opaque pass-through values: the plugin must never persist, echo,
  or log them. Any path that leaks a `credential` object is a vulnerability.
- **Signing keys**: the EC P-256 private key lives in a non-autoloaded option (or an external
  file/env via `UCPWS_SIGNING_KEY_PATH`) and must never appear in responses, exports, or logs.
- **API keys** are stored as SHA-256 hashes; the plaintext is shown exactly once at creation.
- **SSRF**: platform profile fetching is HTTPS-only, redirect-free, size- and time-limited, and
  rejects private hosts unless the dev-only `allow_private_hosts` flag is set. The
  `dev_url_rewrites` map is a test-environment feature; report any way to influence it remotely.
- **Test endpoints**: `/testing/simulate-shipping/{id}` is disabled unless a `simulation_secret`
  is configured and responds 403 otherwise. The mock payment handler is off by default.

## Supported versions

Security fixes target the latest minor release.
