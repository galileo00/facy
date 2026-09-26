<?php
/**
 * A new product is filed in its category, not left in "غير مصنّف".
 *
 * Why: the Taager importer leaves every product in the default category (19,
 * "غير مصنّف"), so each import sat outside the seven store categories: no
 * category page, no breadcrumb, and "غير مصنّف" as its product type in Google
 * Merchant Center, until someone filed it by hand. A Taager SKU carries Taager's
 * own category path ("SA" then the path: SA0301... kitchen, SA0403... beauty),
 * and the products already filed show where each path belongs in this store.
 *
 * So a product whose only category is the default one is filed by its SKU: in the
 * category that most products sharing the first four path characters are in,
 * when at least MIN_SHARE of at least MIN_PRODUCTS of them agree. The map is
 * learnt from the catalogue and rebuilt daily, so it follows every correction
 * made by hand. A product whose path is ambiguous stays where it is.
 *
 * It runs whenever a product's categories are set (the importer, admin, REST),
 * and a daily sweep files anything that slipped through.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Product_Category {

	const MAP_TRANSIENT = 'hayak_core_category_map';
	const SWEEP_HOOK    = 'hayak_core_product_category_sweep';
	const MIN_PRODUCTS  = 8;
	const MIN_SHARE     = 0.7;

	private static $filing = false;

	public static function init() {
		add_action( 'set_object_terms', array( __CLASS__, 'terms_set' ), 20, 4 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 4 * HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
	}

	public static function terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy || self::$filing || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::file( $object_id );
	}

	/** File a product that is only in the default category. Returns the category id used, or 0. */
	public static function file( $post_id ) {
		$default = (int) get_option( 'default_product_cat' );
		$current = wp_get_object_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $current ) || array_diff( array_map( 'intval', $current ), array( $default ) ) ) {
			return 0;
		}
		$category = self::category_for( (string) get_post_meta( $post_id, '_sku', true ) );
		if ( ! $category ) {
			return 0;
		}
		self::$filing = true;
		$result       = wp_set_object_terms( $post_id, array( $category ), 'product_cat' );
		self::$filing = false;
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
		// Setting terms is not a product save, so Google for WooCommerce would not
		// send the new category (the product type in Merchant Center) on its own.
		if ( class_exists( 'Hayak_Merchant_Feed' ) ) {
			Hayak_Merchant_Feed::resync( array( $post_id ) );
		}
		return $category;
	}

	/** The store category for a Taager SKU, or 0 when its path is not decisive. */
	public static function category_for( $sku ) {
		if ( 0 !== strpos( $sku, 'SA' ) || strlen( $sku ) < 6 ) {
			return 0;
		}
		$map  = self::map();
		$code = strtoupper( substr( $sku, 2, 4 ) );
		return isset( $map[ $code ] ) ? (int) $map[ $code ] : 0;
	}

	/** Taager path (4 characters) => store category id, learnt from published products. */
	public static function map( $fresh = false ) {
		$map = $fresh ? false : get_transient( self::MAP_TRANSIENT );
		if ( is_array( $map ) ) {
			return $map;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT UPPER(SUBSTRING(s.meta_value, 3, 4)) AS code, tt.term_id AS category, COUNT(*) AS n
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_sku'
				 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				   AND s.meta_value LIKE %s AND tt.term_id <> %d
				 GROUP BY code, category",
				'SA%',
				(int) get_option( 'default_product_cat' )
			)
		);
		$by_code = array();
		foreach ( $rows as $row ) {
			$by_code[ $row->code ][ (int) $row->category ] = (int) $row->n;
		}
		$map = array();
		foreach ( $by_code as $code => $counts ) {
			$total = array_sum( $counts );
			arsort( $counts );
			$top = (int) key( $counts );
			if ( $total >= self::MIN_PRODUCTS && $counts[ $top ] / $total >= self::MIN_SHARE ) {
				$map[ $code ] = $top;
			}
		}
		set_transient( self::MAP_TRANSIENT, $map, DAY_IN_SECONDS );
		return $map;
	}

	/** Daily: rebuild the map and file products still only in the default category. */
	public static function sweep( $limit = 200 ) {
		global $wpdb;
		self::map( true );
		$default = (int) get_option( 'default_product_cat' );
		$ids     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
				   AND NOT EXISTS (
				     SELECT 1 FROM {$wpdb->term_relationships} tr
				     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				     WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id <> %d )
				 ORDER BY p.ID DESC LIMIT %d",
				$default,
				$limit
			)
		);
		$done = array( 'checked' => count( $ids ), 'filed' => 0 );
		foreach ( $ids as $id ) {
			if ( self::file( (int) $id ) ) {
				$done['filed']++;
			}
		}
		update_option( 'hayak_core_product_category_last_sweep', array( 'time' => time() ) + $done, false );
		return $done;
	}
}
