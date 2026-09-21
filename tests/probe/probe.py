#!/usr/bin/env python3
"""Anonymous HTTP probe for guest price visibility.

This is the project's definition of done. It talks to a running WooCommerce
site as a plain anonymous client -- no cookies, no authentication, no nonce --
and inspects every surface WooCommerce serves a price on:

  * the shop/catalogue page
  * each product page (simple, variable, grouped)
  * the inline ``data-product_variations`` JSON on the variable product page
  * ``?wc-ajax=get_variation`` for the variable product
  * the JSON-LD block on each product page
  * Store API ``/wc/store/v1/products``, ``/products/{id}``, ``/products/collection-data``
    and the unversioned ``/wc/store/products/{id}`` alias
  * legacy REST ``/wc/v3/products``, ``/products/{id}``, ``/products/{id}/variations``
  * with a guest cart (a cookie jar filled through ``?wc-ajax=add_to_cart``):
    the add-to-cart fragments, the cart page, ``?wc-ajax=get_cart_totals``
    and the Mini Cart block's Interactivity API state on the home page
  * with a second guest cart built through the Store API (nonce, then
    ``cart/add-item``): the add-item response, ``/wc/store/v1/cart``,
    ``/cart/items``, the ``/wc/store/cart`` alias and a ``/batch``
    sub-response; then the payloads WooCommerce embeds into the cart and
    checkout pages (``createPreloadingMiddleware``, the product grids'
    ``JSON.parse( decodeURIComponent(...) )`` lists, the Interactivity API
    state), the ``woocommerce.cart`` state on the home page, and the
    ``woocommerce/products`` state on block-theme product pages
  * the empty-cart render of the cart page (no cookie jar), whose block
    version carries a product grid: no ``data-price`` attribute, no amount
  * the price inference channels: ``?min_price=&max_price=`` must not narrow
    the catalogue or the Store API products route for a guest, and
    ``?orderby=price`` must not reorder either; nor may WordPress core's
    ``/wp/v2/product`` route, which the Product Collection block's editor
    hook opens to ``priceRange`` and ``orderby=price`` for any request
    carrying ``isProductCollectionBlock=true``

Every HTML surface is also failed on a ``data-price="<digits>"`` attribute
(or any other ``data-*price*`` attribute holding a digit), which the product
grid blocks print on their add-to-cart buttons.

With ``--expect-purchasing blocked`` a second group of checks runs, covering
module 6: anonymous ``?wc-ajax=add_to_cart`` and the Store API
``/wc/store/v1/cart/add-item`` route (each must answer with the plugin's own
refusal -- ``{"error": true}`` and HTTP 403 ``pricecloak_login_required``
respectively -- not merely fail), the versioned and unversioned Store API
checkout routes, ``?wc-ajax=checkout`` with a session captured earlier (see
``--session-file``), the cart and checkout pages, and the add-to-cart
buttons in the catalogue. ``allowed`` (the default) asserts the opposite --
that a guest really can still buy -- which is what keeps the "master switch
on, purchasing off" run honest.

The one surface that needs state across runs is the classic checkout POST:
a guest who loaded a classic ``[woocommerce_checkout]`` page before
purchasing was blocked holds a valid process-checkout nonce. Pass
``--session-file PATH`` to the allowed run and the probe saves that guest's
cookies and nonce there (when the checkout page carries the classic form;
the Checkout block carries none); pass the same path to the blocked run and
``wc-ajax-checkout-blocked`` posts with them and requires the plugin's
refusal. Without it, or on a block checkout, that row is a documented SKIP.

Each surface is one named check reporting PASS, FAIL or SKIP with a reason.
A SKIP is a surface the probe could not judge. In the ``--expect visible``
run only the documented ones survive as SKIP -- the ``rest-v3-*`` rows a
stock WooCommerce answers 401 to, the Mini Cart and hydration rows on a
theme or template that has nothing to hydrate -- and every other SKIP is a
FAIL, so a probe
that has gone blind (a WAF, basic auth, a page cache serving a stub, a
missing seed product) cannot be green. ``--strict`` makes every SKIP a FAIL.

With ``--expect hidden`` a check fails on any numeric price field, any
``offers`` node in JSON-LD, or any price HTML fragment, and the replacement
text must appear where price HTML would be. With ``--expect visible`` the same
surfaces must *carry* prices -- that is the sanity check that the seed worked
and that the probe is looking in the right places.

Each surface names the module that owns it, and ``--modules-off <ids>`` says
which of those modules are switched off in the site's settings: their surfaces
are then expected to go on showing prices, so a deliberately disabled module
reads as a deliberate red row that still counts as a pass.

Exit status is 0 when every check matched the expectation, 1 otherwise.

Usage:
    python3 tests/probe/probe.py --base-url http://localhost:8888 \
        --seed tests/probe/seed.json --expect visible

Standard library only, on purpose: it has to run anywhere with Python 3.
"""

from __future__ import annotations

import argparse
import html
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass, field
from typing import Any, Iterator

DEFAULT_REPLACEMENT_TEXT = "Sign in to see prices"
DEFAULT_LOGIN_TO_BUY_TEXT = "Log in to buy"
TIMEOUT_SECONDS = 30

# Keys that carry a machine-readable amount on one surface or another.
PRICE_KEYS = frozenset(
    {
        "price",
        "regular_price",
        "sale_price",
        "display_price",
        "display_regular_price",
        "price_min",
        "price_max",
        "min_price",
        "max_price",
        "amount",
        "min_amount",
        "max_amount",
        "lowPrice",
        "highPrice",
    }
)

# Inside a Store API ``prices`` object every value is an amount except the
# currency formatting metadata.
PRICES_OBJECT_EXEMPT_PREFIX = "currency"

# WooCommerce's own price wrapper, present in every core template and block.
PRICE_MARKUP_RE = re.compile(r"woocommerce-Price-amount|woocommerce-Price-currencySymbol")
# Inline stylesheets mention Woo's price classes as selectors (block themes
# print the product-price block CSS on every page); those are not prices.
STYLE_BLOCK_RE = re.compile(r"<style\b[^>]*>.*?</style>", re.IGNORECASE | re.DOTALL)
# An attribute named after a price holding a digit: the product grid blocks'
# add-to-cart buttons carry data-price="123.45" (wc_get_price_to_display).
PRICE_ATTRIBUTE_RE = re.compile(r'\bdata-[a-z_-]*price[a-z_-]*="[^"]*\d[^"]*"', re.IGNORECASE)
# The Mini Cart block's wrapper element (MiniCart.php, WooCommerce 11.1):
# both class names on every render path. Matched on an element's class
# attribute, not anywhere in the page, since the plugin's own inline
# stylesheet names .wc-block-mini-cart__drawer as a selector.
MINI_CART_ELEMENT_RE = re.compile(
    r'class="[^"]*\b(?:wc-block-mini-cart|wp-block-woocommerce-mini-cart)\b[^"]*"', re.IGNORECASE
)

NUMERIC_STRING_RE = re.compile(r"^\s*-?\d+(?:[.,]\d+)?\s*$")

JSON_LD_RE = re.compile(
    r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
    re.DOTALL | re.IGNORECASE,
)

VARIATIONS_ATTR_RE = re.compile(r'data-product_variations=(["\'])(.*?)\1', re.DOTALL)

# The Interactivity API prints every registered store's state into one JSON
# script; WordPress 6.5-6.8 use the wp-interactivity-data id, 6.9+ the
# script-module data id.
INTERACTIVITY_STATE_RE = re.compile(
    r'<script[^>]+id=["\'](?:wp-interactivity-data|wp-script-module-data-@wordpress/interactivity)["\']'
    r'[^>]*>(.*?)</script>',
    re.DOTALL | re.IGNORECASE,
)

# A currency symbol may sit before or after the digits, separated by nothing,
# whitespace or a non-breaking space (entity-encoded or literal), depending on
# WooCommerce's currency position setting.
CURRENCY_GAP = r"(?:\s|&nbsp;|\u00a0)*"
DIGITS = r"\d[\d ,.]*"

# Which module owns which surface. Longest-matching prefix wins, so the
# purchasing prefixes are listed before the catalogue ones they overlap with.
# A surface whose module is switched off is expected to leak: that is what
# makes a deliberately disabled module read as a red row that is still a pass.
#
# The split between store_api and price_filters on the Store API follows the
# modules: store_api owns what a response *carries* (amounts, on the products,
# cart, checkout, order and batch routes and wherever WooCommerce hydrates
# those responses into a page), price_filters owns the price-shaped
# *question* (the range parameters and price ordering, on the shop and on the
# Store API alike). The Store API checkout refusals are purchasing's.
SURFACE_MODULES: tuple[tuple[str, str], ...] = (
    ("add-to-cart-", "purchasing"),
    ("cart-page", "purchasing"),
    ("cart-page-totals", "price_html"),
    ("cart-page-empty-grid", "price_html"),
    ("cart-page-hydration", "store_api"),
    ("cart-fragments", "price_html"),
    ("mini-cart-state", "price_html"),
    ("mini-cart-hydration", "store_api"),
    ("shop-price-bisection", "price_filters"),
    ("shop-orderby-price", "price_filters"),
    ("checkout-page", "purchasing"),
    ("checkout-page-hydration", "store_api"),
    ("shop-page", "price_html"),
    ("product-page-", "price_html"),
    ("product-page-hydration", "store_api"),
    ("json-ld-", "structured_data"),
    ("inline-variations-json", "variation_payload"),
    ("wc-ajax-get-variation", "variation_payload"),
    ("wc-ajax-checkout", "purchasing"),
    ("store-api-", "store_api"),
    ("store-api-checkout", "purchasing"),
    ("store-api-price-params", "price_filters"),
    ("store-api-orderby-price", "price_filters"),
    ("wp-v2-", "price_filters"),
    ("rest-v3-", "legacy_rest"),
)

