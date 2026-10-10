/* Masa Geo-Pricing — Frontend */
(function ($) {
	'use strict';

	if (typeof MasaGeoFront === 'undefined') {
		return;
	}

	// ─── Currency Switcher ───
	$(document).on('change', '#masa-currency-select', function () {
		$.post(MasaGeoFront.ajax_url, {
			action:   'masa_geo_switch_currency',
			nonce:    MasaGeoFront.nonce,
			currency: $(this).val()
		}, function (res) {
			if (res && res.success && res.data && res.data.reload) {
				window.location.reload();
			}
		});
	});
})(jQuery);
