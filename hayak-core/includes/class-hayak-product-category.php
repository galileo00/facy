<?php
/**
 * Every product sits in one store category, with at most one subcategory under
 * it, and the category comes from where Taager files the product.
 *
 * Why: the Taager importer leaves every product in the default category (19,
 * "غير مصنّف"), and by-hand filing left products under two top-level categories,
 * or under a subcategory and a second, unrelated top-level one. The owner's rule
 * (2 Oct 2026): a product has one top-level category, plus one subcategory of it
 * at most, and the category follows Taager's own classification.
 *
 * What the SKU tells: a Taager SKU is "SA", Taager's main category (01
 * electronics, 02 fashion, 03 home, 04 health and beauty, 05 entertainment,
 * which at Taager holds cars, sports, toys, tools, camping and kids), then a
 * grouping code that does not follow Taager's subcategories (SA0301 holds
 * cleaning, storage and household tools alike), then the supplier's own code.
 * So the main category can be read from the SKU, the subcategory cannot.
 *
 * Where the subcategory comes from: Taager's catalogue (data/taager-catalog.json,
 * Taager product id => Taager category id, built from the catalogue export the
 * owner provides; OPTION_CATALOG holds additions made since, which win). A
 * product's Taager id is in its Taager URL, which the sync plugin records in
 * its report (option hts_report, read only). Both ids are kept on the product
 * (META_TAAGER_ID, META_TAAGER_CAT). A product Taager has not been seen in, or
 * whose SKU is not a Taager one, is left where it is and listed in the sweep
 * result (META_UNRESOLVED) for the daily task to resolve by reading the Taager
 * product page.
 *
 * The map (option OPTION_MAP, seeded from defaults() and edited by the daily
 * task or the owner, never by code): "roots" are the top-level categories;
 * "subs" the subcategories (existing ones by id, new ones created by name
 * under their root), each with the title words that pick it where Taager does
 * not separate (fans, coolers and heaters all sit in Taager's "air care");
 * "leaves" map every Taager category to a root and, where the store has one,
 * a subcategory. A subcategory the product already has under the right root
 * is kept when the map gives none. META_SUB pins a product's subcategory and
 * META_LOCK keeps its categories as they are, apart from the one-root rule.
 * A map that does not validate is reported (OPTION_MAP_ERROR) and the last
 * valid one keeps working.
 *
 * It runs whenever a product is saved or its categories are set, and a daily
 * sweep re-files, in batches, every product saved since the last sweep, every
 * product that breaks the rule, and every product filed under an older map
 * (the map's hash is kept on each product).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Product_Category {

	const SWEEP_HOOK  = 'hayak_core_product_category_sweep';
	const BATCH_HOOK  = 'hayak_core_product_category_batch';
	const OPTION_LAST = 'hayak_core_product_category_last_sweep';
	/** Resolved subcategory term ids by key, with the hash of the map they were resolved for. */
	const OPTION_SUBS = 'hayak_core_category_subs';

	/** The editable map: roots, sku_roots, sku_groups, subs, leaves (see defaults()). */
	const OPTION_MAP       = 'hayak_category_map';
	const OPTION_MAP_OK    = 'hayak_category_map_last_ok';
	const OPTION_MAP_ERROR = 'hayak_category_map_error';

	/** Taager product id => Taager category id, added since the catalogue file (wins over it). */
	const OPTION_CATALOG = 'hayak_taager_catalog';
	/** The catalogue export: {"tree": {id: [name, parent, level]}, "products": {product id: category id}}. */
	const CATALOG_FILE = 'data/taager-catalog.json';

	const META_TAAGER_ID  = '_hayak_taager_id';
	const META_TAAGER_CAT = '_hayak_taager_category';
	const META_VERSION    = '_hayak_cat_version';
	const META_UNRESOLVED = '_hayak_cat_unresolved';
	const META_LOCK       = '_hayak_cat_lock';
	/** A subcategory pinned by hand: its term id or its key in the map. Applies under the product's root. */
	const META_SUB = '_hayak_cat_sub';

	const BATCH_SIZE = 100;

	private static $filing   = false;
	private static $catalog  = null;
	private static $map      = null;
	private static $map_hash = '';

	/**
	 * The map the option is seeded with. Keys: roots (key => term id);
	 * sku_roots (Taager main code => root key, where one main maps to one
	 * root); sku_groups (Taager "05" grouping code => root key, for the codes
	 * that mostly hold one kind of product); subs (key => root, name, slug,
	 * id when the term exists, keywords as a regex of title words that pick
	 * it within its root, start_only when the words count only at the start
	 * of the title); leaves (Taager category id => "root", "root/sub", or
	 * "keep:root" for Taager categories that say nothing about the product).
	 */
	public static function defaults() {
		return array(
			'roots'      => array(
				'home'        => 298,
				'electronics' => 299,
				'beauty'      => 300,
				'fun'         => 301,
				'sport'       => 302,
				'car'         => 303,
				'tools'       => 188,
				'fashion'     => 324,
			),
			'sku_roots'  => array( '01' => 'electronics', '02' => 'fashion', '03' => 'home', '04' => 'beauty' ),
			'sku_groups' => array( '0501' => 'car', '0502' => 'sport', '0503' => 'fun', '0504' => 'tools', '0505' => 'fun', '0510' => 'home' ),
			// Order matters: the first subcategory whose words match wins.
			'subs'       => array(
				'vacuums'     => array( 'root' => 'home', 'name' => 'مكانس كهربائية', 'slug' => 'vacuum-cleaners', 'id' => 310, 'keywords' => 'مكنس[ةه]|مكانس|مكنست(?:ان|ين)' ),
				'coolers'     => array( 'root' => 'home', 'name' => 'مكيفات صحراوية', 'slug' => 'air-coolers', 'id' => 319, 'keywords' => 'مكيف(?:ات)?(?!\s+(?:ال)?سيار)' ),
				'fans'        => array( 'root' => 'home', 'name' => 'مراوح', 'slug' => 'fans', 'id' => 320, 'keywords' => 'مروح[ةه]|مراوح' ),
				'heaters'     => array( 'root' => 'home', 'name' => 'دفايات', 'slug' => 'heaters', 'id' => 318, 'keywords' => 'دفاي[ةه]|دفايات|مدفأ[ةه]' ),
				'slicers'     => array( 'root' => 'home', 'name' => 'قطاعات خضار', 'slug' => 'vegetable-slicers', 'id' => 321, 'keywords' => 'قطاع[ةه]|قطّاع[ةه]|قطاعات' ),
				'floodlights' => array( 'root' => 'home', 'name' => 'كشافات', 'slug' => 'floodlights', 'id' => 315, 'keywords' => 'كشاف|كشافات' ),
				'blenders'    => array( 'root' => 'home', 'name' => 'خلاطات كهربائية', 'slug' => 'blenders', 'id' => 322, 'keywords' => 'خلاط|خلاطات|هاند\s*بلندر|محضر\s+(?:ال)?طعام' ),
				'camping'     => array( 'root' => 'home', 'name' => 'أدوات تخييم', 'slug' => 'camping-tools', 'id' => 313, 'keywords' => 'خيم[ةه]|خيام' ),
				'kitchen'     => array( 'root' => 'home', 'name' => 'أدوات المطبخ', 'slug' => 'kitchen-tools', 'id' => 325 ),
				'kitchen_app' => array( 'root' => 'home', 'name' => 'أجهزة المطبخ', 'slug' => 'kitchen-appliances', 'id' => 326 ),
				'cleaning'    => array( 'root' => 'home', 'name' => 'أدوات التنظيف', 'slug' => 'cleaning-tools', 'id' => 327 ),
				'storage'     => array( 'root' => 'home', 'name' => 'التخزين والتنظيم', 'slug' => 'storage-organizers', 'id' => 328 ),
				'furniture'   => array( 'root' => 'home', 'name' => 'أثاث', 'slug' => 'furniture', 'id' => 329 ),
				'tablets'     => array( 'root' => 'electronics', 'name' => 'أجهزة تابلت', 'slug' => 'tablets', 'id' => 317, 'keywords' => 'تابلت', 'start_only' => true ),
				'phones'      => array( 'root' => 'electronics', 'name' => 'جوالات', 'slug' => 'mobile-phones', 'id' => 312, 'keywords' => 'جوال|هاتف|موبايل|تليفون|شبيه\s+(?:ال)?(?:ايفون|آيفون|أيفون|جوال)', 'start_only' => true ),
				'cctv'        => array( 'root' => 'electronics', 'name' => 'كاميرات مراقبة', 'slug' => 'security-cameras', 'id' => 309, 'keywords' => 'كاميرا(?:ت)?\s+(?:ال)?مراقب[ةه]' ),
				'chargers'    => array( 'root' => 'electronics', 'name' => 'شواحن وباور بانك', 'slug' => 'chargers-power-banks', 'id' => 331, 'keywords' => 'باور\s*بانك|باوربانك|شاحن' ),
				'mobile_acc'  => array( 'root' => 'electronics', 'name' => 'إكسسوارات الجوال', 'slug' => 'mobile-accessories', 'id' => 330 ),
				'massage'     => array( 'root' => 'beauty', 'name' => 'أجهزة مساج', 'slug' => 'massagers', 'id' => 314, 'keywords' => '(?:جهاز\s+)?(?:مساج|تدليك|مدلك)' ),
				'skin'        => array( 'root' => 'beauty', 'name' => 'العناية بالبشرة', 'slug' => 'skin-care', 'id' => 332 ),
				'styling'     => array( 'root' => 'beauty', 'name' => 'أدوات التجميل والتصفيف', 'slug' => 'beauty-styling-tools', 'id' => 333 ),
				'hair'        => array( 'root' => 'beauty', 'name' => 'العناية بالشعر', 'slug' => 'hair-care', 'id' => 334 ),
				'medical'     => array( 'root' => 'beauty', 'name' => 'منتجات طبية', 'slug' => 'medical-products', 'id' => 335 ),
				'dashcams'    => array( 'root' => 'car', 'name' => 'داش كام للسيارة', 'slug' => 'dash-cams', 'id' => 323, 'keywords' => 'داش\s*كام|كاميرا\s+(?:ال)?سيار[ةه]|كاميرا\s+للسيار[ةه]' ),
				'car_care'    => array( 'root' => 'car', 'name' => 'العناية بالسيارة', 'slug' => 'car-care', 'id' => 336 ),
				'drills'      => array( 'root' => 'tools', 'name' => 'دريل كهربائي وشنيور', 'slug' => 'drills', 'id' => 316, 'keywords' => 'دريل|شنيور|مثقاب' ),
				'power_tools' => array( 'root' => 'tools', 'name' => 'أدوات كهربائية', 'slug' => 'power-tools', 'id' => 337 ),
				'hand_tools'  => array( 'root' => 'tools', 'name' => 'أدوات يدوية', 'slug' => 'hand-tools', 'id' => 338 ),
			),
			// Taager's own names are in the comments of the first version of this file (2 Oct 2026).
			'leaves'     => array(
				// المنزل
				'10' => 'home', '79' => 'home', '375' => 'home', '72' => 'home', '360' => 'home', '339' => 'home/cleaning',
				'364' => 'home/storage', '340' => 'home/furniture', '362' => 'home', '366' => 'home/kitchen', '367' => 'home',
				'770' => 'home', '772' => 'home', '774' => 'home', '828' => 'home', '73' => 'home', '368' => 'home/heaters',
				'369' => 'home', '370' => 'home', '825' => 'home', '826' => 'home', '827' => 'home', '75' => 'home', '374' => 'home',
				'335' => 'home/floodlights', '829' => 'home/furniture', '830' => 'home/camping', '76' => 'home/kitchen',
				'373' => 'home/kitchen', '337' => 'home/kitchen_app', '771' => 'home/kitchen', '831' => 'home/storage',
				'832' => 'home/kitchen', '833' => 'home/kitchen', '834' => 'home', '835' => 'home', '836' => 'home/storage', '837' => 'home',
				// إلكترونيات
				'6' => 'electronics', '84' => 'electronics', '350' => 'electronics', '85' => 'electronics', '351' => 'electronics/phones',
				'352' => 'electronics/tablets', '775' => 'electronics', '82' => 'electronics/mobile_acc', '346' => 'electronics/mobile_acc',
				'345' => 'electronics/mobile_acc', '343' => 'electronics/mobile_acc', '344' => 'electronics/mobile_acc',
				'69' => 'electronics/mobile_acc', '479' => 'electronics/mobile_acc', '83' => 'electronics', '349' => 'electronics',
				'348' => 'electronics/cctv', '347' => 'electronics', '776' => 'home/floodlights', '778' => 'electronics',
				'779' => 'electronics', '99' => 'electronics/chargers', '353' => 'electronics/chargers', '355' => 'electronics/chargers',
				'777' => 'electronics/chargers', '100' => 'electronics', '357' => 'electronics',
				// الصحه والجمال
				'9' => 'beauty', '88' => 'beauty/styling', '377' => 'beauty/styling', '379' => 'beauty/styling', '381' => 'beauty/styling',
				'380' => 'beauty/styling', '378' => 'beauty/styling', '780' => 'beauty/styling', '90' => 'beauty', '392' => 'beauty',
				'89' => 'beauty', '383' => 'beauty/styling', '382' => 'beauty', '385' => 'beauty/styling', '387' => 'beauty', '386' => 'beauty',
				'390' => 'beauty', '391' => 'beauty', '389' => 'beauty', '388' => 'beauty/skin', '735' => 'beauty/skin', '740' => 'beauty/skin',
				'781' => 'beauty/skin', '782' => 'beauty/skin', '783' => 'beauty/skin', '786' => 'beauty/skin', '736' => 'beauty/massage',
				'738' => 'beauty/massage', '787' => 'beauty/massage', '788' => 'beauty/massage', '790' => 'beauty/massage',
				'791' => 'beauty/massage', '737' => 'beauty/hair', '739' => 'beauty', '792' => 'beauty/hair', '793' => 'beauty/hair',
				'794' => 'beauty/hair', '795' => 'beauty/hair', '796' => 'beauty/hair', '797' => 'beauty', '799' => 'beauty', '800' => 'beauty',
				'801' => 'beauty', '802' => 'beauty', '803' => 'beauty', '804' => 'beauty', '805' => 'beauty', '806' => 'beauty', '807' => 'beauty',
				'808' => 'beauty', '809' => 'beauty', '810' => 'beauty', '811' => 'beauty', '812' => 'beauty', '813' => 'beauty/medical',
				'816' => 'beauty/medical', '817' => 'beauty/medical', '818' => 'beauty/medical', '819' => 'beauty/medical', '821' => 'beauty', '824' => 'beauty',
				// منتجات ترفيهية: split by Taager's own subcategory
				'8' => 'keep:fun', '74' => 'keep:fun', '333' => 'keep:fun', '70' => 'car', '342' => 'car', '358' => 'car/car_care', '359' => 'car',
				'361' => 'car', '823' => 'car', '77' => 'fun', '363' => 'fun', '365' => 'fun', '67' => 'fun', '354' => 'fun', '138' => 'fun',
				'480' => 'fun', '87' => 'sport', '371' => 'sport', '372' => 'sport', '822' => 'sport', '68' => 'tools', '356' => 'tools/power_tools',
				'336' => 'tools/hand_tools', '769' => 'tools', '80' => 'home/camping', '376' => 'home/camping', '741' => 'home', '742' => 'home',
				'101' => 'home', '384' => 'home',
				// فاشون
				'7' => 'fashion', '71' => 'fashion', '338' => 'fashion', '86' => 'fashion', '94' => 'fashion', '95' => 'fashion', '96' => 'fashion',
				'97' => 'fashion', '474' => 'fashion', '98' => 'fashion',
				// عروض تاجر الحصرية, خصومات, منتجات اسلامية: no type of their own
				'13' => 'keep:home', '126' => 'keep:home', '469' => 'keep:home', '12' => 'keep:home', '81' => 'keep:home', '91' => 'keep:home',
				'136' => 'keep:home', '476' => 'keep:home', '18' => 'keep:home', '137' => 'keep:home', '477' => 'keep:home', '478' => 'keep:home',
			),
		);
	}

	public static function init() {
		add_action( 'set_object_terms', array( __CLASS__, 'terms_set' ), 20, 4 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'product_saved' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_saved' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 4 * HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
		// A changed map re-files the catalogue once, in batches.
		$subs = get_option( self::OPTION_SUBS, array() );
		if ( ( is_array( $subs ) ? (string) ( $subs['hash'] ?? '' ) : '' ) !== self::map_hash() && ! wp_next_scheduled( self::SWEEP_HOOK, array( true ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SWEEP_HOOK, array( true ) );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_clear_scheduled_hook( self::SWEEP_HOOK, array( true ) );
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	public static function terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy || self::$filing || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::file( (int) $object_id );
	}

	public static function product_saved( $product_id ) {
		if ( self::$filing ) {
			return;
		}
		self::file( (int) $product_id );
	}

	/* ---------------------------------------------------------------- The map */

	/**
	 * The map in use: the option when it validates (seeded from defaults()
	 * when missing), else the last valid one, else the defaults. An invalid
	 * option is reported in OPTION_MAP_ERROR for the daily task.
	 */
	public static function map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$saved = get_option( self::OPTION_MAP, null );
		if ( ! is_array( $saved ) || ! $saved ) {
			$saved = self::defaults();
			update_option( self::OPTION_MAP, $saved, true );
		}
		$error = self::validate( $saved );
		if ( '' === $error ) {
			self::$map = self::normalize( $saved );
			if ( '' !== (string) get_option( self::OPTION_MAP_ERROR, '' ) ) {
				delete_option( self::OPTION_MAP_ERROR );
			}
		} else {
			update_option( self::OPTION_MAP_ERROR, $error, true );
			$ok        = get_option( self::OPTION_MAP_OK, null );
			self::$map = self::normalize( is_array( $ok ) && '' === self::validate( $ok ) ? $ok : self::defaults() );
		}
		self::$map_hash = substr( md5( (string) wp_json_encode( self::$map ) ), 0, 12 );
		if ( '' === $error ) {
			// Keep a copy of the last map that validated, for the fallback above (written once per change).
			$subs = get_option( self::OPTION_SUBS, array() );
			if ( ! is_array( $subs ) || (string) ( $subs['hash'] ?? '' ) !== self::$map_hash ) {
				update_option( self::OPTION_MAP_OK, $saved, false );
			}
		}
		return self::$map;
	}

	/** A short hash of the map in use; kept on each product it filed. */
	public static function map_hash() {
		self::map();
		return self::$map_hash;
	}

	/** '' when the map is usable, else what is wrong with it (the first problem found). */
	public static function validate( $m ) {
		if ( ! is_array( $m ) ) {
			return 'map is not an object';
		}
		foreach ( array( 'roots', 'subs', 'leaves' ) as $k ) {
			if ( empty( $m[ $k ] ) || ! is_array( $m[ $k ] ) ) {
				return "$k is missing or empty";
			}
		}
		foreach ( $m['roots'] as $key => $id ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $key ) ) {
				return "roots: key '$key' must be latin letters, digits or _";
			}
			if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
				return "roots.$key must be a term id";
			}
		}
		foreach ( array( 'sku_roots', 'sku_groups' ) as $k ) {
			if ( isset( $m[ $k ] ) && ! is_array( $m[ $k ] ) ) {
				return "$k must be an object";
			}
			foreach ( (array) ( $m[ $k ] ?? array() ) as $code => $root ) {
				if ( ! preg_match( '/^[0-9]{2,4}$/', (string) $code ) || ! isset( $m['roots'][ $root ] ) ) {
					return "$k.$code must map a 2-4 digit code to a root key";
				}
			}
		}
		foreach ( $m['subs'] as $key => $def ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', (string) $key ) ) {
				return "subs: key '$key' must be latin letters, digits or _";
			}
			if ( ! is_array( $def ) || empty( $def['root'] ) || ! isset( $m['roots'][ $def['root'] ] ) ) {
				return "subs.$key.root must be a root key";
			}
			if ( empty( $def['name'] ) || ! is_string( $def['name'] ) ) {
				return "subs.$key.name is missing";
			}
			if ( empty( $def['slug'] ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $def['slug'] ) ) {
				return "subs.$key.slug must be latin letters, digits and dashes";
			}
			if ( isset( $def['id'] ) && ! is_numeric( $def['id'] ) ) {
				return "subs.$key.id must be a term id";
			}
			if ( ! empty( $def['keywords'] ) ) {
				if ( ! is_string( $def['keywords'] ) || false === @preg_match( '/(?:' . $def['keywords'] . ')/u', '' ) ) {
					return "subs.$key.keywords is not a valid regular expression";
				}
			}
		}
		foreach ( $m['leaves'] as $leaf => $target ) {
			if ( ! preg_match( '/^[0-9]+$/', (string) $leaf ) ) {
				return "leaves: key '$leaf' must be a Taager category id";
			}
			$e = self::parse_leaf( $target );
			if ( ! $e ) {
				return "leaves.$leaf must be 'root', 'root/sub' or 'keep:root'";
			}
			if ( 'keep' === $e[0] ) {
				if ( ! isset( $m['roots'][ $e[2] ] ) ) {
					return "leaves.$leaf: '{$e[2]}' is not a root key";
				}
				continue;
			}
			if ( ! isset( $m['roots'][ $e[0] ] ) ) {
				return "leaves.$leaf: '{$e[0]}' is not a root key";
			}
			if ( $e[1] && ( ! isset( $m['subs'][ $e[1] ] ) || ( $m['subs'][ $e[1] ]['root'] ?? '' ) !== $e[0] ) ) {
				return "leaves.$leaf: '{$e[1]}' is not a subcategory of '{$e[0]}'";
			}
		}
		return '';
	}

	/** "root" => [root, null], "root/sub" => [root, sub], "keep:root" => ['keep', null, root]; null when malformed. */
	protected static function parse_leaf( $target ) {
		if ( ! is_string( $target ) || '' === $target ) {
			return null;
		}
		if ( 0 === strpos( $target, 'keep:' ) ) {
			$root = substr( $target, 5 );
			return '' !== $root ? array( 'keep', null, $root ) : null;
		}
		$parts = explode( '/', $target, 2 );
		if ( '' === $parts[0] || ( isset( $parts[1] ) && '' === $parts[1] ) ) {
			return null;
		}
		return array( $parts[0], $parts[1] ?? null );
	}

	/** Types made uniform, leaves parsed; the map the rest of the class reads. */
	protected static function normalize( array $m ) {
		$out = array( 'roots' => array(), 'sku_roots' => array(), 'sku_groups' => array(), 'subs' => array(), 'leaves' => array() );
		foreach ( $m['roots'] as $key => $id ) {
			$out['roots'][ (string) $key ] = (int) $id;
		}
		foreach ( array( 'sku_roots', 'sku_groups' ) as $k ) {
			foreach ( (array) ( $m[ $k ] ?? array() ) as $code => $root ) {
				$out[ $k ][ (string) $code ] = (string) $root;
			}
		}
		foreach ( $m['subs'] as $key => $def ) {
			$out['subs'][ (string) $key ] = array(
				'root'       => (string) $def['root'],
				'name'       => trim( (string) $def['name'] ),
				'slug'       => (string) $def['slug'],
				'id'         => (int) ( $def['id'] ?? 0 ),
				'keywords'   => (string) ( $def['keywords'] ?? '' ),
				'start_only' => ! empty( $def['start_only'] ),
			);
		}
		foreach ( $m['leaves'] as $leaf => $target ) {
			$out['leaves'][ (string) (int) $leaf ] = self::parse_leaf( $target );
		}
		return $out;
	}

	/** Term id of a root key, or 0. */
	protected static function root_id( $key ) {
		return (int) ( self::map()['roots'][ (string) $key ] ?? 0 );
	}

	/* ------------------------------------------------------------- Filing */

	/**
	 * File one product. Returns ['changed' => bool, 'root' => id, 'sub' => id|0,
	 * 'unresolved' => reason|''].
	 */
	public static function file( $post_id ) {
		$result  = array( 'changed' => false, 'root' => 0, 'sub' => 0, 'unresolved' => '' );
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return $result;
		}
		$map     = self::map();
		$subs    = self::subs();
		$current = self::current_terms( $post_id );
		$roots   = array_values( array_intersect( $current, $map['roots'] ) );
		$locked  = (bool) $product->get_meta( self::META_LOCK );
		$sku     = $product->get_sku( 'edit' );

		$leaf  = $locked ? 0 : self::leaf_for( $product );
		$entry = $leaf ? ( $map['leaves'][ (string) $leaf ] ?? null ) : null;
		$root  = 0;
		$sub   = 0;

		if ( $locked || ! $entry ) {
			if ( ! $locked && ! $entry ) {
				$code = self::sku_main( $sku );
				if ( $code && isset( $map['sku_roots'][ $code ] ) ) {
					$root = self::root_id( $map['sku_roots'][ $code ] );
				}
				if ( $leaf ) {
					$result['unresolved'] = 'leaf ' . $leaf . ' not in map';
				} elseif ( $product->get_meta( self::META_TAAGER_ID ) ) {
					$result['unresolved'] = 'taager product not in catalogue';
				} else {
					$result['unresolved'] = $code ? 'no taager id' : 'not a taager sku';
				}
			}
			if ( ! $root ) {
				$root = self::pick_root( $current, $roots, $locked ? 0 : self::sku_group_root( $sku ), 0, $product, $subs );
			}
		} elseif ( 'keep' === $entry[0] ) {
			$root = self::pick_root( $current, $roots, self::sku_group_root( $sku ), self::root_id( $entry[2] ), $product, $subs );
			if ( ! $root ) {
				$result['unresolved'] = 'two roots';
			}
		} else {
			$root = self::root_id( $entry[0] );
		}

		if ( ! $root ) {
			// Nothing to decide the root: leave the product as it is.
			if ( $result['unresolved'] ) {
				$product->update_meta_data( self::META_UNRESOLVED, $result['unresolved'] );
				$product->update_meta_data( self::META_VERSION, self::map_hash() );
				self::$filing = true;
				$product->save_meta_data();
				self::$filing = false;
			}
			return $result;
		}

		if ( ! $locked ) {
			$sub = self::pinned_sub( $product, $root, $subs );
			if ( ! $sub ) {
				$sub = self::keyword_sub( $product->get_name( 'edit' ), $root, $subs );
			}
			if ( ! $sub && $entry && ! empty( $entry[1] ) && ! empty( $subs[ $entry[1] ] ) && self::sub_parent( $subs[ $entry[1] ], $subs ) === $root ) {
				$sub = (int) $subs[ $entry[1] ];
			}
		}
		if ( ! $sub ) {
			// The subcategory the product already has under this root stays.
			foreach ( $current as $term_id ) {
				if ( self::sub_parent( $term_id, $subs ) === $root ) {
					$sub = (int) $term_id;
					break;
				}
			}
		}

		$wanted = $sub ? array( $root, $sub ) : array( $root );
		sort( $wanted );
		$have = $current;
		sort( $have );
		$result['root'] = $root;
		$result['sub']  = $sub;

		self::$filing = true;
		if ( $wanted !== $have ) {
			$set = wp_set_object_terms( $post_id, $wanted, 'product_cat' );
			if ( ! is_wp_error( $set ) ) {
				$result['changed'] = true;
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $post_id );
				}
				// Rank Math's primary category (breadcrumbs, schema) must be one the product has.
				if ( ! in_array( (int) get_post_meta( $post_id, 'rank_math_primary_product_cat', true ), $wanted, true ) ) {
					update_post_meta( $post_id, 'rank_math_primary_product_cat', $sub ? $sub : $root );
				}
			}
		}
		$product->update_meta_data( self::META_VERSION, self::map_hash() );
		if ( $result['unresolved'] ) {
			$product->update_meta_data( self::META_UNRESOLVED, $result['unresolved'] );
		} else {
			$product->delete_meta_data( self::META_UNRESOLVED );
		}
		$product->save_meta_data();
		self::$filing = false;

		// Setting terms is not a product save, so Google for WooCommerce would not
		// send the new category (the product type in Merchant Center) on its own.
		if ( $result['changed'] && class_exists( 'Hayak_Merchant_Feed' ) ) {
			Hayak_Merchant_Feed::resync( array( $post_id ) );
		}
		return $result;
	}

	/**
	 * The root to keep when the map does not name one: the parent of a
	 * subcategory the product has, else its single root, else, among two or
	 * more roots, the one the SKU group or the default or a title word points
	 * to; with no root at all, the SKU group's root, else the default. 0 when
	 * two roots remain and nothing decides: the product is left for the daily
	 * task.
	 */
	protected static function pick_root( array $current, array $roots, $group_root, $default_root, WC_Product $product, array $subs ) {
		foreach ( $current as $term_id ) {
			$parent = self::sub_parent( $term_id, $subs );
			if ( $parent ) {
				return $parent;
			}
		}
		if ( 1 === count( $roots ) ) {
			return $roots[0];
		}
		if ( $roots ) {
			if ( $group_root && in_array( $group_root, $roots, true ) ) {
				return $group_root;
			}
			if ( $default_root && in_array( $default_root, $roots, true ) ) {
				return $default_root;
			}
			$hits = array_values( array_filter( $roots, function ( $r ) use ( $product, $subs ) {
				return self::keyword_sub( $product->get_name( 'edit' ), $r, $subs ) > 0;
			} ) );
			return 1 === count( $hits ) ? $hits[0] : 0;
		}
		return $group_root ? $group_root : $default_root;
	}

	/** The product's categories, as term ids (the default one included, so it gets removed). */
	protected static function current_terms( $post_id ) {
		$terms = wp_get_object_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		return is_wp_error( $terms ) ? array() : array_values( array_map( 'intval', $terms ) );
	}

	/** The root of a store subcategory term, or 0 when the term is not one of the map's subs. */
	protected static function sub_parent( $term_id, array $subs ) {
		$key = array_search( (int) $term_id, $subs, true );
		if ( false === $key || 'hash' === $key || ! isset( self::map()['subs'][ $key ] ) ) {
			return 0;
		}
		return self::root_id( self::map()['subs'][ $key ]['root'] );
	}

	/** The subcategory pinned on the product (META_SUB: term id or map key), when it sits under $root; else 0. */
	protected static function pinned_sub( WC_Product $product, $root, array $subs ) {
		$pin = trim( (string) $product->get_meta( self::META_SUB ) );
		if ( '' === $pin ) {
			return 0;
		}
		$term_id = is_numeric( $pin ) ? (int) $pin : (int) ( $subs[ $pin ] ?? 0 );
		return $term_id && self::sub_parent( $term_id, $subs ) === $root ? $term_id : 0;
	}

	/** Taager's main category code from a SKU ("01".."05"), or ''. */
	public static function sku_main( $sku ) {
		$sku = strtoupper( (string) $sku );
		if ( 0 !== strpos( $sku, 'SA' ) || strlen( $sku ) < 4 ) {
			return '';
		}
		$code = substr( $sku, 2, 2 );
		return ctype_digit( $code ) ? $code : '';
	}

	/** The root a grouping code points to (the map's sku_groups), or 0. */
	public static function sku_group_root( $sku ) {
		$sku   = strtoupper( (string) $sku );
		$group = 0 === strpos( $sku, 'SA' ) ? substr( $sku, 2, 4 ) : '';
		$key   = self::map()['sku_groups'][ $group ] ?? '';
		return $key ? self::root_id( $key ) : 0;
	}

	/**
	 * The subcategory a title picks within a root, or 0: every subcategory's
	 * words at the start of the title (after an offer or bundle prefix) first,
	 * then anywhere in it, skipping start-only ones; the first match wins.
	 */
	public static function keyword_sub( $title, $root, array $subs ) {
		$title  = trim( (string) $title );
		$prefix = '^(?:(?:عرض|باقة|بكج|باكج|طقم|مجموعة)\s*\d*\s*(?:حبات|قطع|حبة|قطعة)?\s*)?(?:ال)?';
		foreach ( array( true, false ) as $anchored ) {
			foreach ( self::map()['subs'] as $key => $def ) {
				if ( '' === $def['keywords'] || self::root_id( $def['root'] ) !== $root || empty( $subs[ $key ] ) || ( ! $anchored && $def['start_only'] ) ) {
					continue;
				}
				$pattern = $anchored ? '/' . $prefix . '(?:' . $def['keywords'] . ')/u' : '/(?<!\p{L})(?:' . $def['keywords'] . ')/u';
				if ( @preg_match( $pattern, $title ) ) {
					return (int) $subs[ $key ];
				}
			}
		}
		return 0;
	}

	/* -------------------------------------------------------- Taager data */

	/**
	 * The Taager category id of a product: from its meta, else from the
	 * catalogue by its Taager id, which comes from the Taager URL the sync plugin
	 * recorded for the SKU. Both are stored on the product once found.
	 */
	public static function leaf_for( WC_Product $product ) {
		$leaf = (int) $product->get_meta( self::META_TAAGER_CAT );
		if ( $leaf ) {
			return $leaf;
		}
		$pid = (int) $product->get_meta( self::META_TAAGER_ID );
		if ( ! $pid ) {
			$pid = self::taager_id_for_sku( $product->get_sku( 'edit' ) );
			if ( $pid ) {
				update_post_meta( $product->get_id(), self::META_TAAGER_ID, $pid );
			}
		}
		if ( ! $pid ) {
			return 0;
		}
		$leaf = (int) ( self::catalog()[ $pid ] ?? 0 );
		if ( $leaf ) {
			update_post_meta( $product->get_id(), self::META_TAAGER_CAT, $leaf );
		}
		return $leaf;
	}

	/** Taager product id => Taager category id: the catalogue file, with OPTION_CATALOG over it. */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		$file = HAYAK_CORE_PATH . self::CATALOG_FILE;
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$base = is_array( $data ) && isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
		$more = get_option( self::OPTION_CATALOG, array() );
		self::$catalog = array_replace( $base, is_array( $more ) ? $more : array() );
		return self::$catalog;
	}

	/** The Taager product id behind a SKU, from the sync plugin's report, or 0. */
	public static function taager_id_for_sku( $sku ) {
		global $wpdb;
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return 0;
		}
		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT REGEXP_SUBSTR(SUBSTRING(option_value, LOCATE(%s, option_value), 1500), 'taager[.]com/[a-z]+/products/[0-9]+')
				 FROM {$wpdb->options} WHERE option_name = 'hts_report' AND LOCATE(%s, option_value) > 0",
				'"' . $sku . '"',
				'"' . $sku . '"'
			)
		);
		return $url && preg_match( '~/products/(\d+)~', $url, $m ) ? (int) $m[1] : 0;
	}

	/* ------------------------------------------------------------- Terms */

	/**
	 * Subcategory term ids by map key, creating the missing terms under their
	 * root. Cached in OPTION_SUBS with the hash of the map they came from.
	 */
	public static function subs( $fresh = false ) {
		static $cache = null;
		if ( null !== $cache && ! $fresh ) {
			return $cache;
		}
		$hash  = self::map_hash();
		$saved = get_option( self::OPTION_SUBS, array() );
		if ( ! $fresh && is_array( $saved ) && (string) ( $saved['hash'] ?? '' ) === $hash ) {
			$cache = $saved;
			return $cache;
		}
		$ids = array();
		foreach ( self::map()['subs'] as $key => $def ) {
			$parent = self::root_id( $def['root'] );
			$term   = $def['id'] ? get_term( $def['id'], 'product_cat' ) : null;
			if ( ! $term instanceof WP_Term || (int) $term->parent !== $parent ) {
				$term = get_term_by( 'slug', $def['slug'], 'product_cat' );
				if ( ! $term instanceof WP_Term || (int) $term->parent !== $parent ) {
					$term = null;
					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $parent, 'name' => $def['name'] ) ) as $found ) {
						if ( $found instanceof WP_Term ) {
							$term = $found;
							break;
						}
					}
				}
			}
			if ( ! $term instanceof WP_Term ) {
				$created = wp_insert_term( $def['name'], 'product_cat', array( 'parent' => $parent, 'slug' => $def['slug'] ) );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term = get_term( (int) $created['term_id'], 'product_cat' );
			}
			if ( $term instanceof WP_Term ) {
				$ids[ $key ] = (int) $term->term_id;
			}
		}
		$ids['hash'] = $hash;
		update_option( self::OPTION_SUBS, $ids, true );
		$cache = $ids;
		return $cache;
	}

	/* ------------------------------------------------------------- Sweeps */

	/**
	 * Daily, or once after a map change ($all): queue every product that needs
	 * filing and work through it in batches.
	 */
	public static function sweep( $all = false ) {
		global $wpdb;
		self::subs( true );
		$last  = get_option( self::OPTION_LAST, array() );
		$since = is_array( $last ) ? (int) ( $last['time'] ?? 0 ) : 0;
		$where = $all ? '1=1' : $wpdb->prepare(
			"( p.post_modified_gmt >= %s
			   OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} v WHERE v.post_id = p.ID AND v.meta_key = %s AND v.meta_value = %s )
			   OR ( SELECT COUNT(*) FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
			        WHERE r.object_id = p.ID AND t.taxonomy = 'product_cat' AND t.parent = 0 AND t.term_id <> %d ) <> 1
			   OR EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
			        WHERE r.object_id = p.ID AND t.taxonomy = 'product_cat' AND t.term_id = %d )
			   OR ( SELECT COUNT(*) FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
			        WHERE r.object_id = p.ID AND t.taxonomy = 'product_cat' AND t.parent <> 0 ) > 1 )",
			gmdate( 'Y-m-d H:i:s', $since ? $since - DAY_IN_SECONDS : 0 ),
			self::META_VERSION,
			self::map_hash(),
			(int) get_option( 'default_product_cat' ),
			(int) get_option( 'default_product_cat' )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private') AND {$where} ORDER BY p.ID DESC" );
		update_option(
			self::OPTION_LAST,
			array(
				'time'       => time(),
				'map_hash'   => self::map_hash(),
				'map_error'  => (string) get_option( self::OPTION_MAP_ERROR, '' ),
				'queued'     => count( $ids ),
				'done'       => 0,
				'changed'    => 0,
				'unresolved' => array(),
				'pending'    => array_map( 'intval', $ids ),
			),
			false
		);
		self::batch();
	}

	/** Files the next BATCH_SIZE queued products and schedules the next batch. */
	public static function batch() {
		$state = get_option( self::OPTION_LAST, array() );
		if ( ! is_array( $state ) || empty( $state['pending'] ) || ! is_array( $state['pending'] ) ) {
			return;
		}
		$ids = array_splice( $state['pending'], 0, self::BATCH_SIZE );
		foreach ( $ids as $id ) {
			$done = self::file( (int) $id );
			$state['done']++;
			if ( $done['changed'] ) {
				$state['changed']++;
			}
			if ( $done['unresolved'] && count( $state['unresolved'] ) < 200 ) {
				$state['unresolved'][] = (int) $id;
			}
		}
		// A sweep that started while this batch ran owns the queue now; its own batch chain carries on.
		$now = get_option( self::OPTION_LAST, array() );
		if ( is_array( $now ) && (int) ( $now['time'] ?? 0 ) !== (int) ( $state['time'] ?? 0 ) ) {
			return;
		}
		update_option( self::OPTION_LAST, $state, false );
		if ( $state['pending'] && ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK );
		}
	}
}