# The plugin's own refusal code on the Store API, so a WooCommerce nonce
# error, a WAF or a cached page cannot pass for it.
PLUGIN_REFUSAL_CODE = "pricecloak_login_required"

MODULE_IDS = sorted({module for _, module in SURFACE_MODULES})


def module_for(name: str) -> str:
    """The module id that owns a named surface."""
    for prefix, module in sorted(SURFACE_MODULES, key=lambda pair: -len(pair[0])):
        if name.startswith(prefix):
            return module
    return "-"


# --------------------------------------------------------------------------
# HTTP
# --------------------------------------------------------------------------


@dataclass
class Response:
    """What came back, including error statuses -- 401 is data, not a crash."""

    url: str
    status: int
    body: str
    location: str = ""
    headers: dict[str, str] = field(default_factory=dict)

    def json(self) -> Any:
        return json.loads(self.body)


def lower_headers(headers: Any) -> dict[str, str]:
    """Response headers keyed by lower-cased name."""
    return {str(key).lower(): str(value) for key, value in headers.items()}


class _NoRedirects(urllib.request.HTTPRedirectHandler):
    """Turns a redirect into an HTTPError so the probe can read its Location."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D102
        return None


class AnonymousClient:
    """An HTTP client that deliberately keeps no state between requests."""

    def __init__(self, base_url: str, cache_bust: bool = False) -> None:
        self.base_url = base_url.rstrip("/")
        # With cache_bust, every URL gets a unique query argument so a page
        # cache in front of the site (nginx, a caching plugin, a CDN) cannot
        # answer from a copy stored before the settings changed.
        self.cache_bust = cache_bust
        # No cookie processor and no redirect-preserving auth: every request
        # must look like a first-time visitor.
        self._opener = urllib.request.build_opener()

    def url_for(self, path: str) -> str:
        if path.startswith("http://") or path.startswith("https://"):
            url = path
        else:
            url = self.base_url + "/" + path.lstrip("/")
        if self.cache_bust:
            joiner = "&" if "?" in url else "?"
            url += joiner + "pricecloak_nocache=" + uuid.uuid4().hex
        return url

    def get(self, path: str) -> Response:
        return self._send(urllib.request.Request(self.url_for(path), method="GET"))

    def get_without_redirects(self, path: str) -> Response:
        """A GET that reports the 302 rather than following it."""
        opener = urllib.request.build_opener(_NoRedirects)
        request = urllib.request.Request(self.url_for(path), method="GET")
        request.add_header("User-Agent", "pricecloak-probe/1.0")
        try:
            with opener.open(request, timeout=TIMEOUT_SECONDS) as handle:
                body = handle.read().decode("utf-8", errors="replace")
                return Response(handle.geturl(), handle.status, body)
        except urllib.error.HTTPError as error:
            body = error.read().decode("utf-8", errors="replace")
            return Response(
                request.full_url,
                error.code,
                body,
                error.headers.get("Location", "") or "",
            )

    def post_json(
        self, path: str, payload: Any, headers: dict[str, str] | None = None
    ) -> Response:
        data = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            self.url_for(path),
            data=data,
            method="POST",
            headers={"Content-Type": "application/json", **(headers or {})},
        )
        return self._send(request)

    def store_api_nonce(self) -> str:
        """The Store API hands every client, guest included, a nonce on the cart
        route; its write routes then require it. Fetching it here is what a
        shopper's browser does, so the add-item check exercises the real path
        rather than bouncing off nonce validation. Header names are matched
        case-insensitively: a proxy may lower-case them."""
        response = self.get("/wp-json/wc/store/v1/cart")
        for header in ("nonce", "x-wp-nonce", "x-wc-store-api-nonce"):
            value = response.headers.get(header)
            if value:
                return value
        return ""

    def post_form(self, path: str, fields: dict[str, str]) -> Response:
        data = urllib.parse.urlencode(fields).encode("utf-8")
        request = urllib.request.Request(
            self.url_for(path),
            data=data,
            method="POST",
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )
        return self._send(request)

    def _send(self, request: urllib.request.Request) -> Response:
        request.add_header("User-Agent", "pricecloak-probe/1.0")
        request.add_header("Accept", "*/*")
        try:
            with self._opener.open(request, timeout=TIMEOUT_SECONDS) as handle:
                body = handle.read().decode("utf-8", errors="replace")
                return Response(
                    handle.geturl(), handle.status, body, headers=lower_headers(handle.headers)
                )
        except urllib.error.HTTPError as error:
            body = error.read().decode("utf-8", errors="replace")
            return Response(
                request.full_url, error.code, body, headers=lower_headers(error.headers)
            )


class CartClient(AnonymousClient):
    """A guest with a cart: the same first-time visitor, but keeping the
    session cookie WooCommerce hands out on the first add-to-cart, so the
    cart page, the totals endpoint and the Mini Cart block have something to
    show. Still no login and no nonce."""

    def __init__(self, base_url: str, cache_bust: bool = False) -> None:
        super().__init__(base_url, cache_bust)
        self.jar = http.cookiejar.CookieJar()
        self._opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar)
        )

    def has_session(self) -> bool:
        return any(cookie.name.startswith("wp_woocommerce_session_") for cookie in self.jar)

    def export_cookies(self) -> list[dict[str, Any]]:
        return [
            {"name": c.name, "value": c.value, "domain": c.domain, "path": c.path}
            for c in self.jar
        ]

    def import_cookies(self, cookies: list[dict[str, Any]]) -> None:
        for c in cookies:
            self.jar.set_cookie(
                http.cookiejar.Cookie(
                    0, c["name"], c["value"], None, False, c["domain"], bool(c["domain"]), c["domain"].startswith("."),
                    c["path"], bool(c["path"]), False, None, False, None, None, {},
                )
            )


# --------------------------------------------------------------------------
# Leak detection
# --------------------------------------------------------------------------


def looks_numeric(value: Any) -> bool:
    """True for something a customer could read as an amount.

    A bare zero is not one: it is what the plugin leaves in the few fields
    the Cart and Checkout blocks parse as integers (``raw_prices`` and a
    line item's ``line_*`` totals), because Dinero refuses anything else,
    and it says nothing about the price. A shop's genuinely free product
    would read the same way, which the visible sanity run does not depend
    on, since the seed's products are all priced.
    """
    if isinstance(value, bool) or value is None:
        return False
    if isinstance(value, (int, float)):
        return value != 0
    if isinstance(value, str):
        if not value.strip() or not NUMERIC_STRING_RE.match(value):
            return False
        return float(value.replace(",", ".")) != 0
    return False


def numeric_price_fields(node: Any, path: str = "$", parent_key: str = "") -> Iterator[str]:
    """Yield dotted paths of every numeric price-ish field found in JSON."""
    if isinstance(node, dict):
        for key, value in node.items():
            child_path = f"{path}.{key}"
            in_prices_object = parent_key == "prices" or path.endswith(".prices")
            is_amount_key = key in PRICE_KEYS or (
                in_prices_object and not key.startswith(PRICES_OBJECT_EXEMPT_PREFIX)
            )
            if is_amount_key and looks_numeric(value):
                yield f"{child_path}={value!r}"
            yield from numeric_price_fields(value, child_path, key)
    elif isinstance(node, list):
        for index, item in enumerate(node):
            yield from numeric_price_fields(item, f"{path}[{index}]", parent_key)


def price_html_fragments(document: str, currency_symbol: str) -> list[str]:
    """Rendered price fragments: Woo's own markup, a price-named attribute
    holding a digit, or a symbol next to digits."""
    found: list[str] = []
    document = STYLE_BLOCK_RE.sub("", document)
    match = PRICE_MARKUP_RE.search(document)
    if match:
        found.append(f"price markup {match.group(0)!r}")
    match = PRICE_ATTRIBUTE_RE.search(document)
    if match:
        found.append(f"price attribute {match.group(0)!r}")
    if currency_symbol:
        symbol_match = currency_amount_pattern(currency_symbol).search(html.unescape(document))
        if symbol_match:
            found.append(f"currency next to digits {symbol_match.group(0).strip()!r}")
    return found


def currency_amount_pattern(currency_symbol: str) -> re.Pattern[str]:
    """Symbol-then-digits or digits-then-symbol, whichever the shop prints."""
    sym = re.escape(currency_symbol)
    # A letter symbol (R, kr, CHF) must stand alone: "3 results" is not "3 R".
    if currency_symbol.isalpha():
        sym = r"(?<![A-Za-z])" + sym + r"(?![A-Za-z])"
    # Digits-then-symbol must not be a sprintf placeholder: WooCommerce prints
    # its price format ("%1$s%2$s") into the page's settings JSON.
    trailing = r"(?<!%)" + DIGITS + CURRENCY_GAP + sym + r"(?![A-Za-z])"
    return re.compile(r"(?:" + sym + CURRENCY_GAP + DIGITS + r"|" + trailing + r")")


def carries_amount(text: str, currency_symbol: str) -> bool:
    """Whether a short formatted string reads as an amount: a digit next to
    the currency symbol, or -- with no symbol to look for -- any digit at all."""
    text = html.unescape(text)
    if currency_symbol:
        return bool(currency_amount_pattern(currency_symbol).search(text))
    return bool(re.search(r"\d", text))


def mini_cart_block_present(document: str) -> bool:
    """Whether the page carries a Mini Cart block element (not merely a
    stylesheet naming its classes)."""
    return MINI_CART_ELEMENT_RE.search(STYLE_BLOCK_RE.sub("", document)) is not None


def interactivity_state(document: str) -> dict[str, Any] | None:
    """The Interactivity API state printed on a page, or None when there is none."""
    match = INTERACTIVITY_STATE_RE.search(document)
    if not match:
        return None
    raw = match.group(1).strip()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        try:
            data = json.loads(html.unescape(raw))
        except json.JSONDecodeError:
            return None
    if isinstance(data, dict):
        state = data.get("state", data)
        return state if isinstance(state, dict) else None
    return None


# The two ways WooCommerce embeds a Store API payload into a page besides the
# Interactivity API state: `wp.apiFetch.createPreloadingMiddleware( JSON.parse(
# decodeURIComponent( '...' ) ) )` for the Cart, Checkout and All Products
# blocks' preloaded requests, and the product grids' `JSON.parse(
# decodeURIComponent( "..." ) )` product list for their render hook.
ENCODED_PAYLOAD_RE = re.compile(r"""decodeURIComponent\(\s*(["'])([A-Za-z0-9%._~\\-]*)\1\s*\)""")


def embedded_payloads(document: str) -> list[tuple[str, Any]]:
    """Every JSON payload a page embeds for its scripts: (label, parsed)."""
    found: list[tuple[str, Any]] = []
    for index, match in enumerate(ENCODED_PAYLOAD_RE.finditer(document)):
        raw = urllib.parse.unquote(match.group(2).replace("\\", ""))
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            continue
        label = "preload" if "createPreloadingMiddleware" in document[max(0, match.start() - 120):match.start()] else "inline-json"
        found.append((f"{label}[{index}]", data))
    state = interactivity_state(document)
    if state is not None:
        found.append(("interactivity-state", state))
    return found


def product_order(document: str, paths: list[str]) -> list[str]:
    """The given product paths in the order they first appear on a page."""
    positions = [(document.find(path), path) for path in paths]
    return [path for position, path in sorted(positions) if position >= 0]


def json_ld_documents(document: str) -> list[Any]:
    """Every parseable JSON-LD block on a page."""
    blocks: list[Any] = []
    for raw in JSON_LD_RE.findall(document):
        try:
            blocks.append(json.loads(raw.strip()))
        except json.JSONDecodeError:
            continue
    return blocks


def offers_nodes(node: Any, path: str = "$") -> Iterator[str]:
    """Yield the path of every ``offers`` node, however deeply nested."""
    if isinstance(node, dict):
        for key, value in node.items():
            child_path = f"{path}.{key}"
            if key == "offers" and value not in ({}, [], None, ""):
                yield child_path
            yield from offers_nodes(value, child_path)
    elif isinstance(node, list):
        for index, item in enumerate(node):
            yield from offers_nodes(item, f"{path}[{index}]")


# --------------------------------------------------------------------------
# Results
# --------------------------------------------------------------------------

PASS = "PASS"
FAIL = "FAIL"
SKIP = "SKIP"


@dataclass
class Result:
    name: str
    status: str
    detail: str
    module: str = "-"


@dataclass
class Report:
    """The rows, and the rule for what a SKIP is worth.

    A SKIP means the probe could not judge a surface. Two kinds exist. An
    *absent* surface is one the site verifiably does not have -- the legacy
    REST routes answering 401 to a guest, a theme with no Mini Cart block --
    so there is nothing there to leak. A *blind* SKIP is everything else: a
    page behind a WAF or basic auth, a missing JSON-LD block, an add-to-cart
    response without fragments, a seed without a product. In the sanity run
    (--expect visible, the one that proves the probe looks in the right
    places) a blind SKIP is a FAIL, otherwise a later hidden run could be
    green without judging anything. --strict makes every SKIP a FAIL.
    """

    fail_blind_skips: bool = False
    fail_all_skips: bool = False
    results: list[Result] = field(default_factory=list)

    def add(self, name: str, status: str, detail: str, absent: bool = False) -> None:
        if status == SKIP and (self.fail_all_skips or (self.fail_blind_skips and not absent)):
            status = FAIL
            why = "--strict" if self.fail_all_skips else "sanity run"
            detail = f"SKIP counts as a failure ({why}, surface not judged): {detail}"
        self.results.append(Result(name, status, detail, module_for(name)))

    def ok(self) -> bool:
        return all(result.status != FAIL for result in self.results)

    def render(self, modules_off: frozenset[str] = frozenset()) -> str:
        width = max((len(r.name) for r in self.results), default=10)
        mwidth = max(
            [len(r.module) for r in self.results]
            + [len("MODULE"), *(len(m) + len(" (off)") for m in modules_off)]
        )
        header = f"{'SURFACE'.ljust(width)}  {'MODULE'.ljust(mwidth)}  STATUS  DETAIL"
        lines = [header, "-" * (width + mwidth + 42)]
        for result in self.results:
            module = result.module
            if module in modules_off:
                module += " (off)"
            lines.append(
                f"{result.name.ljust(width)}  {module.ljust(mwidth)}  "
                f"{result.status:<6}  {result.detail}"
            )
        counts = {status: 0 for status in (PASS, FAIL, SKIP)}
        for result in self.results:
            counts[result.status] += 1
        lines.append("-" * (width + mwidth + 42))
        summary = f"{counts[PASS]} passed, {counts[FAIL]} failed, {counts[SKIP]} skipped"
        if modules_off:
            summary += (
                "; modules off: "
                + ", ".join(sorted(modules_off))
                + " (their surfaces are expected to show prices)"
            )
        lines.append(summary)
        return "\n".join(lines)


# --------------------------------------------------------------------------
# The probe
# --------------------------------------------------------------------------


class Probe:
    """One run against one site, in one expectation."""

    def __init__(
        self,
        client: AnonymousClient,
        seed: dict[str, Any],
        expect_hidden: bool,
        replacement_text: str,
        expect_purchasing_blocked: bool = False,
        login_to_buy_text: str = DEFAULT_LOGIN_TO_BUY_TEXT,
        modules_off: frozenset[str] = frozenset(),
        strict: bool = False,
        session_file: str = "",
    ) -> None:
        self.client = client
        self.seed = seed
        self.expect_hidden = expect_hidden
        self.replacement_text = replacement_text
        self.modules_off = modules_off
        self.session_file = session_file
        # A disabled Purchasing module means a guest can buy again, whatever
        # --expect-purchasing said.
        self.expect_purchasing_blocked = (
            expect_purchasing_blocked and "purchasing" not in modules_off
        )
        self.login_to_buy_text = login_to_buy_text
        self.currency_symbol = seed.get("currency_symbol", "")
        # The visible run is the sanity run: a surface it cannot judge is a
        # probe that has gone blind, not a site that is safe.
        self.report = Report(fail_blind_skips=not expect_hidden, fail_all_skips=strict)

    # -- helpers ----------------------------------------------------------

    def hidden_for(self, name: str) -> bool:
        """Whether *this* surface must be price-free.

        The run-wide expectation, minus the surfaces owned by a module the
        operator has switched off: those are expected to go on showing prices,
        so a deliberately disabled module reads as a red row that is still a
        pass rather than an unexplained failure.
        """
        return self.expect_hidden and module_for(name) not in self.modules_off

    def judge_json(self, name: str, payload: Any, source: str) -> None:
        """A JSON surface passes when its price fields match the expectation."""
        leaks = list(numeric_price_fields(payload))
        if self.hidden_for(name):
            if leaks:
                self.report.add(name, FAIL, f"{source}: numeric price {'; '.join(leaks[:4])}")
            else:
                self.report.add(name, PASS, f"{source}: no numeric price fields")
        else:
            if leaks:
                self.report.add(name, PASS, f"{source}: prices present ({leaks[0]})")
            else:
                self.report.add(
                    name, FAIL, f"{source}: expected prices but found none (seed problem?)"
                )

    def judge_html(self, name: str, document: str, source: str) -> None:
        """An HTML surface: no price markup, and the replacement text instead."""
        fragments = price_html_fragments(document, self.currency_symbol)
        if self.hidden_for(name):
            problems = list(fragments)
            if self.replacement_text and self.replacement_text not in html.unescape(document):
                problems.append(f"replacement text {self.replacement_text!r} absent")
            if problems:
                self.report.add(name, FAIL, f"{source}: " + "; ".join(problems[:3]))
            else:
                self.report.add(name, PASS, f"{source}: no price HTML, replacement text present")
        else:
            if fragments:
                self.report.add(name, PASS, f"{source}: {fragments[0]}")
            else:
                self.report.add(
                    name, FAIL, f"{source}: expected price HTML but found none (seed problem?)"
                )

    def judge_payloads(self, name: str, document: str, source: str, only: str | None = None) -> bool:
        """Every payload a page embeds must match the expectation. With
        ``only`` set, just that Interactivity API namespace is judged. Returns
        False when there was nothing to judge (the caller reports the SKIP)."""
        payloads = embedded_payloads(document)
        if only is not None:
            payloads = [
                (f"interactivity-state[{only}]", state.get(only))
                for label, state in payloads
                if label == "interactivity-state" and isinstance(state, dict) and only in state
            ]
        if not payloads:
            return False
        leaks = [f"{label}: {leak}" for label, data in payloads for leak in numeric_price_fields(data)]
        if self.hidden_for(name):
            if leaks:
                self.report.add(name, FAIL, f"{source}: numeric price " + "; ".join(leaks[:4]))
            else:
                self.report.add(name, PASS, f"{source}: {len(payloads)} embedded payload(s), no numeric price field")
        else:
            if leaks:
                self.report.add(name, PASS, f"{source}: prices present ({leaks[0]})")
            else:
                self.report.add(name, FAIL, f"{source}: expected prices in the embedded payloads but found none (seed problem?)")
        return True

    def fetch_or_skip(self, name: str, path: str) -> Response | None:
        response = self.client.get(path)
        if response.status in (401, 403):
            self.report.add(
                name,
                SKIP,
                f"HTTP {response.status} anonymously; surface not reachable by guests",
            )
            return None
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status} for {path}")
            return None
        return response

    # -- surfaces ---------------------------------------------------------

    def check_shop_page(self) -> None:
        name = "shop-page"
        response = self.fetch_or_skip(name, self.seed.get("shop_path", "/shop/"))
        if response is not None:
            self.judge_html(name, response.body, "catalogue")

    def check_product_pages(self) -> dict[str, str]:
        """Fetch each product page; return the bodies for the JSON-LD checks."""
        bodies: dict[str, str] = {}
        for kind in ("simple", "variable", "grouped"):
            product = self.seed.get(kind)
            if not product:
                self.report.add(f"product-page-{kind}", SKIP, "not in seed")
                continue
            name = f"product-page-{kind}"
            response = self.fetch_or_skip(name, product["path"])
            if response is None:
                continue
            bodies[kind] = response.body
            self.judge_html(name, response.body, product["slug"])
        return bodies

    def check_json_ld(self, bodies: dict[str, str]) -> None:
        for kind, body in bodies.items():
            name = f"json-ld-{kind}"
            blocks = json_ld_documents(body)
            if not blocks:
                self.report.add(name, SKIP, "no JSON-LD block on the page")
                continue
            offers = [path for block in blocks for path in offers_nodes(block)]
            leaks = [leak for block in blocks for leak in numeric_price_fields(block)]
            if self.hidden_for(name):
                problems = offers + leaks
                if problems:
                    self.report.add(name, FAIL, "offers/price in JSON-LD: " + "; ".join(problems[:4]))
                else:
                    self.report.add(name, PASS, "no offers node, no numeric price")
            else:
                if offers or leaks:
                    self.report.add(name, PASS, f"offers present ({(offers + leaks)[0]})")
                else:
                    self.report.add(name, FAIL, "expected an offers node but found none")

    def check_inline_variations(self, bodies: dict[str, str]) -> None:
        name = "inline-variations-json"
        body = bodies.get("variable")
        if body is None:
            self.report.add(name, SKIP, "variable product page not fetched")
            return
        match = VARIATIONS_ATTR_RE.search(body)
        if not match:
            self.report.add(name, SKIP, "no data-product_variations attribute on the page")
            return
        raw = html.unescape(match.group(2))
        if raw.strip() in ("false", ""):
            self.report.add(name, SKIP, "variations served via AJAX only (attribute is false)")
            return
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError as error:
            self.report.add(name, FAIL, f"data-product_variations is not JSON: {error}")
            return
        self.judge_json(name, payload, "data-product_variations")

    def check_wc_ajax_get_variation(self) -> None:
        name = "wc-ajax-get-variation"
        variable = self.seed.get("variable")
        if not variable or not variable.get("variations"):
            self.report.add(name, SKIP, "no variable product in seed")
            return
        first = variable["variations"][0]
        fields = {"product_id": str(variable["id"])}
        fields.update({key: str(value) for key, value in first["attributes"].items()})
        response = self.client.post_form("/?wc-ajax=get_variation", fields)
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status}")
            return
        try:
            payload = response.json()
        except json.JSONDecodeError:
            self.report.add(name, FAIL, f"response was not JSON: {response.body[:120]!r}")
            return
        if payload in (False, None, ""):
            self.report.add(name, SKIP, "WooCommerce matched no variation for those attributes")
            return
        self.judge_json(name, payload, f"variation {first['id']}")
        # price_html rides along in the same payload and is HTML, not a number.
        if isinstance(payload, dict) and "price_html" in payload:
            self.judge_html(f"{name}-price-html", payload["price_html"] or "", "price_html")

    def check_store_api(self) -> None:
        products = self.seed.get("simple", {})
        surfaces = [
            ("store-api-products", "/wp-json/wc/store/v1/products"),
            (
                "store-api-product",
                f"/wp-json/wc/store/v1/products/{products.get('id', 0)}",
            ),
            (
                "store-api-collection-data",
                "/wp-json/wc/store/v1/products/collection-data"
                "?calculate_price_range=true&calculate_attribute_counts=",
            ),
            # WooCommerce registers every v1 route under the bare wc/store
            # namespace as well; a module that matches only /v1/ misses it.
            (
                "store-api-products-alias",
                f"/wp-json/wc/store/products/{products.get('id', 0)}",
            ),
        ]
        for name, path in surfaces:
            response = self.fetch_or_skip(name, path)
            if response is None:
                continue
            try:
                payload = response.json()
            except json.JSONDecodeError:
                self.report.add(name, FAIL, "response was not JSON")
                continue
            self.judge_json(name, payload, path)

    def check_legacy_rest(self) -> None:
        simple = self.seed.get("simple", {})
        variable = self.seed.get("variable", {})
        surfaces = [
            ("rest-v3-products", "/wp-json/wc/v3/products"),
            ("rest-v3-product", f"/wp-json/wc/v3/products/{simple.get('id', 0)}"),
            (
                "rest-v3-variations",
                f"/wp-json/wc/v3/products/{variable.get('id', 0)}/variations",
            ),
        ]
        for name, path in surfaces:
            response = self.client.get(path)
            if response.status in (401, 403):
                # The expected, and perfectly acceptable, outcome: the legacy
                # REST API refuses anonymous reads, so it leaks nothing.
                self.report.add(
                    name,
                    SKIP,
                    f"HTTP {response.status} anonymously (no guest access, nothing to leak)",
                    absent=True,
                )
                continue
            if response.status >= 400:
                self.report.add(name, FAIL, f"HTTP {response.status}")
                continue
            try:
                payload = response.json()
            except json.JSONDecodeError:
                self.report.add(name, FAIL, "response was not JSON")
                continue
            self.judge_json(name, payload, path)

    # -- purchasing (module 6) --------------------------------------------

    def check_wc_ajax_add_to_cart(self) -> None:
        """The classic AJAX add-to-cart path, exactly as the shop page uses it."""
        name = "add-to-cart-wc-ajax"
        simple = self.seed.get("simple")
        if not simple:
            self.report.add(name, SKIP, "no simple product in seed")
            return
        response = self.client.post_form(
            f"/?wc-ajax=add_to_cart&product_id={simple['id']}",
            {"product_id": str(simple["id"]), "quantity": "1"},
        )
        if response.status >= 500:
            self.report.add(name, FAIL, f"HTTP {response.status}")
            return
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = None
        if not isinstance(payload, dict):
            # A cached page, a WAF interstitial or an unrouted wc-ajax is not
            # a refusal; it is a surface the probe could not judge.
            self.report.add(name, FAIL, f"response was not JSON (HTTP {response.status}): {response.body[:120]!r}")
            return
        # WooCommerce answers a refused add with {"error": true, ...} plus the
        # notice HTML, and a successful one with fragments and a cart hash.
        refused = payload.get("error") is True
        added = not refused and ("fragments" in payload or "cart_hash" in payload)
        if self.expect_purchasing_blocked:
            if added:
                self.report.add(name, FAIL, "guest add-to-cart succeeded")
            elif refused:
                self.report.add(name, PASS, "guest add-to-cart refused ({\"error\": true})")
            else:
                self.report.add(name, FAIL, f"neither refused nor added: {response.body[:120]!r}")
        else:
            if added:
                self.report.add(name, PASS, "guest add-to-cart succeeded")
            else:
                self.report.add(
                    name, FAIL, f"expected a successful add, got {response.body[:120]!r}"
                )

    def check_store_api_add_item(self) -> None:
        """The blocks' add-to-cart route."""
        name = "add-to-cart-store-api"
        simple = self.seed.get("simple")
        if not simple:
            self.report.add(name, SKIP, "no simple product in seed")
            return
        nonce = self.client.store_api_nonce()
        response = self.client.post_json(
            "/wp-json/wc/store/v1/cart/add-item",
            {"id": simple["id"], "quantity": 1},
            {"Nonce": nonce} if nonce else {},
        )
        if self.expect_purchasing_blocked:
            self.judge_plugin_refusal(name, response)
        else:
            if response.status in (200, 201):
                self.report.add(name, PASS, f"HTTP {response.status}, item added")
            else:
                self.report.add(
                    name,
                    FAIL,
                    f"expected 200/201, got HTTP {response.status}: {response.body[:120]!r}",
                )

    def judge_plugin_refusal(self, name: str, response: Response) -> None:
        """A blocked Store API write must carry the plugin's own 403, not any
        4xx: WooCommerce's nonce errors are 401/403 too, and would otherwise
        pass for a refusal the plugin never made."""
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = None
        code = payload.get("code") if isinstance(payload, dict) else None
        if response.status == 403 and code == PLUGIN_REFUSAL_CODE:
            self.report.add(name, PASS, f"HTTP 403 {PLUGIN_REFUSAL_CODE} for a guest")
        else:
            self.report.add(
                name, FAIL, f"expected HTTP 403 {PLUGIN_REFUSAL_CODE}, got HTTP {response.status} {code!r}: {response.body[:100]!r}"
            )

    def checkout_body(self) -> dict[str, Any]:
        """A minimal Store API checkout request. With purchasing blocked the
        plugin refuses it before it is read; with purchasing allowed the
        anonymous client has no cart, so WooCommerce refuses it for that and
        no order is ever created either way."""
        address = {
            "first_name": "Probe", "last_name": "Guest", "address_1": "1 Probe Street",
            "city": "Probe", "state": "", "postcode": "00000", "country": "US", "email": "probe-guest@example.com", "phone": "0000000000",
        }
        return {"billing_address": address, "shipping_address": address, "payment_method": "cod"}

    def check_store_api_checkout(self) -> None:
        """The Store API checkout POST, versioned and on the unversioned alias.
        Blocked: the plugin's 403. Allowed: whatever WooCommerce says, as long
        as it is not the plugin's refusal."""
        nonce = self.client.store_api_nonce()
        headers = {"Nonce": nonce} if nonce else {}
        for name, path in (
            ("store-api-checkout-blocked", "/wp-json/wc/store/v1/checkout"),
            ("store-api-checkout-alias-blocked", "/wp-json/wc/store/checkout"),
        ):
            response = self.client.post_json(path, self.checkout_body(), headers)
            if self.expect_purchasing_blocked:
                self.judge_plugin_refusal(name, response)
                continue
            try:
                payload = response.json()
            except json.JSONDecodeError:
                payload = None
            code = payload.get("code") if isinstance(payload, dict) else None
            if code == PLUGIN_REFUSAL_CODE:
                self.report.add(name, FAIL, f"refused by the plugin although purchasing is allowed (HTTP {response.status})")
            elif response.status >= 500:
                self.report.add(name, FAIL, f"HTTP {response.status}: {response.body[:100]!r}")
            else:
                self.report.add(name, PASS, f"not refused by the plugin (HTTP {response.status} {code or 'ok'})")

    def check_wc_ajax_checkout(self) -> None:
        """``?wc-ajax=checkout`` with a session captured while purchasing was
        allowed (see --session-file): the guest holds a real cart and a real
        process-checkout nonce, and the plugin must still refuse the order."""
        name = "wc-ajax-checkout-blocked"
        if not self.expect_purchasing_blocked:
            return
        session = self.load_session()
        if session is None:
            page = self.client.get_without_redirects(self.seed.get("checkout_path", "/checkout/"))
            if page.status in (301, 302, 303, 307, 308):
                reason = "checkout page redirects, so no classic form to take a nonce from"
            elif "woocommerce-process-checkout-nonce" in page.body:
                reason = "checkout form present but no cart to order with"
            else:
                reason = "checkout page carries no classic form (Checkout block)"
            self.report.add(name, SKIP, f"{reason}; run the allowed pass with --session-file first", absent=True)
            return
        client = CartClient(self.client.base_url, self.client.cache_bust)
        client.import_cookies(session["cookies"])
        fields = {
            "woocommerce-process-checkout-nonce": session["nonce"],
            "billing_first_name": "Probe", "billing_last_name": "Guest", "billing_country": "US",
            "billing_address_1": "1 Probe Street", "billing_city": "Probe", "billing_state": "", "billing_postcode": "00000",
            "billing_phone": "0000000000", "billing_email": "probe-guest@example.com",
            "payment_method": "cod", "terms": "on", "terms-field": "1",
        }
        response = client.post_form("/?wc-ajax=checkout", fields)
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = None
        if not isinstance(payload, dict):
            self.report.add(name, FAIL, f"response was not JSON (HTTP {response.status}): {response.body[:120]!r}")
            return
        messages = html.unescape(str(payload.get("messages", "")))
        if payload.get("result") == "failure" and "log in" in messages.lower() and "order" not in str(payload.get("redirect", "")):
            self.report.add(name, PASS, "guest checkout refused with the login notice")
        elif payload.get("result") == "success":
            self.report.add(name, FAIL, f"guest order created: {payload.get('redirect', '')}")
        else:
            self.report.add(name, FAIL, f"failed for another reason: {messages[:120]!r}")

    def load_session(self) -> dict[str, Any] | None:
        if not self.session_file:
            return None
        try:
            with open(self.session_file, encoding="utf-8") as handle:
                data = json.load(handle)
        except (OSError, json.JSONDecodeError):
            return None
        if isinstance(data, dict) and data.get("nonce") and data.get("cookies"):
            return data
        return None

    def save_session(self, client: CartClient) -> None:
        """With buying allowed and --session-file given: capture the guest's
        cookies and, when the checkout page carries the classic form, its
        process-checkout nonce, for the blocked run's wc-ajax-checkout row."""
        if not self.session_file or self.expect_purchasing_blocked:
            return
        page = client.get(self.seed.get("checkout_path", "/checkout/"))
        match = re.search(r'id="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"', page.body) or re.search(
            r'name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"', page.body
        )
        if not match:
            return
        with open(self.session_file, "w", encoding="utf-8") as handle:
            json.dump({"nonce": match.group(1), "cookies": client.export_cookies()}, handle)

    def check_cart_and_checkout_pages(self) -> None:
        """Cart and checkout: a guest is either shopping or being sent to log in."""
        for name, path in (
            ("cart-page", self.seed.get("cart_path", "/cart/")),
            ("checkout-page", self.seed.get("checkout_path", "/checkout/")),
        ):
            response = self.client.get_without_redirects(path)
            location = response.location or ""
            if self.expect_purchasing_blocked:
                if response.status in (301, 302, 303, 307, 308) and "my-account" in location:
                    self.report.add(name, PASS, f"HTTP {response.status} to {location}")
                elif response.status in (301, 302, 303, 307, 308):
                    self.report.add(name, FAIL, f"redirected somewhere else: {location}")
                else:
                    self.report.add(name, FAIL, f"HTTP {response.status}, expected a redirect to login")
            else:
                # WooCommerce sends a shopper with an empty cart from the
                # checkout back to the cart page itself; that is the shop
                # working normally, not this plugin intervening. Only a bounce
                # to the login page is a failure here.
                if "my-account" in location:
                    self.report.add(name, FAIL, f"redirected to login: {location}")
                elif response.status == 200 or response.status in (301, 302, 303, 307, 308):
                    detail = "HTTP 200" if response.status == 200 else f"HTTP {response.status} to {location}"
                    self.report.add(name, PASS, detail)
                else:
                    self.report.add(
                        name, FAIL, f"HTTP {response.status} (location {location!r})"
                    )

    def check_empty_cart_grid(self) -> None:
        """The cart page for a visitor with no cart at all: the block version
        renders its empty-cart view server-side, and that view carries a
        product grid ("New in store", the Product New block) whose add-to-cart
        buttons print ``data-price`` and whose cards print a price."""
        name = "cart-page-empty-grid"
        path = self.seed.get("cart_path", "/cart/")
        response = self.client.get_without_redirects(path)
        if response.status in (301, 302, 303, 307, 308):
            self.report.add(name, SKIP, f"{path} redirects to {response.location} (purchasing blocked)", absent=True)
            return
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status} for {path}")
            return
        if "wc-block-grid" not in response.body:
            self.report.add(name, SKIP, f"{path}: no product grid on the empty cart page (classic template)", absent=True)
            return
        fragments = price_html_fragments(response.body, self.currency_symbol)
        if self.hidden_for(name):
            if fragments:
                self.report.add(name, FAIL, "empty-cart product grid: " + "; ".join(fragments[:3]))
            else:
                self.report.add(name, PASS, "empty-cart product grid: no data-price attribute, no rendered amount")
        else:
            if fragments:
                self.report.add(name, PASS, f"empty-cart product grid: {fragments[0]}")
            else:
                self.report.add(name, FAIL, "expected prices in the empty-cart product grid but found none (seed problem?)")

    def check_add_to_cart_buttons(self) -> None:
        """The catalogue's buttons: gone and replaced, or present and working."""
        name = "add-to-cart-buttons"
        response = self.fetch_or_skip(name, self.seed.get("shop_path", "/shop/"))
        if response is None:
            return
        body = html.unescape(response.body)
        markers = [
            marker
            for marker in ("add_to_cart_button", "add-to-cart=", "?add-to-cart")
            if marker in body
        ]
        if self.expect_purchasing_blocked:
            problems = []
            if markers:
                problems.append("add-to-cart button present: " + ", ".join(markers))
            if self.login_to_buy_text not in body:
                problems.append(f"{self.login_to_buy_text!r} absent")
            if problems:
                self.report.add(name, FAIL, "; ".join(problems))
            else:
                self.report.add(name, PASS, f"no add-to-cart button, {self.login_to_buy_text!r} present")
        else:
            if markers:
                self.report.add(name, PASS, f"buttons present ({markers[0]})")
            else:
                self.report.add(name, FAIL, "expected add-to-cart buttons but found none")

    # -- a guest with a cart (module 1, cart and mini-cart surfaces) -------

    def guest_cart(self) -> CartClient | None:
        """A cookie jar holding a cart with the simple product in it, or None
        with the reason already reported when no such cart can exist."""
        names = ("cart-fragments", "cart-page-totals", "cart-page-totals-ajax", "mini-cart-state")
        simple = self.seed.get("simple")
        if not simple:
            for name in names:
                self.report.add(name, SKIP, "no simple product in seed")
            return None
        if self.expect_purchasing_blocked:
            for name in names:
                self.report.add(
                    name, SKIP, "purchasing blocked: a guest cannot build a cart to inspect", absent=True
                )
            return None

        client = CartClient(self.client.base_url, self.client.cache_bust)
        response = client.post_form(
            f"/?wc-ajax=add_to_cart&product_id={simple['id']}",
            {"product_id": str(simple["id"]), "quantity": "1"},
        )
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = {}
        if response.status >= 400 or not isinstance(payload, dict) or payload.get("error") or not client.has_session():
            for name in names:
                self.report.add(
                    name, FAIL, f"could not build a guest cart: HTTP {response.status} {response.body[:100]!r}"
                )
            return None

        # The add-to-cart response carries the refreshed mini-cart fragments:
        # the cart widget's item line ("1 x price") and its subtotal.
        fragments = payload.get("fragments") or {}
        joined = "\n".join(str(value) for value in fragments.values()) if isinstance(fragments, dict) else ""
        if joined.strip():
            self.judge_html("cart-fragments", joined, "add_to_cart fragments")
        else:
            self.report.add("cart-fragments", SKIP, "add_to_cart response carried no fragments")
        return client

    def check_cart_totals(self, client: CartClient) -> None:
        """The cart page and the endpoint that re-renders its totals table."""
        name = "cart-page-totals"
        response = client.get(self.seed.get("cart_path", "/cart/"))
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status}")
        elif "wp-block-woocommerce-cart" in response.body and "woocommerce-cart-form" not in response.body:
            # The Cart block renders its line items and totals in the browser
            # from the Store API cart route, so the page HTML itself carries no
            # totals row to judge; it must still print no amount anywhere.
            fragments = price_html_fragments(response.body, self.currency_symbol)
            if self.hidden_for(name):
                if fragments:
                    self.report.add(name, FAIL, "block cart page: " + fragments[0])
                else:
                    self.report.add(
                        name, PASS, "block cart page: no rendered amount (totals hydrate from the Store API cart route)"
                    )
            else:
                self.report.add(name, PASS, "block cart page (totals hydrate from the Store API cart route)")
        else:
            self.judge_html(name, response.body, "classic cart page")

        name = "cart-page-totals-ajax"
        response = client.get("/?wc-ajax=get_cart_totals")
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status}")
        elif "order-total" not in response.body:
            self.report.add(name, FAIL, f"no order-total row in the response: {response.body[:120]!r}")
        else:
            self.judge_html(name, response.body, "get_cart_totals")

    def check_mini_cart_state(self, client: CartClient) -> None:
        """The Mini Cart block registers the cart subtotal as Interactivity API
        state on every page that carries it (a block theme's header), whether
        or not the block shows it."""
        name = "mini-cart-state"
        response = client.get("/")
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status} for the home page")
            return
        # The block's wrapper carries both class names on every render path
        # (MiniCart.php in WooCommerce 11.1); a page without such an element
        # has no Mini Cart block, which is the normal state of a classic theme.
        has_block = mini_cart_block_present(response.body)
        state = interactivity_state(response.body)
        if state is None:
            if has_block:
                self.report.add(name, SKIP, "Mini Cart block markup present but no Interactivity API state on the home page")
            else:
                self.report.add(name, SKIP, "no Mini Cart block on the home page (classic theme)", absent=True)
            return
        stores = {key: value for key, value in state.items() if key.startswith("woocommerce/mini-cart")}
        if not stores:
            if has_block:
                self.report.add(name, SKIP, "Mini Cart block markup present but no woocommerce/mini-cart state")
            else:
                self.report.add(name, SKIP, "no Mini Cart block on the home page (classic theme)", absent=True)
            return

        problems: list[str] = []
        subtotals: list[str] = []
        for store, values in stores.items():
            if not isinstance(values, dict):
                continue
            for key, value in values.items():
                if key in ("formattedSubtotal", "buttonAriaLabel") and isinstance(value, str):
                    subtotals.append(f"{store}.{key}={value!r}")
                    if carries_amount(value, self.currency_symbol):
                        problems.append(f"{store}.{key}={value!r}")
            problems.extend(numeric_price_fields(values, f"$.{store}"))
        # The rendered button's aria-label is the same value, server-processed.
        for label in re.findall(r'aria-label="([^"]*)"', response.body):
            if "cart" in label.lower() and carries_amount(label, self.currency_symbol):
                problems.append(f"aria-label={html.unescape(label)!r}")

        if self.hidden_for(name):
            if problems:
                self.report.add(name, FAIL, "amount in Mini Cart state: " + "; ".join(problems[:3]))
            else:
                self.report.add(name, PASS, "no amount in Mini Cart state (" + ", ".join(subtotals[:2]) + ")")
        else:
            if problems:
                self.report.add(name, PASS, f"amount present ({problems[0]})")
            else:
                self.report.add(name, FAIL, "expected the cart subtotal in the Mini Cart state but found none")

    # -- a guest cart built through the Store API (module 4) ----------------

    STORE_API_CART_ROWS = (
        "store-api-cart-add-item", "store-api-cart", "store-api-cart-items", "store-api-cart-alias", "store-api-batch",
        "cart-page-hydration", "checkout-page-hydration", "mini-cart-hydration",
    )

    def store_api_cart(self) -> CartClient | None:
        """A second cookie jar, filled the way the blocks fill one: the nonce
        from GET /cart, then POST /cart/add-item. Its add-item response is the
        first cart-shaped payload judged."""
        simple = self.seed.get("simple")
        if not simple:
            for name in self.STORE_API_CART_ROWS:
                self.report.add(name, SKIP, "no simple product in seed")
            return None
        if self.expect_purchasing_blocked:
            for name in self.STORE_API_CART_ROWS:
                self.report.add(name, SKIP, "purchasing blocked: a guest cannot build a cart to inspect", absent=True)
            return None
        client = CartClient(self.client.base_url, self.client.cache_bust)
        nonce = client.store_api_nonce()
        response = client.post_json(
            "/wp-json/wc/store/v1/cart/add-item", {"id": simple["id"], "quantity": 1}, {"Nonce": nonce} if nonce else {}
        )
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = None
        if response.status not in (200, 201) or not isinstance(payload, dict) or not payload.get("items"):
            for name in self.STORE_API_CART_ROWS:
                self.report.add(name, FAIL, f"could not build a Store API guest cart: HTTP {response.status} {response.body[:100]!r}")
            return None
        self.judge_json("store-api-cart-add-item", payload, "POST cart/add-item")
        return client

    def check_store_api_cart_routes(self, client: CartClient) -> None:
        for name, path in (
            ("store-api-cart", "/wp-json/wc/store/v1/cart"),
            ("store-api-cart-items", "/wp-json/wc/store/v1/cart/items"),
            ("store-api-cart-alias", "/wp-json/wc/store/cart"),
        ):
            response = client.get(path)
            if response.status >= 400:
                self.report.add(name, FAIL, f"HTTP {response.status}")
                continue
            try:
                payload = response.json()
            except json.JSONDecodeError:
                self.report.add(name, FAIL, "response was not JSON")
                continue
            self.judge_json(name, payload, path)

        # A batch sub-request is dispatched like any other request, so its
        # sub-response must be blanked too. update-customer returns the full
        # cart and changes nothing that matters.
        name = "store-api-batch"
        nonce = client.store_api_nonce()
        response = client.post_json(
            "/wp-json/wc/store/v1/batch",
            {"requests": [{"path": "/wc/store/v1/cart/update-customer", "method": "POST", "body": {"billing_address": {"country": "US"}}, "headers": {"Nonce": nonce}}]},
            {"Nonce": nonce} if nonce else {},
        )
        try:
            payload = response.json()
        except json.JSONDecodeError:
            payload = None
        sub = (payload.get("responses") or [{}])[0] if isinstance(payload, dict) else {}
        if response.status >= 400 or not isinstance(sub, dict) or sub.get("status") not in (200, 201) or not isinstance(sub.get("body"), dict):
            self.report.add(name, FAIL, f"batch sub-request did not return a cart: HTTP {response.status} {response.body[:120]!r}")
            return
        self.judge_json(name, sub["body"], "batch update-customer sub-response")

    def check_page_hydration(self, client: CartClient) -> None:
        """The Cart and Checkout blocks preload /wc/store/v1/cart (and the
        checkout data) into the page; the product grids on those pages embed
        their product lists; the Interactivity API prints every store's state."""
        for name, path, wrapper in (
            ("cart-page-hydration", self.seed.get("cart_path", "/cart/"), "wp-block-woocommerce-cart"),
            ("checkout-page-hydration", self.seed.get("checkout_path", "/checkout/"), "wp-block-woocommerce-checkout"),
        ):
            response = client.get(path)
            if response.status >= 400:
                self.report.add(name, FAIL, f"HTTP {response.status}")
                continue
            if not self.judge_payloads(name, response.body, path):
                if wrapper in response.body:
                    self.report.add(name, SKIP, f"{path}: block page but no embedded payload found")
                else:
                    self.report.add(name, SKIP, f"{path}: classic template, nothing hydrated", absent=True)

    def check_mini_cart_hydration(self, client: CartClient) -> None:
        """The Mini Cart block registers the whole Store API cart response as
        the `woocommerce.cart` Interactivity state on every page it is on."""
        name = "mini-cart-hydration"
        response = client.get("/")
        if response.status >= 400:
            self.report.add(name, FAIL, f"HTTP {response.status} for the home page")
            return
        state = interactivity_state(response.body)
        cart = (state or {}).get("woocommerce", {}).get("cart") if isinstance((state or {}).get("woocommerce"), dict) else None
        if not isinstance(cart, dict) or not cart:
            has_block = mini_cart_block_present(response.body)
            if has_block:
                self.report.add(name, SKIP, "Mini Cart block present but no woocommerce.cart state on the home page")
            else:
                self.report.add(name, SKIP, "no Mini Cart block on the home page (classic theme)", absent=True)
            return
        self.judge_json(name, cart, "woocommerce.cart state")

    def check_product_page_hydration(self, bodies: dict[str, str]) -> None:
        """Block themes load every rendered product (and a variable product's
        variations) into the `woocommerce/products` Interactivity state."""
        name = "product-page-hydration"
        judged = False
        leaks: list[str] = []
        pages = 0
        for kind in ("simple", "variable"):
            body = bodies.get(kind)
            if body is None:
                continue
            state = interactivity_state(body)
            store = (state or {}).get("woocommerce/products")
            if not isinstance(store, dict):
                continue
            pages += 1
            judged = True
            leaks.extend(f"{kind}: {leak}" for leak in numeric_price_fields(store, "$"))
        if not judged:
            self.report.add(name, SKIP, "no woocommerce/products state on the product pages (classic theme)", absent=True)
            return
        if self.hidden_for(name):
            if leaks:
                self.report.add(name, FAIL, "numeric price in woocommerce/products state: " + "; ".join(leaks[:4]))
            else:
                self.report.add(name, PASS, f"woocommerce/products state on {pages} page(s) carries no numeric price")
        else:
            if leaks:
                self.report.add(name, PASS, f"prices present ({leaks[0]})")
            else:
                self.report.add(name, FAIL, "expected prices in the woocommerce/products state but found none")

    # -- price inference channels (module 7) -------------------------------

    def catalogue_paths(self) -> list[str]:
        return [self.seed[kind]["path"] for kind in ("simple", "variable", "grouped") if self.seed.get(kind)]

    def check_price_bisection(self) -> None:
        """A price range that excludes the simple product must not exclude it
        for a guest when prices are hidden; it must when they are visible."""
        name = "shop-price-bisection"
        simple = self.seed.get("simple")
        paths = self.catalogue_paths()
        if not simple or not simple.get("price") or not paths:
            self.report.add(name, SKIP, "seed has no simple product price to bisect against")
            return
        shop = self.seed.get("shop_path", "/shop/")
        joiner = "&" if "?" in shop else "?"
        below = f"{float(simple['price']) - 1:.2f}"
        plain = self.client.get(shop)
        ranged = self.client.get(f"{shop}{joiner}min_price=0&max_price={below}")
        if plain.status >= 400 or ranged.status >= 400:
            self.report.add(name, FAIL, f"HTTP {plain.status}/{ranged.status}")
            return
        without = {path for path in paths if path in plain.body}
        with_range = {path for path in paths if path in ranged.body}
        if not without:
            self.report.add(name, FAIL, "no seeded product on the shop page (seed problem?)")
            return
        detail = f"max_price={below}: {len(with_range)} of {len(without)} products listed"
        if self.hidden_for(name):
            if with_range == without:
                self.report.add(name, PASS, f"price range ignored for a guest ({detail})")
            else:
                self.report.add(name, FAIL, f"price range narrows the catalogue ({detail}); prices are bisectable")
        else:
            if with_range < without:
                self.report.add(name, PASS, f"price range narrows the catalogue ({detail})")
            else:
                self.report.add(name, FAIL, f"expected the range to narrow the catalogue ({detail})")

    def check_orderby_price(self) -> None:
        """Sorting by price must not reorder the catalogue for a guest when
        prices are hidden; it must when they are visible."""
        name = "shop-orderby-price"
        paths = self.catalogue_paths()
        if len(paths) < 2:
            self.report.add(name, SKIP, "fewer than two seeded products to order")
            return
        shop = self.seed.get("shop_path", "/shop/")
        joiner = "&" if "?" in shop else "?"
        default = self.client.get(shop)
        asc = self.client.get(f"{shop}{joiner}orderby=price")
        desc = self.client.get(f"{shop}{joiner}orderby=price-desc")
        if max(default.status, asc.status, desc.status) >= 400:
            self.report.add(name, FAIL, f"HTTP {default.status}/{asc.status}/{desc.status}")
            return
        order_default = product_order(default.body, paths)
        order_asc = product_order(asc.body, paths)
        order_desc = product_order(desc.body, paths)
        if len(order_default) < 2:
            self.report.add(name, FAIL, "fewer than two seeded products on the shop page (seed problem?)")
            return
        shorten = lambda order: [path.strip("/").split("/")[-1].replace("probe-", "") for path in order]  # noqa: E731
        if self.hidden_for(name):
            if order_asc == order_default and order_desc == order_default:
                self.report.add(name, PASS, f"orderby=price ignored for a guest ({shorten(order_default)})")
            else:
                self.report.add(
                    name, FAIL, f"orderby=price reorders the catalogue: default {shorten(order_default)}, asc {shorten(order_asc)}, desc {shorten(order_desc)}"
                )
        else:
            if order_asc != order_desc:
                self.report.add(name, PASS, f"price sorting works: asc {shorten(order_asc)}, desc {shorten(order_desc)}")
            else:
                self.report.add(name, FAIL, f"expected price sorting to reorder the catalogue ({shorten(order_asc)})")

    def check_store_api_price_params(self) -> None:
        """A Store API price range that excludes the simple product must not
        exclude it for a guest when prices are hidden; it must when visible."""
        name = "store-api-price-params"
        simple = self.seed.get("simple")
        if not simple or not simple.get("price"):
            self.report.add(name, SKIP, "seed has no simple product price to bisect against")
            return
        minor = int(round(float(simple["price"]) * 100))
        below = minor - 100
        plain = self.client.get(f"/wp-json/wc/store/v1/products?include[]={simple['id']}")
        ranged = self.client.get(f"/wp-json/wc/store/v1/products?include[]={simple['id']}&min_price=0&max_price={below}")
        if plain.status >= 400 or ranged.status >= 400:
            self.report.add(name, FAIL, f"HTTP {plain.status}/{ranged.status}")
            return
        try:
            listed = {p.get("id") for p in plain.json()}
            with_range = {p.get("id") for p in ranged.json()}
        except (json.JSONDecodeError, AttributeError, TypeError):
            self.report.add(name, FAIL, "response was not a product list")
            return
        if simple["id"] not in listed:
            self.report.add(name, FAIL, "simple product missing from the Store API (seed problem?)")
            return
        detail = f"max_price={below}: {'listed' if simple['id'] in with_range else 'excluded'}"
        if self.hidden_for(name):
            if simple["id"] in with_range:
                self.report.add(name, PASS, f"price range ignored for a guest ({detail})")
            else:
                self.report.add(name, FAIL, f"price range narrows the Store API result ({detail}); prices are bisectable")
        else:
            if simple["id"] not in with_range:
                self.report.add(name, PASS, f"price range narrows the Store API result ({detail})")
            else:
                self.report.add(name, FAIL, f"expected the range to exclude the product ({detail})")

    def check_store_api_orderby_price(self) -> None:
        """Store API price sorting must not reorder the catalogue for a guest
        when prices are hidden; it must when they are visible."""
        name = "store-api-orderby-price"
        ids = [self.seed[kind]["id"] for kind in ("simple", "variable", "grouped") if self.seed.get(kind)]
        if len(ids) < 2:
            self.report.add(name, SKIP, "fewer than two seeded products to order")
            return
        include = "&".join(f"include[]={i}" for i in ids)
        orders: dict[str, list[int]] = {}
        for label, query in (("default", ""), ("asc", "&orderby=price&order=asc"), ("desc", "&orderby=price&order=desc")):
            # `orderby=include` is not asked for, so the route's own default applies to the first fetch.
            response = self.client.get(f"/wp-json/wc/store/v1/products?{include}{query}")
            if response.status >= 400:
                self.report.add(name, FAIL, f"HTTP {response.status} ({label})")
                return
            try:
                orders[label] = [p.get("id") for p in response.json()]
            except (json.JSONDecodeError, AttributeError, TypeError):
                self.report.add(name, FAIL, f"response was not a product list ({label})")
                return
        if len(orders["default"]) < 2:
            self.report.add(name, FAIL, "fewer than two seeded products returned (seed problem?)")
            return
        # For a guest the plugin swaps price ordering for the *catalogue's*
        # default ordering, which is not the products route's own default
        # (date), so the honest test is that the direction makes no
        # difference: asc and desc must agree.
        if self.hidden_for(name):
            if orders["asc"] == orders["desc"]:
                self.report.add(name, PASS, f"orderby=price ignored for a guest (asc and desc both {orders['asc']}, route default {orders['default']})")
            else:
                self.report.add(name, FAIL, f"orderby=price reorders the Store API result: asc {orders['asc']}, desc {orders['desc']}")
        else:
            if orders["asc"] != orders["desc"]:
                self.report.add(name, PASS, f"price sorting works: asc {orders['asc']}, desc {orders['desc']}")
            else:
                self.report.add(name, FAIL, f"expected price sorting to reorder the result ({orders['asc']})")

    def check_wp_v2_price_params(self) -> None:
        """WordPress core's /wp/v2/product route with the Product Collection
        block's editor parameters: a price range that excludes the simple
        product must not exclude it for a guest when prices are hidden; it
        must when they are visible."""
        name = "wp-v2-price-params"
        simple = self.seed.get("simple")
        if not simple or not simple.get("price"):
            self.report.add(name, SKIP, "seed has no simple product price to bisect against")
            return
        below = f"{float(simple['price']) - 1:.2f}"
        base = f"/wp-json/wp/v2/product?include[]={simple['id']}&isProductCollectionBlock=true&_fields=id"
        plain = self.client.get(base)
        ranged = self.client.get(f"{base}&priceRange[max]={below}")
        if plain.status in (401, 403):
            self.report.add(name, SKIP, f"HTTP {plain.status} anonymously (route not open to guests, nothing to infer)", absent=True)
            return
        if plain.status >= 400 or ranged.status >= 400:
            self.report.add(name, FAIL, f"HTTP {plain.status}/{ranged.status}")
            return
        try:
            listed = {p.get("id") for p in plain.json()}
            with_range = {p.get("id") for p in ranged.json()}
        except (json.JSONDecodeError, AttributeError, TypeError):
            self.report.add(name, FAIL, "response was not a product list")
            return
        if simple["id"] not in listed:
            self.report.add(name, FAIL, "simple product missing from /wp/v2/product (seed problem?)")
            return
        detail = f"priceRange[max]={below}: {'listed' if simple['id'] in with_range else 'excluded'}"
        if self.hidden_for(name):
            if simple["id"] in with_range:
                self.report.add(name, PASS, f"price range ignored for a guest ({detail})")
            else:
                self.report.add(name, FAIL, f"price range narrows /wp/v2/product ({detail}); prices are bisectable")
        else:
            if simple["id"] not in with_range:
                self.report.add(name, PASS, f"price range narrows /wp/v2/product ({detail})")
            else:
                self.report.add(name, FAIL, f"expected the range to exclude the product ({detail})")

    def check_wp_v2_orderby_price(self) -> None:
        """Price sorting on /wp/v2/product through the same editor hook must
        not reorder the result for a guest when prices are hidden; it must
        when they are visible."""
        name = "wp-v2-orderby-price"
        ids = [self.seed[kind]["id"] for kind in ("simple", "variable", "grouped") if self.seed.get(kind)]
        if len(ids) < 2:
            self.report.add(name, SKIP, "fewer than two seeded products to order")
            return
        include = "&".join(f"include[]={i}" for i in ids)
        orders: dict[str, list[int]] = {}
        for label, order in (("asc", "asc"), ("desc", "desc")):
            response = self.client.get(
                f"/wp-json/wp/v2/product?{include}&isProductCollectionBlock=true&orderby=price&order={order}&_fields=id"
            )
            if response.status in (401, 403):
                self.report.add(name, SKIP, f"HTTP {response.status} anonymously (route not open to guests, nothing to infer)", absent=True)
                return
            if response.status >= 400:
                self.report.add(name, FAIL, f"HTTP {response.status} ({label})")
                return
            try:
                orders[label] = [p.get("id") for p in response.json()]
            except (json.JSONDecodeError, AttributeError, TypeError):
                self.report.add(name, FAIL, f"response was not a product list ({label})")
                return
        if len(orders["asc"]) < 2:
            self.report.add(name, FAIL, "fewer than two seeded products returned (seed problem?)")
            return
        # For a guest the plugin swaps price ordering for the route's default
        # ordering in both directions, so asc and desc must agree.
        if self.hidden_for(name):
            if orders["asc"] == orders["desc"]:
                self.report.add(name, PASS, f"orderby=price ignored for a guest (asc and desc both {orders['asc']})")
            else:
                self.report.add(name, FAIL, f"orderby=price reorders /wp/v2/product: asc {orders['asc']}, desc {orders['desc']}")
        else:
            if orders["asc"] != orders["desc"]:
                self.report.add(name, PASS, f"price sorting works: asc {orders['asc']}, desc {orders['desc']}")
            else:
                self.report.add(name, FAIL, f"expected price sorting to reorder the result ({orders['asc']})")

    def run(self) -> Report:
        self.check_shop_page()
        bodies = self.check_product_pages()
        self.check_json_ld(bodies)
        self.check_inline_variations(bodies)
        self.check_wc_ajax_get_variation()
        self.check_store_api()
        self.check_legacy_rest()
        self.check_product_page_hydration(bodies)
        self.check_price_bisection()
        self.check_orderby_price()
        self.check_store_api_price_params()
        self.check_store_api_orderby_price()
        self.check_wp_v2_price_params()
        self.check_wp_v2_orderby_price()
        self.check_wc_ajax_add_to_cart()
        self.check_store_api_add_item()
        self.check_store_api_checkout()
        self.check_wc_ajax_checkout()
        self.check_cart_and_checkout_pages()
        self.check_empty_cart_grid()
        self.check_add_to_cart_buttons()
        cart = self.guest_cart()
        if cart is not None:
            self.check_cart_totals(cart)
            self.check_mini_cart_state(cart)
            self.save_session(cart)
        store_cart = self.store_api_cart()
        if store_cart is not None:
            self.check_store_api_cart_routes(store_cart)
            self.check_page_hydration(store_cart)
            self.check_mini_cart_hydration(store_cart)
        return self.report


