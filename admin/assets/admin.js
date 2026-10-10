/* global jQuery, MasaGeo */
(function ($) {
    'use strict';

    function showNotice(msg, type) {
        var $n = $('#masa-geo-notice');
        $n.removeClass('masa-geo-notice--success masa-geo-notice--error')
          .addClass('masa-geo-notice--' + type)
          .html(msg)
          .slideDown(200);
        setTimeout(function () { $n.slideUp(300); }, 4000);
    }

    // Toggle Zone Body
    $(document).on('click', '.masa-toggle-zone', function (e) {
        e.stopPropagation();
        $(this).closest('.masa-zone-row').find('.masa-zone-body').slideToggle(200);
    });

    $(document).on('click', '.masa-zone-header', function () {
        $(this).next('.masa-zone-body').slideToggle(200);
    });

    // حذف Zone
    $(document).on('click', '.masa-delete-zone', function (e) {
        e.stopPropagation();
        if (!confirm(MasaGeo.i18n.confirm_delete)) return;
        $(this).closest('.masa-zone-row').fadeOut(250, function () { $(this).remove(); });
    });

    // إضافة Zone جديد
    $('#masa-add-zone').on('click', function () {
        var idx  = Date.now();
        var html = buildZoneRow({ id: 'zone_' + idx, label: '', currency: 'USD', multiplier: '1.00', countries: '' }, idx);
        $('#masa-zones-container').append(html);
        $('#masa-zones-container .masa-zone-row').last().find('.masa-zone-body').show();
    });

    function buildZoneRow(zone, idx) {
        return '<div class="masa-zone-row" data-index="' + idx + '">' +
            '<div class="masa-zone-header">' +
                '<span class="masa-zone-drag">☰</span>' +
                '<strong class="masa-zone-label-display">' + escHtml(zone.label || '...') + '</strong>' +
                '<div class="masa-zone-actions">' +
                    '<button type="button" class="button button-small masa-toggle-zone">تعديل</button>' +
                    '<button type="button" class="button button-small button-link-delete masa-delete-zone">حذف</button>' +
                '</div>' +
            '</div>' +
            '<div class="masa-zone-body">' +
                '<input type="hidden" class="masa-zone-id" value="' + escHtml(zone.id) + '" />' +
                '<table class="form-table">' +
                    '<tr><th>اسم المنطقة</th><td><input type="text" class="masa-zone-label regular-text" value="' + escHtml(zone.label) + '" /></td></tr>' +
                    '<tr><th>رمز العملة</th><td><input type="text" class="masa-zone-currency small-text" value="' + escHtml(zone.currency) + '" maxlength="3" style="width:80px;" /></td></tr>' +
                    '<tr><th>المضاعف</th><td><input type="number" class="masa-zone-multiplier small-text" value="' + escHtml(zone.multiplier) + '" step="0.01" min="0.01" style="width:100px;" /></td></tr>' +
                    '<tr><th>مصدر سعر الصرف</th><td><select class="masa-zone-rate-mode"><option value="api" selected>تلقائي (API) مع احتياطي</option><option value="manual">يدوي إجباري</option></select></td></tr>' +
                    '<tr><th>سعر الصرف اليدوي/الاحتياطي</th><td><div style="display:flex;align-items:center;gap:5px;"><span>1 <strong class="masa-zone-currency-badge">' + escHtml(zone.currency || 'USD') + '</strong> = </span><input type="number" class="masa-zone-manual-rate small-text" value="' + escHtml(zone.manual_rate || '') + '" step="0.0001" min="0" style="width:100px;" /><span>EGP</span></div><p class="description">لو اخترت تلقائي، سيتم استخدامه كاحتياطي عند فشل الـ API. لو اخترت يدوي إجباري، سيتم تجاهل الـ API.</p></td></tr>' +
                    '<tr><th>الحد الأدنى للطلب</th><td><input type="number" class="masa-zone-minimum-order small-text" value="' + escHtml(zone.minimum_order || '') + '" step="0.01" min="0" style="width:100px;" /> <span class="masa-zone-currency-badge" style="font-weight:600;">' + escHtml(zone.currency || 'USD') + '</span><p class="description">الحد الأدنى للطلب لهذه المنطقة بالعملة المحلية. اتركه فارغاً أو 0 لتعطيل الميزة.</p></td></tr>' +
                    '<tr><th>الدفع عند الاستلام (COD)</th><td><label><input type="checkbox" class="masa-zone-cod-enabled" value="1" ' + (zone.cod_enabled === undefined || zone.cod_enabled ? 'checked' : '') + ' /> تفعيل الدفع عند الاستلام لهذه المنطقة</label></td></tr>' +
                    '<tr><th>الدول (كود ISO)</th><td><textarea class="masa-zone-countries large-text" rows="3" placeholder="SA, AE, KW">' + escHtml(zone.countries) + '</textarea></td></tr>' +
                '</table>' +
            '</div>' +
        '</div>';
    }

    // تحديث الاسم عند الكتابة
    $(document).on('input', '.masa-zone-label', function () {
        var val = $(this).val().trim() || '...';
        $(this).closest('.masa-zone-row').find('.masa-zone-label-display').text(val);
    });

    // جمع بيانات الـ Zones
    function collectZones() {
        var zones = [];
        $('#masa-zones-container .masa-zone-row').each(function () {
            var $row        = $(this);
            var id          = $row.find('.masa-zone-id').val().trim();
            var label       = $row.find('.masa-zone-label').val().trim();
            var currency    = $row.find('.masa-zone-currency').val().trim().toUpperCase();
            var multiplier  = parseFloat($row.find('.masa-zone-multiplier').val()) || 1.0;
            var rateMode    = $row.find('.masa-zone-rate-mode').val() || 'api';
            var manualRate  = parseFloat($row.find('.masa-zone-manual-rate').val()) || 0.0;
            var minimumOrder = parseFloat($row.find('.masa-zone-minimum-order').val()) || 0.0;
            var codEnabled = $row.find('.masa-zone-cod-enabled').is(':checked') ? 1 : 0;
            var countriesRaw = $row.find('.masa-zone-countries').val();

            var countries;
            if (countriesRaw === '__FALLBACK__') {
                countries = ['__FALLBACK__'];
            } else {
                countries = countriesRaw.split(/[\s,،]+/)
                    .map(function (c) { return c.trim().toUpperCase(); })
                    .filter(function (c) { return c.length === 2; });
            }

            if (!id || !label) return;
            zones.push({ id: id, label: label, currency: currency || 'USD', multiplier: multiplier, minimum_order: minimumOrder, countries: countries, rate_mode: rateMode, manual_rate: manualRate, cod_enabled: codEnabled });
        });
        return zones;
    }

    // حفظ Zones
    $('#masa-save-zones').on('click', function () {
        var $btn = $(this).prop('disabled', true).text(MasaGeo.i18n.saving);
        $.post(MasaGeo.ajax_url, {
            action: 'masa_geo_save_zones',
            nonce:  MasaGeo.nonce,
            zones:  JSON.stringify(collectZones()),
        })
        .done(function (res) {
            showNotice(res.success ? res.data.message : (res.data.message || MasaGeo.i18n.save_fail), res.success ? 'success' : 'error');
        })
        .fail(function () { showNotice(MasaGeo.i18n.save_fail, 'error'); })
        .always(function () { $btn.prop('disabled', false).text('💾 حفظ جميع المناطق'); });
    });

    // تحديث الأسعار
    $('#masa-refresh-rates').on('click', function () {
        var $btn = $(this).prop('disabled', true).text(MasaGeo.i18n.refreshing);
        $.post(MasaGeo.ajax_url, { action: 'masa_geo_refresh_rates', nonce: MasaGeo.nonce })
        .done(function (res) {
            if (res.success) { $('#masa-last-update').text(res.data.last_update); }
            showNotice(res.success ? res.data.message : (res.data.message || MasaGeo.i18n.refresh_fail), res.success ? 'success' : 'error');
        })
        .fail(function () { showNotice(MasaGeo.i18n.refresh_fail, 'error'); })
        .always(function () { $btn.prop('disabled', false).text('🔄 تحديث الآن'); });
    });

    // تحديث قاعدة بيانات Geolocation
    $('#masa-update-geoip').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('جاري التحديث...');
        var $status = $('#masa-geoip-status');
        $status.css('color', '#6b7280').text('جاري الاتصال والتحميل من MaxMind...');
        $.post(MasaGeo.ajax_url, { action: 'masa_geo_update_geoip', nonce: MasaGeo.nonce })
        .done(function (res) {
            $status.css('color', res.success ? '#16a34a' : '#dc2626').text(res.data.message);
        })
        .fail(function () {
            $status.css('color', '#dc2626').text('فشل تحديث قاعدة البيانات بسبب خطأ في السيرفر.');
        })
        .always(function () {
            $btn.prop('disabled', false).text('🔄 تحديث الآن');
        });
    });

    // Show/Hide API Key
    // v3.4.0: إظهار قائمة الـ Trusted Proxies بس في الوضع المخصص
    $('#masa_geo_proxy_mode').on('change', function () {
        $('#masa_trusted_proxies_row').toggle($(this).val() === 'custom');
    });

    $('#masa_api_provider').on('change', function () {
        $(this).val() === 'exchangerate_api' ? $('#masa_api_key_row').show() : $('#masa_api_key_row').hide();
    });

    // تحديث رمز العملة بجانب الحد الأدنى للطلب ديناميكياً عند الكتابة
    $(document).on('input', '.masa-zone-currency', function () {
        var val = $(this).val().trim().toUpperCase() || 'EGP';
        $(this).closest('.masa-zone-row').find('.masa-zone-currency-badge').text(val);
    });

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ─── شحن المدن التفصيلي (إضافة وحذف ديناميكي) ───
    $('#masa-add-shipping-city').on('click', function () {
        var $body = $('#masa-shipping-cities-body');
        var index = $body.find('.masa-shipping-city-row').length;
        var rowHtml = '<tr class="masa-shipping-city-row">' +
            '<td style="padding: 8px;">' +
                '<input type="text" name="masa_geo_shipping_cities[' + index + '][country]" value="" placeholder="SA" maxlength="2" style="width: 80px; text-transform: uppercase;" required />' +
            '</td>' +
            '<td style="padding: 8px;">' +
                '<input type="text" name="masa_geo_shipping_cities[' + index + '][city]" value="" placeholder="الرياض" style="width: 95%;" required />' +
            '</td>' +
            '<td style="padding: 8px;">' +
                '<input type="number" step="0.01" min="0" name="masa_geo_shipping_cities[' + index + '][cost]" value="" placeholder="30" style="width: 110px;" required />' +
            '</td>' +
            '<td style="padding: 8px; text-align: center; vertical-align: middle;">' +
                '<button type="button" class="button button-link-delete masa-remove-city-row" style="color: #dc2626; font-weight: bold;">✕</button>' +
            '</td>' +
        '</tr>';
        $body.append(rowHtml);
    });

    $(document).on('click', '.masa-remove-city-row', function () {
        $(this).closest('.masa-shipping-city-row').remove();
        // إعادة فهرسة المدخلات لمنع وجود فجوات في المصفوفة المرسلة
        $('#masa-shipping-cities-body .masa-shipping-city-row').each(function (i) {
            $(this).find('input').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    var newName = name.replace(/\[\d+\]/, '[' + i + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    });

})(jQuery);

/* ═══ Product Panel (تاب التسعير الجغرافي) — v3.5.0: اتنقلت من inline ═══ */
jQuery(function ($) {
	'use strict';

	var $panel = $('#masa_geo_pricing_panel');
	if (!$panel.length) { return; }

	// ─── المخزون الجغرافي: تحديث الحالة لحظياً مع الكتابة ───
	$(document).on('input change', '.masa-zone-stock-field', function () {
		var val     = $.trim($(this).val());
		var $status = $(this).closest('tr').find('.masa-zone-stock-status');
		if (val === '') {
			$status.html('<span style="color:#6b7280;">\u267E\uFE0F يتبع المخزون العام</span>');
		} else if (parseInt(val, 10) === 0) {
			$status.html('<span style="color:#dc2626;font-weight:600;">\uD83D\uDEAB غير متاح في المنطقة</span>');
		} else {
			$status.html('<span style="color:#16a34a;font-weight:600;">\u2705 متاح \u2014 ' + parseInt(val, 10) + ' قطعة</span>');
		}
	});

	// ─── منتج حسب الطلب: إخفاء حقول التسعير ───
	function toggleMasaPricing() {
		var excluded = $('#masa_geo_exclude').is(':checked');
		$('#masa-pricing-fields-wrap').toggle(!excluded);
		$panel.find('.masa-product-panel-header').eq(1).css(
			'background', excluded ? '#fef9ec' : '#fff'
		);
	}
	$(document).on('change', '#masa_geo_exclude', toggleMasaPricing);
	toggleMasaPricing();

	// ─── التسعير المؤقت: إضافة/حذف قواعد ───
	$(document).on('click', '.masa-add-timed-rule', function () {
		var zone  = $(this).data('zone');
		var $wrap = $('.masa-timed-rules[data-zone="' + zone + '"]');
		var ri    = $wrap.children().length;
		$wrap.append('<div class="masa-timed-rule" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
			+ '<input type="text" placeholder="وصف (مثال: رمضان)" name="masa_geo_timed[' + zone + '][' + ri + '][label]" style="width:130px;" />'
			+ '<input type="number" step="0.01" min="0" placeholder="سعر EGP" name="masa_geo_timed[' + zone + '][' + ri + '][price]" style="width:110px;" />'
			+ '<input type="date" name="masa_geo_timed[' + zone + '][' + ri + '][date_from]" style="width:140px;" />'
			+ '<span style="color:#9ca3af;">→</span>'
			+ '<input type="date" name="masa_geo_timed[' + zone + '][' + ri + '][date_to]" style="width:140px;" />'
			+ '<button type="button" class="button button-small masa-remove-timed-rule" style="color:#dc2626;">✕</button>'
			+ '</div>');
	});
	$(document).on('click', '.masa-remove-timed-rule', function () {
		$(this).closest('.masa-timed-rule').remove();
	});
});
