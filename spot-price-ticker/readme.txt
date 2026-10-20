=== Spot Price Ticker ===

Precious-metal spot price bar (gold, silver, platinum, palladium) fed by the
Dillon Gage FizTrade API, styled with your Elementor global colors.

== Install ==
1. Plugins > Add New > Upload Plugin > choose spot-price-ticker.zip > Activate.
2. Settings > Spot Price Ticker: paste your API token, confirm the API URL from
   your FizTrade documentation, Save, then click "Call the API now".
3. Edit your header in Elementor (Templates > Theme Builder > Header), drag a
   Shortcode widget to the bottom, and enter: [spot_ticker]

== Notes ==
- The token stays on the server. Visitors' browsers only talk to
  /wp-json/spot-ticker/v1/prices on your own site.
- The API is called at most once per "Check the API every" period, no matter
  how much traffic the site gets.
- If the API fails, the last good prices show marked "Delayed"; after 30
  minutes without fresh prices the bar hides itself.
- SiteGround Speed Optimizer: if prices stop updating, add
  /wp-json/spot-ticker/* to its cache exclusions.

== Store pricing (WooCommerce → Metal Pricing) ==
Price = spot ask × fine troy oz + premium, rounded to the cent.

Per product (General tab) or per variation:
- Spot-priced metal, fine metal weight (troy oz)
- Wire premium over spot ($)
- Card premium over spot ($) — blank = wire price + the card % setting
- Minimum price ($) — optional floor

Behavior:
- Prices update every few minutes (default 5) and are written to the
  product's regular price, so the shop, search, sorting and feeds see them.
- Wire pricing applies to the payment methods ticked in the settings (e.g.
  "Direct bank transfer"); every other method gets the card price. The
  checkout reprices when the customer switches payment method.
- The spot is locked for each customer when they open checkout (default
  10 minutes). If the lock runs out, they are shown the updated total
  before the order goes through. Each order gets a note with the spot used.
- If spot is older than the limit (default 15 minutes), metal products
  can't be added to the cart or checked out until fresh prices return.
- If spot moves more than the limit (default 5%) in one update, prices are
  frozen until you click "Accept current spot".
- Requires the classic checkout (Elementor Checkout widget or
  [woocommerce_checkout]), not the block checkout.
- Elementor single product templates: add [spot_product_prices] in a
  Shortcode widget to show both wire and card prices.

Bulk setup: Products > Import, upload a CSV like
metal-pricing-import-template.csv, tick "Update existing products", and map
the "Meta:" columns to "Import as meta data". Then click "Update prices now".

Reliable timing on SiteGround: Site Tools > Devs > Cron Jobs, every 5 minutes:
  wget -q -O - "https://YOUR-SITE/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
