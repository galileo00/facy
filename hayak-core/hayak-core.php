<?php
/**
 * Plugin Name:       Hayak Core
 * Description:       Hayak Store's own integrations in one place: Elementor order forms -> WooCommerce orders, the product-page quick order form, purchase dataLayer for the GTM pixels, the Taager webhook watchdog, and the storefront polish.
 * Version:           2.3.1
 * Author:            Hayak Store
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Replaces four earlier plugins: Hayak Home Polish, Hayak Taager Webhook
 * Watchdog, Hayak Pixel DataLayer and WooCommerce Quick Order Form Pro.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HAYAK_CORE_VERSION', '2.3.1' );
define( 'HAYAK_CORE_PATH', plugin_dir_path( __FILE__ ) );

require_once HAYAK_CORE_PATH . 'includes/class-hayak-fields.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-form-spec.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-orders.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-order-status.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-datalayer.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-taager-watchdog.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-theme-polish.php';
require_once HAYAK_CORE_PATH . 'includes/class-hayak-admin.php';

Hayak_Orders::init();
Hayak_Order_Status::init();

// A landing page whose price was edited must not keep selling at the old one.
add_action( 'save_post', array( 'Hayak_Form_Spec', 'flush' ) );

Hayak_DataLayer::init();
Hayak_Taager_Watchdog::init();
Hayak_Theme_Polish::init();

/**
 * Product-page quick order form.
 *
 * Guarded by file_exists so the cutover is order-independent and can never take
 * the site down: until the module file is in place this is simply a no-op, and
 * the module itself refuses to run while the old standalone plugin is still
 * active (see Hayak_Quick_Order::boot). So the file can be dropped in and the
 * old plugin switched off in either order, with no window where both or neither
 * is serving the form.
 */
$hayak_quick_order = HAYAK_CORE_PATH . 'includes/class-hayak-quick-order.php';
if ( file_exists( $hayak_quick_order ) ) {
	require_once $hayak_quick_order;
	if ( class_exists( 'Hayak_Quick_Order' ) ) {
		Hayak_Quick_Order::init();
	}
}

if ( is_admin() ) {
	Hayak_Admin::init();
}

register_activation_hook( __FILE__, 'hayak_core_activate' );
function hayak_core_activate() {
	// Direct order creation stays OFF until Make is switched off, so that
	// activating this plugin can never double-create orders.
	add_option( Hayak_Orders::OPTION_ENABLED, 'no' );
	Hayak_Taager_Watchdog::activate();
	if ( ! wp_next_scheduled( Hayak_Orders::GC_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Hayak_Orders::GC_HOOK );
	}
	Hayak_Theme_Polish::apply_settings();
	update_option( Hayak_Theme_Polish::OPTION_STAMP, Hayak_Theme_Polish::VERSION . '|' . get_stylesheet(), false );
}

register_deactivation_hook( __FILE__, 'hayak_core_deactivate' );
function hayak_core_deactivate() {
	Hayak_Taager_Watchdog::deactivate();
	$gc = wp_next_scheduled( Hayak_Orders::GC_HOOK );
	while ( $gc ) {
		wp_unschedule_event( $gc, Hayak_Orders::GC_HOOK );
		$gc = wp_next_scheduled( Hayak_Orders::GC_HOOK );
	}
}
