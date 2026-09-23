<?php
/**
 * Storefront data the home page and menus depend on.
 *
 *  - [hayak_best_sellers]  products actually sold in the last N days, in stock,
 *    topped up from all-time sales when the window is thin. Flatsome's own
 *    "sales" ordering uses all-time totals, so a product that sold out months
 *    ago stays on top forever; this one follows real recent demand.
 *  - the "offers" product tag: any product whose title starts with "عرض" or
 *    joins items with "+" is a bundle, and the tag gives those a real archive
 *    page for the menu instead of a link back to the home page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Storefront {

	const OFFER_TAG_SLUG = 'offers';
	const OFFER_TAG_NAME = 'عروض وباقات';
	const CACHE_KEY      = 'hayak_best_sellers_ids';

	public static function init() {
		add_shortcode( 'hayak_best_sellers', array( __CLASS__, 'best_sellers_shortcode' ) );
		// After WooCommerce has registered its taxonomies (init 5).
		add_action( 'init', array( __CLASS__, 'ensure_offer_tag' ), 20 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'tag_offer' ) );
		// A completed order changes the ranking; the cache is cheap to rebuild.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	public static function ensure_offer_tag() {
		if ( ! taxonomy_exists( 'product_tag' ) || term_exists( self::OFFER_TAG_SLUG, 'product_tag' ) ) {
			return;
		}
		wp_insert_term( self::OFFER_TAG_NAME, 'product_tag', array( 'slug' => self::OFFER_TAG_SLUG ) );
	}

	/** True for "عرض 3 قطع ..." and "X + Y" bundle titles. */
	/**
	 * An offer is a bundle or a multi-pack: the title opens with عرض / باقة /
	 * باكج / بكج, joins items with "+", or sells two of the item (قطعتين, حبتين).
	 * "N قطع" alone is not enough: a 4-piece luggage set is one product.
	 */
	public static function is_offer_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return false;
		}
		return (bool) preg_match( '/^[^\p{Arabic}]{0,3}(?:عرض|باقة|باكج|بكج)(?:\s|$|[:(\x{0027}"])/u', $title )
			|| false !== strpos( $title, '+' )
			|| (bool) preg_match( '/(?:قطعتين|حبتين)/u', $title );
	}

	public static function tag_offer( $product ) {
		if ( ! $product instanceof WC_Product || 'variation' === $product->get_type() ) {
			return;
		}
		$id  = $product->get_id();
		$has = has_term( self::OFFER_TAG_SLUG, 'product_tag', $id );
		if ( self::is_offer_title( $product->get_name() ) ) {
			if ( ! $has ) {
				wp_set_object_terms( $id, self::OFFER_TAG_SLUG, 'product_tag', true );
			}
		} elseif ( $has ) {
			wp_remove_object_terms( $id, self::OFFER_TAG_SLUG, 'product_tag' );
		}
	}

	/**
	 * Product ids ordered by units sold in the last $days days, published and
	 * in stock only, topped up with all-time best sellers to reach $limit.
	 */
	public static function best_seller_ids( $days = 30, $limit = 8 ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$limit = max( 1, (int) $limit );
		$cache = get_transient( self::CACHE_KEY );
		$ckey  = $days . ':' . $limit;
		if ( is_array( $cache ) && isset( $cache[ $ckey ] ) ) {
			return $cache[ $ckey ];
		}

		$live = "JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_type = 'product' AND p.post_status = 'publish'
		         JOIN {$wpdb->prefix}wc_product_meta_lookup ml ON ml.product_id = p.ID AND ml.stock_status = 'instock'";

		$recent = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.product_id
				 FROM {$wpdb->prefix}wc_order_product_lookup l
				 JOIN {$wpdb->posts} o ON o.ID = l.order_id AND o.post_status IN ('wc-processing','wc-completed','wc-on-hold')
				 $live
				 WHERE l.date_created > DATE_SUB( NOW(), INTERVAL %d DAY )
				 GROUP BY l.product_id
				 HAVING SUM(l.product_qty) > 0
				 ORDER BY SUM(l.product_qty) DESC, COUNT(DISTINCT l.order_id) DESC
				 LIMIT %d",
				$days,
				$limit
			)
		);
		$ids = array_map( 'intval', (array) $recent );

		if ( count( $ids ) < $limit ) {
			$exclude = $ids ? implode( ',', $ids ) : '0';
			$top_up  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.product_id
					 FROM {$wpdb->prefix}wc_product_meta_lookup l
					 $live
					 WHERE l.product_id NOT IN ($exclude) AND l.total_sales > 0
					 ORDER BY l.total_sales DESC, p.post_date DESC
					 LIMIT %d",
					$limit - count( $ids )
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', (array) $top_up ) );
		}

		$cache          = is_array( $cache ) ? $cache : array();
		$cache[ $ckey ] = $ids;
		set_transient( self::CACHE_KEY, $cache, HOUR_IN_SECONDS );
		return $ids;
	}

	public static function best_sellers_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'days'        => 30,
				'number'      => 8,
				'columns'     => 4,
				'columns__sm' => 2,
			),
			$atts,
			'hayak_best_sellers'
		);
		$ids = self::best_seller_ids( (int) $atts['days'], (int) $atts['number'] );
		if ( empty( $ids ) ) {
			return '';
		}
		if ( ! shortcode_exists( 'ux_products' ) ) {
			return do_shortcode( '[products ids="' . esc_attr( implode( ',', $ids ) ) . '" columns="' . (int) $atts['columns'] . '" orderby="post__in"]' );
		}
		return do_shortcode(
			'[ux_products ids="' . esc_attr( implode( ',', $ids ) ) . '" columns="' . (int) $atts['columns'] . '" columns__sm="' . (int) $atts['columns__sm'] . '" out_of_stock="exclude" image_hover="zoom"]'
		);
	}
}
