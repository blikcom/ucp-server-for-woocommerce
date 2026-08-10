# Contributing

Thanks for helping make WooCommerce a first-class UCP citizen!

## Ground rules

- Target UCP release **2026-04-08** (pinned). Behavior questions are settled in this order:
  official conformance suite → spec text → reference merchant server.
- PHP ≥ 7.4 syntax (WooCommerce's supported minimum), WordPress Coding Standards, PHPStan level 6.
- All money math stays in WooCommerce. If you find yourself adding amounts, stop.
- Never log or echo payment credentials. Reviewers will look.

## Dev setup

The only host requirement is Docker — all PHP tooling runs in containers. Functional testing
happens against your own WordPress + WooCommerce environment (install the plugin there).

```bash
make install    # composer install (Docker)
make lint       # PHPCS + PHPStan
make test-unit  # PHPUnit, PHP 7.4 container
make check      # all of the above
```

## Pull requests

1. One logical change per PR.
2. `make check` must pass locally; CI runs the same gates on PHP 7.4 and 8.3.
3. New pure logic needs a unit test; protocol-visible behavior should be exercised against a
   real environment with the official conformance suite before merging.
4. Spec-visible changes (payloads, status codes, headers) need a pointer to the spec/conformance
   test that justifies them.

## Reporting security issues

Please do not open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).