# --------------------------------------------------------------------------
# Seed discovery
# --------------------------------------------------------------------------


def discover_seed(client: AnonymousClient) -> dict[str, Any]:
    """Fall back to the Store API when seed.json is missing.

    Good enough to point the probe at an arbitrary shop; seed.sh remains the
    reliable path because it also knows the variation attribute names.
    """
    response = client.get("/wp-json/wc/store/v1/products?per_page=100")
    if response.status >= 400:
        raise SystemExit(
            f"cannot discover products: Store API returned HTTP {response.status}. "
            "Run tests/probe/seed.sh and pass --seed."
        )
    seed: dict[str, Any] = {"shop_path": "/shop/", "currency_symbol": ""}
    for product in response.json():
        kind = product.get("type")
        if kind in ("simple", "variable", "grouped") and kind not in seed:
            entry = {
                "id": product["id"],
                "slug": product.get("slug", ""),
                "path": urllib.parse.urlparse(product.get("permalink", "")).path,
            }
            if kind == "variable":
                entry["variations"] = []
            seed[kind] = entry
        symbol = (product.get("prices") or {}).get("currency_symbol")
        if symbol and not seed["currency_symbol"]:
            seed["currency_symbol"] = symbol
    if not any(key in seed for key in ("simple", "variable", "grouped")):
        raise SystemExit("Store API returned no products; run tests/probe/seed.sh first.")
    return seed


