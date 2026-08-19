# Conformance harness

```
make conformance
```

Spins up a throwaway WordPress + WooCommerce, installs the plugin **from the
working tree**, seeds the dataset the official suite expects, runs a full
mock-handler checkout with assertions, and destroys the stack. A red run
tears down too, so the next run still starts clean.

Nothing is shared with a dev stack: its own compose project (`ucpws-conformance`),
its own volume, and port 8099 by default (`CONFORMANCE_PORT` to change it).
Images are pinned — WordPress 6.9/PHP 8.3, MariaDB 11.8.8, WooCommerce
10.4.0 — so two runs answer the same question.

## What the scripted checkout asserts

Discovery advertises the shopping service and publishes signing keys; the
catalog returns money as integer minor units; a session moves
`incomplete -> ready_for_complete -> completed`; the shop's own totals add up
(`subtotal + fulfillment + tax == total`); an order comes back; and replaying
the completion with the same `Idempotency-Key` returns **the same order**
rather than charging twice.

These are the things a conformance regression shows up in first, which is why
they run on every invocation rather than only before a release.

## The official UCP suite

The harness runs it when you point at a local checkout:

```
CONFORMANCE_SUITE_DIR=~/src/conformance \
CONFORMANCE_INPUT=~/src/conformance/conformance_input.json \
make conformance
```

It needs the [suite](https://github.com/Universal-Commerce-Protocol/conformance)
cloned with `python-sdk` as a sibling, plus `uv`. Without
`CONFORMANCE_SUITE_DIR` the harness says it skipped that step rather than
implying it passed.

The suite's own requirements are already met by the throwaway instance:
`auth_mode=open`, `negotiation_mode=lenient`, the mock handler enabled and a
`simulation_secret` (`UCPWS_SIMULATION_SECRET`, default
`conformance-secret`). Those are set for the throwaway container only — they
are not a posture any real deployment should run.

Note the suite starts local mock servers (platform profile on `:8285`,
webhook receiver on `:8284`) that the WordPress container must be able to
reach; `dev_url_rewrites` exists for that.

## When to run it

- **CI runs the scripted half on every push and pull request** (the
  `conformance` job), so a change that breaks the checkout flow cannot merge
  green.
- **Before tagging a release, run it locally with `CONFORMANCE_SUITE_DIR`
  set** so the official suite runs too. CI does not clone the suite: it is a
  separate repository with its own toolchain, and a release gate that depends
  on someone else's default branch is a gate that fails for reasons unrelated
  to this plugin.

## Debugging a failure

`KEEP_STACK=1 make conformance` leaves the instance running so you can look
at it: `http://localhost:8099/wp-admin` (admin / conformance). Tear it down
with `docker compose -f harness/docker-compose.yml down -v`.
