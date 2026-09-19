<?php
/**
 * GA4-format purchase dataLayer event on the thank-you page.
 *
 * Feeds GTM container GTM-MBJVPZW, which maps it onto Meta, TikTok, Snapchat
 * and Google Ads. The container also carries a fallback tag, so if this module
 * is ever disabled the conversion tags still fire - just without value/items.
 *
 * The event is named `hayak_purchase`, not `purchase`, on purpose. Google Site
 * Kit and Google Listings & Ads both call gtag('event','purchase') on the same
 * page, and gtag writes into the very same window.dataLayer, so a GTM trigger
 * on `purchase` fired three times per order. A name only this plugin emits is
 * the one thing those two cannot collide with.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_DataLayer {

	const EVENT_NAME       = 'hayak_purchase';
	const THANK_YOU_SLUG   = 'thank-you';
	const PHONE_PARAM      = 'phone';
	const COUNTRY_CODE     = '966';
	const LOOKBACK_SECONDS = 1800;  // 30 min - only used by the ?phone= fallback
	const COUNTED_STATUSES = array( 'pending', 'processing', 'on-hold', 'completed' );

	public static function init() {
		// Priority 1: always ahead of the GTM container that GTM4WP prints.
		add_action( 'wp_head', array( __CLASS__, 'render' ), 1 );
	}

	private static function hash_phone( $local ) {
		if ( '' === $local ) {
			return '';
		}
		return hash( 'sha256', self::COUNTRY_CODE . $local );
	}

	/**
	 * The same number, hashed the way Google wants it.
	 *
	 * Google's enhanced conversions normalise a phone to E.164 *including* the
	 * leading plus before hashing; Meta, Snap and TikTok drop the plus. Same
	 * digits, completely different digest, so a hash built for one platform
	 * matches nobody on the other. Both formats travel in user_data and each
	 * tag picks the key it understands.
	 */
	private static function hash_phone_e164( $local ) {
		if ( '' === $local ) {
			return '';
		}
		return hash( 'sha256', '+' . self::COUNTRY_CODE . $local );
	}

	private static function hash_email( $email ) {
		$email = trim( strtolower( (string) $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		return hash( 'sha256', $email );
	}

	private static function is_thank_you_page() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return true;
		}
		return is_page( self::THANK_YOU_SLUG );
	}

	/**
	 * Find the order this visit belongs to.
	 *
	 * Order of preference:
	 *   1. the signed cookie the form submission just set (exact),
	 *   2. a real WooCommerce order-received URL,
	 *   3. the phone in the query string (only while orders come from Make).
	 */
	private static function find_order( $local_phone ) {
		$remembered = Hayak_Orders::remembered_order_id();
		if ( $remembered ) {
			$order = wc_get_order( $remembered );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			if ( $order && $key && hash_equals( $order->get_order_key(), $key ) ) {
				return $order;
			}
		}

		if ( '' === $local_phone || ! self::phone_lookup_allowed() ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'        => 40,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'status'       => self::COUNTED_STATUSES,
				'date_created' => '>' . ( time() - self::LOOKBACK_SECONDS ),
			)
		);

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return null;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( Hayak_Fields::normalise_phone( $order->get_billing_phone(), self::COUNTRY_CODE ) === $local_phone ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Whether to fall back to matching the order by ?phone=.
	 *
	 * That lookup lets anyone who knows a mobile number read back that buyer's
	 * most recent order, so it is only worth its cost while orders are still
	 * created outside this plugin (i.e. by Make) and therefore carry no signed
	 * cookie. Once direct order creation is on, every real buyer has the cookie
	 * and the fallback is switched off automatically.
	 */
	private static function phone_lookup_allowed() {
		return (bool) apply_filters( 'hayak_pixel_allow_phone_lookup', ! Hayak_Orders::is_enabled() );
	}

	private static function build_items( WC_Order $order ) {
		$items = array();

		foreach ( $order->get_items() as $line ) {
			$product_id = $line->get_product_id();
			$qty        = max( 1, (int) $line->get_quantity() );
			$line_total = (float) $line->get_total();

			$category = '';
			$terms    = get_the_terms( $product_id, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$first    = reset( $terms );
				$category = $first->name;
			}

			/**
			 * Must match the id used in your Meta / TikTok catalogue feed -
			 * return the SKU here instead if your feed is keyed on SKU.
			 */
			$item_id = apply_filters( 'hayak_pixel_item_id', (string) $product_id, $line, $order );

			$items[] = array(
				'item_id'       => (string) $item_id,
				'item_name'     => wp_strip_all_tags( $line->get_name() ),
				'item_category' => $category,
				'price'         => round( $line_total / $qty, 2 ),
				'quantity'      => $qty,
			);
		}

		return $items;
	}

	public static function render() {
		if ( ! function_exists( 'wc_get_orders' ) || ! self::is_thank_you_page() ) {
			return;
		}

		// The payload is per-visitor: never let a page cache store it.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$raw_phone   = isset( $_GET[ self::PHONE_PARAM ] ) && is_string( $_GET[ self::PHONE_PARAM ] )
			? wp_unslash( $_GET[ self::PHONE_PARAM ] )
			: '';
		$local_phone = Hayak_Fields::normalise_phone( $raw_phone, self::COUNTRY_CODE );

		$order = self::find_order( $local_phone );
		if ( ! $order instanceof WC_Order ) {
			// The container's fallback tag fires the conversion without order
			// data rather than losing it entirely.
			return;
		}

		$order_phone = Hayak_Fields::normalise_phone( $order->get_billing_phone(), self::COUNTRY_CODE );
		if ( '' === $order_phone ) {
			$order_phone = $local_phone;
		}

		$payload = array(
			// Stable per order: Meta/TikTok/Snap de-duplicate on this, so a
			// refresh of the thank-you page is not counted twice.
			'event'     => self::EVENT_NAME,
			'event_id'  => 'hy-' . $order->get_id(),
			'user_data' => array(
				'sha256_phone_number'      => self::hash_phone( $order_phone ),
				'sha256_phone_number_e164' => self::hash_phone_e164( $order_phone ),
				'sha256_email_address'     => self::hash_email( $order->get_billing_email() ),
			),
			'ecommerce' => array(
				'transaction_id' => (string) $order->get_id(),
				'value'          => round( (float) $order->get_total(), 2 ),
				'currency'       => $order->get_currency(),
				'shipping'       => round( (float) $order->get_shipping_total(), 2 ),
				'tax'            => round( (float) $order->get_total_tax(), 2 ),
				'coupon'         => implode( ',', (array) $order->get_coupon_codes() ),
				'items'          => self::build_items( $order ),
			),
		);

		$source = $order->get_meta( Hayak_Orders::META_SOURCE );
		if ( $source ) {
			$payload['traffic_source'] = $source;
		}

		/** Last chance to adjust what reaches the pixels. */
		$payload = apply_filters( 'hayak_pixel_datalayer_payload', $payload, $order );

		// JSON_HEX_TAG is what stops a value like "</script><script src=...>" from
		// closing this block; JSON_UNESCAPED_SLASHES would have allowed it.
		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}
		?>
<!-- Hayak Core: purchase dataLayer -->
<script data-cfasync="false">
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({ ecommerce: null });
window.dataLayer.push(<?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output, tag-escaped. ?>);
</script>
<!-- /Hayak Core -->
		<?php
	}
}
