<?php
/**
 * What Google Merchant Center is sent, where it has to differ from the storefront.
 *
 * Main image. Taager's first image for a product is usually an ad creative with a
 * price, "free delivery" or a warranty seal burned into the photo. The storefront
 * can show it; Merchant Center disapproves the item for "Promotional overlay on
 * image" (63 in-stock products on 24 Sep 2026). The feed therefore picks its own
 * main image from the product's own images, and the storefront is left alone:
 *   - META_IMAGE is the attachment sent as the main image;
 *   - META_REJECTED lists attachments never to send as the main image, and they
 *     are also left out of the additional images;
 *   - a daily review reads Google's own verdict (the issues Google for
 *     WooCommerce fetches from Merchant Center). A product still disapproved for
 *     its image SETTLE after its current image was sent gets that image
 *     rejected and the next one sent, so new imports are handled with Google as
 *     the judge. A product with no acceptable image left is listed in
 *     OPTION_REPORT: it needs a real photo.
 *
 * Words. Arabic names many tools "مسدس" (pistol): heat gun, nail gun, massage gun,
 * foam sprayer, tagging gun. Google reads the word and disapproves the item as
 * "Guns and Parts" (15 on 24 Sep 2026). The storefront keeps the words shoppers
 * search with; the feed title and description name the tool for what it is.
 * Only names are translated: a product that really is weapon-like (one that
 * fires powder loads, a replica gun) is not reworded but kept out of the feed
 * with Google for WooCommerce's own "dont-sync-and-show" visibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Merchant_Feed {

	const META_IMAGE    = '_hayak_feed_image';
	const META_IMAGE_AT = '_hayak_feed_image_at';
	const META_REJECTED = '_hayak_feed_rejected';
	const REVIEW_HOOK   = 'hayak_core_merchant_feed_review';
	const OPTION_REPORT = 'hayak_core_merchant_feed_report';
	const OPTION_LAST   = 'hayak_core_merchant_feed_last_review';
	const OPTION_ASKED  = 'hayak_core_merchant_feed_refresh_at';

	/** Google re-reads a changed image within three days. */
	const SETTLE = 3 * DAY_IN_SECONDS;

	/** Shortest side a main image may have; images of at least PREFERRED_SIDE go first. */
	const MIN_SIDE       = 250;
	const PREFERRED_SIDE = 500;

	/** Merchant Center issues that mean "send another main image". */
	const IMAGE_CODES = array( 'image_unwanted_overlays', 'image_too_small', 'image_single_color', 'image_link_broken' );

	/** Feed wording: pattern => replacement, most specific first. */
	const WORDS = array(
		'/مسدس\s+(?:ال)?(?:حرار[ةه]|حراري[ةه]?|هواء\s+(?:ال)?ساخن)/u'                                    => 'منفاخ هواء ساخن',
		'/بندقي[ةه]\s+(?:ال)?هواء\s+(?:ال)?ساخن/u'                                                       => 'منفاخ هواء ساخن',
		'/خرطوش[ةه](?=\s+(?:ال)?(?:سيليكون|سيلكون|سليكون|غراء))/u'                                        => 'أنبوب',
		'/مسدس\s+(?:ال)?(?:مسامير|دبابيس|تدبيس)/u'                                                       => 'دباسة مسامير',
		'/مسدس\s+(?:ال)?(?:تدليك|مساج)/u'                                                                 => 'جهاز تدليك',
		'/مسدس(\s+(?:ال)?(?:سيليكون|سيلكون|سليكون|شمع|غراء|صمغ))/u'                                       => 'أداة$1',
		'/مسدس(?:ات)?(\s+(?:ال)?(?:رش|بخ|فوم|رغو[ةه]|ماء|مياه|موي[ةه]|ضغط|غسيل|طلاء|دهان|بوي[ةه]))/u'    => 'بخاخ$1',
		'/مسدس(\s+(?:ال)?(?:تعديل|تسعير|بطاقات|تثبيت))/u'                                                 => 'أداة$1',
		'/(?<!\p{L})(ال)?زناد(?!\p{L})/u'                                                                 => '$1مقبض',
		'/(?:الإطلاق|الاطلاق)(?=\s+(?:بالخطأ|بالخطا|بالغلط))/u'                                           => 'التشغيل',
	);

	public static function init() {
		add_filter( 'woocommerce_gla_product_attribute_values', array( __CLASS__, 'attributes' ), 10, 3 );
		add_action( self::REVIEW_HOOK, array( __CLASS__, 'review' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::REVIEW_HOOK ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', self::REVIEW_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::REVIEW_HOOK );
		wp_clear_scheduled_hook( self::REVIEW_HOOK, array( true ) );
	}

	/** Google for WooCommerce: the product's own overrides, applied after everything it maps. */
	public static function attributes( $attributes, $product, $adapter = null ) {
		if ( ! $product instanceof WC_Product ) {
			return $attributes;
		}
		$source = $product;
		if ( $product->is_type( 'variation' ) ) {
			$source = $product->get_image_id() ? null : wc_get_product( $product->get_parent_id() );
		}
		if ( $source instanceof WC_Product ) {
			$urls = self::image_urls( self::feed_image_ids( $source ) );
			if ( $urls ) {
				$attributes['imageLink']            = array_shift( $urls );
				$attributes['additionalImageLinks'] = array_slice( $urls, 0, 10 );
			}
		}
		if ( is_object( $adapter ) && method_exists( $adapter, 'getTitle' ) && ! isset( $attributes['title'] ) ) {
			$title = (string) $adapter->getTitle();
			$words = self::words( $title );
			if ( '' !== $words && $words !== $title ) {
				$attributes['title'] = $words;
			}
		}
		if ( is_object( $adapter ) && method_exists( $adapter, 'getDescription' ) && ! isset( $attributes['description'] ) ) {
			$description = (string) $adapter->getDescription();
			$words       = self::words( $description );
			if ( '' !== $words && $words !== $description ) {
				$attributes['description'] = $words;
			}
		}
		return $attributes;
	}

	/** Feed wording for tools Arabic names after a gun. */
	public static function words( $text ) {
		$text = (string) $text;
		if ( '' === $text || ! preg_match( '/مسدس|بندقي|خرطوش|زناد|الإطلاق|الاطلاق/u', $text ) ) {
			return $text;
		}
		$out = $text;
		foreach ( self::WORDS as $pattern => $replacement ) {
			$out = preg_replace( $pattern, $replacement, $out );
		}
		return trim( preg_replace( '/[ \t]{2,}/u', ' ', $out ) );
	}

	/**
	 * Attachment ids to send, main image first; empty when the product has no feed
	 * choice of its own (Google for WooCommerce then sends featured + gallery).
	 */
	public static function feed_image_ids( WC_Product $product ) {
		$chosen   = (int) $product->get_meta( self::META_IMAGE );
		$rejected = self::rejected( $product );
		if ( ! $chosen && ! $rejected ) {
			return array();
		}
		$own  = self::own_images( $product );
		$main = ( $chosen && in_array( $chosen, $own, true ) && ! in_array( $chosen, $rejected, true ) ) ? $chosen : self::next_candidate( $product, $rejected );
		if ( ! $main ) {
			return array();
		}
		$ids = array( $main );
		foreach ( $own as $id ) {
			if ( $id !== $main && ! in_array( $id, $rejected, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** The featured image, then the gallery, each once. */
	public static function own_images( WC_Product $product ) {
		$ids = array_map( 'intval', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public static function rejected( WC_Product $product ) {
		$rejected = $product->get_meta( self::META_REJECTED );
		return is_array( $rejected ) ? array_values( array_unique( array_map( 'intval', $rejected ) ) ) : array();
	}

	/** First image not rejected and big enough, preferring those of PREFERRED_SIDE. */
	public static function next_candidate( WC_Product $product, array $rejected ) {
		$fallback = 0;
		foreach ( self::own_images( $product ) as $id ) {
			if ( in_array( $id, $rejected, true ) ) {
				continue;
			}
			$side = self::short_side( $id );
			if ( $side >= self::PREFERRED_SIDE ) {
				return $id;
			}
			if ( ! $fallback && $side >= self::MIN_SIDE ) {
				$fallback = $id;
			}
		}
		return $fallback;
	}

	protected static function short_side( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return 0;
		}
		return (int) min( $meta['width'], $meta['height'] );
	}

	protected static function image_urls( array $ids ) {
		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Daily: ask Google for WooCommerce to fetch fresh statuses, then act on them
	 * two hours later, when the fetch has finished.
	 */
	public static function review( $process = false ) {
		if ( ! $process ) {
			update_option( self::OPTION_ASKED, time(), false );
			if ( self::refresh_statuses() ) {
				wp_schedule_single_event( time() + 2 * HOUR_IN_SECONDS, self::REVIEW_HOOK, array( true ) );
			}
			return;
		}
		$result = self::apply_verdicts( (int) get_option( self::OPTION_ASKED, 0 ) );
		update_option( self::OPTION_LAST, array( 'time' => time() ) + $result, false );
	}

	/** Reject the image Google still disapproves and send the next one. */
	public static function apply_verdicts( $asked_at = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gla_merchant_issues';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'skipped' => 'no issues table' );
		}
		$latest = (string) $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
		if ( '' === $latest ) {
			return array( 'flagged' => 0 );
		}
		if ( $asked_at && strtotime( $latest . ' UTC' ) < $asked_at - MINUTE_IN_SECONDS ) {
			return array( 'skipped' => 'statuses not refreshed' );
		}
		$codes = implode( ',', array_fill( 0, count( self::IMAGE_CODES ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT product_id FROM {$table} WHERE severity = 'DISAPPROVED' AND code IN ({$codes})", self::IMAGE_CODES ) );

		$now    = time();
		$report = get_option( self::OPTION_REPORT, array() );
		$done   = array( 'flagged' => count( $ids ), 'switched' => array(), 'waiting' => 0, 'exhausted' => array() );
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) || 'publish' !== $product->get_status() ) {
				continue;
			}
			$since = (int) $product->get_meta( self::META_IMAGE_AT );
			if ( $since && $now - $since < self::SETTLE ) {
				$done['waiting']++;
				continue;
			}
			$sent     = self::feed_image_ids( $product );
			$current  = $sent ? $sent[0] : (int) $product->get_image_id();
			$rejected = self::rejected( $product );
			if ( $current ) {
				$rejected[] = $current;
			}
			$next = self::next_candidate( $product, $rejected );
			$product->update_meta_data( self::META_REJECTED, array_values( array_unique( $rejected ) ) );
			$product->update_meta_data( self::META_IMAGE_AT, $now );
			if ( $next ) {
				$product->update_meta_data( self::META_IMAGE, $next );
				$done['switched'][] = $product->get_id();
				unset( $report[ $product->get_id() ] );
			} else {
				$product->delete_meta_data( self::META_IMAGE );
				$done['exhausted'][]          = $product->get_id();
				$report[ $product->get_id() ] = $now;
			}
			$product->save_meta_data();
		}
		update_option( self::OPTION_REPORT, $report, false );
		self::resync( $done['switched'] );
		return $done;
	}

	/** Ask Google for WooCommerce to fetch product statuses from Merchant Center now. */
	protected static function refresh_statuses() {
		if ( ! function_exists( 'woogle_get_container' ) ) {
			return false;
		}
		try {
			woogle_get_container()->get( 'Automattic\WooCommerce\GoogleListingsAndAds\MerchantCenter\MerchantStatuses' )->maybe_refresh_status_data( true );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Queue products for Google for WooCommerce to send again. */
	public static function resync( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! function_exists( 'woogle_get_container' ) ) {
			return false;
		}
		try {
			$job = woogle_get_container()
				->get( 'Automattic\WooCommerce\GoogleListingsAndAds\Jobs\JobRepository' )
				->get( 'Automattic\WooCommerce\GoogleListingsAndAds\Jobs\UpdateProducts' );
			foreach ( array_chunk( $ids, 50 ) as $chunk ) {
				$job->schedule( array( $chunk ) );
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
