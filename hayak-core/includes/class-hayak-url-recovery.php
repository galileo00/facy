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
	const KEEP_QUERY = '/^(utm_[a-z_]+|ttclid|fbclid|gclid|gbraid|wbraid|dclid|msclkid|sccid|sc_click_id|twclid|srsltid|gad_source|gad_campaignid|_gl)$/i';

	public static function init() {
		add_filter( 'pre_redirect_guess_404_permalink', array( __CLASS__, 'guess' ) );
		add_filter( 'old_slug_redirect_post_id', array( __CLASS__, 'old_slug_target' ) );
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
			$target = 410 === $code ? '' : self::final_target( self::relative( (string) $rule['t'] ), $map );
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

	/** Home-relative form of a same-site URL, so stored rules survive a host or scheme change. */
	public static function relative( $target ) {
		$target = (string) $target;
		if ( ! preg_match( '#^https?://#i', $target ) ) {
			return '/' . ltrim( $target, '/' );
		}
		$home = wp_parse_url( home_url( '/' ) );
		$url  = wp_parse_url( $target );
		if ( empty( $url['host'] ) || empty( $home['host'] ) || strtolower( $url['host'] ) !== strtolower( $home['host'] ) ) {
			return $target; // Another site: keep as is (wp_validate_redirect will refuse it).
		}
		$path  = isset( $url['path'] ) ? $url['path'] : '/';
		$hpath = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $hpath && 0 === strpos( $path, $hpath . '/' ) ) {
			$path = substr( $path, strlen( $hpath ) );
		}
		return $path . ( isset( $url['query'] ) ? '?' . $url['query'] : '' );
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
		if ( $rule && 301 === (int) $rule['c'] ) {
			$map = self::map();
			for ( $hop = 0; $hop < 5; $hop++ ) {
				$next = self::target_key( $rule['t'] );
				if ( $next === $key || ! isset( $map[ $next ] ) ) {
					break;
				}
				$rule = $map[ $next ];
				if ( 301 !== (int) $rule['c'] ) {
					break;
				}
			}
		}
		return $rule;
	}

	/** Absolute URL for a rule's target, or '' when it is not a safe same-site destination. */
	protected static function safe_target( $rule ) {
		$url = self::absolute( $rule['t'] );
		return ( '' !== $url && wp_validate_redirect( $url, false ) ) ? $url : '';
	}

	/** An old slug that belongs to a product no longer published must not redirect to its bare ?p= URL. */
	public static function old_slug_target( $post_id ) {
		if ( $post_id && 'product' === get_post_type( $post_id ) && 'publish' !== get_post_status( $post_id ) ) {
			return 0;
		}
		return $post_id;
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
		$target = self::safe_target( $rule );
		return '' !== $target ? $target : null;
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
		$target = self::safe_target( $rule );
		if ( '' === $target || self::key( (string) wp_parse_url( $target, PHP_URL_PATH ) ) === $key ) {
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
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$clean = remove_query_arg( 'product-page', $uri );
		$path  = ltrim( (string) wp_parse_url( $clean, PHP_URL_PATH ), '/' );
		$query = (string) wp_parse_url( $clean, PHP_URL_QUERY );
		$home  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = ltrim( substr( $path, strlen( $home ) ), '/' );
		}
		wp_safe_redirect( home_url( '/' . $path ) . ( '' !== $query ? '?' . $query : '' ), 301, 'Hayak URL Recovery' );
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
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		$s = preg_replace( '/[\x{064B}-\x{0652}\x{0670}]/u', '', $s );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $s );
		return trim( (string) $s, '-' );
	}

	/**
	 * normalised slug => target path. Every live product points at itself, under
	 * its current slug and under every slug it had before (_wp_old_slug), so a
	 * mangled copy of an old link still finds it. Every product that exists but
	 * is not published (held as a draft, trashed) maps to '' under all of its
	 * slugs, so its own URL is never guessed onto a sibling. Retired product
	 * paths in the map point where the map sends them, so a mangled link to a
	 * deleted product still lands on that product's replacement.
	 */
	protected static function slug_index() {
		$index = get_transient( self::SLUG_INDEX );
		if ( is_array( $index ) ) {
			return $index;
		}
		global $wpdb;
		$index = array();
		$rows  = $wpdb->get_results( "SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('auto-draft', 'inherit') AND post_name <> ''" );
		$old   = $wpdb->get_results( "SELECT p.post_name, p.post_status, m.meta_value AS old_name FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_old_slug' AND p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'inherit') AND p.post_name <> '' AND m.meta_value <> ''" );
		// Current slugs first, so an old slug never displaces the product that owns it now.
		foreach ( array( (array) $rows, (array) $old ) as $pass => $list ) {
			foreach ( $list as $row ) {
				$status = isset( $row->post_status ) ? $row->post_status : 'publish';
				$name   = preg_replace( '/__trashed(-\d+)?$/', '', (string) $row->post_name );
				$norm   = self::norm( 0 === $pass ? $name : (string) $row->old_name );
				if ( '' === $norm ) {
					continue;
				}
				if ( 'publish' === $status && ( 0 === $pass || ! isset( $index[ $norm ] ) || '' === $index[ $norm ] ) ) {
					// post_name is stored percent-encoded, exactly as get_permalink() prints it.
					$index[ $norm ] = 'product/' . $name . '/';
				} elseif ( ! isset( $index[ $norm ] ) ) {
					$index[ $norm ] = '';
				}
			}
		}
		foreach ( self::map() as $path => $rule ) {
			if ( 0 === strpos( $path, 'product/' ) && 301 === (int) $rule['c'] ) {
				$norm = self::norm( explode( '/', substr( $path, 8 ) )[0] );
				if ( '' !== $norm && ( ! isset( $index[ $norm ] ) || '' === $index[ $norm ] ) ) {
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
		$want = self::norm( explode( '/', substr( $key, 8 ) )[0] );
		if ( mb_strlen( $want, 'UTF-8' ) < 6 ) {
			return null;
		}
		$index = self::slug_index();
		if ( isset( $index[ $want ] ) ) {
			// A known product: live -> itself, not published -> no guess at all.
			return '' === $index[ $want ] ? null : array( 't' => $index[ $want ], 'c' => 301 );
		}
		$tokens = self::hard_tokens( $want );
		$chars  = self::chars( $want );
		$len    = count( $chars );
		$best   = PHP_INT_MAX;
		$second = PHP_INT_MAX;
		$hit    = null;
		foreach ( $index as $norm => $target ) {
			// A slug ten or more letters longer or shorter is at least that far away,
			// beyond anything the rule below could accept or be blocked by.
			if ( abs( mb_strlen( $norm, 'UTF-8' ) - $len ) > 9 || self::hard_tokens( $norm ) !== $tokens ) {
				continue; // Numbers and Latin model names must match exactly: 2 packs is not 3.
			}
			$d = self::char_distance( $chars, self::chars( $norm ) );
			if ( $d < $best ) {
				$second = $best;
				$best   = $d;
				$hit    = $target;
			} elseif ( $d < $second ) {
				$second = $d;
			}
		}
		// Mangled links on this store are one to three damaged letters. Accept at
		// most three (and never more than a quarter of the slug), and only when the
		// match is at least three times closer than any other product, so a link
		// that sits between two similar products never guesses. A candidate that
		// exists but is not published ('') is never a destination.
		$limit = min( 3, max( 1, intdiv( $len, 4 ) ) );
		if ( null !== $hit && '' !== $hit && $best <= $limit && $second >= max( 3 * $best, $best + 3 ) ) {
			return array( 't' => $hit, 'c' => 301 );
		}
		return null;
	}

	/** The digit and Latin runs of a normalised slug, sorted: sizes, capacities, models. */
	protected static function hard_tokens( $norm ) {
		preg_match_all( '/[0-9]+|[a-z]+/', $norm, $m );
		$t = $m[0];
		sort( $t );
		return implode( ' ', $t );
	}

	protected static function chars( $s ) {
		return preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
	}

	/** Levenshtein distance in characters, not bytes (an Arabic letter is two bytes). */
	protected static function char_distance( array $a, array $b ) {
		$alphabet = array();
		$x        = '';
		$y        = '';
		foreach ( $a as $c ) {
			if ( ! isset( $alphabet[ $c ] ) ) {
				$alphabet[ $c ] = chr( count( $alphabet ) + 1 );
			}
			$x .= $alphabet[ $c ];
		}
		foreach ( $b as $c ) {
			if ( ! isset( $alphabet[ $c ] ) ) {
				$alphabet[ $c ] = chr( min( 255, count( $alphabet ) + 1 ) );
			}
			$y .= $alphabet[ $c ];
		}
		return levenshtein( $x, $y );
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
		$target = self::replacement_for( $post );
		$rules  = array( $key => array( 't' => $target, 'c' => 301, 's' => 'removed_product:' . $post->ID ) );
		foreach ( (array) get_post_meta( $post->ID, '_wp_old_slug' ) as $old ) {
			if ( '' !== (string) $old ) {
				$rules[ 'product/' . $old ] = $rules[ $key ];
			}
		}
		self::add_rules( $rules );
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
