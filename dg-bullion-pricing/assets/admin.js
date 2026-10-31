/* DG Bullion – settings page */
jQuery(function ($) {
	$('.dgbp-color').wpColorPicker();

	var $out = $('#dgbp-test-out');

	function run(type) {
		$out.show().text('Contacting FizTrade…');
		$.post(DGBPAdmin.ajax, {
			action: 'dgbp_test',
			nonce: DGBPAdmin.nonce,
			type: type,
			code: $('#dgbp-test-code').val()
		}).done(function (res) {
			var d = res.data || {};
			var lines = ['Request: ' + (d.url || '')];
			if (res.success) {
				lines.push('', '✔ What the plugin read:', JSON.stringify(d.parsed, null, 2));
				if (type === 'spot' && (!d.parsed || !Object.keys(d.parsed).length)) {
					lines.push('', '⚠ No metal prices recognised. Send the raw response below to your developer.');
				}
				if (type === 'product' && (!d.parsed || d.parsed.dealer_ask === null)) {
					lines.push('', '⚠ No ask price recognised. Enter the right field name in "Product price field".');
				}
				lines.push('', 'Raw response:', JSON.stringify(d.raw, null, 2));
			} else {
				lines.push('', '✖ ' + (d.error || d || 'Request failed'));
				if (d.body) { lines.push('', d.body); }
			}
			$out.text(lines.join('\n'));
		}).fail(function (xhr) {
			$out.text('Request failed: HTTP ' + xhr.status);
		});
	}

	$('#dgbp-test-spot').on('click', function () { run('spot'); });
	$('#dgbp-test-product').on('click', function () { run('product'); });
});
