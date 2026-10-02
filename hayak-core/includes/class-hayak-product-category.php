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
 * product's Taager id is in its Taager URL, which the sync plugin
 * records in its report (option hts_report, read only). Both ids are kept on the
 * product (META_TAAGER_ID, META_TAAGER_CAT). A product Taager has not been seen
 * in, or whose SKU is not a Taager one, is left where it is and listed in the
 * sweep result (META_UNRESOLVED) for the daily task to resolve by reading the
 * Taager product page.
 *
 * The store tree: ROOTS are the top-level categories; SUBS the subcategories
 * (existing ones by id, new ones created by name under their root). LEAVES maps
 * every Taager category to a root and, where the store has one, a subcategory.
 * KEYWORDS refine within a root from the product title, for the few store
 * subcategories Taager does not separate (fans, coolers and heaters all sit in
 * Taager's "air care"). A subcategory the product already has under the right
 * root is kept when the map gives none. META_LOCK on a product keeps its
 * categories as they are, apart from the one-root rule.
 *
 * It runs whenever a product is saved or its categories are set, and a daily
 * sweep re-files, in batches, every product saved since the last sweep, every
 * product that breaks the rule, and every product filed under an older
 * MAP_VERSION.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Product_Category {

	const SWEEP_HOOK  = 'hayak_core_product_category_sweep';
	const BATCH_HOOK  = 'hayak_core_product_category_batch';
	const OPTION_LAST = 'hayak_core_product_category_last_sweep';
	const OPTION_SUBS = 'hayak_core_category_subs';

	/** Taager product id => Taager category id, added since the catalogue file (wins over it). */
	const OPTION_CATALOG = 'hayak_taager_catalog';
	/** The catalogue export: {"tree": {id: [name, parent, level]}, "products": {product id: category id}}. */
	const CATALOG_FILE = 'data/taager-catalog.json';

	const META_TAAGER_ID  = '_hayak_taager_id';
	const META_TAAGER_CAT = '_hayak_taager_category';
	const META_VERSION    = '_hayak_cat_version';
	const META_UNRESOLVED = '_hayak_cat_unresolved';
	const META_LOCK       = '_hayak_cat_lock';

	/** Bump when LEAVES, SUBS or KEYWORDS change: every product is then re-filed by the sweep. */
	const MAP_VERSION = 2;

	const BATCH_SIZE = 100;

	/** Store top-level categories. */
	const ROOTS = array(
		'home'        => 298,
		'electronics' => 299,
		'beauty'      => 300,
		'fun'         => 301,
		'sport'       => 302,
		'car'         => 303,
		'tools'       => 188,
		'fashion'     => 324,
	);

	/** Taager main category in the SKU => store root, where one main maps to one root. */
	const SKU_ROOTS = array(
		'01' => 'electronics',
		'02' => 'fashion',
		'03' => 'home',
		'04' => 'beauty',
	);

	/**
	 * Taager's "05" grouping codes that mostly hold one kind of product (cars
	 * 81%, sport 78%, toys 90%, tools 79%, kids 65%, camping 72% of the
	 * catalogue): the root when nothing better is known. The other codes
	 * (0506, 0507, 0509) are mixed and give nothing.
	 */
	const SKU_GROUPS = array(
		'0501' => 'car',
		'0502' => 'sport',
		'0503' => 'fun',
		'0504' => 'tools',
		'0505' => 'fun',
		'0510' => 'home',
	);

	/**
	 * Store subcategories: key => [root key, name, slug, term id when it already exists].
	 * A missing one is created under its root on the first sweep.
	 */
	const SUBS = array(
		'camping'     => array( 'home', 'أدوات تخييم', 'camping-tools', 313 ),
		'floodlights' => array( 'home', 'كشافات', 'floodlights', 315 ),
		'vacuums'     => array( 'home', 'مكانس كهربائية', 'vacuum-cleaners', 310 ),
		'coolers'     => array( 'home', 'مكيفات صحراوية', 'air-coolers', 319 ),
		'slicers'     => array( 'home', 'قطاعات خضار', 'vegetable-slicers', 321 ),
		'fans'        => array( 'home', 'مراوح', 'fans', 320 ),
		'heaters'     => array( 'home', 'دفايات', 'heaters', 318 ),
		'blenders'    => array( 'home', 'خلاطات كهربائية', 'blenders', 322 ),
		'kitchen'     => array( 'home', 'أدوات المطبخ', 'kitchen-tools', 0 ),
		'kitchen_app' => array( 'home', 'أجهزة المطبخ', 'kitchen-appliances', 0 ),
		'cleaning'    => array( 'home', 'أدوات التنظيف', 'cleaning-tools', 0 ),
		'storage'     => array( 'home', 'التخزين والتنظيم', 'storage-organizers', 0 ),
		'furniture'   => array( 'home', 'أثاث', 'furniture', 0 ),
		'tablets'     => array( 'electronics', 'أجهزة تابلت', 'tablets', 317 ),
		'cctv'        => array( 'electronics', 'كاميرات مراقبة', 'security-cameras', 309 ),
		'phones'      => array( 'electronics', 'جوالات', 'mobile-phones', 312 ),
		'mobile_acc'  => array( 'electronics', 'إكسسوارات الجوال', 'mobile-accessories', 0 ),
		'chargers'    => array( 'electronics', 'شواحن وباور بانك', 'chargers-power-banks', 0 ),
		'massage'     => array( 'beauty', 'أجهزة مساج', 'massagers', 314 ),
		'skin'        => array( 'beauty', 'العناية بالبشرة', 'skin-care', 0 ),
		'styling'     => array( 'beauty', 'أدوات التجميل والتصفيف', 'beauty-styling-tools', 0 ),
		'hair'        => array( 'beauty', 'العناية بالشعر', 'hair-care', 0 ),
		'medical'     => array( 'beauty', 'منتجات طبية', 'medical-products', 0 ),
		'dashcams'    => array( 'car', 'داش كام للسيارة', 'dash-cams', 323 ),
		'car_care'    => array( 'car', 'العناية بالسيارة', 'car-care', 0 ),
		'drills'      => array( 'tools', 'دريل كهربائي وشنيور', 'drills', 316 ),
		'power_tools' => array( 'tools', 'أدوات كهربائية', 'power-tools', 0 ),
		'hand_tools'  => array( 'tools', 'أدوات يدوية', 'hand-tools', 0 ),
	);

	/**
	 * Taager category id => [root key, sub key or null]. A root key of 'keep'
	 * means the product's current root stays when it has exactly one, else the
	 * third element is used. Taager's own names are in the comments.
	 */
	const LEAVES = array(
		// المنزل (10)
		10  => array( 'home', null ),
		79  => array( 'home', null ),            // عروض المنزل
		375 => array( 'home', null ),            // عروض المنزل > عروض المنزل
		72  => array( 'home', null ),            // مستلزمات المنزل
		360 => array( 'home', null ),            // أدوات منزلية
		339 => array( 'home', 'cleaning' ),      // منتجات التنظيف
		364 => array( 'home', 'storage' ),       // أدوات تخزين
		340 => array( 'home', 'furniture' ),     // أثاث
		362 => array( 'home', null ),            // اضواء داخلية
		366 => array( 'home', 'kitchen' ),       // أدوات الشرب
		367 => array( 'home', null ),            // منتجات الديكور
		770 => array( 'home', null ),            // مفارش
		772 => array( 'home', null ),            // مواد استهلاكية للمنزل
		774 => array( 'home', null ),            // اكسسوارات منزلية
		828 => array( 'home', null ),            // منتجات الامن و الحماية
		73  => array( 'home', null ),            // الأجهزه المنزلية
		368 => array( 'home', 'heaters' ),       // دفايات
		369 => array( 'home', null ),            // العناية بالهواء (keywords: fans, coolers, heaters)
		370 => array( 'home', null ),            // أجهزة منزلية صغيرة
		825 => array( 'home', null ),            // العناية بالارضيات
		826 => array( 'home', null ),            // العناية بالملابس
		827 => array( 'home', null ),            // العناية بالماء
		75  => array( 'home', null ),            // لحدائق المنزل
		374 => array( 'home', null ),            // الحدائق
		335 => array( 'home', 'floodlights' ),   // إضاءة خارجية
		829 => array( 'home', 'furniture' ),     // الاثاث الخارجي
		830 => array( 'home', 'camping' ),       // الشواء والباربيكيو
		76  => array( 'home', 'kitchen' ),       // مستلزمات المطبخ
		373 => array( 'home', 'kitchen' ),       // ادوات المطبخ
		337 => array( 'home', 'kitchen_app' ),   // اجهزة المطبخ
		771 => array( 'home', 'kitchen' ),       // اكسسوارات المطبخ
		831 => array( 'home', 'storage' ),       // وحدات تخزين المطبخ
		832 => array( 'home', 'kitchen' ),       // أدوات الطهي
		833 => array( 'home', 'kitchen' ),       // أدوات المائدة وأواني التقديم
		834 => array( 'home', null ),            // مستلزمات الحمام
		835 => array( 'home', null ),
		836 => array( 'home', 'storage' ),       // وحدات تخزين الحمام
		837 => array( 'home', null ),
		// إلكترونيات (6)
		6   => array( 'electronics', null ),
		84  => array( 'electronics', null ),     // جيمنج
		350 => array( 'electronics', null ),     // اكسسوارات الجامينج
		85  => array( 'electronics', null ),     // موبايل و تابلت
		351 => array( 'electronics', 'phones' ),  // موبايلات
		352 => array( 'electronics', 'tablets' ), // تابلتس
		775 => array( 'electronics', null ),     // شاشات
		82  => array( 'electronics', 'mobile_acc' ), // اكسسوارات موبايل
		346 => array( 'electronics', 'mobile_acc' ), // اكسسوارات ذكية
		345 => array( 'electronics', 'mobile_acc' ), // حوامل موبايل
		343 => array( 'electronics', 'mobile_acc' ), // ساعات ذكية
		344 => array( 'electronics', 'mobile_acc' ), // سماعات لاسلكية
		69  => array( 'electronics', 'mobile_acc' ), // اكسسوارات كمبيوتر
		479 => array( 'electronics', 'mobile_acc' ), // سماعات رأس
		83  => array( 'electronics', null ),     // الكترونيات اخرى
		349 => array( 'electronics', null ),     // الكترونيات اخرى > الكترونيات اخرى
		348 => array( 'electronics', 'cctv' ),   // كاميرات
		347 => array( 'electronics', null ),     // مكبرات الصوت
		776 => array( 'home', 'floodlights' ),   // الأضائات و الفلاشات: the store keeps floodlights under home
		778 => array( 'electronics', null ),     // أجهزة الشبكات
		779 => array( 'electronics', null ),     // طابعات
		99  => array( 'electronics', 'chargers' ), // شواحن
		353 => array( 'electronics', 'chargers' ), // محولات
		355 => array( 'electronics', 'chargers' ), // شواحن لاسلكية
		777 => array( 'electronics', 'chargers' ), // باوربنكات
		100 => array( 'electronics', null ),     // عروض الالكترونيات
		357 => array( 'electronics', null ),
		// الصحه والجمال (9)
		9   => array( 'beauty', null ),
		88  => array( 'beauty', 'styling' ),     // أدوات التجميل والتصفيف
		377 => array( 'beauty', 'styling' ),     // مجفف شعر
		379 => array( 'beauty', 'styling' ),     // مكواة فرد الشعر
		381 => array( 'beauty', 'styling' ),     // مكواة لف الشعر
		380 => array( 'beauty', 'styling' ),     // أجهزة IPL وليزر
		378 => array( 'beauty', 'styling' ),     // أجهزة إزالة الشعر
		780 => array( 'beauty', 'styling' ),     // إكسسوارات
		90  => array( 'beauty', null ),          // عروض الصحة والجمال
		392 => array( 'beauty', null ),
		89  => array( 'beauty', null ),          // العناية الشخصية
		383 => array( 'beauty', 'styling' ),     // مزيلات الشعر الكهربائية
		382 => array( 'beauty', null ),          // مكن حلاقة
		385 => array( 'beauty', 'styling' ),     // مجففات الشعر
		387 => array( 'beauty', null ),          // ميزان
		386 => array( 'beauty', null ),          // منتجات الاستحمام والعناية بالجسم
		390 => array( 'beauty', null ),          // العناية بالفم والأسنان
		391 => array( 'beauty', null ),          // مزيلات العرق
		389 => array( 'beauty', null ),          // العناية النسائية
		388 => array( 'beauty', 'skin' ),        // كريمات ترطيب
		735 => array( 'beauty', 'skin' ),        // مستحضرات العناية بالبشرة
		740 => array( 'beauty', 'skin' ),        // مرطبات
		781 => array( 'beauty', 'skin' ),        // واقي الشمس
		782 => array( 'beauty', 'skin' ),        // غسول
		783 => array( 'beauty', 'skin' ),        // تونر
		786 => array( 'beauty', 'skin' ),        // مجموعة هدايا
		736 => array( 'beauty', 'massage' ),     // اجهزة مساج
		738 => array( 'beauty', 'massage' ),
		787 => array( 'beauty', 'massage' ),
		788 => array( 'beauty', 'massage' ),
		790 => array( 'beauty', 'massage' ),
		791 => array( 'beauty', 'massage' ),
		737 => array( 'beauty', 'hair' ),        // منتجات العناية بالشعر
		739 => array( 'beauty', null ),          // بلسم: at Taager a catch-all (shapers, devices, one conditioner)
		792 => array( 'beauty', 'hair' ),        // شامبو
		793 => array( 'beauty', 'hair' ),        // ماسك للشعر
		794 => array( 'beauty', 'hair' ),        // زيوت وسيروم
		795 => array( 'beauty', 'hair' ),        // مستحضرات صبغ الشعر
		796 => array( 'beauty', 'hair' ),        // منتجات تساقط الشعر
		797 => array( 'beauty', null ),          // منتجات العناية بالرجال
		799 => array( 'beauty', null ),          // ماكينة حلاقة
		800 => array( 'beauty', null ),
		801 => array( 'beauty', null ),
		802 => array( 'beauty', null ),
		803 => array( 'beauty', null ),          // العناية باللحية
		804 => array( 'beauty', null ),          // ميك-اب
		805 => array( 'beauty', null ),
		806 => array( 'beauty', null ),
		807 => array( 'beauty', null ),
		808 => array( 'beauty', null ),
		809 => array( 'beauty', null ),
		810 => array( 'beauty', null ),
		811 => array( 'beauty', null ),
		812 => array( 'beauty', null ),
		813 => array( 'beauty', 'medical' ),     // منتجات طبية
		816 => array( 'beauty', 'medical' ),     // فارما
		817 => array( 'beauty', 'medical' ),     // مكملات غذائية
		818 => array( 'beauty', 'medical' ),     // أجهزة طبية
		819 => array( 'beauty', 'medical' ),     // مشدات الجسم ودعامات المفاصل
		821 => array( 'beauty', null ),          // عطور
		824 => array( 'beauty', null ),
		// منتجات ترفيهية (8): split by Taager's own subcategory
		8   => array( 'keep', null, 'fun' ),
		74  => array( 'keep', null, 'fun' ),     // عروض ترفيهية
		333 => array( 'keep', null, 'fun' ),
		70  => array( 'car', null ),             // مستلزمات السيارات
		342 => array( 'car', null ),             // اكسسوارات سيارات
		358 => array( 'car', 'car_care' ),       // منظفات ومعطرات للسياره
		359 => array( 'car', null ),             // حوامل
		361 => array( 'car', null ),             // إصلاحات
		823 => array( 'car', null ),             // مكانس السيارات
		77  => array( 'fun', null ),             // العاب
		363 => array( 'fun', null ),
		365 => array( 'fun', null ),             // العاب اطفال
		67  => array( 'fun', null ),             // مستلزمات أطفال
		354 => array( 'fun', null ),
		138 => array( 'fun', null ),             // هدايا
		480 => array( 'fun', null ),
		87  => array( 'sport', null ),           // منتجات رياضية
		371 => array( 'sport', null ),           // أدوات رياضية
		372 => array( 'sport', null ),
		822 => array( 'sport', null ),           // الآلات الرياضية
		68  => array( 'tools', null ),           // أدوات
		356 => array( 'tools', 'power_tools' ),  // أدوات كهربائية
		336 => array( 'tools', 'hand_tools' ),   // أدوات يدوية
		769 => array( 'tools', null ),           // ادوات زراعة
		80  => array( 'home', 'camping' ),       // التخييم و الرحلات
		376 => array( 'home', 'camping' ),
		741 => array( 'home', null ),            // القرطاسية
		742 => array( 'home', null ),            // مستلزمات المكاتب
		101 => array( 'home', null ),            // منتجات الحيوانات الأليفة
		384 => array( 'home', null ),
		// فاشون (7)
		7   => array( 'fashion', null ),
		71  => array( 'fashion', null ),         // ساعات
		338 => array( 'fashion', null ),
		86  => array( 'fashion', null ),
		94  => array( 'fashion', null ),
		95  => array( 'fashion', null ),
		96  => array( 'fashion', null ),         // أحذية
		97  => array( 'fashion', null ),         // أكسسوارات
		474 => array( 'fashion', null ),         // شنط
		98  => array( 'fashion', null ),
		// عروض تاجر الحصرية (13), خصومات (12), منتجات اسلامية (18): no type of their own
		13  => array( 'keep', null, 'home' ),
		126 => array( 'keep', null, 'home' ),
		469 => array( 'keep', null, 'home' ),
		12  => array( 'keep', null, 'home' ),
		81  => array( 'keep', null, 'home' ),
		91  => array( 'keep', null, 'home' ),
		136 => array( 'keep', null, 'home' ),
		476 => array( 'keep', null, 'home' ),
		18  => array( 'keep', null, 'home' ),
		137 => array( 'keep', null, 'home' ),
		477 => array( 'keep', null, 'home' ),
		478 => array( 'keep', null, 'home' ),
	);

	/**
	 * Title words that pick a subcategory within a root, where Taager does not
	 * separate them: [root key, pattern, sub key, start only]. The pattern is
	 * tried at the start of the title (after an offer or bundle prefix) first,
	 * for every rule, then anywhere in the title; the first match wins. A rule
	 * marked start-only is skipped in the second pass: "حامل هاتف" is an
	 * accessory, "هاتف نوكيا" a phone.
	 */
	const KEYWORDS = array(
		array( 'home', 'مكنس[ةه]|مكانس|مكنست(?:ان|ين)', 'vacuums' ),
		array( 'home', 'مكيف(?:ات)?(?!\s+(?:ال)?سيار)', 'coolers' ),
		array( 'home', 'مروح[ةه]|مراوح', 'fans' ),
		array( 'home', 'دفاي[ةه]|دفايات|مدفأ[ةه]', 'heaters' ),
		array( 'home', 'قطاع[ةه]|قطّاع[ةه]|قطاعات', 'slicers' ),
		array( 'home', 'كشاف|كشافات', 'floodlights' ),
		array( 'home', 'خلاط|خلاطات|هاند\s*بلندر|محضر\s+(?:ال)?طعام', 'blenders' ),
		array( 'home', 'خيم[ةه]|خيام', 'camping' ),
		array( 'electronics', 'تابلت', 'tablets', true ),
		array( 'electronics', 'جوال|هاتف|موبايل|تليفون|شبيه\s+(?:ال)?(?:ايفون|آيفون|أيفون|جوال)', 'phones', true ),
		array( 'electronics', 'كاميرا(?:ت)?\s+(?:ال)?مراقب[ةه]', 'cctv' ),
		array( 'electronics', 'باور\s*بانك|باوربانك|شاحن', 'chargers' ),
		array( 'beauty', '(?:جهاز\s+)?(?:مساج|تدليك|مدلك)', 'massage' ),
		array( 'car', 'داش\s*كام|كاميرا\s+(?:ال)?سيار[ةه]|كاميرا\s+للسيار[ةه]', 'dashcams' ),
		array( 'tools', 'دريل|شنيور|مثقاب', 'drills' ),
	);

	private static $filing  = false;
	private static $catalog = null;

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
		// A new map version re-files the catalogue once, in batches.
		$subs = get_option( self::OPTION_SUBS, array() );
		if ( ( (int) ( $subs['version'] ?? 0 ) ) !== self::MAP_VERSION && ! wp_next_scheduled( self::SWEEP_HOOK, array( true ) ) ) {
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
		$subs    = self::subs();
		$current = self::current_terms( $post_id );
		$roots   = array_values( array_intersect( $current, self::ROOTS ) );
		$locked  = (bool) $product->get_meta( self::META_LOCK );

		$leaf  = $locked ? 0 : self::leaf_for( $product );
		$entry = $leaf ? ( self::LEAVES[ $leaf ] ?? null ) : null;
		$root  = 0;
		$sub   = 0;

		if ( $locked || ! $entry ) {
			if ( ! $locked && ! $entry ) {
				$code = self::sku_main( $product->get_sku( 'edit' ) );
				if ( $code && isset( self::SKU_ROOTS[ $code ] ) ) {
					$root = self::ROOTS[ self::SKU_ROOTS[ $code ] ];
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
				$root = self::pick_root( $current, $roots, $locked ? 0 : self::sku_group_root( $product->get_sku( 'edit' ) ), 0, $product, $subs );
			}
		} elseif ( 'keep' === $entry[0] ) {
			$root = self::pick_root( $current, $roots, self::sku_group_root( $product->get_sku( 'edit' ) ), self::ROOTS[ $entry[2] ], $product, $subs );
			if ( ! $root ) {
				$result['unresolved'] = 'two roots';
			}
		} else {
			$root = self::ROOTS[ $entry[0] ];
		}

		if ( ! $root ) {
			// Nothing to decide the root: leave the product as it is.
			if ( $result['unresolved'] ) {
				$product->update_meta_data( self::META_UNRESOLVED, $result['unresolved'] );
				$product->update_meta_data( self::META_VERSION, self::MAP_VERSION );
				self::$filing = true;
				$product->save_meta_data();
				self::$filing = false;
			}
			return $result;
		}

		if ( ! $locked ) {
			$sub = self::keyword_sub( $product->get_name( 'edit' ), $root, $subs );
			if ( ! $sub && $entry && ! empty( $entry[1] ) && isset( $subs[ $entry[1] ] ) && self::ROOTS[ self::SUBS[ $entry[1] ][0] ] === $root ) {
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
		$product->update_meta_data( self::META_VERSION, self::MAP_VERSION );
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

	/** The root of a store subcategory term, or 0 when the term is not one of SUBS. */
	protected static function sub_parent( $term_id, array $subs ) {
		$key = array_search( (int) $term_id, $subs, true );
		if ( false === $key || 'version' === $key || ! isset( self::SUBS[ $key ] ) ) {
			return 0;
		}
		return self::ROOTS[ self::SUBS[ $key ][0] ];
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

	/** The root a "05" grouping code points to (SKU_GROUPS), or 0. */
	public static function sku_group_root( $sku ) {
		$sku   = strtoupper( (string) $sku );
		$group = 0 === strpos( $sku, 'SA' ) ? substr( $sku, 2, 4 ) : '';
		return isset( self::SKU_GROUPS[ $group ] ) ? self::ROOTS[ self::SKU_GROUPS[ $group ] ] : 0;
	}

	/**
	 * The subcategory a title picks within a root, or 0: every rule at the start
	 * of the title first, then every rule anywhere in it.
	 */
	public static function keyword_sub( $title, $root, array $subs ) {
		$title  = trim( (string) $title );
		$prefix = '^(?:(?:عرض|باقة|بكج|باكج|طقم|مجموعة)\s*\d*\s*(?:حبات|قطع|حبة|قطعة)?\s*)?(?:ال)?';
		foreach ( array( true, false ) as $anchored ) {
			foreach ( self::KEYWORDS as $rule ) {
				if ( self::ROOTS[ $rule[0] ] !== $root || empty( $subs[ $rule[2] ] ) || ( ! $anchored && ! empty( $rule[3] ) ) ) {
					continue;
				}
				$pattern = $anchored ? '/' . $prefix . '(?:' . $rule[1] . ')/u' : '/(?<!\p{L})(?:' . $rule[1] . ')/u';
				if ( preg_match( $pattern, $title ) ) {
					return (int) $subs[ $rule[2] ];
				}
			}
		}
		return 0;
	}

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

	/**
	 * Store subcategory term ids by key, creating the missing ones under their
	 * root. Cached in OPTION_SUBS with the map version.
	 */
	public static function subs( $fresh = false ) {
		static $cache = null;
		if ( null !== $cache && ! $fresh ) {
			return $cache;
		}
		$saved = get_option( self::OPTION_SUBS, array() );
		if ( ! $fresh && is_array( $saved ) && (int) ( $saved['version'] ?? 0 ) === self::MAP_VERSION ) {
			$cache = $saved;
			return $cache;
		}
		$ids = array();
		foreach ( self::SUBS as $key => $def ) {
			$parent = self::ROOTS[ $def[0] ];
			$term   = $def[3] ? get_term( (int) $def[3], 'product_cat' ) : null;
			if ( ! $term instanceof WP_Term || (int) $term->parent !== $parent ) {
				$term = get_term_by( 'slug', $def[2], 'product_cat' );
				if ( ! $term instanceof WP_Term || (int) $term->parent !== $parent ) {
					$term = null;
					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $parent, 'name' => $def[1] ) ) as $found ) {
						if ( $found instanceof WP_Term ) {
							$term = $found;
							break;
						}
					}
				}
			}
			if ( ! $term instanceof WP_Term ) {
				$created = wp_insert_term( $def[1], 'product_cat', array( 'parent' => $parent, 'slug' => $def[2] ) );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term = get_term( (int) $created['term_id'], 'product_cat' );
			}
			if ( $term instanceof WP_Term ) {
				$ids[ $key ] = (int) $term->term_id;
			}
		}
		$ids['version'] = self::MAP_VERSION;
		update_option( self::OPTION_SUBS, $ids, true );
		$cache = $ids;
		return $cache;
	}

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
			(string) self::MAP_VERSION,
			(int) get_option( 'default_product_cat' ),
			(int) get_option( 'default_product_cat' )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private') AND {$where} ORDER BY p.ID DESC" );
		update_option(
			self::OPTION_LAST,
			array( 'time' => time(), 'queued' => count( $ids ), 'done' => 0, 'changed' => 0, 'unresolved' => array(), 'pending' => array_map( 'intval', $ids ) ),
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
