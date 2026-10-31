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
