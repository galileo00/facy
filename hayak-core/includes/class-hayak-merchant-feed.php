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
 * search with; the feed names the tool for what it is ("مسدس حرارة" is sent as
 * "منفاخ هواء ساخن"). Only named tool phrases are translated, and in a tool's own
 * description the bare word and the trigger too. Nothing else is: a toy water
 * gun or a product that fires powder loads is not reworded (the latter is kept
 * out of the feed with Google for WooCommerce's "dont-sync-and-show" visibility).
 *
 * Pages Google could not load. When Merchant Center's crawler fails to load a
 * product page it disapproves the item as "Product page unavailable" and does not
 * look again until the item is sent again. Google for WooCommerce never sends a
 * product whose data has not changed, so a plain save does not help. The daily
 * review therefore re-sends a published, in-stock product Google still flags,
 * once per SETTLE, and Google for WooCommerce is told not to skip it (15 items,
 * the store's best-selling laptop among them, on 26 Sep 2026).
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

	/** When the daily review last re-sent a product whose page Google could not load. */
	const META_RESENT_AT = '_hayak_feed_resent_at';

	/** How long Google for WooCommerce must not skip a re-sent product as unchanged. */
	const RESEND_WINDOW = 6 * HOUR_IN_SECONDS;

	/** SKUs never sent to Merchant Center, whatever product carries them (a re-import too). */
	const OPTION_NEVER = 'hayak_core_merchant_feed_never';

	/** Google re-reads a changed image within three days. */
	const SETTLE = 3 * DAY_IN_SECONDS;

	/** Shortest side a main image may have; images of at least PREFERRED_SIDE go first. */
	const MIN_SIDE       = 250;
	const PREFERRED_SIDE = 500;

	/**
	 * Merchant Center verdicts on what an image shows. A broken or unreachable
	 * link says nothing about the picture and is fixed on the server, never by
	 * rejecting the image.
	 */
	const IMAGE_CODES = array( 'image_unwanted_overlays', 'image_too_small', 'image_single_color' );

	/**
	 * Tools Arabic names "مسدس ...": [words after مسدس, feed name, feed name when
	 * definite, keep those words, title words naming the same tool without مسدس].
	 * Masculine names, like مسدس, so the adjectives that follow still agree.
	 */
	const TOOLS = array(
		array( '(?:ال)?(?:حرار[ةه]|حراري[ةه]?|هواء\s+(?:ال)?ساخن)', 'منفاخ هواء ساخن', 'منفاخ الهواء الساخن', false, 'منفاخ\s+(?:ال)?هواء|هيت\s*جن' ),
		array( '(?:ال)?(?:مسامير|دبابيس|تدبيس)', 'جهاز تثبيت مسامير', 'جهاز تثبيت المسامير', false, 'دباس[ةه]|مثبت\s+(?:ال)?مسامير' ),
		array( '(?:ال)?(?:تدليك|مساج)', 'جهاز تدليك', 'جهاز التدليك', false, 'مدلك|مساج|تدليك' ),
		array( '(?:ال)?(?:سيليكون|سيلكون|سليكون)', 'جهاز ضخ السيليكون', 'جهاز ضخ السيليكون', false, 'لاصق[ةه]?\s+(?:ال)?(?:سيليكون|سيلكون|سليكون)|(?:سيليكون|سيلكون|سليكون)\S*\s+(?:ال)?(?:لاصق|مانع)' ),
		array( '(?:ال)?(?:شمع|غراء|صمغ)', 'جهاز لصق حراري', 'جهاز اللصق الحراري', false, 'شمع\s+(?:ال)?لصق|غراء\s+حراري' ),
		array( '(?:ال)?(?:رش|بخ|فوم|رغو[ةه]|ضغط|غسيل|طلاء|دهان|بوي[ةه])', 'بخاخ', 'البخاخ', true, 'بخاخ|مضخ[ةه]|رشاش\s+(?:ال)?(?:مياه|ماء|مبيد)' ),
		array( '(?:تعديل\s+(?:ال)?مقاسات|(?:ال)?تسعير|(?:ال)?بطاقات)', 'جهاز تثبيت البطاقات', 'جهاز تثبيت البطاقات', false, 'تسعير|بطاقات\s+(?:ال)?أسعار' ),
	);

	/** A toy is never a tool, whatever it is called. */
	const TOY = '/لعب[ةه]?|ألعاب|العاب|أطفال|اطفال/u';

	/** Other tool phrases that read as weapons: pattern => feed wording. */
	const PHRASES = array(
		'/بندقي[ةه]\s+(?:ال)?هواء\s+(?:ال)?ساخن/u'                     => 'منفاخ هواء ساخن',
		'/خرطوش[ةه](?=\s+(?:ال)?(?:سيليكون|سيلكون|سليكون|غراء))/u'      => 'أنبوب',
		'/\bmassage\s+guns?\b/iu'                                      => 'massager',
		'/\bheat\s+guns?\b/iu'                                         => 'hot air tool',
		'/\bnail\s+guns?\b/iu'                                         => 'nailer',
		'/\bglue\s+guns?\b/iu'                                         => 'glue applicator',
		'/\b(spray|foam)\s+guns?\b/iu'                                 => '$1 sprayer',
	);

	/** A product that fires powder loads is never reworded. */
	const POWDER = '/بارود|طلقات|ذخير/u';

	public static function init() {
		add_filter( 'woocommerce_gla_product_attribute_values', array( __CLASS__, 'attributes' ), 10, 2 );
		add_filter( 'woocommerce_gla_product_attribute_value_description', array( __CLASS__, 'description' ), 10, 2 );
		add_action( self::REVIEW_HOOK, array( __CLASS__, 'review' ) );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'keep_out' ), 30 );
		add_filter( 'woocommerce_gla_force_product_resync', array( __CLASS__, 'force_resync' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** Google for WooCommerce: send a product the review just re-sent, even unchanged. */
	public static function force_resync( $force, $product ) {
		if ( $force || ! $product instanceof WC_Product ) {
			return $force;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$at = (int) get_post_meta( $id, self::META_RESENT_AT, true );
		return $at > 0 && time() - $at < self::RESEND_WINDOW;
	}

	/** A product whose SKU the owner has ruled out of Google stays out, on every save. */
	public static function keep_out( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$never = get_option( self::OPTION_NEVER, array() );
		$sku   = (string) $product->get_sku( 'edit' );
		if ( '' === $sku || ! is_array( $never ) || ! in_array( $sku, $never, true ) ) {
			return;
		}
		if ( 'dont-sync-and-show' !== $product->get_meta( '_wc_gla_visibility' ) ) {
			$product->update_meta_data( '_wc_gla_visibility', 'dont-sync-and-show' );
		}
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

	/**
	 * Google for WooCommerce: the product's own overrides, merged over everything it
	 * maps (the Merchant API adapter takes the title from the product, so it is
	 * computed here from the product, not read back from the adapter).
	 */
	public static function attributes( $attributes, $product ) {
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
		$title = (string) $product->get_title();
		$feed  = self::feed_title( $title, (string) $product->get_description() );
		if ( '' !== $feed && $feed !== $title && ! isset( $attributes['title'] ) ) {
			$attributes['title'] = $feed;
		}
		return $attributes;
	}

	/** Google for WooCommerce: the description it sends, as its last step. */
	public static function description( $description, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $description;
		}
		return self::feed_description( (string) $description, (string) $product->get_title() );
	}

	/** Feed title: named tool phrases translated, and a tool's "gun-shaped". */
	public static function feed_title( $title, $description = '' ) {
		if ( ! preg_match( '/مسدس|بندقي|خرطوش|gun/iu', $title ) || self::keep_words( $title, $description ) ) {
			return $title;
		}
		$out = self::rename( $title );
		if ( self::tool_in( $title ) ) {
			$out = self::shape( $out );
		}
		return self::tidy( $out );
	}

	/**
	 * Feed description: named tool phrases translated; when the title names one of
	 * the tools, its bare name, the "gun-shaped" phrase and the trigger too.
	 */
	public static function feed_description( $description, $title ) {
		if ( ! preg_match( '/مسدس|بندقي|خرطوش|زناد|الإطلاق|الاطلاق|gun/iu', $description ) || self::keep_words( $title, $description ) ) {
			return $description;
		}
		$out  = self::rename( $description );
		$tool = self::tool_in( $title );
		if ( $tool ) {
			$out = self::shape( $out );
			$out = preg_replace_callback(
				'/(?<!\p{L})([وف]?[بلك]?)(ال|لل)?مسدس(?:ات)?(?!\p{L})/u',
				function ( $m ) use ( $tool ) {
					return self::join( $m[1], $m[2] ?? '', $tool[1], $tool[2] );
				},
				$out
			);
			$out = preg_replace_callback(
				'/(?<!\p{L})([وف]?[بلك]?)(ال|لل)?زناد(?!\p{L})/u',
				function ( $m ) {
					return self::join( $m[1], $m[2] ?? '', 'زر تشغيل', 'زر التشغيل' );
				},
				$out
			);
			$out = preg_replace( '/(?:الإطلاق|الاطلاق)(?=\s+(?:بالخطأ|بالخطا|بالغلط))/u', 'التشغيل', $out );
			if ( 'جهاز ضخ السيليكون' === $tool[1] ) {
				// The silicone tube is a "cartridge" too.
				$out = preg_replace_callback(
					'/(?<!\p{L})([وف]?[بلك]?)(ال|لل)?(?:خرطوش[ةه]|خراطيش)(?!\p{L})/u',
					function ( $m ) {
						return self::join( $m[1], $m[2] ?? '', 'أنبوب', 'الأنبوب' );
					},
					$out
				);
			}
		}
		return self::tidy( $out );
	}

	/** A toy, or a product that fires powder loads, keeps its own words. */
	protected static function keep_words( $title, $description ) {
		return preg_match( self::TOY, $title ) || preg_match( self::POWDER, $title . ' ' . $description );
	}

	/** Every "مسدس <tool words>" and other tool phrase, in any text. */
	protected static function rename( $text ) {
		foreach ( self::TOOLS as $tool ) {
			$text = preg_replace_callback(
				'/(?<!\p{L})([وف]?[بلك]?)(ال|لل)?مسدس(?:ات)?\s+(' . $tool[0] . ')(?!\p{L})/u',
				function ( $m ) use ( $tool ) {
					if ( $tool[3] ) {
						return self::join( $m[1], $m[2], $tool[1], $tool[2] ) . ' ' . $m[3];
					}
					// "مسدس المسامير" is definite through its second word.
					$article = ( '' === $m[2] && 0 === mb_strpos( $m[3], 'ال' ) ) ? 'ال' : $m[2];
					return self::join( $m[1], $article, $tool[1], $tool[2] );
				},
				$text
			);
		}
		foreach ( self::PHRASES as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}
		return $text;
	}

	/** The tool a title names, with or without مسدس, or null. */
	protected static function tool_in( $title ) {
		foreach ( self::TOOLS as $tool ) {
			if ( preg_match( '/مسدس(?:ات)?\s+(?:' . $tool[0] . ')(?!\p{L})/u', $title ) ) {
				return $tool;
			}
		}
		foreach ( self::TOOLS as $tool ) {
			if ( preg_match( '/(?<!\p{L})(?:[وفبلك]?(?:ال)?)(?:' . $tool[4] . ')/u', $title ) ) {
				return $tool;
			}
		}
		return null;
	}

	/** A tool "بشكل مسدس" / "على شكل مسدس" has a pistol grip. */
	protected static function shape( $text ) {
		return preg_replace( '/(?:ب|على\s+)شكل\s+(?:ال)?مسدس(?!\p{L})/u', 'بمقبض', $text );
	}

	/** A name after its clitics (و ف, then ب ل ك) and the article the replaced word carried. */
	protected static function join( $clitic, $article, $name, $definite ) {
		if ( 'لل' === $article ) {
			$clitic .= 'ل';
		}
		$word = '' === $article ? $name : $definite;
		if ( 'ل' === mb_substr( $clitic, -1 ) && 0 === mb_strpos( $word, 'ال' ) ) {
			return $clitic . mb_substr( $word, 1 );
		}
		return $clitic . $word;
	}

	protected static function tidy( $text ) {
		return trim( preg_replace( '/[ \t]{2,}/u', ' ', $text ) );
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
		$done['resent'] = self::resend_unavailable( $table, $now );
		return $done;
	}

	/**
	 * Send again each product whose page Google could not load, so Merchant Center
	 * crawls it again: published, in stock and synced only, once per SETTLE.
	 */
	protected static function resend_unavailable( $table, $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids    = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$table} WHERE severity = 'DISAPPROVED' AND code = 'landing_page_error'" );
		$resend = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) || 'publish' !== $product->get_status()
				|| ! $product->is_in_stock() || 'dont-sync-and-show' === $product->get_meta( '_wc_gla_visibility' ) ) {
				continue;
			}
			$at = (int) $product->get_meta( self::META_RESENT_AT );
			if ( $at && $now - $at < self::SETTLE ) {
				continue;
			}
			$product->update_meta_data( self::META_RESENT_AT, $now );
			$product->save_meta_data();
			$resend[] = $product->get_id();
		}
		self::resync( $resend );
		return $resend;
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
