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
