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
 *   - reseller-notes blocks (from their heading to the next divider or the next
 *     heading of the real text), runs of ad-angle lines, lines introducing
 *     pasted generated text, and tatweel divider lines are removed from both
 *     texts (a text made only of notes keeps its lines, without the heading);
 *   - a short description that is not a summary (longer than SUMMARY_MAX_LINES
 *     lines or SUMMARY_MAX_CHARS characters) is replaced by the opening lines of
 *     the description, so it matches the current text. The TikTok catalogue
 *     sends the short description, so it is never left empty;
 *   - a title has no leading list mark and no doubled spaces: the importer
 *     copies Taager's "• " bullet into the name (254 titles cleaned by hand in
 *     pass 5, and new ones arriving with every import since).
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
	const NOTES_HEADING = '/^(?:[أا]فكار\s+(?:ال)?محتوى|زوايا\s+(?:تسويقي[ةه]|بيعي[ةه]|(?:ال)?بيع))(?:\s.{0,60}|\s*:.*)?$/u';

	/** A line that only introduces pasted, generated text ("بالطبع، إليك بعض المميزات ...:"). */
	const PREAMBLE = '/^(?:(?:بالطبع|بالتأكيد|بكل\s+سرور|طبع[اًا]+|أكيد)[،,!\s]*)?[إا]ليك\s+(?:محتوى|وصف|نص|بعض|أهم|اهم)[^\n]{0,160}[:：]$/u';

	/** Headings of the real text; a notes block with no divider ends at the first of these. */
	const TEXT_HEADING = '/^(?:(?:ال)?مميزات|(?:ال)?مواصفات|تفاصيل|(?:ال)?محتويات|(?:كيفي[ةه]|طريق[ةه])\s+(?:ال)?استخدام|(?:ال)?خصائص)[^.،!؟]{0,30}$/u';

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
		$name  = (string) $product->get_name( 'edit' );
		$title = self::title( $name );
		if ( '' !== $title && $title !== $name ) {
			$product->set_name( $title );
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

	/** A title as a title: ordinary letters, no leading list mark, single spaces. */
	public static function title( $name ) {
		$name = preg_replace( '/[\s\x{00A0}]+/u', ' ', self::letters( (string) $name ) );
		return trim( preg_replace( '/^(?:[\s•·▪●◦►✔✅🔹🔸*]|[\-–—](?=\s))+/u', '', $name ) );
	}

	/** Empty, or short enough to be a summary rather than a description. */
	public static function is_summary( $text ) {
		$lines = self::lines( $text );
		return count( $lines ) <= self::SUMMARY_MAX_LINES && mb_strlen( implode( ' ', $lines ), 'UTF-8' ) <= self::SUMMARY_MAX_CHARS;
	}

	/**
	 * Text without reseller-notes blocks and tatweel dividers. A notes block runs
	 * from its heading to the next divider or the next heading of the real text,
	 * wherever it sits. When the notes are all there is, only their headings go.
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$text = self::letters( $text );
		// A long tatweel run sharing a line with text is still a divider.
		$text = preg_replace( '/[ \t]*\x{0640}{10,}[ \t]*/u', "\n\u{0640}\u{0640}\u{0640}\u{0640}\u{0640}\n", $text );
		$all  = preg_split( '/\R/u', $text );
		$drop = self::angle_runs( $all );
		$out     = array();
		$skip    = false;
		$changed = (bool) $drop;
		foreach ( $all as $n => $line ) {
			if ( isset( $drop[ $n ] ) || preg_match( self::PREAMBLE, self::plain( $line ) ) ) {
				$changed = true;
				continue;
			}
			if ( self::is_divider( $line ) ) {
				$skip    = false;
				$changed = true;
				$out[]   = '';
				continue;
			}
			$plain = self::plain( $line );
			if ( preg_match( self::NOTES_HEADING, $plain ) ) {
				$skip    = true;
				$changed = true;
				continue;
			}
			if ( $skip ) {
				if ( ! preg_match( self::TEXT_HEADING, $plain ) ) {
					continue;
				}
				$skip  = false;
				$out[] = '';
			}
			$out[] = $line;
		}
		if ( ! $changed ) {
			return $text;
		}
		$lines = array();
		foreach ( $out as $line ) {
			$blank = '' === trim( $line );
			if ( $blank && ( ! $lines || '' === end( $lines ) ) ) {
				continue; // One blank line between blocks, none at the start.
			}
			$lines[] = $blank ? '' : rtrim( $line );
		}
		while ( $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}
		$result = implode( "\n", $lines );
		if ( '' !== self::plain( str_replace( "\n", ' ', $result ) ) ) {
			return $result;
		}
		// Notes are all there is: keep their lines, drop only the headings and dividers.
		$keep = array();
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			if ( ! self::is_divider( $line ) && ! preg_match( self::NOTES_HEADING, self::plain( $line ) ) ) {
				$keep[] = $line;
			}
		}
		$keep = trim( implode( "\n", $keep ) );
		return '' === $keep ? $text : $keep;
	}

	/**
	 * Text pasted from a PDF arrives in Arabic presentation forms (one code point
	 * per glyph shape) with Persian yeh and kaf. It reads the same but matches
	 * nothing: not a search, not a heading. Restore ordinary Arabic letters.
	 */
	protected static function letters( $text ) {
		if ( ! class_exists( 'Normalizer' ) || ! preg_match( '/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]/u', $text ) ) {
			return $text;
		}
		$text = preg_replace_callback(
			'/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]+/u',
			function ( $m ) {
				$n = Normalizer::normalize( $m[0], Normalizer::FORM_KC );
				return false === $n ? $m[0] : $n;
			},
			$text
		);
		return strtr( $text, array( 'ی' => 'ي', 'ک' => 'ك' ) );
	}

	/**
	 * Line numbers of runs of three or more ad-angle lines ("زاوية القوة: ...")
	 * with no heading of their own; blank lines do not break a run. A single
	 * spec line such as "زاوية الدوران: 360" is never part of one.
	 */
	protected static function angle_runs( array $lines ) {
		$drop = array();
		$run  = array();
		foreach ( $lines as $n => $line ) {
			$plain = self::plain( $line );
			if ( '' === $plain ) {
				continue;
			}
			if ( preg_match( '/^زاوي[ةه]\s/u', $plain ) ) {
				$run[] = $n;
				continue;
			}
			if ( count( $run ) >= 3 ) {
				$drop += array_fill_keys( $run, true );
			}
			$run = array();
		}
		if ( count( $run ) >= 3 ) {
			$drop += array_fill_keys( $run, true );
		}
		return $drop;
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
		$p = preg_replace( '/^[\-–•·]+(?=\x{0640})/u', '', $p );
		return '' !== $p && (bool) preg_match( '/^(?:\x{0640}{5,}|[-_=─━•.*]{8,})$/u', $p );
	}

	/** A line as the reader sees it: no tags, bullets or emphasis marks. */
	protected static function plain( $line ) {
		$p = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $line ) ) );
		return trim( preg_replace( '/^(?:[\-–•*·▪►✔✅🔹🔸]+\s*)+|\*+/u', '', $p ) );
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
				   AND ( CHAR_LENGTH(post_excerpt) > %d OR CHAR_LENGTH(post_excerpt) - CHAR_LENGTH(REPLACE(post_excerpt, '\n', '')) >= %d OR post_content LIKE %s OR post_excerpt LIKE %s OR post_content REGEXP %s OR post_excerpt REGEXP %s
				         OR post_content LIKE %s OR post_excerpt LIKE %s
				         OR post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s )
				 ORDER BY ID DESC",
				self::SUMMARY_MAX_CHARS,
				self::SUMMARY_MAX_LINES,
				'%' . $wpdb->esc_like( 'ـــــ' ) . '%',
				'%' . $wpdb->esc_like( 'ـــــ' ) . '%',
				'(أفكار|افكار) المحتوى|زوايا (تسويقي|بيع|البيع)|[إا]ليك (محتوى|وصف|نص|بعض|أهم|اهم)',
				'(أفكار|افكار) المحتوى|زوايا (تسويقي|بيع|البيع)|[إا]ليك (محتوى|وصف|نص|بعض|أهم|اهم)',
				'%' . $wpdb->esc_like( 'زاوية ' ) . '%',
				'%' . $wpdb->esc_like( 'زاوية ' ) . '%',
				$wpdb->esc_like( '•' ) . '%',
				$wpdb->esc_like( '-' ) . '%',
				$wpdb->esc_like( ' ' ) . '%',
				'%' . $wpdb->esc_like( ' ' ),
				'%' . $wpdb->esc_like( '  ' ) . '%'
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
