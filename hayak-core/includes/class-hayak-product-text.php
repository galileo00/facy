<?php
/**
 * Product text: a short description is short, and supplier notes never reach the page.
 *
 * Why: the Taager importer writes the full supplier description into both the
 * description and the short description. Flatsome prints the short description
 * beside the price and the description in the tab below, so every product page
 * carried its whole text twice (2,121 of 2,422 products byte-identical on
 * 23 Sep 2026, ~300 more a copy of an older version of the text, some with specs
 * that no longer match the product). Search Console had these pages under
 * "Crawled - currently not indexed". The supplier text also carries notes meant
 * for resellers ("زوايا تسويقية", "أفكار المحتوى": ad angles and content ideas),
 * separated from the real text by lines of tatweel.
 *
 * The invariant, enforced inside every WC_Product::save() (admin, REST, importer):
 *   - sections headed by a reseller-notes heading are removed from both texts,
 *     and so are the tatweel divider lines, as long as real text remains;
 *   - a short description that is not a summary (longer than SUMMARY_MAX_LINES
 *     lines or SUMMARY_MAX_CHARS characters) is replaced by the opening lines of
 *     the description, so it matches the current text. The TikTok catalogue
 *     sends the short description, so it is never left empty.
 * The replaced short description is kept in META_ORIGINAL. A daily sweep
 * catches text written behind WooCommerce's back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Product_Text {

	const META_ORIGINAL     = '_hayak_original_short_description';
	const SWEEP_HOOK        = 'hayak_core_product_text_sweep';
	const SUMMARY_MAX_LINES = 6;
	const SUMMARY_MAX_CHARS = 500;
	const SUMMARY_WORDS     = 35;
	const SUMMARY_LINES     = 4;

	/** Reseller-notes headings seen in the supplier text. */
	const NOTES_HEADING = '/^(?:[أا]فكار\s+(?:ال)?محتوى|زوايا\s+(?:تسويقي[ةه]|بيعي[ةه]|(?:ال)?بيع(?:\s+(?:ال)?منتج)?)|زاوية\s+\S+\s*:)/u';

	/** Headings of the real text; a notes block with no divider ends at the first of these. */
	const TEXT_HEADING = '/^(?:(?:ال)?مميزات(?:\s+(?:ال)?منتج)?|(?:ال)?مواصفات(?:\s+(?:ال)?منتج)?|تفاصيل\s+سريعة|(?:ال)?محتويات(?:\s+(?:ال)?(?:منتج|عبو[ةه]|عرض))?|(?:كيفي[ةه]|طريق[ةه])\s+(?:ال)?استخدام|(?:ال)?خصائص)\s*:?$/u';

	public static function init() {
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'normalise' ), 20 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	public static function unschedule() {
		$next = wp_next_scheduled( self::SWEEP_HOOK );
		while ( $next ) {
			wp_unschedule_event( $next, self::SWEEP_HOOK );
			$next = wp_next_scheduled( self::SWEEP_HOOK );
		}
	}

	/** Runs inside WC_Product::save(); only sets fields, the save writes them. */
	public static function normalise( $product ) {
		if ( ! $product instanceof WC_Product || 'variation' === $product->get_type() ) {
			return;
		}
		$desc  = (string) $product->get_description( 'edit' );
		$short = (string) $product->get_short_description( 'edit' );
		$clean = self::clean( $desc );
		if ( $clean !== $desc ) {
			$product->set_description( $clean );
		}
		if ( self::is_summary( $short ) ) {
			$tidy = self::clean( $short );
			if ( $tidy !== $short ) {
				$product->set_short_description( $tidy );
			}
			return;
		}
		$summary = self::summary( $clean );
		if ( '' !== $summary && $summary !== $short ) {
			if ( '' === (string) $product->get_meta( self::META_ORIGINAL ) ) {
				$product->update_meta_data( self::META_ORIGINAL, $short );
			}
			$product->set_short_description( $summary );
		}
	}

	/** Empty, or short enough to be a summary rather than a description. */
	public static function is_summary( $text ) {
		$lines = self::lines( $text );
		return count( $lines ) <= self::SUMMARY_MAX_LINES && mb_strlen( implode( ' ', $lines ), 'UTF-8' ) <= self::SUMMARY_MAX_CHARS;
	}

	/** Text without reseller-notes sections and tatweel dividers; unchanged if nothing else would remain. */
	public static function clean( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$sections = array( array() );
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			if ( self::is_divider( $line ) ) {
				$sections[] = array();
				continue;
			}
			$sections[ count( $sections ) - 1 ][] = $line;
		}
		$kept = array();
		$cut  = false;
		foreach ( $sections as $section ) {
			$first = null;
			foreach ( $section as $i => $line ) {
				if ( '' !== self::plain( $line ) ) {
					$first = $i;
					break;
				}
			}
			if ( null === $first ) {
				continue;
			}
			if ( preg_match( self::NOTES_HEADING, self::plain( $section[ $first ] ) ) ) {
				$cut  = true;
				$rest = null;
				for ( $j = $first + 1; $j < count( $section ); $j++ ) {
					if ( preg_match( self::TEXT_HEADING, self::plain( $section[ $j ] ) ) ) {
						$rest = $j;
						break;
					}
				}
				if ( null === $rest ) {
					continue;
				}
				$section = array_slice( $section, $rest );
			}
			$kept[] = trim( implode( "\n", $section ), "\n\r" );
		}
		$kept = array_values( array_filter( $kept, function ( $s ) { return '' !== self::plain( $s ); } ) );
		if ( ! $kept ) {
			return $text; // Never empty a text: a notes block with nothing after it stays until a person rewrites it.
		}
		if ( ! $cut && 1 === count( $sections ) ) {
			return $text;
		}
		return implode( "\n\n", $kept );
	}

	/** The opening lines of a description, headings skipped, as plain lines. */
	public static function summary( $description ) {
		$out   = array();
		$words = 0;
		foreach ( self::lines( $description ) as $line ) {
			if ( self::is_heading( $line ) ) {
				if ( $out ) {
					break; // The first section is the summary; the next heading starts the details.
				}
				continue;
			}
			$n = count( preg_split( '/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY ) );
			if ( ! $out && $n > self::SUMMARY_WORDS + 10 ) {
				return wp_trim_words( $line, self::SUMMARY_WORDS, '…' );
			}
			$out[]  = $line;
			$words += $n;
			if ( $words >= self::SUMMARY_WORDS || count( $out ) >= self::SUMMARY_LINES ) {
				break;
			}
		}
		return implode( "\n", $out );
	}

	protected static function is_heading( $line ) {
		$p = self::plain( $line );
		return (bool) preg_match( self::TEXT_HEADING, $p ) || ( mb_strlen( $p, 'UTF-8' ) <= 30 && preg_match( '/[:：]$/u', $p ) );
	}

	protected static function is_divider( $line ) {
		$p = preg_replace( '/\s+/u', '', wp_strip_all_tags( (string) $line ) );
		return '' !== $p && (bool) preg_match( '/^(?:\x{0640}{5,}|[-_=─━•.*]{8,})$/u', $p );
	}

	/** A line as the reader sees it: no tags, bullets or emphasis marks. */
	protected static function plain( $line ) {
		$p = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $line ) ) );
		return trim( preg_replace( '/^[\-–•*·▪►✔✅🔹🔸]+\s*|\*+/u', '', $p ) );
	}

	/** Non-empty trimmed lines, tags removed. */
	protected static function lines( $text ) {
		$text = preg_replace( '#<\s*(?:br|/p|/li|/h[1-6]|/div)\s*/?>#i', "\n", (string) $text );
		$out  = array();
		foreach ( preg_split( '/\R/u', wp_strip_all_tags( $text ) ) as $line ) {
			$line = trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line && ! self::is_divider( $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Reconcile products whose text breaks the invariant. Daily from cron with a
	 * small batch; also callable with a larger one. Returns what it changed.
	 */
	public static function sweep( $limit = 100 ) {
		global $wpdb;
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending')
				   AND ( CHAR_LENGTH(post_excerpt) > %d OR post_content LIKE %s OR post_excerpt LIKE %s OR post_content REGEXP %s OR post_excerpt REGEXP %s )
				 ORDER BY ID DESC",
				self::SUMMARY_MAX_CHARS,
				'%' . $wpdb->esc_like( 'ـــــ' ) . '%',
				'%' . $wpdb->esc_like( 'ـــــ' ) . '%',
				'(أفكار|افكار) المحتوى|زوايا (تسويقي|بيع|البيع)',
				'(أفكار|افكار) المحتوى|زوايا (تسويقي|بيع|البيع)'
			)
		);
		$done = array( 'checked' => 0, 'changed' => 0 );
		foreach ( $ids as $id ) {
			if ( $done['changed'] >= $limit ) {
				break;
			}
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$done['checked']++;
			self::normalise( $product );
			if ( $product->get_changes() ) {
				$product->save();
				$done['changed']++;
			}
		}
		$done['remaining'] = max( 0, count( $ids ) - $done['checked'] );
		update_option( 'hayak_core_product_text_last_sweep', array( 'time' => time() ) + $done, false );
		return $done;
	}
}
