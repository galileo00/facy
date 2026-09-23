<?php
/**
 * Dead URLs get a real destination instead of a 404.
 *
 * Why: Search Console's "Not found (404)" list on this store is made of URLs
 * that used to work or that people still follow, none of which should end on
 * a 404 page:
 *   1. products that were deleted or trashed (catalogue clean-ups, the Taager
 *      sync, which trashes products that stay out of stock or leave the
 *      supplier's catalogue),
 *   2. category URLs from before the category tree was flattened, and the old
 *      /shop-2/ page,
 *   3. product links shared elsewhere with one or two Arabic letters mangled
 *      ("شظيه" for "شبيه", "بالطاقإ" for "بالطاقة"),
 *   4. archive page numbers past the end after the catalogue shrank.
 * WordPress only redirects a URL whose post still exists under an old slug,
 * so every one of these fell through to a 404, or to WordPress's own guess,
 * which picks any product whose slug merely starts the same way.
 *
 * The module plugs into that guess (pre_redirect_guess_404_permalink), so there
 * is one redirect authority and it only ever sees requests WordPress has already
 * decided are 404s, after wp_old_slug_redirect: it can never shadow a live page.
 *   - A stored map of retired paths gives each one its destination (301), or
 *     410 when something is gone with no sensible replacement.
 *   - A published product that is trashed or deleted adds its own entry: to an
 *     equivalent live product when one exists, otherwise to its category. Rules
 *     that pointed at it are moved to the same destination, so no chains form.
 *   - /product/<slug>/ that matches no product but is a letter or two away from
 *     exactly one live product is sent to that product.
 *   - /page/N/ past the end of a live archive goes to the archive's first page.
 *   - Anything else falls through to WordPress's own guess, then to the 404.
 * The query string, including ad attribution (utm_*, ttclid, fbclid...), is
 * carried by redirect_canonical.
 *
 * It also retires ?product-page=N on the shop, product archives and the front
 * page. That parameter came from a [products paginate="true"] block that sat on
 * the Shop page on top of WooCommerce's own listing, so every /shop/page/N/
 * linked to every ?product-page=M: thousands of near-duplicate URLs, several
 * of them indexed. The block is gone; the URLs Google already has collapse
 * back into the real archive page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_URL_Recovery {

	const OPTION_MAP = 'hayak_redirect_map';
	const SLUG_INDEX = 'hayak_product_slug_index';

	/** Query parameters worth carrying through a redirect this module issues itself. */
	const KEEP_QUERY = '/^(utm_[a-z_]+|ttclid|fbclid|gclid|gbraid|wbraid|msclkid|sccid|sc_click_id|twclid)$/i';

	public static function init() {
		add_filter( 'pre_redirect_guess_404_permalink', array( __CLASS__, 'guess' ) );
		add_action( 'template_redirect', array( __CLASS__, 'drop_retired_params' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'after_canonical' ), 20 );
		add_action( 'wp_trash_post', array( __CLASS__, 'product_removed' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'product_removed' ), 10, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'product_restored' ), 10, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( 'post_updated', array( __CLASS__, 'post_updated' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ *
	 * Redirect map
	 * ------------------------------------------------------------------ */

	/** Map key for a path: decoded, no slashes at either end, lower-case. */
	public static function key( $path ) {
		$path = rawurldecode( (string) $path );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( $path, '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		return mb_strtolower( $path, 'UTF-8' );
	}

	/** The stored map, keyed by key(); keys written any other way are normalised on read. */
	public static function map() {
		$map = get_option( self::OPTION_MAP, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$clean = array();
		foreach ( $map as $path => $rule ) {
			if ( is_array( $rule ) && isset( $rule['c'] ) ) {
				$clean[ self::key( $path ) ] = $rule;
			}
		}
		return $clean;
	}

	/**
	 * Add or replace retired paths.
	 *
	 * @param array $rules path => array( 't' => target path or URL, 'c' => 301|410, 's' => source label ).
	 */
	public static function add_rules( array $rules ) {
		$map = self::map();
		foreach ( $rules as $path => $rule ) {
			$key = self::key( $path );
			if ( '' === $key || ! is_array( $rule ) ) {
				continue;
			}
			$code = isset( $rule['c'] ) ? (int) $rule['c'] : 301;
			if ( 410 !== $code && empty( $rule['t'] ) ) {
				continue;
			}
			$target = 410 === $code ? '' : self::final_target( (string) $rule['t'], $map );
			if ( 410 !== $code && self::target_key( $target ) === $key ) {
				continue; // A rule may never point at itself.
			}
			$map[ $key ] = array(
				't' => $target,
				'c' => 410 === $code ? 410 : 301,
				's' => isset( $rule['s'] ) ? (string) $rule['s'] : 'manual',
				'd' => gmdate( 'Y-m-d' ),
			);
			// Whatever pointed at the retired path now points where it points.
			foreach ( $map as $other => $r ) {
				if ( $other !== $key && 301 === (int) $r['c'] && self::target_key( $r['t'] ) === $key ) {
					$map[ $other ]['t'] = $target;
					$map[ $other ]['c'] = 410 === $code ? 410 : 301;
				}
			}
		}
		update_option( self::OPTION_MAP, $map, false );
		self::flush_index();
	}

	protected static function target_key( $target ) {
		return self::key( (string) wp_parse_url( self::absolute( $target ), PHP_URL_PATH ) );
	}

	/** Follow a target through the map so a new rule never starts a chain. */
	protected static function final_target( $target, array $map ) {
		for ( $hop = 0; $hop < 5; $hop++ ) {
			$k = self::target_key( $target );
			if ( ! isset( $map[ $k ] ) || 301 !== (int) $map[ $k ]['c'] ) {
				break;
			}
			$target = $map[ $k ]['t'];
		}
		return $target;
	}

	public static function remove_rule( $path ) {
		$map = self::map();
		$key = self::key( $path );
		if ( isset( $map[ $key ] ) ) {
			unset( $map[ $key ] );
			update_option( self::OPTION_MAP, $map, false );
			self::flush_index();
		}
	}

	/** Exact path, then the same path without a trailing /page/N. */
	protected static function lookup( $key ) {
		$map = self::map();
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( preg_match( '#^(.+?)/page/\d+$#', $key, $m ) && isset( $map[ $m[1] ] ) ) {
			return $map[ $m[1] ];
		}
		return null;
	}

	/** Destination for a 404 path, or null. */
	public static function resolve( $key ) {
		if ( '' === $key ) {
			return null;
		}
		$rule = self::lookup( $key );
		if ( ! $rule ) {
			$rule = self::fuzzy_product( $key );
		}
		if ( ! $rule ) {
			$rule = self::paged_past_end( $key );
		}
		return $rule;
	}

	protected static function request_key() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return self::key( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	protected static function is_read_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/* ------------------------------------------------------------------ *
	 * The 404 handling
	 * ------------------------------------------------------------------ */

	/**
	 * pre_redirect_guess_404_permalink: runs inside redirect_canonical for GET/HEAD
	 * 404s. A URL answers with that redirect (301, query string kept by core);
	 * false stops core guessing (410 rules); null lets core guess as usual.
	 */
	public static function guess( $pre ) {
		if ( null !== $pre ) {
			return $pre;
		}
		$rule = self::resolve( self::request_key() );
		if ( ! $rule ) {
			return null;
		}
		if ( 410 === (int) $rule['c'] ) {
			return false;
		}
		$target = self::absolute( $rule['t'] );
		return $target ? $target : null;
	}

	/**
	 * template_redirect after redirect_canonical: give 410 rules their status, and
	 * redirect ourselves only if redirect_canonical did not (unhooked elsewhere).
	 */
	public static function after_canonical() {
		if ( ! is_404() || ! self::is_read_request() ) {
			return;
		}
		$key  = self::request_key();
		$rule = self::resolve( $key );
		if ( ! $rule ) {
			return;
		}
		if ( 410 === (int) $rule['c'] ) {
			status_header( 410 ); // The 404 template still renders, with the honest status.
			return;
		}
		$target = self::absolute( $rule['t'] );
		if ( ! $target || self::key( (string) wp_parse_url( $target, PHP_URL_PATH ) ) === $key ) {
			return;
		}
		$keep = array();
		foreach ( (array) $_GET as $name => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $value ) && preg_match( self::KEEP_QUERY, (string) $name ) ) {
				$keep[ $name ] = rawurlencode( wp_unslash( $value ) );
			}
		}
		if ( $keep ) {
			$target = add_query_arg( $keep, $target );
		}
		wp_safe_redirect( $target, 301, 'Hayak URL Recovery' );
		exit;
	}

	protected static function absolute( $target ) {
		$target = (string) $target;
		if ( '' === $target ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return home_url( '/' . ltrim( $target, '/' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Retired query parameters
	 * ------------------------------------------------------------------ */

	public static function drop_retired_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['product-page'] ) || isset( $_GET['add-to-cart'] ) || ! self::is_read_request() ) {
			return;
		}
		// phpcs:enable
		$archive = ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
			|| is_front_page();
		if ( ! $archive ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$uri = remove_query_arg( 'product-page', $uri );
		wp_safe_redirect( home_url( '/' . ltrim( (string) $uri, '/' ) ), 301, 'Hayak URL Recovery' );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Mangled product slugs
	 * ------------------------------------------------------------------ */

	/** Spelling-insensitive form of an Arabic/Latin slug. */
	public static function norm( $s ) {
		$s = mb_strtolower( rawurldecode( (string) $s ), 'UTF-8' );
		$s = strtr(
			$s,
			array(
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ة' => 'ه',
				'ى' => 'ي',
				'ؤ' => 'و',
				'ئ' => 'ي',
				'ـ' => '',
			)
		);
		$s = preg_replace( '/[\x{064B}-\x{0652}\x{0670}]/u', '', $s );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $s );
		return trim( (string) $s, '-' );
	}

	/**
	 * normalised slug => target path, for every live product plus every retired
	 * product path in the map (so a mangled link to a deleted product still
	 * lands where the deleted product now points).
	 */
	protected static function slug_index() {
		$index = get_transient( self::SLUG_INDEX );
		if ( is_array( $index ) ) {
			return $index;
		}
		global $wpdb;
		$index = array();
		$rows  = $wpdb->get_results( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_name <> ''" );
		foreach ( (array) $rows as $row ) {
			// post_name is stored percent-encoded, exactly as get_permalink() prints it.
			$index[ self::norm( $row->post_name ) ] = 'product/' . $row->post_name . '/';
		}
		foreach ( self::map() as $path => $rule ) {
			if ( 0 === strpos( $path, 'product/' ) && 301 === (int) $rule['c'] ) {
				$slug = explode( '/', substr( $path, 8 ) )[0];
				$norm = self::norm( $slug );
				if ( '' !== $norm && ! isset( $index[ $norm ] ) ) {
					$index[ $norm ] = $rule['t'];
				}
			}
		}
		set_transient( self::SLUG_INDEX, $index, 12 * HOUR_IN_SECONDS );
		return $index;
	}

	public static function flush_index() {
		delete_transient( self::SLUG_INDEX );
	}

	protected static function fuzzy_product( $key ) {
		if ( 0 !== strpos( $key, 'product/' ) ) {
			return null;
		}
		$slug = explode( '/', substr( $key, 8 ) )[0];
		$want = self::norm( $slug );
		if ( strlen( $want ) < 8 ) {
			return null;
		}
		$index = self::slug_index();
		if ( isset( $index[ $want ] ) ) {
			return array( 't' => $index[ $want ], 'c' => 301 );
		}
		$best   = PHP_INT_MAX;
		$second = PHP_INT_MAX;
		$hit    = null;
		$len    = strlen( $want );
		foreach ( $index as $norm => $target ) {
			if ( abs( strlen( $norm ) - $len ) > 8 ) {
				continue;
			}
			$d = levenshtein( $want, $norm );
			if ( $d < $best ) {
				$second = $best;
				$best   = $d;
				$hit    = $target;
			} elseif ( $d < $second ) {
				$second = $d;
			}
		}
		// A few bytes apart (an Arabic letter is two), and clearly closer than any
		// other product, so a near-miss between two similar products never guesses.
		$limit = max( 4, (int) floor( $len * 0.08 ) );
		if ( null !== $hit && $best <= $limit && $second - $best >= 4 ) {
			return array( 't' => $hit, 'c' => 301 );
		}
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Archive page numbers past the end
	 * ------------------------------------------------------------------ */

	protected static function paged_past_end( $key ) {
		if ( ! preg_match( '#^(?:(.+?)/)?page/\d+$#', $key, $m ) ) {
			return null;
		}
		$base = isset( $m[1] ) ? $m[1] : '';
		if ( '' === $base ) {
			return array( 't' => '/', 'c' => 301 );
		}
		$parts = explode( '/', $base );
		$first = $parts[0];
		$last  = end( $parts );
		$shop  = function_exists( 'wc_get_page_id' ) ? get_post( wc_get_page_id( 'shop' ) ) : null;
		$blog  = get_option( 'page_for_posts' ) ? get_post( (int) get_option( 'page_for_posts' ) ) : null;
		if ( ( $shop && 1 === count( $parts ) && rawurldecode( $shop->post_name ) === $first )
			|| ( $blog && 1 === count( $parts ) && rawurldecode( $blog->post_name ) === $first ) ) {
			return array( 't' => $base . '/', 'c' => 301 );
		}
		$taxonomies = array(
			'product-category' => 'product_cat',
			'product-tag'      => 'product_tag',
			'category'         => 'category',
		);
		if ( count( $parts ) >= 2 && isset( $taxonomies[ $first ] ) ) {
			$term = get_term_by( 'slug', sanitize_title( $last ), $taxonomies[ $first ] );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? null : array( 't' => $link, 'c' => 301 );
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Keeping the map current
	 * ------------------------------------------------------------------ */

	/**
	 * A product leaving the shop leaves a destination behind. Called on
	 * wp_trash_post (still published, original slug) and before_delete_post
	 * (published when force-deleted; 'trash' when the trash is emptied, in which
	 * case the original slug is in _wp_desired_post_slug).
	 */
	public static function product_removed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			$path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
		} elseif ( 'trash' === $post->post_status && 'publish' === get_post_meta( $post->ID, '_wp_trash_meta_status', true ) ) {
			$slug = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );
			$path = '' !== $slug ? 'product/' . $slug . '/' : '';
		} else {
			return;
		}
		$key = self::key( $path );
		if ( '' === $key || 0 !== strpos( $key, 'product/' ) ) {
			return;
		}
		$map = self::map();
		if ( isset( $map[ $key ] ) && 'trash' === $post->post_status ) {
			return; // Recorded when it was trashed.
		}
		self::add_rules( array( $key => array( 't' => self::replacement_for( $post ), 'c' => 301, 's' => 'removed_product:' . $post->ID ) ) );
	}

	/** A restored product is live again; its rule is no longer needed. */
	public static function product_restored( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'product' === $post->post_type ) {
			$slug = (string) get_post_meta( $post->ID, '_wp_desired_post_slug', true );
			self::remove_rule( 'product/' . ( '' !== $slug ? $slug : $post->post_name ) );
		}
	}

	/** An equivalent live product, else the product's category, else the shop. */
	public static function replacement_for( $post ) {
		global $wpdb;
		$sku = (string) get_post_meta( $post->ID, '_sku', true );
		if ( '' !== $sku ) {
			$same = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
				 WHERE m.meta_value = %s AND p.ID <> %d AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1",
				$sku,
				$post->ID
			) );
			if ( $same ) {
				return (string) get_permalink( (int) $same );
			}
		}
		$title = trim( (string) $post->post_title );
		if ( '' !== $title ) {
			$twin = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID AND l.stock_status = 'instock'
				 WHERE p.post_title = %s AND p.ID <> %d AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1",
				$title,
				$post->ID
			) );
			if ( $twin ) {
				return (string) get_permalink( (int) $twin );
			}
		}
		$primary = (int) get_post_meta( $post->ID, 'rank_math_primary_product_cat', true );
		$terms   = $primary ? array( get_term( $primary, 'product_cat' ) ) : wp_get_post_terms( $post->ID, 'product_cat' );
		foreach ( (array) $terms as $term ) {
			if ( $term && ! is_wp_error( $term ) && 'misc-products' !== $term->slug ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}
		}
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
	}

	public static function status_changed( $new_status, $old_status, $post ) {
		if ( $post && 'product' === $post->post_type && ( 'publish' === $new_status ) !== ( 'publish' === $old_status ) ) {
			self::flush_index();
		}
	}

	public static function post_updated( $post_id, $after, $before ) {
		if ( $after && 'product' === $after->post_type && $after->post_name !== $before->post_name ) {
			self::flush_index();
		}
	}
}