# --------------------------------------------------------------------------
# Entry point
# --------------------------------------------------------------------------


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--base-url", required=True, help="e.g. http://localhost:8888")
    parser.add_argument("--seed", help="seed.json written by tests/probe/seed.sh")
    parser.add_argument(
        "--expect",
        choices=("visible", "hidden", "on", "off"),
        default="visible",
        help="'hidden'/'on' = the master switch is on and guests must see no price; "
        "'visible'/'off' = prices must be present",
    )
    parser.add_argument(
        "--expect-hidden",
        action="store_true",
        help="shorthand for --expect hidden",
    )
    parser.add_argument(
        "--expect-purchasing",
        choices=("allowed", "blocked"),
        default="allowed",
        help="'blocked' = pricecloak_block_purchasing is on and a guest must not be "
        "able to add to cart, reach the cart or checkout, or see a buy button",
    )
    parser.add_argument(
        "--modules-off",
        default="",
        help="comma-separated module ids that are switched off in the site's "
        "settings (" + ", ".join(MODULE_IDS) + "). Their surfaces are expected "
        "to go on showing prices, and the summary table names the owning module "
        "for every surface, so a disabled module reads as a deliberate red.",
    )
    parser.add_argument(
        "--login-to-buy-text",
        default=DEFAULT_LOGIN_TO_BUY_TEXT,
        help="text that must appear where an add-to-cart button would be",
    )
    parser.add_argument(
        "--cache-bust",
        action="store_true",
        help="append a unique query argument to every request so a page cache "
        "in front of the site cannot serve copies stored before the settings "
        "changed; use on live sites with nginx/CDN/plugin caching",
    )
    parser.add_argument(
        "--replacement-text",
        default=DEFAULT_REPLACEMENT_TEXT,
        help="text that must appear where a price would be when hidden",
    )
    parser.add_argument(
        "--session-file",
        default="",
        help="path the allowed run writes a guest's cookies and classic "
        "process-checkout nonce to (when the checkout page carries the classic "
        "form), and the blocked run reads them from for wc-ajax-checkout-blocked",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="every SKIP is a failure, including the documented ones (the "
        "rest-v3-* rows a stock WooCommerce answers 401 to, a classic theme "
        "with no Mini Cart block); for live sites where every surface must be "
        "judged. Without it the --expect visible run already fails on any SKIP "
        "other than those documented ones, so a probe that has gone blind "
        "cannot be green.",
    )
    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)
    expect_hidden = args.expect_hidden or args.expect in ("hidden", "on")

    modules_off = frozenset(
        part.strip() for part in args.modules_off.split(",") if part.strip()
    )
    unknown = sorted(modules_off - set(MODULE_IDS))
    if unknown:
        raise SystemExit(
            f"unknown module id(s) {', '.join(unknown)}; known: {', '.join(MODULE_IDS)}"
        )

    client = AnonymousClient(args.base_url, cache_bust=args.cache_bust)
    if args.seed:
        with open(args.seed, encoding="utf-8") as handle:
            seed = json.load(handle)
    else:
        seed = discover_seed(client)

    expectation = "hidden" if expect_hidden else "visible"
    banner = (
        f"Anonymous probe of {client.base_url} -- expecting prices to be "
        f"{expectation} and purchasing to be {args.expect_purchasing}"
    )
    if modules_off:
        banner += (
            "\nModules switched off: "
            + ", ".join(sorted(modules_off))
            + " -- their surfaces are expected to show prices"
        )
    print(banner + "\n")

    report = Probe(
        client,
        seed,
        expect_hidden,
        args.replacement_text,
        args.expect_purchasing == "blocked",
        args.login_to_buy_text,
        modules_off,
        args.strict,
        args.session_file,
    ).run()
    print(report.render(modules_off))
    return 0 if report.ok() else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
