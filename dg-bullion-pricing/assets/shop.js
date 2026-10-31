/* DG Bullion – live product prices + checkout payment-method pricing */
jQuery(function ($) {
	'use strict';
	var cfg = window.DGBPShop || {};

	/* ---------- Live refresh of product prices (works behind page caches) ---------- */
	function ids() {
		var seen = {};
		$('[data-dgbp-pid]').each(function () { seen[$(this).data('dgbp-pid')] = 1; });
		return Object.keys(seen);
	}

	function refreshPrices() {
		var list = ids();
		if (!list.length || document.hidden || !cfg.endpoint) { return; }
		$.ajax({ url: cfg.endpoint, data: { ids: list.join(',') }, cache: false, dataType: 'json' })
			.done(function (res) {
				var products = (res && res.products) || {};
				Object.keys(products).forEach(function (id) {
					var p = products[id];
					$('[data-dgbp-pid="' + id + '"]').each(function () {
						var $box = $(this);
						$box.find('[data-dgbp-tier]').addBack('[data-dgbp-tier]').each(function () {
							var $el = $(this), tier = $el.data('dgbp-tier');
							if (p.available && p[tier] && $el.text() !== p[tier]) {
								$el.text(p[tier]).addClass('dgbp-updated');
								setTimeout(function () { $el.removeClass('dgbp-updated'); }, 1200);
							}
						});
					});
				});
			});
	}

	if (ids().length) {
		refreshPrices();
		setInterval(refreshPrices, (cfg.refresh || 30) * 1000);
	}

	/* ---------- Checkout: re-price when payment method / card type changes ---------- */
	var $form = $('form.checkout');
	if (!$form.length) { return; }

	function selectedGateway() {
		return $form.find('input[name="payment_method"]:checked').val() || '';
	}

	function toggleCardType() {
		var $box = $('#dgbp-card-type');
		if (!$box.length) { return; }
		var gws = String($box.data('gateways') || '').split(',');
		$box.toggle(gws.indexOf(selectedGateway()) !== -1);
	}

	$form.on('change', 'input[name="payment_method"]', function () {
		toggleCardType();
		$(document.body).trigger('update_checkout');
	});
	$form.on('change', 'input[name="dgbp_card_type"]', function () {
		$(document.body).trigger('update_checkout');
	});
	$(document.body).on('updated_checkout payment_method_selected', toggleCardType);
	toggleCardType();
});
