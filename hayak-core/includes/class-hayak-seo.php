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
 * Ad landing pages are kept out of the index by default.
 * The store runs its TikTok, Snap and Meta ads to Elementor order-form pages
 * built on the Canvas template (no header, no footer). There are hundreds of
 * them, many near-copies of one another or of the product page, and they are
 * reached from ads, not from search: of 456 published on 23 Sep 2026, 12 had
 * ever earned a search click. Indexed, they compete with the product pages and
 * fill Search Console's "crawled / discovered - currently not indexed" and
 * duplicate lists. So a Canvas page or post that is published without an
 * explicit robots choice gets Rank Math's noindex (links still followed). Ads
 * are unaffected: ad review and AdsBot do not read meta robots. A page that
 * should rank is set to "index" in its Rank Math panel, and that choice is kept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_SEO {

	const LANDING_TEMPLATE = 'elementor_canvas';

	public static function init() {
		add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
		// A page becomes a published Canvas page in either order: status first or template first.
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 20, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'template_changed' ), 20, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'template_changed' ), 20, 4 );
	}

	public static function status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status && $post ) {
			self::landing_default( $post->ID );
		}
	}

	public static function template_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_wp_page_template' === $meta_key && self::LANDING_TEMPLATE === $meta_value ) {
			self::landing_default( $post_id );
		}
	}

	/** Published Canvas page or post with no robots choice of its own: noindex, follow. */
	public static function landing_default( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return false;
		}
		if ( self::LANDING_TEMPLATE !== get_post_meta( $post->ID, '_wp_page_template', true ) ) {
			return false;
		}
		if ( metadata_exists( 'post', $post->ID, 'rank_math_robots' ) ) {
			return false;
		}
		return (bool) update_post_meta( $post->ID, 'rank_math_robots', array( 'noindex' ) );
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
