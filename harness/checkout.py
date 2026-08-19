"""One full mock-handler checkout against the throwaway instance.

Asserts the things a conformance failure would show up in first: discovery
advertises the shopping service, a session moves incomplete -> ready ->
completed, the shop's own totals add up, and completing twice with the same
idempotency key returns the same order rather than charging again.
"""

import json
import os
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("BASE_URL", "http://localhost:8099")
PROFILE = os.environ.get("PLATFORM_PROFILE", "http://localhost:8285/profile.json")

failures: list[str] = []


def check(label: str, condition: bool, detail: str = "") -> None:
    print(f"  {'ok  ' if condition else 'FAIL'} {label}{f' — {detail}' if detail else ''}")
    if not condition:
        failures.append(label)


def call(method: str, path: str, body: dict | None = None, idem: str | None = None) -> dict:
    data = json.dumps(body).encode() if body is not None else None
    request = urllib.request.Request(BASE + path, data=data, method=method)
    request.add_header("Content-Type", "application/json")
    request.add_header("UCP-Agent", f'profile="{PROFILE}"')
    if idem:
        request.add_header("Idempotency-Key", idem)
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.loads(response.read())


print("discovery")
profile = call("GET", "/.well-known/ucp")
services = profile.get("ucp", {}).get("services", {})
check("advertises dev.ucp.shopping", "dev.ucp.shopping" in services)
check("publishes signing keys", bool(profile.get("signing_keys")))

endpoint = services["dev.ucp.shopping"][0]["endpoint"].rstrip("/")
api = endpoint.replace(BASE, "")

print("catalog")
found = call("POST", f"{api}/catalog/lookup", {"ids": ["bouquet_roses"]})
products = found.get("products", [])
check("finds the seeded product", bool(products))
variant = products[0]["variants"][0]
check("price is integer minor units", isinstance(variant["price"]["amount"], int),
      f"{variant['price']['amount']} {variant['price']['currency']}")

print("checkout")
item = {"id": variant["id"], "title": variant.get("title"), "price": variant["price"]}
session = call("POST", f"{api}/checkout-sessions", {"line_items": [{"item": item, "quantity": 1}]},
               idem="conformance-create")
check("session starts incomplete", session["status"] == "incomplete", session["status"])

line_id = session["line_items"][0]["id"]
destination = {"id": "d1", "first_name": "Ada", "last_name": "Lovelace",
               "street_address": "1 Test Street", "address_locality": "San Francisco",
               "postal_code": "94103", "address_country": "US"}
updated = call("PUT", f"{api}/checkout-sessions/{session['id']}", {
    "line_items": [{"id": line_id, "item": item, "quantity": 1}],
    "buyer": {"email": "ada@example.test", "first_name": "Ada", "last_name": "Lovelace"},
    "fulfillment": {"methods": [{"id": "m1", "type": "shipping", "line_item_ids": [line_id],
                                 "destinations": [destination], "selected_destination_id": "d1"}]},
}, idem="conformance-destination")

method = updated["fulfillment"]["methods"][0]
group = method["groups"][0]
check("offers fulfillment options", bool(group.get("options")),
      ", ".join(o["title"] for o in group.get("options", [])))

ready = call("PUT", f"{api}/checkout-sessions/{session['id']}", {
    "line_items": [{"id": line_id, "item": item, "quantity": 1}],
    "fulfillment": {"methods": [{"id": method["id"], "type": "shipping",
                                 "line_item_ids": method["line_item_ids"],
                                 "destinations": method["destinations"],
                                 "selected_destination_id": method["selected_destination_id"],
                                 "groups": [{"id": group["id"], "line_item_ids": group["line_item_ids"],
                                             "selected_option_id": group["options"][0]["id"]}]}]},
}, idem="conformance-option")
check("session becomes ready_for_complete", ready["status"] == "ready_for_complete", ready["status"])

totals = {t["type"]: t["amount"] for t in ready["totals"]}
expected = totals.get("subtotal", 0) + totals.get("fulfillment", 0) + totals.get("tax", 0)
check("totals add up", totals.get("total") == expected,
      f"{totals.get('total')} vs {expected} ({totals})")

payment = {"payment": {"instruments": [{"id": "i1", "handler_id": "mock_payment_handler",
                                        "type": "card", "selected": True,
                                        "credential": {"type": "card", "number": "4242424242424242"}}]}}
completed = call("POST", f"{api}/checkout-sessions/{session['id']}/complete", payment,
                 idem="conformance-complete")
check("session completes", completed["status"] == "completed", completed["status"])
check("an order comes back", bool(completed.get("order", {}).get("id")),
      str(completed.get("order", {}).get("id")))

replay = call("POST", f"{api}/checkout-sessions/{session['id']}/complete", payment,
              idem="conformance-complete")
check("replaying the same key returns the same order",
      replay.get("order", {}).get("id") == completed.get("order", {}).get("id"))

print("refusals")
# A request with no platform profile must be refused, not served. This is the
# exact failure a deployment hits when STORE_ORIGIN is set but the agent's
# profile URL is not - it cost a live storefront outage, so it is worth a
# standing check.
try:
    anonymous = urllib.request.Request(BASE + f"{api}/catalog/search", method="POST",
                                       data=json.dumps({"query": "roses"}).encode())
    anonymous.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(anonymous, timeout=30) as response:
        body = json.loads(response.read())
    check("a request without UCP-Agent is refused", body.get("code") == "invalid_profile_url",
          str(body.get("code")))
except urllib.error.HTTPError as error:
    # A 4xx is an equally valid refusal; silently serving it would not be.
    check("a request without UCP-Agent is refused", error.code >= 400, f"HTTP {error.code}")

print()
if failures:
    print(f"{len(failures)} check(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("scripted checkout: all checks passed")
