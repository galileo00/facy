<?php
/**
 * Storefront cosmetics: theme mods, footer widgets and CSS.
 *
 * Behaviour is identical to the old Hayak Home Polish plugin, with one fix:
 * the theme mods and widget options used to be rewritten on EVERY page load.
 * They are now applied once and only re-applied when this version changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Theme_Polish {

	const VERSION      = '1.3.0';
	const OPTION_STAMP = 'hayak_core_polish_applied';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_apply_settings' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'css' ), 99 );
		// Flatsome's category page title style prints only the breadcrumb, so a
		// category page had no <h1> at all. Give it one above the product grid.
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'category_heading' ), 1 );
	}

	/** One <h1> per category or tag archive: the term name, followed by its description. */
	public static function category_heading() {
		if ( ! function_exists( 'is_product_category' ) || ! ( is_product_category() || is_product_tag() ) ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term || empty( $term->name ) ) {
			return;
		}
		echo '<h1 class="hayak-cat-title" dir="rtl">' . esc_html( $term->name ) . '</h1>';
		// WooCommerce prints the term description itself on the first page only
		// (woocommerce_taxonomy_archive_description), so nothing else is needed.
	}

	/** Re-apply only when this module's version or the active theme changes. */
	public static function maybe_apply_settings() {
		$stamp = self::VERSION . '|' . get_stylesheet();
		if ( get_option( self::OPTION_STAMP ) === $stamp ) {
			return;
		}
		self::apply_settings();
		update_option( self::OPTION_STAMP, $stamp, false );
	}

	public static function apply_settings() {
		self::theme_mods();
		self::footer_widgets();
	}

	protected static function theme_mods() {
		set_theme_mod( 'topbar_elements_left', array() );
		set_theme_mod( 'topbar_elements_right', array() );
		set_theme_mod( 'header_elements_left', array( 'search', 'nav' ) );
		set_theme_mod( 'header_elements_right', array( 'cart' ) );
		set_theme_mod( 'header_mobile_elements_left', array( 'menu-icon' ) );
		set_theme_mod( 'header_mobile_elements_right', array( 'cart' ) );
		set_theme_mod( 'mobile_sidebar', array( 'search-form', 'nav' ) );
		set_theme_mod( 'wc_account_links', false );

		set_theme_mod( 'follow_twitter', '' );
		set_theme_mod( 'follow_facebook', '' );
		set_theme_mod( 'follow_instagram', '' );
		set_theme_mod( 'follow_email', '' );

		set_theme_mod( 'color_primary', '#1A2A4F' );
		set_theme_mod( 'color_secondary', '#C9A24B' );
		set_theme_mod( 'color_success', '#1A2A4F' );
		set_theme_mod( 'type_texts', array( 'font-family' => 'Arial', 'variant' => 'regular' ) );
		set_theme_mod( 'type_headings', array( 'font-family' => 'Arial', 'variant' => '700' ) );
		set_theme_mod( 'type_nav', array( 'font-family' => 'Arial', 'variant' => '700' ) );
	}

	protected static function footer_widgets() {
		$widgets = get_option( 'widget_text' );
		if ( ! is_array( $widgets ) ) {
			$widgets = array();
		}

		$widgets[20] = array(
			'title'  => 'عن حياك ستور',
			'text'   => '<div dir="rtl" style="line-height:1.9">حياك ستور وجهتك لتسوق منتجات مختارة للبيت، الجمال، الإلكترونيات، السيارة والرحلات بأسعار منافسة وتجربة شراء سهلة.</div>',
			'filter' => false,
		);
		$widgets[21] = array(
			'title'  => 'روابط مهمة',
			'text'   => '<div dir="rtl" style="line-height:2.1"><a href="/shop/">تسوق الآن</a><br><a href="/product-tag/offers/">عروض وباقات</a><br><a href="/wpautoterms/shipping-policy/">سياسة الشحن والتوصيل</a><br><a href="/wpautoterms/return-policy/">سياسة الاستبدال والاسترجاع</a><br><a href="/wpautoterms/privacy-policy/">سياسة الخصوصية</a><br><a href="/wpautoterms/terms-and-conditions/">الشروط والأحكام</a></div>',
			'filter' => false,
		);
		$widgets[22] = array(
			'title'  => 'أقسام المتجر',
			'text'   => '<div dir="rtl" style="line-height:2.1"><a href="/product-category/home-kitchen/">المنزل والمطبخ</a><br><a href="/product-category/electronics/">الإلكترونيات</a><br><a href="/product-category/health-beauty/">الصحة والجمال</a><br><a href="/product-category/tools-repairs-main/">أدوات وإصلاحات</a><br><a href="/product-category/entertainment-games/">الترفيه والألعاب</a><br><a href="/product-category/sports-fitness/">الرياضة واللياقة</a><br><a href="/product-category/car-accessories/">السيارة</a></div>',
			'filter' => false,
		);
		$widgets[23] = array(
			'title'  => 'خدمة العملاء',
			'text'   => '<div dir="rtl" style="line-height:1.9">توصيل مجاني لكل السعودية<br>الدفع عند الاستلام<br>استبدال واسترجاع وفق السياسة<br>دعم سريع لمساعدتك</div>',
			'filter' => false,
		);
		$widgets['_multiwidget'] = 1;
		update_option( 'widget_text', $widgets );

		$sidebars = get_option( 'sidebars_widgets' );
		if ( is_array( $sidebars ) ) {
			$sidebars['sidebar-footer-1'] = array();
			$sidebars['sidebar-footer-2'] = array( 'text-20', 'text-21', 'text-22', 'text-23' );
			update_option( 'sidebars_widgets', $sidebars );
		}
	}

	public static function css() {
		?>
	<style id="hayak-home-polish-css">
	body, button, input, textarea, select{font-family:Arial,Tahoma,sans-serif!important}
	.footer-widgets{background:#1A2A4F!important;color:#fff!important;border-top:3px solid #C9A24B!important;padding-top:34px!important;padding-bottom:26px!important;direction:rtl}
	.footer-widgets .widget-title,.footer-widgets .widgettitle{color:#C9A24B!important;font-weight:800!important;letter-spacing:0!important;text-align:right!important}
	.footer-widgets p,.footer-widgets li,.footer-widgets div{color:#f4ead7!important;text-align:right!important}
	.footer-widgets a{color:#fff!important;text-decoration:none!important}
	.footer-widgets a:hover{color:#C9A24B!important}
	.footer-widgets .is-divider{background:#C9A24B!important;margin-right:0!important}
	.absolute-footer{background:#111b32!important;color:#fff!important;border-top:1px solid rgba(201,162,75,.35)!important}
	.absolute-footer .payment-icons{margin-bottom:8px!important}
	.absolute-footer .copyright-footer{font-size:0!important}
	.absolute-footer .copyright-footer:after{content:' © 2026 حياك ستور - جميع الحقوق محفوظة';font-size:13px!important;color:#f4ead7!important}
	.hayak-cat-title{direction:rtl;text-align:right;color:#1A2A4F;font-size:26px;font-weight:800;margin:6px 0 14px}
	.tax-product_cat .term-description,.tax-product_tag .term-description{direction:rtl;text-align:right;line-height:1.9;color:#333;margin-bottom:18px}
	.hayak-reviews-section .col-inner{height:100%}
	.hayak-reviews-section{background:#fff!important}
	.single-product{direction:rtl!important}
	.single-product .product-info,.single-product .summary,.single-product .product-summary,.single-product .product-main{text-align:right!important;direction:rtl!important}
	.single-product form.cart,.single-product .wc-quick-order-form,.single-product .quick-order-form,.single-product .quick-order-form-pro,.single-product [class*="quick-order"],.single-product [id*="quick-order"],.single-product [class*="order-form"],.single-product [id*="order-form"]{direction:rtl!important;text-align:right!important;background:#fff!important;border:1px solid #e9e3d5!important;border-radius:18px!important;padding:12px!important;box-shadow:0 8px 22px rgba(26,42,79,.07)!important;margin-top:10px!important}
	.single-product form.cart input,.single-product form.cart select,.single-product form.cart textarea,.single-product [class*="quick-order"] input,.single-product [id*="quick-order"] input,.single-product [class*="order-form"] input,.single-product [id*="order-form"] input,.single-product [class*="quick-order"] select,.single-product [id*="quick-order"] select,.single-product [class*="order-form"] select,.single-product [id*="order-form"] select,.single-product [class*="quick-order"] textarea,.single-product [id*="quick-order"] textarea,.single-product [class*="order-form"] textarea,.single-product [id*="order-form"] textarea{direction:rtl!important;text-align:right!important;border-radius:10px!important;border:1px solid #ded8cc!important;min-height:40px!important}
	.single-product form.cart button,.single-product form.cart .button,.single-product [class*="quick-order"] button,.single-product [id*="quick-order"] button,.single-product [class*="order-form"] button,.single-product [id*="order-form"] button,.single-product [class*="quick-order"] .button,.single-product [id*="quick-order"] .button,.single-product [class*="order-form"] .button,.single-product [id*="order-form"] .button{border-radius:12px!important;font-weight:800!important;min-height:40px!important}
	.single-product .price,.single-product .product-title,.single-product .product_meta{text-align:right!important;direction:rtl!important}
	.single-product form.cart p,.single-product form.cart label,.single-product [class*="quick-order"] p,.single-product [id*="quick-order"] p,.single-product [class*="order-form"] p,.single-product [id*="order-form"] p,.single-product [class*="quick-order"] label,.single-product [id*="quick-order"] label,.single-product [class*="order-form"] label,.single-product [id*="order-form"] label{margin-bottom:6px!important}
	.single-product [class*="quick-order"] .form-row,.single-product [id*="quick-order"] .form-row,.single-product [class*="order-form"] .form-row,.single-product [id*="order-form"] .form-row{margin-bottom:10px!important}
	@media(max-width:549px){.footer-widgets{padding:28px 18px!important}.footer-widgets .widget-title,.footer-widgets .widgettitle{text-align:right!important;font-size:16px!important}}
	</style>
		<?php
	}
}
