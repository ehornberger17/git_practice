=== DG Bullion Ticker & Pricing ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0

Live precious-metal ticker under your header plus WooCommerce bullion pricing
(wire / debit card / credit card) from the Dillon Gage FizTrade API.

== Installation ==

1. Plugins > Add New > Upload Plugin > choose dg-bullion-pricing.zip > Activate.
2. Settings > DG Bullion:
   - Environment: Staging. Paste the API token Dillon Gage gave you. Save.
   - Click "Test spot prices". You should see gold/silver/platinum/palladium
     ask, bid and change. If not, send the raw response to your developer.
   - Set the ticker colors to your brand colors. Save.
3. Visit the site: the ticker appears at the bottom of the header. If your
   theme's header isn't detected, put its CSS selector in "Header CSS
   selector", or choose "Only where I place [dg_ticker]".
4. WooCommerce pricing:
   - Set debit and credit surcharges (%).
   - Map each payment method to Wire, Debit, Credit, or "ask customer" (for a
     card gateway that takes both debit and credit).
   - The Checkout page must use the classic [woocommerce_checkout] shortcode,
     not the Checkout block (a warning appears in settings if it doesn't).
5. On each bullion product (simple product) > General tab:
   - Tick "Price from Dillon Gage".
   - Basis "Metal spot x weight": choose metal, fine troy oz, and your premium.
     OR basis "Dillon Gage product price": enter the DG product code; your
     premium is added on top of DG's ask.
   - Update. The "Current prices" line shows the three live prices.
6. Going live: change Environment to Production and enter the production token.

== How prices are calculated ==

Wire   = basis + premium
         basis   = spot ask x troy oz, or Dillon Gage product ask
         premium = $ per oz x oz, or $ per item, or % of basis
Debit  = Wire x (1 + debit %)
Credit = Wire x (1 + credit %)

The shop shows the headline tier you pick (default: wire). In checkout the
cart re-prices instantly when the customer changes payment method, and the
order records the tier used and a spot snapshot.

== Checkout price lock ==

When a customer opens checkout, their metal prices are frozen for
"Checkout price lock" minutes (default 10). If it runs out before they order,
they see "Your price lock ran out..." with the updated total and place the
order again. Each order gets a note with the spot (and DG product prices)
used. Set to 0 to turn off.

== Price-jump hold ==

If one update moves a metal's spot (or a DG product price) more than
"Hold prices if one update moves more than" percent (default 5%), the store
keeps the previous price and the dashboard shows "Accept current prices".
A one-off glitch clears itself when a normal price comes back. If nobody
accepts a real move, bullion sales pause once the held price is older than
the stale-price limit. Set to 0 to turn off.

== Florida sales tax ==

Settings > DG Bullion > Sales tax = "Florida" (default). Each bullion
product's tax status is then set automatically from the Form, Purity and
"U.S. legal tender coin" fields in its General tab:

  Exempt   gold, silver, platinum bars / ingots / rounds (s. 212.08(7)(ww), F.S.)
           gold coins >= 99.5% and silver coins >= 99.9% (HB 999 legal tender)
  Taxable  palladium; other coins; numismatics / other items; form not set
  Setting  U.S. legal tender coins below those purities (e.g. Gold Eagles,
           90% silver): taxable by default until your CPA confirms

"Tax decision" on a product overrides the rule. Each order line records the
decision and the reason. Use WooCommerce Tax (free) for the 6% state rate plus
county surtax on the taxable items. Not tax advice.

== Elementor ==

* Ticker: placed under the Elementor header automatically, or put
  [dg_ticker] in a Shortcode widget ([spot_ticker] also works).
* Single product templates: add a Shortcode widget with [dg_product_prices]
  ([spot_product_prices] also works) to show wire / debit / credit prices.
* Checkout: use the Elementor Checkout widget or [woocommerce_checkout], not
  the Checkout block.

== Safety ==

* The API token stays on the server. Browsers only call this site's own
  /wp-json/dgbp/v1/ endpoints, which return cached public prices.
* Prices are cached (default 30 s) and refreshed every minute by WP-Cron.
* If FizTrade can't be reached, the last good price is used until it is older
  than "Stop selling if prices older than"; after that bullion products can't be
  added to the cart or checked out.
* Unmapped payment methods charge the credit price by default.

== Notes ==

* Works behind page caching: ticker and product prices refresh in the browser.
* For reliable 1-minute refreshes on low-traffic sites, set up a real server
  cron hitting wp-cron.php.
* Developers: filters dgbp_parse_spot, dgbp_parse_product_price and
  dgbp_product_prices allow custom mapping or pricing rules.
