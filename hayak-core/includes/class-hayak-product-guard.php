<?php
/**
 * A product is only ever public when it has a featured image.
 *
 * Why: the importer occasionally creates a product whose image download failed,
 * and WooCommerce publishes it anyway. A published product with no image renders
 * Product structured data without the required `image` field, which Google
 * Search Console reports as a critical "Merchant listings" error, and Google
 * for WooCommerce then ships the same item to Merchant Center where it is
 * disapproved. Every one of those errors on this store traced back to exactly
 * that: a live product with no `_thumbnail_id`.
 *
 * So instead of patching the schema or the feed, the store enforces the
 * invariant at the source:
 *   - a product being saved as `publish` without an image is held as a draft,
 *   - the moment a featured image lands on a held product it is published,
 *   - a published product whose featured image is removed or deleted is held again,
 *   - a daily sweep reconciles anything written behind WooCommerce's back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Product_Guard {

	const META_HELD   = '_hayak_held_no_image';
	const HELD_STATUS = 'draft';
	const SWEEP_HOOK  = 'hayak_core_product_guard_sweep';

	/** Held products that gained an image during this request; published on shutdown. */
	private static $release = array();

	/** Published products whose featured image went away this request; held on shutdown. */
	private static $recheck = array();

	public static function init() {
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'hold_if_no_image' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'thumbnail_meta_written' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'thumbnail_meta_written' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'thumbnail_meta_deleted' ), 10, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'attachment_deleted' ) );
		add_action( 'shutdown', array( __CLASS__, 'release_queued' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	public static function unschedule() {
		$next = wp_next_scheduled( self::SWEEP_HOOK );
		while ( $next ) {
			wp_unschedule_event( $next, self::SWEEP_HOOK );
			$next = wp_next_scheduled( self::SWEEP_HOOK );
		}
	}

	/**
	 * Runs inside every WC_Product::save(), REST, admin and importer alike, with
	 * the in-memory image id already set, so a product created with an image
	 * publishes normally and one without is never public for even a moment.
	 */
	public static function hold_if_no_image( $product ) {
		if ( ! $product instanceof WC_Product || 'variation' === $product->get_type() ) {
			return;
		}
		if ( 'publish' !== $product->get_status( 'edit' ) ) {
			return;
		}
		if ( $product->get_image_id( 'edit' ) ) {
			return;
		}
		$product->set_status( self::HELD_STATUS );
		$product->update_meta_data( self::META_HELD, time() );
	}

	public static function thumbnail_meta_written( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' !== $meta_key || ! (int) $meta_value ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) || ! get_post_meta( $post_id, self::META_HELD, true ) ) {
			return;
		}
		// Not published inline: this can fire in the middle of a WC save, and a
		// nested wp_update_post there would race the data store. Shutdown is
		// after every save of this request has finished.
		self::$release[ (int) $post_id ] = true;
	}

	/** Featured image removed from a published product: re-check it once the request is done. */
	public static function thumbnail_meta_deleted( $meta_ids, $post_id, $meta_key ) {
		if ( '_thumbnail_id' !== $meta_key || 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::$recheck[ (int) $post_id ] = true;
	}

	public static function release_queued() {
		foreach ( array_keys( self::$release ) as $post_id ) {
			self::publish_if_ready( $post_id );
		}
		self::$release = array();
		foreach ( array_keys( self::$recheck ) as $post_id ) {
			self::hold_if_bare( $post_id );
		}
		self::$recheck = array();
	}

	/** Published product that really has no image any more: take it off the shelf. */
	public static function hold_if_bare( $post_id ) {
		clean_post_cache( $post_id );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status( 'edit' ) ) {
			return false;
		}
		if ( $product->get_image_id( 'edit' ) ) {
			return false;
		}
		$product->set_status( self::HELD_STATUS );
		$product->update_meta_data( self::META_HELD, time() );
		$product->save();
		return true;
	}

	/** Held product with a real image now: make it public and clear the hold. */
	public static function publish_if_ready( $post_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( ! $product->get_meta( self::META_HELD ) ) {
			return false;
		}
		$image_id = $product->get_image_id( 'edit' );
		if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
			return false;
		}
		$product->delete_meta_data( self::META_HELD );
		$product->set_status( 'publish' );
		$product->save();
		return true;
	}

	/** Featured image is about to go away: hold every published product using it. */
	public static function attachment_deleted( $attachment_id ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value = %s",
				(string) $attachment_id
			)
		);
		foreach ( $ids as $id ) {
			self::hold( (int) $id );
		}
	}

	private static function hold( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$product->set_image_id( 0 );
		$product->set_status( self::HELD_STATUS );
		$product->update_meta_data( self::META_HELD, time() );
		$product->save();
	}

	/**
	 * Daily reconciliation. Catches writes that bypass WooCommerce (direct SQL,
	 * a REST client that sets `_thumbnail_id` after the product save has ended,
	 * an attachment removed with the hook suppressed).
	 */
	public static function sweep() {
		global $wpdb;
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$held = 0;
		$live = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value <> '' AND m.meta_value <> '0'
			 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_id IS NULL LIMIT 500"
		);
		foreach ( $live as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product instanceof WC_Product && ! $product->get_image_id( 'edit' ) ) {
				$product->set_status( self::HELD_STATUS );
				$product->update_meta_data( self::META_HELD, time() );
				$product->save();
				$held++;
			}
		}
		$released = 0;
		$waiting  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = %s
				 JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value <> '' AND t.meta_value <> '0'
				 WHERE p.post_type = 'product' AND p.post_status = %s LIMIT 500",
				self::META_HELD,
				self::HELD_STATUS
			)
		);
		foreach ( $waiting as $id ) {
			if ( self::publish_if_ready( (int) $id ) ) {
				$released++;
			}
		}
		update_option(
			'hayak_core_product_guard_last_sweep',
			array( 'time' => time(), 'held' => $held, 'released' => $released ),
			false
		);
	}

	public static function admin_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! get_post_meta( $post_id, self::META_HELD, true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p dir="rtl">'
			. esc_html__( 'هذا المنتج محجوز كمسودة لأنه بدون صورة رئيسية. أضف صورة المنتج وسيُنشر تلقائيًا.', 'hayak-core' )
			. '</p></div>';
	}
}
