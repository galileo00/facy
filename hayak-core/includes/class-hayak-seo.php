<?php
/**
 * Search-engine plumbing Rank Math gets wrong on this store.
 *
 * Sitemaps are generated on request, never cached.
 * Rank Math writes each sitemap page to a file and later deletes only the files
 * it has recorded in its `sitemap_cache_files` option. On this store 11 of the
 * 14 cached files were missing from that record, the sitemap index among them,
 * so the index never refreshed: from 18 Sep 2026 Google was served 4 of the 13
 * product sitemaps and no product-category sitemap at all, while the catalogue
 * grew past 2,400 products. With ~2,400 products at 200 per page, building a
 * sitemap on request is cheap, and an uncached sitemap cannot go stale.
 *
 * Wishlist action links are kept out of crawlers' way.
 * YITH Wishlist prints "?add_to_wishlist=ID&_wpnonce=..." on every product and
 * archive card. Crawlers followed them: every one of the 631 wishlists stored
 * between 25 Aug and 23 Sep 2026 was anonymous with a single item, and Search
 * Console counted the resulting URLs as "Alternate page with proper canonical
 * tag". They are actions, not pages, so they get the same robots.txt rule
 * WooCommerce already ships for ?add-to-cart=.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_SEO {

	public static function init() {
		add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
	}

	public static function robots_txt( $output, $public ) {
		if ( ! $public || false !== strpos( $output, 'add_to_wishlist=' ) ) {
			return $output;
		}
		$rules = "Disallow: /*?add_to_wishlist=\nDisallow: /*&add_to_wishlist=\n";
		// Inside the existing "User-agent: *" group, right after its first line.
		if ( preg_match( '/^User-agent:\s*\*\s*$/mi', $output, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			return substr( $output, 0, $at ) . "\n" . rtrim( $rules ) . substr( $output, $at );
		}
		return "User-agent: *\n" . $rules . "\n" . $output;
	}

	/** One-off: remove every sitemap file and transient Rank Math already cached. */
	public static function purge_sitemap_cache() {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
			return true;
		}
		return false;
	}
}
