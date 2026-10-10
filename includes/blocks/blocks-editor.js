/**
 * Masa Geo Pricing — Block Editor
 * مكتوب بـ wp.element.createElement (بدون JSX) عشان ما يحتاجش build step
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	// أيقونة مشتركة
	var gridIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24' },
		el( 'path', {
			d: 'M3 3h7v7H3V3zm11 0h7v7h-7V3zM3 14h7v7H3v-7zm11 0h7v7h-7v-7z',
			fill: 'currentColor',
		} )
	);

	var tagIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24' },
		el( 'path', {
			d: 'M21 11.5L12.5 3H4v8.5L12.5 20 21 11.5zM6.5 7A1.5 1.5 0 116.5 4a1.5 1.5 0 010 3z',
			fill: 'currentColor',
		} )
	);

	var starIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24' },
		el( 'path', {
			d: 'M12 2l2.9 6.3 6.9.7-5.2 4.6 1.5 6.7L12 17.3 5.9 20.3l1.5-6.7L2.2 9l6.9-.7L12 2z',
			fill: 'currentColor',
		} )
	);

	// رسالة تحميل أثناء الـ SSR
	function ssrPlaceholder() {
		return el(
			'div',
			{ style: { padding: '20px', textAlign: 'center', color: '#777' } },
			__( 'جاري تحميل المعاينة…', 'masa-geo-pricing' )
		);
	}

	// ════════════════════════════════════════════════════════
	// 1) Products Grid
	// ════════════════════════════════════════════════════════
	blocks.registerBlockType( 'masa-geo/products-grid', {
		title: __( 'Masa: شبكة منتجات', 'masa-geo-pricing' ),
		description: __( 'شبكة منتجات تراعي التسعير الجغرافي تلقائياً', 'masa-geo-pricing' ),
		icon: gridIcon,
		category: 'woocommerce',
		keywords: [ 'masa', 'products', 'منتجات', 'grid' ],

		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'إعدادات العرض', 'masa-geo-pricing' ), initialOpen: true },
					el( RangeControl, {
						label: __( 'عدد الأعمدة', 'masa-geo-pricing' ),
						value: attrs.columns,
						onChange: function ( v ) { setAttributes( { columns: v } ); },
						min: 1, max: 6,
					} ),
					el( RangeControl, {
						label: __( 'عدد المنتجات', 'masa-geo-pricing' ),
						value: attrs.limit,
						onChange: function ( v ) { setAttributes( { limit: v } ); },
						min: 1, max: 24,
					} ),
					el( SelectControl, {
						label: __( 'الترتيب حسب', 'masa-geo-pricing' ),
						value: attrs.orderby,
						options: [
							{ label: __( 'الأحدث', 'masa-geo-pricing' ), value: 'date' },
							{ label: __( 'الاسم', 'masa-geo-pricing' ), value: 'title' },
							{ label: __( 'السعر', 'masa-geo-pricing' ), value: 'price' },
							{ label: __( 'الأكثر مبيعاً', 'masa-geo-pricing' ), value: 'popularity' },
							{ label: __( 'عشوائي', 'masa-geo-pricing' ), value: 'rand' },
						],
						onChange: function ( v ) { setAttributes( { orderby: v } ); },
					} ),
					el( SelectControl, {
						label: __( 'الاتجاه', 'masa-geo-pricing' ),
						value: attrs.order,
						options: [
							{ label: __( 'تنازلي', 'masa-geo-pricing' ), value: 'DESC' },
							{ label: __( 'تصاعدي', 'masa-geo-pricing' ), value: 'ASC' },
						],
						onChange: function ( v ) { setAttributes( { order: v } ); },
					} ),
					el( TextControl, {
						label: __( 'تصنيفات (slugs مفصولة بفاصلة)', 'masa-geo-pricing' ),
						value: attrs.category,
						help: __( 'مثال: shirts,shoes — اتركه فارغاً لكل المنتجات', 'masa-geo-pricing' ),
						onChange: function ( v ) { setAttributes( { category: v } ); },
					} )
				),
				el(
					PanelBody,
					{ title: __( 'العناصر الظاهرة', 'masa-geo-pricing' ), initialOpen: false },
					el( ToggleControl, {
						label: __( 'عرض السعر', 'masa-geo-pricing' ),
						checked: attrs.showPrice,
						onChange: function ( v ) { setAttributes( { showPrice: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'عرض التقييم', 'masa-geo-pricing' ),
						checked: attrs.showRating,
						onChange: function ( v ) { setAttributes( { showRating: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'زر الإضافة للسلة', 'masa-geo-pricing' ),
						checked: attrs.showAddToCart,
						onChange: function ( v ) { setAttributes( { showAddToCart: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'إظهار شارة المنطقة', 'masa-geo-pricing' ),
						checked: attrs.showZoneBadge,
						onChange: function ( v ) { setAttributes( { showZoneBadge: v } ); },
					} )
				)
			);

			return el(
				'div',
				useBlockProps(),
				inspector,
				el( ServerSideRender, {
					block: 'masa-geo/products-grid',
					attributes: attrs,
					EmptyResponsePlaceholder: ssrPlaceholder,
					LoadingResponsePlaceholder: ssrPlaceholder,
				} )
			);
		},

		save: function () { return null; }, // dynamic — render في PHP
	} );

	// ════════════════════════════════════════════════════════
	// 2) Single Product
	// ════════════════════════════════════════════════════════
	blocks.registerBlockType( 'masa-geo/single-product', {
		title: __( 'Masa: منتج واحد', 'masa-geo-pricing' ),
		description: __( 'عرض منتج واحد بكارت أنيق مع السعر المحوّل', 'masa-geo-pricing' ),
		icon: tagIcon,
		category: 'woocommerce',
		keywords: [ 'masa', 'product', 'منتج' ],

		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'إعدادات المنتج', 'masa-geo-pricing' ), initialOpen: true },
					el( TextControl, {
						label: __( 'رقم المنتج (ID)', 'masa-geo-pricing' ),
						type: 'number',
						value: attrs.productId || '',
						help: __( 'تقدر تلاقي الـ ID في قائمة المنتجات', 'masa-geo-pricing' ),
						onChange: function ( v ) { setAttributes( { productId: parseInt( v ) || 0 } ); },
					} ),
					el( SelectControl, {
						label: __( 'التخطيط', 'masa-geo-pricing' ),
						value: attrs.layout,
						options: [
							{ label: __( 'أفقي', 'masa-geo-pricing' ), value: 'horizontal' },
							{ label: __( 'عمودي', 'masa-geo-pricing' ), value: 'vertical' },
						],
						onChange: function ( v ) { setAttributes( { layout: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'عرض السعر', 'masa-geo-pricing' ),
						checked: attrs.showPrice,
						onChange: function ( v ) { setAttributes( { showPrice: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'زر الإضافة للسلة', 'masa-geo-pricing' ),
						checked: attrs.showAddToCart,
						onChange: function ( v ) { setAttributes( { showAddToCart: v } ); },
					} )
				)
			);

			return el(
				'div',
				useBlockProps(),
				inspector,
				el( ServerSideRender, {
					block: 'masa-geo/single-product',
					attributes: attrs,
					EmptyResponsePlaceholder: ssrPlaceholder,
					LoadingResponsePlaceholder: ssrPlaceholder,
				} )
			);
		},

		save: function () { return null; },
	} );

	// ════════════════════════════════════════════════════════
	// 3) Featured Products
	// ════════════════════════════════════════════════════════
	blocks.registerBlockType( 'masa-geo/featured-products', {
		title: __( 'Masa: منتجات مميزة', 'masa-geo-pricing' ),
		description: __( 'منتجات مميزة / الأكثر مبيعاً / تخفيضات', 'masa-geo-pricing' ),
		icon: starIcon,
		category: 'woocommerce',
		keywords: [ 'masa', 'featured', 'مميزة', 'best sellers' ],

		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttributes = props.setAttributes;

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'إعدادات العرض', 'masa-geo-pricing' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'المصدر', 'masa-geo-pricing' ),
						value: attrs.source,
						options: [
							{ label: __( 'منتجات مميزة', 'masa-geo-pricing' ), value: 'featured' },
							{ label: __( 'الأكثر مبيعاً', 'masa-geo-pricing' ), value: 'best_selling' },
							{ label: __( 'الأعلى تقييماً', 'masa-geo-pricing' ), value: 'top_rated' },
							{ label: __( 'في التخفيضات', 'masa-geo-pricing' ), value: 'on_sale' },
						],
						onChange: function ( v ) { setAttributes( { source: v } ); },
					} ),
					el( TextControl, {
						label: __( 'عنوان القسم (اختياري)', 'masa-geo-pricing' ),
						value: attrs.heading,
						onChange: function ( v ) { setAttributes( { heading: v } ); },
					} ),
					el( RangeControl, {
						label: __( 'عدد الأعمدة', 'masa-geo-pricing' ),
						value: attrs.columns,
						onChange: function ( v ) { setAttributes( { columns: v } ); },
						min: 1, max: 6,
					} ),
					el( RangeControl, {
						label: __( 'عدد المنتجات', 'masa-geo-pricing' ),
						value: attrs.limit,
						onChange: function ( v ) { setAttributes( { limit: v } ); },
						min: 1, max: 24,
					} ),
					el( ToggleControl, {
						label: __( 'عرض السعر', 'masa-geo-pricing' ),
						checked: attrs.showPrice,
						onChange: function ( v ) { setAttributes( { showPrice: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'زر الإضافة للسلة', 'masa-geo-pricing' ),
						checked: attrs.showAddToCart,
						onChange: function ( v ) { setAttributes( { showAddToCart: v } ); },
					} )
				)
			);

			return el(
				'div',
				useBlockProps(),
				inspector,
				el( ServerSideRender, {
					block: 'masa-geo/featured-products',
					attributes: attrs,
					EmptyResponsePlaceholder: ssrPlaceholder,
					LoadingResponsePlaceholder: ssrPlaceholder,
				} )
			);
		},

		save: function () { return null; },
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
