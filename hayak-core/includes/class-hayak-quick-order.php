<?php
/**
 * Quick order form on the product page (the old WooCommerce Quick Order Form Pro).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hayak_Quick_Order {

    const VERSION      = '3.1.0';
    const CREATED_VIA  = 'hayak-quick-order';
    // Deliberately the SAME prefix Hayak_Orders uses: its hourly gc_locks() sweep
    // matches on that prefix, and a private one would leak a wp_options row per order.
    const LOCK_PREFIX  = 'hayak_lock_';
    const LOCK_TTL     = 120;

    private static $instance = null;

    /**
     * Module entry point, called from hayak-core.php at file scope.
     *
     * The check for WooCommerce MUST be deferred. Plugin files are included in the
     * order stored in active_plugins, which is alphabetical - hayak-core is included
     * several files BEFORE woocommerce, so class_exists('WooCommerce') is still false
     * at that moment. Testing it inline (as an earlier draft did) silently disabled
     * this whole module: no form, no ajax handler, no error. The standalone plugin
     * this replaces deferred on plugins_loaded for exactly this reason.
     */
    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 20 );
    }

    public static function boot() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Never run alongside the standalone plugin this replaces: two identical
        // forms would render, and the buyer could end up filling the one whose
        // submit handler is not bound.
        if ( class_exists( 'WC_Quick_Order_Form' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'duplicate_plugin_notice' ) );
            return;
        }

        self::get_instance();
    }

    public static function duplicate_plugin_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Hayak Core:</strong> '
            . 'فورم الطلب السريع متوقف مؤقتاً لأن بلجن "WooCommerce Quick Order Form Pro" القديم لسه مفعّل. '
            . 'اقفله عشان النسخة الجديدة تشتغل.'
            . '</p></div>';
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }
    
    private function define_constants() {
        if ( ! defined( 'WCQO_VERSION' ) ) {
            define( 'WCQO_VERSION', self::VERSION );
        }
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('woocommerce_after_add_to_cart_form', array($this, 'display_quick_order_form'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_inline_styles'));
        add_action('wp_footer', array($this, 'add_inline_scripts'));
        add_action('wp_ajax_wcqo_create_order', array($this, 'handle_ajax_order'));
        add_action('wp_ajax_nopriv_wcqo_create_order', array($this, 'handle_ajax_order'));
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        // Hooks لعرض السعر الصحيح في صفحة Thank You
        add_filter('woocommerce_get_order_item_totals', array($this, 'modify_order_totals_display'), 10, 3);
        add_filter('woocommerce_order_formatted_line_subtotal', array($this, 'modify_line_subtotal_display'), 10, 3);

        // The WooCommerce order total is deliberately the price MINUS the pre-paid
        // shipping, so it is not what the buyer actually pays. The pixels must
        // report the amount the buyer saw, or every quick order under-reports revenue.
        add_filter('hayak_pixel_datalayer_payload', array($this, 'correct_pixel_value'), 10, 2);

        // A cached product page serves a stale nonce to logged-out visitors, which
        // would reject real orders. Hand out a fresh one on load.
        add_action('wp_ajax_hayak_qo_nonce', array($this, 'ajax_fresh_nonce'));
        add_action('wp_ajax_nopriv_hayak_qo_nonce', array($this, 'ajax_fresh_nonce'));

        // أبسيل صفحة الشكر - أوردر منفصل، من غير ما يلمس الطلب الأصلي
        add_action('woocommerce_thankyou', array($this, 'render_upsell'), 25);
        add_filter('the_content', array($this, 'append_upsell_to_page'), 30);
        add_shortcode('hayak_upsell', array($this, 'upsell_shortcode'));
        add_action('wp_ajax_hayak_upsell_order', array($this, 'handle_upsell_order'));
        add_action('wp_ajax_nopriv_hayak_upsell_order', array($this, 'handle_upsell_order'));
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('wc-quick-order', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function activate_plugin() {
        add_option('wcqo_settings', $this->get_default_settings());
        flush_rewrite_rules();
    }
    
    /**
     * The saved settings with the current defaults merged in.
     *
     * The stored option predates the groups added in 3.1.0, so reading it raw
     * would hand every new feature an undefined index the first time the shop
     * loads. Merging one level deep keeps everything the owner saved and fills
     * in only what is missing.
     */
    private function settings() {
        $defaults = $this->get_default_settings();
        $stored   = get_option('wcqo_settings', array());

        if (!is_array($stored) || empty($stored)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $stored);

        foreach (array('colors', 'texts', 'ux', 'upsell') as $group) {
            $saved = isset($stored[$group]) && is_array($stored[$group]) ? $stored[$group] : array();
            $merged[$group] = array_merge($defaults[$group], $saved);
        }

        return $merged;
    }

    /**
     * Every colour, validated as a hex value before it reaches the stylesheet.
     *
     * The settings screen sanitises on save, but a value written straight into
     * a <style> block by any other route would be echoed unescaped, so the
     * stylesheet re-validates and falls back to the shipped default.
     */
    private function safe_colors($settings) {
        $defaults = $this->get_default_settings();
        $colors   = array();

        foreach ($defaults['colors'] as $key => $default) {
            $raw  = isset($settings['colors'][$key]) ? $settings['colors'][$key] : $default;
            $safe = sanitize_hex_color(is_string($raw) ? $raw : '');
            $colors[$key] = $safe ? $safe : $default;
        }

        return $colors;
    }

    /** The trust badges, one per line in the settings, blank lines dropped. */
    private function ux_badges($ux) {
        $raw = isset($ux['badges']) ? (string) $ux['badges'] : '';
        return array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)))), 0, 6);
    }

    /**
     * How many units are left, or null when the answer is not worth showing.
     *
     * Only a real managed-stock figure is ever displayed - an invented number
     * would be a lie the shop cannot stand behind.
     */
    private function stock_left($product, $threshold) {
        if (!$product || !$product->managing_stock()) {
            return null;
        }

        $left = $product->get_stock_quantity();
        if (null === $left) {
            return null;
        }

        $left      = (int) $left;
        $threshold = (int) $threshold;

        if ($left <= 0 || ($threshold > 0 && $left > $threshold)) {
            return null;
        }

        return $left;
    }

    /**
     * Orders containing this product in the last N hours.
     *
     * Cached for ten minutes: the product page must not pay for this query on
     * every hit, and the figure does not need to be live to the second.
     */
    private function product_orders_recently($product_id, $hours) {
        global $wpdb;

        $product_id = (int) $product_id;
        $hours      = max(1, min(168, (int) $hours));
        $cache_key  = 'hayak_qo_proof_' . $product_id . '_' . $hours;
        $cached     = get_transient($cache_key);

        if (false !== $cached) {
            return (int) $cached;
        }

        $since = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        $hpos  = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT oi.order_id)
                   FROM {$wpdb->prefix}woocommerce_order_items oi
                   INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                           ON oim.order_item_id = oi.order_item_id
                   INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
                  WHERE oim.meta_key = '_product_id'
                    AND oim.meta_value = %d
                    AND o.date_created_gmt >= %s
                    AND o.status NOT IN ('wc-cancelled','wc-failed','trash')",
                $product_id,
                $since
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT oi.order_id)
                   FROM {$wpdb->prefix}woocommerce_order_items oi
                   INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                           ON oim.order_item_id = oi.order_item_id
                   INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                  WHERE oim.meta_key = '_product_id'
                    AND oim.meta_value = %d
                    AND p.post_type = 'shop_order'
                    AND p.post_date_gmt >= %s
                    AND p.post_status NOT IN ('wc-cancelled','wc-failed','trash')",
                $product_id,
                $since
            ));
        }

        $count = (int) $count;
        set_transient($cache_key, $count, 10 * MINUTE_IN_SECONDS);

        return $count;
    }

    private function get_default_settings() {
        return array(
            'enable_form' => 'yes',
            'form_title' => 'To order please fill the form',
            'active_country' => 'SA',
            
            // إعدادات البلاد
            'countries' => array(
                'EG' => array(
                    'name' => 'مصر',
                    'code' => '+20',
                    'pattern' => '^01[0125][0-9]{8}$',
                    'placeholder' => '01xxxxxxxxx',
                    'phone_label' => 'رقم الموبايل',
                    'currency' => 'EGP',
                    'areas' => "القاهرة\nالجيزة\nالإسكندرية\n6 أكتوبر\nالشيخ زايد\nالتجمع الخامس\nمدينة نصر\nالمعادي\nحلوان"
                ),
                'SA' => array(
                    'name' => 'السعودية',
                    'code' => '+966',
                    'pattern' => '^05[0-9]{8}$',
                    'placeholder' => '05xxxxxxxx',
                    'phone_label' => 'رقم الجوال',
                    'currency' => 'SAR',
                    'areas' => "الرياض\nجدة\nمكة المكرمة\nالمدينة المنورة\nالدمام\nالخبر\nالظهران\nالقطيف"
                ),
                'AE' => array(
                    'name' => 'الإمارات',
                    'code' => '+971',
                    'pattern' => '^05[0-9]{8}$',
                    'placeholder' => '05xxxxxxxx',
                    'phone_label' => 'رقم الموبايل',
                    'currency' => 'AED',
                    'areas' => "دبي\nأبوظبي\nالشارقة\nعجمان\nرأس الخيمة\nالفجيرة\nأم القيوين"
                ),
                'KW' => array(
                    'name' => 'الكويت',
                    'code' => '+965',
                    'pattern' => '^[569][0-9]{7}$',
                    'placeholder' => '9xxxxxxx',
                    'phone_label' => 'رقم الموبايل',
                    'currency' => 'KWD',
                    'areas' => "العاصمة\nحولي\nالفروانية\nالأحمدي\nالجهراء\nمبارك الكبير"
                ),
                'IQ' => array(
                    'name' => 'العراق',
                    'code' => '+964',
                    'pattern' => '^07[0-9]{9}$',
                    'placeholder' => '07xxxxxxxxx',
                    'phone_label' => 'رقم الموبايل',
                    'currency' => 'IQD',
                    'areas' => "بغداد\nالبصرة\nأربيل\nالموصل\nكركوك\nالنجف\nكربلاء\nالسليمانية"
                )
            ),
            
            // إعدادات الكميات والخصومات
            'quantity_options' => array(
                'option1' => array(
                    'enabled' => 'yes',
                    'quantity' => 1,
                    'label' => 'Buy 1 unit',
                    'discount_text' => 'No discount',
                    'discount_amount' => 0,  // مبلغ الخصم بدل النسبة
                    'discount_type' => 'fixed'  // fixed أو percentage
                ),
                'option2' => array(
                    'enabled' => 'yes',
                    'quantity' => 2,
                    'label' => 'Buy 2 and save 50 SAR',
                    'discount_text' => '50 SAR OFF',
                    'discount_amount' => 50,  // خصم 50 ريال
                    'discount_type' => 'fixed'
                ),
                'option3' => array(
                    'enabled' => 'yes',
                    'quantity' => 3,
                    'label' => 'Buy 3 and save 100 SAR',
                    'discount_text' => '100 SAR OFF',
                    'discount_amount' => 100,  // خصم 100 ريال
                    'discount_type' => 'fixed'
                )
            ),
            
            // الألوان والتصميم
            'colors' => array(
                'primary_color' => '#ff6b35',
                'selected_bg' => '#e8f5f0',
                'selected_border' => '#00a878',
                'button_bg' => '#ff6b35',
                'button_text' => '#ffffff',
                'whatsapp_bg' => '#25d366',
                'discount_badge_bg' => '#6c757d',
                'discount_badge_text' => '#ffffff'
            ),
            
            // النصوص
            'texts' => array(
                'button_text' => 'Buy Now',
                'whatsapp_text' => 'Order in WhatsApp',
                'name_label' => 'Full Name',
                'city_label' => 'City',
                'address_label' => 'العنوان الوطني'
            ),
            
            // إعدادات أخرى
            'whatsapp_enabled' => 'yes',
            'whatsapp_number' => '',
            'send_admin_email' => 'yes',
            'send_customer_email' => 'yes',
            'track_gtm' => 'yes',
            'gtm_event_name' => 'quick_order_completed',
            'shipping_cost_to_deduct' => 0, // تكلفة الشحن المراد خصمها من السعر

            // تحسينات الفورم - كل واحدة بتتفتح و بتتقفل لوحدها من لوحة التحكم.
            'ux' => array(
                'summary_enabled'    => 'yes',
                'summary_title'      => 'ملخص الطلب',
                'summary_free_text'  => 'مجاني',
                'sticky_enabled'     => 'yes',
                'badges_enabled'     => 'yes',
                'badges'             => "الدفع عند الاستلام\nشحن مجاني\nضمان سنة\nإرجاع مجاني خلال 14 يوم",
                'scarcity_enabled'   => 'yes',
                'scarcity_threshold' => 15,
                'scarcity_text'      => 'باقي {count} قطع بس في المخزن',
                'proof_enabled'      => 'yes',
                'proof_min'          => 3,
                'proof_hours'        => 24,
                'proof_text'         => '{count} شخص طلبوا المنتج ده خلال آخر {hours} ساعة',
                'reassure_enabled'   => 'yes',
                'reassure_text'      => 'مفيش دفع دلوقتي — تدفع للمندوب عند الاستلام',
                'font_enabled'       => 'yes',
                'font_family'        => 'IBM Plex Sans Arabic',
            ),

            // أبسيل صفحة الشكر: منتجات شبيهة، و دوسة الزرار بتعمل أوردر منفصل.
            'upsell' => array(
                'enabled'          => 'yes',
                'page_slug'        => 'thank-you',
                'title'            => 'ضيف لطلبك قبل ما يتشحن',
                'subtitle'         => 'هيوصلك مع نفس الشحنة — نفس المندوب و نفس التوصيل المجاني، و تدفع عند الاستلام',
                'button_text'      => 'ضيفه لطلبي',
                'done_text'        => 'تمام، ضفناه لطلبك',
                'count'            => 3,
                'max_per_order'    => 3,
                'discount_percent' => 0,
                'track_pixel'      => 'yes',
            ),
        );
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('Quick Order Form', 'wc-quick-order'),
            __('Quick Order', 'wc-quick-order'),
            'manage_options',
            'wc-quick-order',
            array($this, 'admin_page'),
            'dashicons-cart',
            56
        );
        
        add_submenu_page(
            'wc-quick-order',
            __('الإعدادات', 'wc-quick-order'),
            __('الإعدادات', 'wc-quick-order'),
            'manage_options',
            'wc-quick-order-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'wc-quick-order',
            __('الطلبات', 'wc-quick-order'),
            __('الطلبات', 'wc-quick-order'),
            'manage_options',
            'wc-quick-order-orders',
            array($this, 'orders_page')
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="wcqo-dashboard">
                <h2>مرحباً بك في WooCommerce Quick Order Form Pro</h2>
                <p>نظام طلب سريع احترافي متعدد البلدان مع خصومات الكميات.</p>
                
                <div class="wcqo-stats" style="display: flex; gap: 20px; margin-top: 30px;">
                    <div style="background: #fff; padding: 20px; border-radius: 5px; flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3>إجمالي الطلبات السريعة</h3>
                        <p style="font-size: 32px; color: #28a745; margin: 10px 0;">
                            <?php echo $this->get_quick_orders_count(); ?>
                        </p>
                    </div>
                    <div style="background: #fff; padding: 20px; border-radius: 5px; flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3>طلبات اليوم</h3>
                        <p style="font-size: 32px; color: #007cba; margin: 10px 0;">
                            <?php echo $this->get_today_orders_count(); ?>
                        </p>
                    </div>
                    <div style="background: #fff; padding: 20px; border-radius: 5px; flex: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3>البلد النشط</h3>
                        <p style="font-size: 24px; color: #ff6b35; margin: 10px 0;">
                            <?php 
                            $settings = $this->settings();
                            echo isset($settings['countries'][$settings['active_country']]) ? 
                                 $settings['countries'][$settings['active_country']]['name'] : 'غير محدد';
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>تم حفظ الإعدادات بنجاح!</p></div>';
        }
        
        $settings = $this->settings();
        
        // Debug: تأكد من وجود البيانات
        if (!isset($settings['countries']) || empty($settings['countries'])) {
            $settings = $this->get_default_settings();
            update_option('wcqo_settings', $settings);
        }
        ?>
        <div class="wrap">
            <h1>إعدادات Quick Order Form Pro</h1>
            
            <style>
                .wcqo-tabs {
                    margin: 20px 0;
                    border-bottom: 2px solid #ccc;
                }
                .wcqo-tabs a {
                    display: inline-block;
                    padding: 10px 20px;
                    margin: 0 5px -2px 0;
                    background: #f1f1f1;
                    border: 1px solid #ccc;
                    border-bottom: 2px solid #ccc;
                    text-decoration: none;
                    color: #333;
                    border-radius: 4px 4px 0 0;
                    transition: all 0.3s;
                }
                .wcqo-tabs a:hover {
                    background: #e0e0e0;
                }
                .wcqo-tabs a.active {
                    background: white;
                    border-bottom: 2px solid white;
                    font-weight: bold;
                    color: #ff6b35;
                }
                .wcqo-tab-content {
                    display: none;
                    padding: 20px 0;
                }
                .wcqo-tab-content.active {
                    display: block;
                }
                .quantity-option-box {
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    padding: 15px;
                    margin-bottom: 15px;
                    border-radius: 5px;
                }
                .quantity-option-box h4 {
                    margin-top: 0;
                    color: #333;
                }
                .color-preview {
                    display: inline-block;
                    width: 30px;
                    height: 30px;
                    border: 1px solid #ddd;
                    vertical-align: middle;
                    margin-left: 10px;
                    border-radius: 3px;
                }
                .country-settings-box {
                    background: #fff;
                    border: 1px solid #ddd;
                    padding: 15px;
                    margin-bottom: 20px;
                    border-radius: 5px;
                }
                /* إصلاح مشكلة الـ dropdown */
                select#wcqo_active_country {
                    min-width: 200px;
                    padding: 8px;
                    font-size: 14px;
                }
                /* تحسينات عامة للـ selects */
                .form-table select {
                    max-width: 25em;
                    width: auto;
                    display: inline-block;
                }
                .debug-info {
                    background: #f0f0f0;
                    padding: 10px;
                    margin: 10px 0;
                    border-radius: 5px;
                    font-family: monospace;
                    font-size: 12px;
                }
            </style>
            
            <div class="wcqo-tabs">
                <a href="#general" class="wcqo-tab active">الإعدادات العامة</a>
                <a href="#countries" class="wcqo-tab">البلاد والمناطق</a>
                <a href="#quantities" class="wcqo-tab">الكميات والخصومات</a>
                <a href="#design" class="wcqo-tab">التصميم والألوان</a>
                <a href="#texts" class="wcqo-tab">النصوص</a>
                <a href="#ux" class="wcqo-tab">تحسينات الفورم</a>
                <a href="#upsell" class="wcqo-tab">أبسيل صفحة الشكر</a>
                <a href="#tracking" class="wcqo-tab">التتبع</a>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wcqo_settings', 'wcqo_nonce'); ?>
                
                <!-- إعدادات عامة -->
                <div id="general" class="wcqo-tab-content active">
                    <h2>الإعدادات العامة</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">تفعيل الفورم</th>
                            <td>
                                <input type="checkbox" name="wcqo[enable_form]" value="yes" 
                                    <?php checked(isset($settings['enable_form']) ? $settings['enable_form'] : 'yes', 'yes'); ?>>
                                <label>تفعيل فورم الطلب السريع في صفحات المنتجات</label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">عنوان الفورم</th>
                            <td>
                                <input type="text" name="wcqo[form_title]" class="regular-text" 
                                    value="<?php echo esc_attr($settings['form_title']); ?>">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">البلد النشط</th>
                            <td>
                                <select name="wcqo[active_country]" id="wcqo_active_country" class="regular-text">
                                    <option value="EG" <?php selected($settings['active_country'], 'EG'); ?>>مصر</option>
                                    <option value="SA" <?php selected($settings['active_country'], 'SA'); ?>>السعودية</option>
                                    <option value="AE" <?php selected($settings['active_country'], 'AE'); ?>>الإمارات</option>
                                    <option value="KW" <?php selected($settings['active_country'], 'KW'); ?>>الكويت</option>
                                    <option value="IQ" <?php selected($settings['active_country'], 'IQ'); ?>>العراق</option>
                                </select>
                                <p class="description">اختر البلد الافتراضي للموقع - هذا سيحدد validation لأرقام الهواتف</p>
                                
                                <?php if (current_user_can('manage_options') && isset($_GET['debug'])): ?>
                                <div class="debug-info">
                                    <strong>Debug Info:</strong><br>
                                    Active Country: <?php echo $settings['active_country']; ?><br>
                                    Countries Available: <?php echo implode(', ', array_keys($settings['countries'])); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">WhatsApp</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[whatsapp_enabled]" value="yes" 
                                        <?php checked(isset($settings['whatsapp_enabled']) ? $settings['whatsapp_enabled'] : 'yes', 'yes'); ?>>
                                    تفعيل زر الطلب عبر WhatsApp
                                </label><br><br>
                                <input type="text" name="wcqo[whatsapp_number]" class="regular-text" 
                                    placeholder="966501234567" 
                                    value="<?php echo esc_attr(isset($settings['whatsapp_number']) ? $settings['whatsapp_number'] : ''); ?>">
                                <p class="description">رقم WhatsApp بدون + (مثال: 966501234567)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">تكلفة الشحن المخفية</th>
                            <td>
                                <input type="number" name="wcqo[shipping_cost_to_deduct]" 
                                       class="small-text" 
                                       min="0" 
                                       step="0.01" 
                                       value="<?php echo esc_attr(isset($settings['shipping_cost_to_deduct']) ? $settings['shipping_cost_to_deduct'] : 0); ?>">
                                <span><?php echo get_woocommerce_currency_symbol(); ?></span>
                                <p class="description">المبلغ الذي سيتم خصمه من السعر عند إرسال الطلب للـ WooCommerce (لن يظهر للعميل)</p>
                                <p class="description" style="color: #d63638;">⚠️ هذا المبلغ سيُخصم من السعر النهائي للطلب في WooCommerce فقط، العميل سيرى السعر الكامل</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>معلومات validation أرقام الهواتف حسب البلد:</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>البلد</th>
                                <th>كود الدولة</th>
                                <th>مثال للرقم</th>
                                <th>Pattern</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>مصر</td>
                                <td>+20</td>
                                <td>01012345678</td>
                                <td>يبدأ بـ 010, 011, 012, 015</td>
                            </tr>
                            <tr>
                                <td>السعودية</td>
                                <td>+966</td>
                                <td>0512345678</td>
                                <td>يبدأ بـ 05</td>
                            </tr>
                            <tr>
                                <td>الإمارات</td>
                                <td>+971</td>
                                <td>0512345678</td>
                                <td>يبدأ بـ 05</td>
                            </tr>
                            <tr>
                                <td>الكويت</td>
                                <td>+965</td>
                                <td>91234567</td>
                                <td>يبدأ بـ 5, 6, أو 9</td>
                            </tr>
                            <tr>
                                <td>العراق</td>
                                <td>+964</td>
                                <td>07901234567</td>
                                <td>يبدأ بـ 07</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- إعدادات البلاد والمناطق -->
                <div id="countries" class="wcqo-tab-content">
                    <h2>إعدادات البلاد والمناطق</h2>
                    <?php foreach ($settings['countries'] as $code => $country): ?>
                    <div class="country-settings-box">
                        <h3><?php echo $country['name']; ?> (<?php echo $country['code']; ?>)</h3>
                        
                        <table class="form-table">
                            <tr>
                                <th>تسمية حقل الهاتف</th>
                                <td>
                                    <input type="text" name="wcqo[countries][<?php echo $code; ?>][phone_label]" 
                                           value="<?php echo esc_attr($country['phone_label']); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Pattern التحقق</th>
                                <td>
                                    <input type="text" name="wcqo[countries][<?php echo $code; ?>][pattern]" 
                                           value="<?php echo esc_attr($country['pattern']); ?>" class="regular-text">
                                    <p class="description">Regular Expression للتحقق من صحة الرقم</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Placeholder</th>
                                <td>
                                    <input type="text" name="wcqo[countries][<?php echo $code; ?>][placeholder]" 
                                           value="<?php echo esc_attr($country['placeholder']); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>العملة</th>
                                <td>
                                    <input type="text" name="wcqo[countries][<?php echo $code; ?>][currency]" 
                                           value="<?php echo esc_attr($country['currency']); ?>" style="width: 100px;">
                                </td>
                            </tr>
                            <tr>
                                <th>المناطق</th>
                                <td>
                                    <textarea name="wcqo[countries][<?php echo $code; ?>][areas]" rows="5" cols="50"><?php echo esc_textarea($country['areas']); ?></textarea>
                                    <p class="description">منطقة في كل سطر</p>
                                </td>
                            </tr>
                        </table>
                        
                        <!-- حفظ البيانات الأساسية -->
                        <input type="hidden" name="wcqo[countries][<?php echo $code; ?>][name]" value="<?php echo esc_attr($country['name']); ?>">
                        <input type="hidden" name="wcqo[countries][<?php echo $code; ?>][code]" value="<?php echo esc_attr($country['code']); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- إعدادات الكميات والخصومات -->
                <div id="quantities" class="wcqo-tab-content">
                    <h2>خيارات الكميات والخصومات</h2>
                    
                    <?php for ($i = 1; $i <= 3; $i++): 
                        $option = isset($settings['quantity_options']['option' . $i]) ? 
                                  $settings['quantity_options']['option' . $i] : 
                                  array('enabled' => 'yes', 'quantity' => $i, 'label' => '', 'discount_text' => '', 'discount_amount' => 0, 'discount_type' => 'fixed');
                    ?>
                    <div class="quantity-option-box">
                        <h4>الخيار <?php echo $i; ?></h4>
                        <table class="form-table">
                            <tr>
                                <th>تفعيل</th>
                                <td>
                                    <input type="checkbox" name="wcqo[quantity_options][option<?php echo $i; ?>][enabled]" 
                                           value="yes" <?php checked($option['enabled'], 'yes'); ?>>
                                </td>
                            </tr>
                            <tr>
                                <th>الكمية</th>
                                <td>
                                    <input type="number" name="wcqo[quantity_options][option<?php echo $i; ?>][quantity]" 
                                           value="<?php echo $option['quantity']; ?>" min="1" style="width: 80px;">
                                </td>
                            </tr>
                            <tr>
                                <th>النص الظاهر</th>
                                <td>
                                    <input type="text" name="wcqo[quantity_options][option<?php echo $i; ?>][label]" 
                                           value="<?php echo esc_attr($option['label']); ?>" class="regular-text">
                                    <p class="description">مثال: اشتري 2 ووفر 50 ريال</p>
                                </td>
                            </tr>
                            <tr>
                                <th>نص الخصم (Badge)</th>
                                <td>
                                    <input type="text" name="wcqo[quantity_options][option<?php echo $i; ?>][discount_text]" 
                                           value="<?php echo esc_attr($option['discount_text']); ?>" class="regular-text">
                                    <p class="description">مثال: وفر 50 ريال</p>
                                </td>
                            </tr>
                            <tr>
                                <th>نوع الخصم</th>
                                <td>
                                    <select name="wcqo[quantity_options][option<?php echo $i; ?>][discount_type]">
                                        <option value="fixed" <?php selected(isset($option['discount_type']) ? $option['discount_type'] : 'fixed', 'fixed'); ?>>مبلغ ثابت</option>
                                        <option value="percentage" <?php selected(isset($option['discount_type']) ? $option['discount_type'] : 'fixed', 'percentage'); ?>>نسبة مئوية</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>قيمة الخصم</th>
                                <td>
                                    <input type="number" name="wcqo[quantity_options][option<?php echo $i; ?>][discount_amount]" 
                                           value="<?php echo isset($option['discount_amount']) ? $option['discount_amount'] : (isset($option['discount_percent']) ? $option['discount_percent'] : 0); ?>" 
                                           min="0" step="0.01" style="width: 100px;">
                                    <span class="discount-type-label">
                                        <span class="fixed-label" <?php echo (isset($option['discount_type']) && $option['discount_type'] === 'percentage') ? 'style="display:none;"' : ''; ?>>ريال</span>
                                        <span class="percentage-label" <?php echo (!isset($option['discount_type']) || $option['discount_type'] === 'fixed') ? 'style="display:none;"' : ''; ?>>%</span>
                                    </span>
                                    <p class="description">المبلغ الذي سيتم خصمه من إجمالي الطلب</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <!-- إعدادات التصميم والألوان -->
                <div id="design" class="wcqo-tab-content">
                    <h2>التصميم والألوان</h2>
                    <table class="form-table">
                        <?php
                        $color_fields = array(
                            'primary_color' => 'اللون الأساسي',
                            'selected_bg' => 'خلفية الخيار المحدد',
                            'selected_border' => 'حدود الخيار المحدد',
                            'button_bg' => 'خلفية الزر الرئيسي',
                            'button_text' => 'نص الزر',
                            'whatsapp_bg' => 'خلفية زر WhatsApp',
                            'discount_badge_bg' => 'خلفية شارة الخصم',
                            'discount_badge_text' => 'نص شارة الخصم'
                        );
                        
                        foreach ($color_fields as $key => $label):
                            $color_value = isset($settings['colors'][$key]) ? $settings['colors'][$key] : '#ffffff';
                        ?>
                        <tr>
                            <th><?php echo $label; ?></th>
                            <td>
                                <input type="color" name="wcqo[colors][<?php echo $key; ?>]" 
                                       value="<?php echo $color_value; ?>">
                                <span class="color-preview" style="background: <?php echo $color_value; ?>"></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                
                <!-- إعدادات النصوص -->
                <div id="texts" class="wcqo-tab-content">
                    <h2>النصوص والعناوين</h2>
                    <table class="form-table">
                        <?php
                        $text_fields = array(
                            'button_text' => 'نص زر الشراء',
                            'whatsapp_text' => 'نص زر WhatsApp',
                            'name_label' => 'تسمية حقل الاسم',
                            'city_label' => 'تسمية حقل المدينة',
                            'address_label' => 'تسمية حقل العنوان'
                        );
                        
                        foreach ($text_fields as $key => $label):
                            $text_value = isset($settings['texts'][$key]) ? $settings['texts'][$key] : '';
                        ?>
                        <tr>
                            <th><?php echo $label; ?></th>
                            <td>
                                <input type="text" name="wcqo[texts][<?php echo $key; ?>]" class="regular-text" 
                                       value="<?php echo esc_attr($text_value); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                
                <!-- تحسينات الفورم -->
                <div id="ux" class="wcqo-tab-content">
                    <h2>تحسينات الفورم</h2>
                    <p class="description" style="margin-bottom:15px;">
                        كل إضافة هنا مستقلة. اقفل أي واحدة و الباقي بيفضل شغال زي ما هو.
                    </p>
                    <?php $ux = $settings['ux']; ?>
                    <input type="hidden" name="wcqo[ux][_present]" value="1">
                    <table class="form-table">
                        <tr>
                            <th scope="row">ملخص السعر</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][summary_enabled]" value="yes"
                                        <?php checked($ux['summary_enabled'], 'yes'); ?>>
                                    عرض ملخص (المنتج + الخصم + التوصيل + الإجمالي) فوق زر الطلب
                                </label><br><br>
                                <label>عنوان الملخص:</label><br>
                                <input type="text" name="wcqo[ux][summary_title]" class="regular-text"
                                       value="<?php echo esc_attr($ux['summary_title']); ?>"><br><br>
                                <label>كلمة التوصيل المجاني:</label><br>
                                <input type="text" name="wcqo[ux][summary_free_text]" class="regular-text"
                                       value="<?php echo esc_attr($ux['summary_free_text']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">شريط الطلب الثابت</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][sticky_enabled]" value="yes"
                                        <?php checked($ux['sticky_enabled'], 'yes'); ?>>
                                    شريط تحت الشاشة فيه السعر و زر الطلب، يظهر على الموبايل لما زر الطلب يختفي
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">شارات الثقة</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][badges_enabled]" value="yes"
                                        <?php checked($ux['badges_enabled'], 'yes'); ?>>
                                    عرض الشارات فوق خيارات الكمية
                                </label><br><br>
                                <label>شارة في كل سطر (٦ كحد أقصى):</label><br>
                                <textarea name="wcqo[ux][badges]" rows="5" class="large-text"><?php echo esc_textarea($ux['badges']); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">تنبيه الكمية</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][scarcity_enabled]" value="yes"
                                        <?php checked($ux['scarcity_enabled'], 'yes'); ?>>
                                    عرض الكمية المتبقية
                                </label>
                                <p class="description">
                                    الرقم بيتقرا من مخزون ووكومرس الحقيقي. لو المنتج مش مفعّل عليه تتبع المخزون
                                    أو الكمية أكبر من الحد اللي تحت، مش هيظهر أي حاجة.
                                </p>
                                <br>
                                <label>يظهر لما الكمية تبقى أقل من أو تساوي:</label>
                                <input type="number" name="wcqo[ux][scarcity_threshold]" min="1" max="999" class="small-text"
                                       value="<?php echo esc_attr($ux['scarcity_threshold']); ?>"><br><br>
                                <label>النص (استخدم <code>{count}</code> مكان الرقم):</label><br>
                                <input type="text" name="wcqo[ux][scarcity_text]" class="large-text"
                                       value="<?php echo esc_attr($ux['scarcity_text']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">عدد الطلبات الأخيرة</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][proof_enabled]" value="yes"
                                        <?php checked($ux['proof_enabled'], 'yes'); ?>>
                                    عرض كام واحد طلب المنتج ده مؤخراً
                                </label>
                                <p class="description">
                                    الرقم من أوردرات المنتج الفعلية. لو أقل من الحد الأدنى مش هيظهر خالص.
                                </p>
                                <br>
                                <label>آخر كام ساعة:</label>
                                <input type="number" name="wcqo[ux][proof_hours]" min="1" max="168" class="small-text"
                                       value="<?php echo esc_attr($ux['proof_hours']); ?>">
                                &nbsp;&nbsp;
                                <label>أقل عدد للعرض:</label>
                                <input type="number" name="wcqo[ux][proof_min]" min="1" max="999" class="small-text"
                                       value="<?php echo esc_attr($ux['proof_min']); ?>"><br><br>
                                <label>النص (<code>{count}</code> للرقم و <code>{hours}</code> للساعات):</label><br>
                                <input type="text" name="wcqo[ux][proof_text]" class="large-text"
                                       value="<?php echo esc_attr($ux['proof_text']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">سطر الطمأنة</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][reassure_enabled]" value="yes"
                                        <?php checked($ux['reassure_enabled'], 'yes'); ?>>
                                    سطر صغير تحت زر الطلب
                                </label><br><br>
                                <input type="text" name="wcqo[ux][reassure_text]" class="large-text"
                                       value="<?php echo esc_attr($ux['reassure_text']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">الخط العربي</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[ux][font_enabled]" value="yes"
                                        <?php checked($ux['font_enabled'], 'yes'); ?>>
                                    تحميل خط عربي من Google Fonts للفورم
                                </label><br><br>
                                <label>اسم الخط:</label><br>
                                <input type="text" name="wcqo[ux][font_family]" class="regular-text"
                                       value="<?php echo esc_attr($ux['font_family']); ?>">
                                <p class="description">
                                    مثال: IBM Plex Sans Arabic أو Cairo أو Tajawal. حروف إنجليزية و أرقام و مسافات بس.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- أبسيل صفحة الشكر -->
                <div id="upsell" class="wcqo-tab-content">
                    <h2>أبسيل صفحة الشكر</h2>
                    <p class="description" style="margin-bottom:15px;">
                        بعد ما العميل يطلب، بيتعرضله منتجات شبيهة. دوسة الزرار بتعمل
                        <strong>طلب منفصل</strong> بنفس بياناته و بنفس حالة الطلب الأصلي —
                        الطلب الأصلي و بياناته و البيكسل بتاعه مبيتغيروش.
                    </p>
                    <?php $upsell = $settings['upsell']; ?>
                    <input type="hidden" name="wcqo[upsell][_present]" value="1">
                    <table class="form-table">
                        <tr>
                            <th scope="row">التفعيل</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[upsell][enabled]" value="yes"
                                        <?php checked($upsell['enabled'], 'yes'); ?>>
                                    تشغيل الأبسيل في صفحة الشكر
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">صفحة الشكر المخصصة</th>
                            <td>
                                <input type="text" name="wcqo[upsell][page_slug]" class="regular-text"
                                       value="<?php echo esc_attr($upsell['page_slug']); ?>">
                                <p class="description">
                                    صفحة الشكر بتاعة فورمات الإليمنتور (الـ slug بتاعها، مثلاً <code>thank-you</code>).
                                    صفحة الشكر بتاعة ووكومرس شغالة أوتوماتيك من غير الخانة دي.
                                    و لو عايز تحط البلوك في مكان معين استخدم الشورت كود <code>[hayak_upsell]</code>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">العناوين</th>
                            <td>
                                <label>العنوان:</label><br>
                                <input type="text" name="wcqo[upsell][title]" class="large-text"
                                       value="<?php echo esc_attr($upsell['title']); ?>"><br><br>
                                <label>السطر التحتاني:</label><br>
                                <input type="text" name="wcqo[upsell][subtitle]" class="large-text"
                                       value="<?php echo esc_attr($upsell['subtitle']); ?>"><br><br>
                                <label>نص الزرار:</label><br>
                                <input type="text" name="wcqo[upsell][button_text]" class="regular-text"
                                       value="<?php echo esc_attr($upsell['button_text']); ?>"><br><br>
                                <label>نص بعد الإضافة:</label><br>
                                <input type="text" name="wcqo[upsell][done_text]" class="regular-text"
                                       value="<?php echo esc_attr($upsell['done_text']); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">الأرقام</th>
                            <td>
                                <label>عدد المنتجات المعروضة:</label>
                                <input type="number" name="wcqo[upsell][count]" min="1" max="6" class="small-text"
                                       value="<?php echo esc_attr($upsell['count']); ?>"><br><br>
                                <label>أقصى عدد إضافات على الطلب الواحد:</label>
                                <input type="number" name="wcqo[upsell][max_per_order]" min="1" max="10" class="small-text"
                                       value="<?php echo esc_attr($upsell['max_per_order']); ?>"><br><br>
                                <label>خصم على منتجات الأبسيل (%):</label>
                                <input type="number" name="wcqo[upsell][discount_percent]" min="0" max="90" step="1" class="small-text"
                                       value="<?php echo esc_attr($upsell['discount_percent']); ?>">
                                <p class="description">صفر يعني بالسعر العادي.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">البيكسل</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[upsell][track_pixel]" value="yes"
                                        <?php checked($upsell['track_pixel'], 'yes'); ?>>
                                    إرسال purchase للطلب الجديد
                                </label>
                                <p class="description">
                                    بيتبعت برقم طلب جديد مختلف، فالإيراد بيتضاف و لا بيتعد مرتين.
                                    اقفله لو عايز الأبسيل ميظهرش في المنصات خالص.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- إعدادات التتبع -->
                <div id="tracking" class="wcqo-tab-content">
                    <h2>إعدادات التتبع</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Google Tag Manager</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[track_gtm]" value="yes" 
                                        <?php checked(isset($settings['track_gtm']) ? $settings['track_gtm'] : 'yes', 'yes'); ?>>
                                    تفعيل التتبع عبر GTM
                                </label><br><br>
                                <label>Event Name:</label><br>
                                <input type="text" name="wcqo[gtm_event_name]" class="regular-text" 
                                    placeholder="quick_order_completed" 
                                    value="<?php echo esc_attr(isset($settings['gtm_event_name']) ? $settings['gtm_event_name'] : 'quick_order_completed'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">الإشعارات</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcqo[send_admin_email]" value="yes" 
                                        <?php checked(isset($settings['send_admin_email']) ? $settings['send_admin_email'] : 'yes', 'yes'); ?>>
                                    إرسال إيميل للأدمن عند استلام طلب جديد
                                </label><br>
                                <label>
                                    <input type="checkbox" name="wcqo[send_customer_email]" value="yes" 
                                        <?php checked(isset($settings['send_customer_email']) ? $settings['send_customer_email'] : 'yes', 'yes'); ?>>
                                    إرسال إيميل تأكيد للعميل
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button('حفظ الإعدادات'); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // تفعيل التابات
            $('.wcqo-tab').click(function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.wcqo-tab').removeClass('active');
                $(this).addClass('active');
                
                $('.wcqo-tab-content').removeClass('active');
                $(target).addClass('active');
            });
            
            // تحديث معاينة الألوان
            $('input[type="color"]').on('input', function() {
                $(this).next('.color-preview').css('background', $(this).val());
            });
            
            // تغيير label الخصم حسب النوع
            $('select[name*="discount_type"]').on('change', function() {
                var $container = $(this).closest('tr').next().find('.discount-type-label');
                if ($(this).val() === 'percentage') {
                    $container.find('.fixed-label').hide();
                    $container.find('.percentage-label').show();
                } else {
                    $container.find('.fixed-label').show();
                    $container.find('.percentage-label').hide();
                }
            });
            
            // تفعيل عند التحميل
            $('select[name*="discount_type"]').trigger('change');
            
            // عرض معلومات البلد المختار
            $('#wcqo_active_country').on('change', function() {
                var country = $(this).val();
                var countryInfo = {
                    'EG': {name: 'مصر', code: '+20', example: '01012345678'},
                    'SA': {name: 'السعودية', code: '+966', example: '0512345678'},
                    'AE': {name: 'الإمارات', code: '+971', example: '0512345678'},
                    'KW': {name: 'الكويت', code: '+965', example: '91234567'},
                    'IQ': {name: 'العراق', code: '+964', example: '07901234567'}
                };
                
                if (countryInfo[country]) {
                    console.log('تم اختيار: ' + countryInfo[country].name);
                    console.log('كود الدولة: ' + countryInfo[country].code);
                    console.log('مثال للرقم: ' + countryInfo[country].example);
                }
            });
            
            // تفعيل الـ select عند التحميل
            $('#wcqo_active_country').trigger('change');
            
            // إضافة تحسينات للـ UX
            $('input[type="checkbox"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $(this).parent().css('font-weight', 'bold');
                } else {
                    $(this).parent().css('font-weight', 'normal');
                }
            });
        });
        </script>
        <?php
    }
    
    private function save_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['wcqo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcqo_nonce'])), 'wcqo_settings')) {
            return;
        }
        
        $settings = $this->settings();
        
        if (isset($_POST['wcqo'])) {
            $posted = $_POST['wcqo'];
            
            // الإعدادات العامة
            $settings['enable_form'] = isset($posted['enable_form']) ? 'yes' : 'no';
            $settings['form_title'] = sanitize_text_field($posted['form_title'] ?? '');
            $settings['active_country'] = sanitize_text_field($posted['active_country'] ?? 'SA');
            $settings['whatsapp_enabled'] = isset($posted['whatsapp_enabled']) ? 'yes' : 'no';
            $settings['whatsapp_number'] = sanitize_text_field($posted['whatsapp_number'] ?? '');
            $settings['shipping_cost_to_deduct'] = floatval($posted['shipping_cost_to_deduct'] ?? 0);
            
            // البلاد
            if (isset($posted['countries'])) {
                foreach ($posted['countries'] as $code => $country_data) {
                    if (isset($settings['countries'][$code])) {
                        $settings['countries'][$code]['name'] = sanitize_text_field($country_data['name'] ?? '');
                        $settings['countries'][$code]['code'] = sanitize_text_field($country_data['code'] ?? '');
                        $settings['countries'][$code]['phone_label'] = sanitize_text_field($country_data['phone_label'] ?? '');
                        $settings['countries'][$code]['pattern'] = sanitize_text_field($country_data['pattern'] ?? '');
                        $settings['countries'][$code]['placeholder'] = sanitize_text_field($country_data['placeholder'] ?? '');
                        $settings['countries'][$code]['currency'] = sanitize_text_field($country_data['currency'] ?? '');
                        $settings['countries'][$code]['areas'] = sanitize_textarea_field($country_data['areas'] ?? '');
                    }
                }
            }
            
            // الكميات
            if (isset($posted['quantity_options'])) {
                foreach ($posted['quantity_options'] as $key => $option) {
                    $settings['quantity_options'][$key]['enabled'] = isset($option['enabled']) ? 'yes' : 'no';
                    $settings['quantity_options'][$key]['quantity'] = intval($option['quantity'] ?? 1);
                    $settings['quantity_options'][$key]['label'] = sanitize_text_field($option['label'] ?? '');
                    $settings['quantity_options'][$key]['discount_text'] = sanitize_text_field($option['discount_text'] ?? '');
                    $settings['quantity_options'][$key]['discount_type'] = sanitize_text_field($option['discount_type'] ?? 'fixed');
                    $settings['quantity_options'][$key]['discount_amount'] = floatval($option['discount_amount'] ?? 0);
                }
            }
            
            // الألوان
            if (isset($posted['colors'])) {
                foreach ($posted['colors'] as $key => $color) {
                    $settings['colors'][$key] = sanitize_hex_color($color);
                }
            }
            
            // النصوص
            if (isset($posted['texts'])) {
                foreach ($posted['texts'] as $key => $text) {
                    $settings['texts'][$key] = sanitize_text_field($text);
                }
            }
            
            // تحسينات الفورم
            if (isset($posted['ux']) && is_array($posted['ux'])) {
                $ux = $posted['ux'];
                foreach (array('summary_enabled', 'sticky_enabled', 'badges_enabled', 'scarcity_enabled', 'proof_enabled', 'reassure_enabled', 'font_enabled') as $flag) {
                    $settings['ux'][$flag] = isset($ux[$flag]) ? 'yes' : 'no';
                }
                $settings['ux']['summary_title']     = sanitize_text_field($ux['summary_title'] ?? '');
                $settings['ux']['summary_free_text'] = sanitize_text_field($ux['summary_free_text'] ?? '');
                $settings['ux']['badges']            = sanitize_textarea_field($ux['badges'] ?? '');
                $settings['ux']['scarcity_threshold'] = max(1, min(999, intval($ux['scarcity_threshold'] ?? 15)));
                $settings['ux']['scarcity_text']     = sanitize_text_field($ux['scarcity_text'] ?? '');
                $settings['ux']['proof_hours']       = max(1, min(168, intval($ux['proof_hours'] ?? 24)));
                $settings['ux']['proof_min']         = max(1, min(999, intval($ux['proof_min'] ?? 3)));
                $settings['ux']['proof_text']        = sanitize_text_field($ux['proof_text'] ?? '');
                $settings['ux']['reassure_text']     = sanitize_text_field($ux['reassure_text'] ?? '');
                $settings['ux']['font_family']       = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($ux['font_family'] ?? ''))));
            }

            // الأبسيل
            if (isset($posted['upsell']) && is_array($posted['upsell'])) {
                $up = $posted['upsell'];
                $settings['upsell']['enabled']     = isset($up['enabled']) ? 'yes' : 'no';
                $settings['upsell']['track_pixel'] = isset($up['track_pixel']) ? 'yes' : 'no';
                $settings['upsell']['page_slug']   = sanitize_title($up['page_slug'] ?? '');
                $settings['upsell']['title']       = sanitize_text_field($up['title'] ?? '');
                $settings['upsell']['subtitle']    = sanitize_text_field($up['subtitle'] ?? '');
                $settings['upsell']['button_text'] = sanitize_text_field($up['button_text'] ?? '');
                $settings['upsell']['done_text']   = sanitize_text_field($up['done_text'] ?? '');
                $settings['upsell']['count']         = max(1, min(6, intval($up['count'] ?? 3)));
                $settings['upsell']['max_per_order'] = max(1, min(10, intval($up['max_per_order'] ?? 3)));
                $settings['upsell']['discount_percent'] = max(0, min(90, floatval($up['discount_percent'] ?? 0)));
            }

            // التتبع
            $settings['track_gtm'] = isset($posted['track_gtm']) ? 'yes' : 'no';
            $settings['gtm_event_name'] = sanitize_text_field($posted['gtm_event_name'] ?? '');
            $settings['send_admin_email'] = isset($posted['send_admin_email']) ? 'yes' : 'no';
            $settings['send_customer_email'] = isset($posted['send_customer_email']) ? 'yes' : 'no';
        }
        
        update_option('wcqo_settings', $settings);
    }
    
    public function orders_page() {
        ?>
        <div class="wrap">
            <h1>الطلبات السريعة</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>اسم العميل</th>
                        <th>الهاتف</th>
                        <th>البلد</th>
                        <th>المدينة</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>الخصم</th>
                        <th>التاريخ</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $orders = $this->get_quick_orders();
                    foreach ($orders as $order) {
                        $wc_order = wc_get_order($order->ID);
                        if ($wc_order) {
                            ?>
                            <tr>
                                <td>#<?php echo $order->ID; ?></td>
                                <td><?php echo $wc_order->get_billing_first_name(); ?></td>
                                <td><?php echo $wc_order->get_billing_phone(); ?></td>
                                <td><?php echo $wc_order->get_meta('_wcqo_country'); ?></td>
                                <td><?php echo $wc_order->get_billing_city(); ?></td>
                                <td>
                                    <?php
                                    foreach ($wc_order->get_items() as $item) {
                                        echo $item->get_name();
                                        break;
                                    }
                                    ?>
                                </td>
                                <td><?php echo $wc_order->get_meta('_wcqo_quantity'); ?></td>
                                <td><?php echo $wc_order->get_meta('_wcqo_discount'); ?>%</td>
                                <td><?php echo $wc_order->get_date_created()->date('Y-m-d H:i'); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $order->ID . '&action=edit'); ?>" 
                                       class="button button-small">عرض</a>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function display_quick_order_form() {
        $settings = $this->settings();
        
        if ($settings['enable_form'] !== 'yes') {
            return;
        }
        
        global $product;
        
        if (!$product) {
            return;
        }
        
        // الحصول على إعدادات البلد النشط
        $active_country = $settings['active_country'];
        $country_settings = isset($settings['countries'][$active_country]) ? $settings['countries'][$active_country] : $settings['countries']['SA'];
        $areas_list = array_filter(array_map('trim', explode("\n", $country_settings['areas'])));
        
        // حساب الأسعار للخيارات
        $product_price = $product->get_price();
        $currency = $country_settings['currency'];

        $ux     = $settings['ux'];
        $badges = ('yes' === ($ux['badges_enabled'] ?? 'no')) ? $this->ux_badges($ux) : array();
        $left   = ('yes' === ($ux['scarcity_enabled'] ?? 'no'))
            ? $this->stock_left($product, $ux['scarcity_threshold'] ?? 15)
            : null;

        $recent = 0;
        if ('yes' === ($ux['proof_enabled'] ?? 'no')) {
            $recent = $this->product_orders_recently($product->get_id(), $ux['proof_hours'] ?? 24);
            if ($recent < max(1, (int) ($ux['proof_min'] ?? 3))) {
                $recent = 0;
            }
        }

        ?>
        <div id="wcqo-form-container">
            <h3><?php echo esc_html($settings['form_title']); ?></h3>

            <?php if (!empty($badges)): ?>
            <ul class="wcqo-badges">
                <?php foreach ($badges as $badge): ?>
                <li><span aria-hidden="true">✓</span><?php echo esc_html($badge); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (null !== $left): ?>
            <p class="wcqo-scarcity">
                <span aria-hidden="true">⏳</span>
                <?php echo esc_html(str_replace('{count}', number_format_i18n($left), (string) ($ux['scarcity_text'] ?? ''))); ?>
            </p>
            <?php endif; ?>

            <?php if ($recent > 0): ?>
            <p class="wcqo-proof">
                <?php
                echo esc_html(str_replace(
                    array('{count}', '{hours}'),
                    array(number_format_i18n($recent), number_format_i18n((int) ($ux['proof_hours'] ?? 24))),
                    (string) ($ux['proof_text'] ?? '')
                ));
                ?>
            </p>
            <?php endif; ?>

            <form id="wcqo-quick-order-form">
                <?php wp_nonce_field('wcqo_order', 'wcqo_nonce'); ?>
                <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
                <input type="hidden" name="product_price" value="<?php echo esc_attr($product_price); ?>">
                <input type="hidden" name="product_name" value="<?php echo esc_attr($product->get_name()); ?>">
                <input type="hidden" name="country_code" value="<?php echo esc_attr($active_country); ?>">
                <input type="hidden" name="selected_quantity" id="selected_quantity" value="1">
                <input type="hidden" name="selected_discount" id="selected_discount" value="0">
                
                <!-- خيارات الكمية -->
                <div class="wcqo-quantity-options">
                    <?php 
                    foreach ($settings['quantity_options'] as $key => $option):
                        if ($option['enabled'] !== 'yes') continue;
                        
                        // حساب السعر بعد الخصم
                        $discount_type = isset($option['discount_type']) ? $option['discount_type'] : 'fixed';
                        $discount_amount = isset($option['discount_amount']) ? $option['discount_amount'] : (isset($option['discount_percent']) ? $option['discount_percent'] : 0);
                        
                        if ($discount_type === 'fixed') {
                            // خصم مبلغ ثابت
                            $total_discount = $discount_amount;
                            $total_price = ($product_price * $option['quantity']) - $total_discount;
                        } else {
                            // خصم نسبة مئوية
                            $total_discount = ($product_price * $option['quantity']) * ($discount_amount / 100);
                            $total_price = ($product_price * $option['quantity']) - $total_discount;
                        }
                        
                        // التأكد من أن السعر لا يقل عن الصفر
                        $total_price = max(0, $total_price);
                        $is_first = ($key === 'option1');
                    ?>
                    <div class="wcqo-quantity-option <?php echo $is_first ? 'selected' : ''; ?>" 
                         data-quantity="<?php echo esc_attr($option['quantity']); ?>" 
                         data-discount="<?php echo esc_attr($discount_amount); ?>"
                         data-subtotal="<?php echo esc_attr(number_format($product_price * $option['quantity'], 2, '.', '')); ?>"
                         data-total="<?php echo esc_attr(number_format($total_price, 2, '.', '')); ?>"
                         data-discount-type="<?php echo esc_attr($discount_type); ?>">
                        
                        <input type="radio" name="quantity_option" value="<?php echo esc_attr($key); ?>" 
                               <?php echo $is_first ? 'checked' : ''; ?>>
                        
                        <div class="option-content">
                            <div class="option-header">
                                <span class="option-label"><?php echo esc_html($option['label']); ?></span>
                                <?php if ($discount_amount > 0): ?>
                                <span class="discount-badge"><?php echo esc_html($option['discount_text']); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="option-price">
                                <?php if ($discount_amount > 0): ?>
                                <span class="original-price"><?php echo esc_html(number_format($product_price * $option['quantity'], 2)); ?> <?php echo esc_html($currency); ?></span>
                                <?php endif; ?>
                                <span class="final-price"><?php echo esc_html(number_format($total_price, 2)); ?> <?php echo esc_html($currency); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- بيانات العميل -->
                <div class="wcqo-customer-fields">
                    <div class="wcqo-field">
                        <label for="wcqo-name"><?php echo esc_html($settings['texts']['name_label']); ?></label>
                        <input type="text" id="wcqo-name" name="customer_name" autocomplete="name"
                               autocapitalize="words" required>
                    </div>
                    
                    <div class="wcqo-field">
                        <label for="wcqo-phone"><?php echo esc_html($country_settings['phone_label']); ?></label>
                        <div class="phone-input-group">
                            <input type="tel" id="wcqo-phone" name="customer_phone" 
                                   placeholder="<?php echo esc_attr($country_settings['placeholder']); ?>" 
                                   pattern="<?php echo esc_attr($country_settings['pattern']); ?>" 
                                   inputmode="numeric" autocomplete="tel-national"
                                   required>
                            <span class="country-code"><?php echo esc_html($country_settings['code']); ?></span>
                        </div>
                    </div>
                    
                    <div class="wcqo-field">
                        <label for="wcqo-city"><?php echo esc_html($settings['texts']['city_label']); ?></label>
                        <select id="wcqo-city" name="customer_city" autocomplete="address-level1" required>
                            <option value="">اختر المدينة</option>
                            <?php foreach ($areas_list as $area): ?>
                            <option value="<?php echo esc_attr($area); ?>"><?php echo esc_html($area); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="wcqo-field">
                        <label for="wcqo-address"><?php echo esc_html($settings['texts']['address_label']); ?></label>
                        <textarea id="wcqo-address" name="customer_address" rows="3"
                                  autocomplete="street-address" required></textarea>
                    </div>
                </div>

                <?php if ('yes' === ($ux['summary_enabled'] ?? 'no')): ?>
                <div class="wcqo-summary" id="wcqo-summary" data-currency="<?php echo esc_attr($currency); ?>">
                    <p class="wcqo-summary-title"><?php echo esc_html($ux['summary_title'] ?? ''); ?></p>
                    <div class="wcqo-sum-row">
                        <span>المنتج (<b class="wcqo-sum-qty">1</b>)</span>
                        <b class="wcqo-sum-sub"></b>
                    </div>
                    <div class="wcqo-sum-row wcqo-sum-discount">
                        <span>خصم العرض</span>
                        <b class="wcqo-sum-disc"></b>
                    </div>
                    <div class="wcqo-sum-row">
                        <span>التوصيل</span>
                        <b class="wcqo-sum-free"><?php echo esc_html($ux['summary_free_text'] ?? 'مجاني'); ?></b>
                    </div>
                    <div class="wcqo-sum-row wcqo-sum-total">
                        <span>الإجمالي عند الاستلام</span>
                        <b class="wcqo-sum-tot"></b>
                    </div>
                </div>
                <?php endif; ?>

                
                <!-- الأزرار -->
                <div class="wcqo-buttons">
                    <button type="submit" class="wcqo-submit-btn">
                        <?php echo esc_html($settings['texts']['button_text']); ?>
                    </button>
                    
                    <?php if ($settings['whatsapp_enabled'] === 'yes' && !empty($settings['whatsapp_number'])): ?>
                    <button type="button" class="wcqo-whatsapp-btn" id="wcqo-whatsapp-button">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.149-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                        </svg>
                        <?php echo esc_html($settings['texts']['whatsapp_text']); ?>
                    </button>
                    <?php endif; ?>
                </div>
                
                <?php if ('yes' === ($ux['reassure_enabled'] ?? 'no') && '' !== trim((string) ($ux['reassure_text'] ?? ''))): ?>
                <p class="wcqo-reassure"><?php echo esc_html($ux['reassure_text']); ?></p>
                <?php endif; ?>

                <div id="wcqo-message"></div>
            </form>

            <?php if ('yes' === ($ux['sticky_enabled'] ?? 'no')): ?>
            <div class="wcqo-sticky" id="wcqo-sticky">
                <span class="wcqo-sticky-price">
                    <span class="wcqo-sticky-label">الإجمالي عند الاستلام</span>
                    <b id="wcqo-sticky-total"></b>
                </span>
                <button type="button" class="wcqo-sticky-btn">
                    <?php echo esc_html($settings['texts']['button_text']); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function add_inline_styles() {
        if (!is_product()) {
            return;
        }
        
        $settings = $this->settings();
        $colors = $this->safe_colors($settings);
        $ux     = $settings['ux'];

        // A webfont name typed into a settings box lands in both a URL and a CSS
        // declaration, so only letters, digits and spaces survive.
        $font = preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($ux['font_family'] ?? ''));
        $font = trim(preg_replace('/\s+/', ' ', (string) $font));
        $use_font = ('yes' === ($ux['font_enabled'] ?? 'no')) && '' !== $font;

        if ($use_font) {
            printf(
                '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
                . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=%s:wght@400;500;600;700&display=swap">' . "\n",
                esc_attr(str_replace('%20', '+', rawurlencode($font)))
            );
        }
        ?>
        <style>
            /* إخفاء فورم WooCommerce الأصلي */
            <?php if ($settings['enable_form'] === 'yes'): ?>
            /* إخفاء الفورم الأصلي بتاع WooCommerce */
            form.cart {
                display: none !important;
            }
            
            /* إخفاء أي فورم variations */
            .variations_form.cart {
                display: none !important;
            }
            
            /* إخفاء زرار Add to Cart */
            .single_add_to_cart_button {
                display: none !important;
            }
            
            /* إخفاء زرار Buy Now */
            .ux-buy-now-button {
                display: none !important;
            }
            
            /* إخفاء quantity selector */
            .ux-quantity.quantity {
                display: none !important;
            }
            
            /* إخفاء الأزرار الإضافية */
            .ux-quantity__button {
                display: none !important;
            }
            
            /* إخفاء grouped products */
            .grouped_form {
                display: none !important;
            }
            
            /* إخفاء أي عناصر أخرى متعلقة بالـ cart */
            .woocommerce-variation-add-to-cart {
                display: none !important;
            }
            <?php endif; ?>
            
            /* أنماط الفورم الخاص بنا */
            #wcqo-form-container {
                margin: 30px 0;
                padding: 25px;
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                max-width: 600px;
                display: block !important; /* التأكد من ظهور الفورم */
            }
            
            #wcqo-form-container h3 {
                color: <?php echo $colors['primary_color']; ?>;
                margin: 0 0 20px;
                font-size: 20px;
                font-weight: 600;
            }
            
            .wcqo-quantity-options {
                margin-bottom: 25px;
            }
            
            .wcqo-quantity-option {
                display: flex;
                align-items: center;
                padding: 15px;
                margin-bottom: 12px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.3s ease;
                background: #fafafa;
                min-height: 56px;
            }
            
            .wcqo-quantity-option:hover {
                border-color: <?php echo $colors['selected_border']; ?>;
                transform: translateY(-1px);
            }
            
            .wcqo-quantity-option.selected {
                background: <?php echo $colors['selected_bg']; ?>;
                border-color: <?php echo $colors['selected_border']; ?>;
            }
            
            .wcqo-quantity-option input[type="radio"] {
                /* منطقية مش يمين/شمال: الصفحة عربي و margin-right كان بيزقها لبرا. */
                margin-inline-end: 15px;
                flex: 0 0 auto;
                width: 22px;
                height: 22px;
                cursor: pointer;
                accent-color: <?php echo $colors['selected_border']; ?>;
            }
            
            .option-content {
                flex: 1;
            }
            
            .option-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            
            .option-label {
                font-weight: 600;
                color: #333;
                font-size: 16px;
            }
            
            .discount-badge {
                background: <?php echo $colors['discount_badge_bg']; ?>;
                color: <?php echo $colors['discount_badge_text']; ?>;
                padding: 4px 10px;
                border-radius: 15px;
                font-size: 12px;
                font-weight: 600;
            }
            
            .option-price {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .original-price {
                text-decoration: line-through;
                color: #999;
                font-size: 14px;
            }
            
            .final-price {
                font-size: 18px;
                font-weight: 700;
                color: <?php echo $colors['primary_color']; ?>;
            }
            
            .wcqo-customer-fields {
                margin: 25px 0;
            }
            
            .wcqo-field {
                margin-bottom: 18px;
            }
            
            .wcqo-field label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #555;
                font-size: 14px;
            }
            
            .wcqo-field input,
            .wcqo-field select,
            .wcqo-field textarea {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 8px;
                /* 16px مقصودة: أي رقم أقل بيخلي سفاري في الآيفون يزوّم الصفحة
                   أول ما العميل يدوس على الخانة. */
                font-size: 16px;
                min-height: 48px;
                transition: border-color 0.3s;
                background: #fff;
                box-sizing: border-box;
                font-family: inherit;
            }
            
            .wcqo-field textarea {
                min-height: 92px;
            }
            
            /* إصلاح ارتفاع select فقط */
            .wcqo-field select {
                min-height: 48px;
                height: 48px;
                line-height: normal;
            }
            
            .wcqo-field input:focus,
            .wcqo-field select:focus,
            .wcqo-field textarea:focus {
                outline: none;
                border-color: <?php echo $colors['primary_color']; ?>;
                box-shadow: 0 0 0 3px rgba(<?php echo $this->hex2rgb($colors['primary_color']); ?>, 0.1);
            }
            
            .phone-input-group {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .country-code {
                order: 2; /* يظهر ثانياً على اليمين */
                padding: 12px;
                background: #f5f5f5;
                border: 1px solid #ddd;
                border-radius: 8px;
                font-weight: 600;
                color: #333;
                min-width: 65px;
                text-align: center;
                display: inline-block;
            }
            
            .phone-input-group input {
                order: 1; /* يظهر أولاً على اليسار */
                flex: 1;
                direction: ltr; /* الأرقام دائماً من الشمال لليمين */
                text-align: left;
            }
            
            .wcqo-buttons {
                display: flex;
                gap: 12px;
                margin-top: 25px;
            }
            
            .wcqo-submit-btn {
                flex: 1;
                padding: 16px;
                background: <?php echo $colors['button_bg']; ?>;
                color: <?php echo $colors['button_text']; ?>;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .wcqo-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(<?php echo $this->hex2rgb($colors['button_bg']); ?>, 0.3);
            }
            
            .wcqo-whatsapp-btn {
                flex: 1;
                padding: 16px;
                background: <?php echo $colors['whatsapp_bg']; ?>;
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            
            .wcqo-whatsapp-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
            }
            
            #wcqo-message {
                margin-top: 20px;
                padding: 15px;
                border-radius: 8px;
                text-align: center;
                display: none;
            }
            
            #wcqo-message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
                display: block;
            }
            
            #wcqo-message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                display: block;
            }
            
            .wcqo-loading {
                opacity: 0.7;
                pointer-events: none;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .wcqo-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 3px solid rgba(255,255,255,0.3);
                border-top-color: white;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            
            <?php if ($use_font): ?>
            #wcqo-form-container,
            #wcqo-sticky {
                font-family: "<?php echo esc_attr($font); ?>", "Segoe UI", Tahoma, sans-serif;
            }
            <?php endif; ?>

            /* شارات الثقة */
            .wcqo-badges {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 0 0 16px;
                padding: 0;
                list-style: none;
            }

            .wcqo-badges li {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin: 0;
                padding: 6px 10px;
                border: 1px solid #e6e2d8;
                border-radius: 999px;
                background: #fbfaf7;
                font-size: 12.5px;
                font-weight: 500;
                color: #4a4740;
                line-height: 1.3;
            }

            .wcqo-badges li span {
                color: #1e7a4c;
                font-weight: 700;
            }

            /* تنبيه الكمية */
            .wcqo-scarcity {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 0 0 12px;
                padding: 9px 12px;
                border-radius: 10px;
                background: #fff5e8;
                border: 1px solid #f2ddb9;
                color: #8a5a12;
                font-size: 13.5px;
                font-weight: 600;
            }

            /* عدد الطلبات الأخيرة */
            .wcqo-proof {
                margin: 0 0 16px;
                font-size: 13px;
                color: #6e6a63;
            }

            .wcqo-proof b {
                color: <?php echo $colors['primary_color']; ?>;
            }

            /* ملخص السعر */
            .wcqo-summary {
                margin: 20px 0 0;
                padding: 14px 16px;
                border: 1px solid #e6e2d8;
                border-radius: 12px;
                background: #fbfaf7;
            }

            .wcqo-summary-title {
                margin: 0 0 10px;
                font-size: 13px;
                font-weight: 700;
                letter-spacing: .02em;
                color: #6e6a63;
            }

            .wcqo-sum-row {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 12px;
                padding: 5px 0;
                font-size: 14px;
                color: #4a4740;
            }

            .wcqo-sum-row b {
                font-variant-numeric: tabular-nums;
            }

            .wcqo-sum-discount {
                display: none;
            }

            .wcqo-sum-discount b {
                color: #1e7a4c;
            }

            .wcqo-sum-free {
                color: #1e7a4c;
            }

            .wcqo-sum-total {
                margin-top: 6px;
                padding-top: 10px;
                border-top: 1px dashed #d8d2c4;
                font-size: 15px;
                font-weight: 700;
                color: #1b1f2a;
            }

            .wcqo-sum-total b {
                font-size: 19px;
                color: <?php echo $colors['primary_color']; ?>;
            }

            .wcqo-reassure {
                margin: 12px 0 0;
                text-align: center;
                font-size: 12.5px;
                color: #6e6a63;
            }

            /* شريط الطلب الثابت - موبايل فقط */
            .wcqo-sticky {
                position: fixed;
                inset-inline: 0;
                bottom: 0;
                z-index: 9999;
                display: none;
                align-items: center;
                gap: 12px;
                padding: 10px 14px;
                padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px));
                background: #fff;
                border-top: 1px solid #e6e2d8;
                box-shadow: 0 -4px 18px rgba(0,0,0,.10);
                transform: translateY(130%);
                transition: transform .22s ease;
                pointer-events: none;
            }

            .wcqo-sticky.is-on {
                transform: none;
                pointer-events: auto;
            }

            .wcqo-sticky-price {
                display: flex;
                flex-direction: column;
                line-height: 1.25;
                min-width: 0;
            }

            .wcqo-sticky-label {
                font-size: 11.5px;
                color: #6e6a63;
            }

            .wcqo-sticky-price b {
                font-size: 17px;
                font-weight: 700;
                color: <?php echo $colors['primary_color']; ?>;
                font-variant-numeric: tabular-nums;
            }

            .wcqo-sticky-btn {
                flex: 1;
                min-height: 48px;
                padding: 12px 16px;
                background: <?php echo $colors['button_bg']; ?>;
                color: <?php echo $colors['button_text']; ?>;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 700;
                font-family: inherit;
                cursor: pointer;
            }

            @media (prefers-reduced-motion: reduce) {
                .wcqo-sticky {
                    transition: none;
                }
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                #wcqo-form-container {
                    padding: 20px 15px;
                    margin: 20px -15px;
                    border-radius: 0;
                    max-width: 100%;
                }
                
                .wcqo-quantity-option {
                    padding: 12px;
                }
                
                .option-label {
                    font-size: 14px;
                }
                
                .final-price {
                    font-size: 16px;
                }
                
                .wcqo-buttons {
                    flex-direction: column;
                }
                
                .wcqo-submit-btn,
                .wcqo-whatsapp-btn {
                    width: 100%;
                }
                
                /* مهما حصل، الخانات تفضل 16px عشان الشاشة متكبرش */
                .wcqo-field input,
                .wcqo-field select,
                .wcqo-field textarea {
                    font-size: 16px;
                }
            }
        </style>
        <?php
    }
    
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }
        wp_enqueue_script('jquery');
    }
    
    public function admin_enqueue_scripts($hook) {
        // Only jQuery is needed. The old plugin also enqueued WCQO_PLUGIN_URL . 'assets/admin.js',
        // but that constant belonged to the standalone plugin and no such asset ships here -
        // leaving it in fataled every Quick Order admin screen once the old plugin was switched off.
        if (strpos($hook, 'wc-quick-order') !== false) {
            wp_enqueue_script('jquery');
        }
    }
    
    public function add_inline_scripts() {
        if (!is_product()) {
            return;
        }
        
        $settings = $this->settings();
        $active_country = $settings['active_country'];
        $country_settings = isset($settings['countries'][$active_country]) ? $settings['countries'][$active_country] : $settings['countries']['SA'];
        ?>
        <script>
        jQuery(document).ready(function($) {

            // A full-page cache can serve this form with a nonce that has already
            // expired, which would reject a real order. Swap in a fresh one.
            $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { action: 'hayak_qo_nonce' }, function (r) {
                if (r && r.success && r.data && r.data.nonce) {
                    $('#wcqo_nonce').val(r.data.nonce);
                }
            });

            
            // إخفاء فورم WooCommerce الأصلي بـ JavaScript كمان للتأكيد
            <?php if ($settings['enable_form'] === 'yes'): ?>
            // إخفاء كل عناصر الفورم الأصلي
            $('form.cart').hide();
            $('.single_add_to_cart_button').hide();
            $('.ux-buy-now-button').hide();
            $('.ux-quantity.quantity').hide();
            $('.variations_form.cart').hide();
            $('.grouped_form').hide();
            
            // التأكد من ظهور الفورم بتاعنا
            $('#wcqo-form-container').show();
            <?php endif; ?>
            
            // اختيار الكمية
            $('.wcqo-quantity-option').click(function() {
                $('.wcqo-quantity-option').removeClass('selected');
                $(this).addClass('selected');
                $(this).find('input[type="radio"]').prop('checked', true);
                
                var quantity = $(this).data('quantity');
                var discount = $(this).data('discount');
                var discountType = $(this).data('discount-type');
                
                $('#selected_quantity').val(quantity);
                $('#selected_discount').val(discount);
                $('#selected_discount_type').val(discountType);
                wcqoSync($(this));
            });

            // ملخص السعر و الشريط الثابت: القيم دي محسوبة على السيرفر و متخزنة
            // على كل خيار، عشان اللي العميل يشوفه في الملخص يبقى هو نفسه اللي
            // الأوردر هيتعمل بيه بالظبط.
            var wcqoCurrency = $('#wcqo-summary').data('currency') || '<?php echo esc_js($country_settings['currency']); ?>';

            function wcqoMoney(amount) {
                var n = Number(amount) || 0;
                return n.toFixed(2) + ' ' + wcqoCurrency;
            }

            function wcqoSync($option) {
                if (!$option || !$option.length) {
                    $option = $('.wcqo-quantity-option.selected').first();
                }
                if (!$option.length) {
                    return;
                }

                var qty = parseInt($option.attr('data-quantity'), 10) || 1;
                var sub = parseFloat($option.attr('data-subtotal')) || 0;
                var tot = parseFloat($option.attr('data-total')) || 0;
                var off = Math.max(0, sub - tot);

                $('.wcqo-sum-qty').text(qty);
                $('.wcqo-sum-sub').text(wcqoMoney(sub));
                $('.wcqo-sum-tot').text(wcqoMoney(tot));
                $('#wcqo-sticky-total').text(wcqoMoney(tot));

                if (off > 0.004) {
                    $('.wcqo-sum-discount').css('display', 'flex').find('.wcqo-sum-disc').text('− ' + wcqoMoney(off));
                } else {
                    $('.wcqo-sum-discount').hide();
                }
            }

            wcqoSync(null);

            var wcqoSticky = document.getElementById('wcqo-sticky');
            if (wcqoSticky) {
                var wcqoAnchor = document.querySelector('#wcqo-quick-order-form .wcqo-submit-btn');

                if (wcqoAnchor && window.IntersectionObserver) {
                    new IntersectionObserver(function (entries) {
                        entries.forEach(function (entry) {
                            if (entry.isIntersecting) {
                                wcqoSticky.classList.remove('is-on');
                            } else {
                                wcqoSticky.classList.add('is-on');
                            }
                        });
                    }, { threshold: 0.15 }).observe(wcqoAnchor);
                } else {
                    wcqoSticky.classList.add('is-on');
                }

                $(wcqoSticky).on('click', '.wcqo-sticky-btn', function () {
                    var form = document.getElementById('wcqo-quick-order-form');
                    if (!form) {
                        return;
                    }
                    if (form.checkValidity()) {
                        $(form).trigger('submit');
                        return;
                    }
                    var container = document.getElementById('wcqo-form-container');
                    if (container && container.scrollIntoView) {
                        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    setTimeout(function () {
                        form.reportValidity();
                    }, 450);
                });
            }

            
            // رسائل الخطأ المخصصة لكل حقل
            var customMessages = {
                'customer_name': {
                    'valueMissing': 'الرجاء إدخال الاسم الكامل',
                    'tooShort': 'الاسم قصير جداً',
                    'patternMismatch': 'الرجاء إدخال اسم صحيح'
                },
                'customer_phone': {
                    'valueMissing': 'الرجاء إدخال رقم الهاتف',
                    'patternMismatch': 'رقم الهاتف غير صحيح - مثال: <?php echo $country_settings['placeholder']; ?>',
                    'tooShort': 'رقم الهاتف غير مكتمل'
                },
                'customer_city': {
                    'valueMissing': 'الرجاء اختيار المدينة من القائمة'
                },
                'customer_address': {
                    'valueMissing': 'الرجاء إدخال العنوان بالتفصيل',
                    'tooShort': 'العنوان قصير جداً - يجب أن يكون 5 أحرف على الأقل'
                }
            };
            
            // تطبيق رسائل الخطأ المخصصة
            function setCustomValidation() {
                // الاسم
                var nameInput = document.querySelector('input[name="customer_name"]');
                if (nameInput) {
                    nameInput.oninvalid = function(e) {
                        e.target.setCustomValidity('');
                        if (!e.target.validity.valid) {
                            if (e.target.validity.valueMissing) {
                                e.target.setCustomValidity(customMessages.customer_name.valueMissing);
                            } else if (e.target.validity.tooShort) {
                                e.target.setCustomValidity(customMessages.customer_name.tooShort);
                            }
                        }
                    };
                    nameInput.oninput = function(e) {
                        e.target.setCustomValidity('');
                    };
                }
                
                // رقم الهاتف
                var phoneInput = document.querySelector('input[name="customer_phone"]');
                if (phoneInput) {
                    phoneInput.oninvalid = function(e) {
                        e.target.setCustomValidity('');
                        if (!e.target.validity.valid) {
                            if (e.target.validity.valueMissing) {
                                e.target.setCustomValidity(customMessages.customer_phone.valueMissing);
                            } else if (e.target.validity.patternMismatch) {
                                e.target.setCustomValidity(customMessages.customer_phone.patternMismatch);
                            }
                        }
                    };
                    phoneInput.oninput = function(e) {
                        e.target.setCustomValidity('');
                    };
                }
                
                // المدينة
                var citySelect = document.querySelector('select[name="customer_city"]');
                if (citySelect) {
                    citySelect.oninvalid = function(e) {
                        e.target.setCustomValidity('');
                        if (!e.target.validity.valid) {
                            e.target.setCustomValidity(customMessages.customer_city.valueMissing);
                        }
                    };
                    citySelect.onchange = function(e) {
                        e.target.setCustomValidity('');
                    };
                }
                
                // العنوان
                var addressTextarea = document.querySelector('textarea[name="customer_address"]');
                if (addressTextarea) {
                    addressTextarea.setAttribute('minlength', '5');
                    addressTextarea.oninvalid = function(e) {
                        e.target.setCustomValidity('');
                        if (!e.target.validity.valid) {
                            if (e.target.validity.valueMissing) {
                                e.target.setCustomValidity(customMessages.customer_address.valueMissing);
                            } else if (e.target.validity.tooShort) {
                                e.target.setCustomValidity(customMessages.customer_address.tooShort);
                            }
                        }
                    };
                    addressTextarea.oninput = function(e) {
                        e.target.setCustomValidity('');
                    };
                }
            }
            
            // تطبيق التحقق عند تحميل الصفحة
            setCustomValidation();
            
            // معالجة إرسال الفورم
            $('#wcqo-quick-order-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $button = $form.find('.wcqo-submit-btn');
                var $message = $('#wcqo-message');
                var originalText = $button.html();
                
                // التحقق من صحة جميع الحقول
                var isValid = true;
                var firstInvalidField = null;
                
                // التحقق من الاسم
                var name = $form.find('input[name="customer_name"]').val().trim();
                if (name.length < 3) {
                    $form.find('input[name="customer_name"]')[0].setCustomValidity('الاسم يجب أن يكون 3 أحرف على الأقل');
                    $form.find('input[name="customer_name"]')[0].reportValidity();
                    return false;
                }
                
                // التحقق من رقم الهاتف
                var phone = $form.find('input[name="customer_phone"]').val();
                var phonePattern = new RegExp('<?php echo esc_js($country_settings['pattern']); ?>');
                
                if (!phonePattern.test(phone)) {
                    $form.find('input[name="customer_phone"]')[0].setCustomValidity('رقم الهاتف غير صحيح للبلد المختار');
                    $form.find('input[name="customer_phone"]')[0].reportValidity();
                    return false;
                }
                
                // التحقق من المدينة
                var city = $form.find('select[name="customer_city"]').val();
                if (!city) {
                    $form.find('select[name="customer_city"]')[0].setCustomValidity('الرجاء اختيار المدينة');
                    $form.find('select[name="customer_city"]')[0].reportValidity();
                    return false;
                }
                
                // التحقق من العنوان
                var address = $form.find('textarea[name="customer_address"]').val().trim();
                if (address.length < 5) {
                    $form.find('textarea[name="customer_address"]')[0].setCustomValidity('العنوان يجب أن يكون مفصل (5 أحرف على الأقل)');
                    $form.find('textarea[name="customer_address"]')[0].reportValidity();
                    return false;
                }
                
                // إظهار حالة التحميل
                $button.html('<span class="wcqo-spinner"></span> جاري الإرسال...');
                $form.addClass('wcqo-loading');
                
                // إرسال البيانات
                $.ajax({
                    url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    data: $form.serialize() + '&action=wcqo_create_order',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('error').addClass('success').html('✔ تم إرسال طلبك بنجاح!').show();
                            
                            // التتبع
                            if (typeof dataLayer !== 'undefined') {
                                dataLayer.push({
                                    'event': '<?php echo esc_js($settings['gtm_event_name']); ?>',
                                    'order_id': response.data.order_id,
                                    'country': '<?php echo esc_js($active_country); ?>',
                                    'quantity': $('#selected_quantity').val(),
                                    'discount': $('#selected_discount').val()
                                });
                            }
                            
                            // إعادة تعيين الفورم
                            $form[0].reset();
                            
                            // التحويل لصفحة الشكر
                            setTimeout(function() {
                                window.location.href = response.data.redirect_url;
                            }, 2000);
                        } else {
                            $message.removeClass('success').addClass('error').html(response.data || 'حدث خطأ').show();
                            $button.html(originalText);
                            $form.removeClass('wcqo-loading');
                        }
                    },
                    error: function() {
                        $message.removeClass('success').addClass('error').html('خطأ في الاتصال - الرجاء المحاولة مرة أخرى').show();
                        $button.html(originalText);
                        $form.removeClass('wcqo-loading');
                    }
                });
            });
            
            // تنسيق رقم الهاتف - قبول الأرقام فقط
            $('input[name="customer_phone"]').on('input', function() {
                var value = $(this).val().replace(/\D/g, '');
                $(this).val(value);
            });
            
            // إضافة تلميحات للمستخدم عند التركيز على الحقول
            $('input[name="customer_name"]').on('focus', function() {
                $(this).attr('placeholder', 'مثال: محمد أحمد');
            });
            
            $('input[name="customer_phone"]').on('focus', function() {
                $(this).attr('placeholder', '<?php echo esc_js($country_settings['placeholder']); ?>');
            });
            
            $('textarea[name="customer_address"]').on('focus', function() {
                $(this).attr('placeholder', 'الحي، الشارع، رقم المبنى، الرمز البريدي');
            });
            
            <?php if ($settings['whatsapp_enabled'] === 'yes' && !empty($settings['whatsapp_number'])): ?>
            // WhatsApp button handler
            $('#wcqo-whatsapp-button').on('click', function(e) {
                e.preventDefault();
                
                var form = document.getElementById('wcqo-quick-order-form');
                var name = form.customer_name.value;
                var phone = form.customer_phone.value;
                var city = form.customer_city.value;
                var address = form.customer_address.value;
                var quantity = document.getElementById('selected_quantity').value;
                var productName = form.product_name.value;
                
                if (!name || !phone || !city || !address) {
                    alert('الرجاء ملء جميع البيانات أولاً');
                    return;
                }
                
                var message = 'مرحباً، أريد طلب:\n';
                message += 'المنتج: ' + productName + '\n';
                message += 'الكمية: ' + quantity + '\n';
                message += 'الاسم: ' + name + '\n';
                message += 'الهاتف: <?php echo esc_js($country_settings['code']); ?>' + phone + '\n';
                message += 'المدينة: ' + city + '\n';
                message += 'العنوان: ' + address + '\n';
                message += 'رابط المنتج: ' + window.location.href;
                
                var whatsappUrl = 'https://wa.me/<?php echo rawurlencode($settings['whatsapp_number']); ?>?text=' + encodeURIComponent(message);
                window.open(whatsappUrl, '_blank');
            });
            <?php else: ?>
            
            
            
            <?php endif; ?>
        });
        </script>
        <?php
    }
    
    /**
     * Resolve which offer the buyer picked.
     *
     * The browser only ever sends the option KEY. Quantity, discount type and
     * discount amount are read from the stored settings, and an option that is
     * switched off is refused - the original honoured a disabled option's discount.
     *
     * @return array{quantity:int,discount_type:string,discount_amount:float,key:string}|null
     */
    public static function resolve_offer($settings, $option_key) {
        $options = isset($settings['quantity_options']) && is_array($settings['quantity_options'])
            ? $settings['quantity_options']
            : array();

        if ('' === (string) $option_key || !isset($options[$option_key]) || !is_array($options[$option_key])) {
            return null;
        }
        $option = $options[$option_key];
        if ('yes' !== ($option['enabled'] ?? 'no')) {
            return null;
        }

        return array(
            'key'             => (string) $option_key,
            'quantity'        => max(1, (int) ($option['quantity'] ?? 1)),
            'discount_type'   => ($option['discount_type'] ?? 'fixed') === 'percentage' ? 'percentage' : 'fixed',
            'discount_amount' => max(0, (float) ($option['discount_amount'] ?? 0)),
        );
    }

    /**
     * Price an offer against the product's own price.
     *
     * @return array{subtotal:float,total:float}
     */
    public static function price_offer(array $offer, $unit_price) {
        $unit     = max(0, (float) $unit_price);
        $quantity = max(1, (int) $offer['quantity']);
        $subtotal = $unit * $quantity;

        if ('fixed' === $offer['discount_type']) {
            $total = $subtotal - (float) $offer['discount_amount'];
        } else {
            $percent = min(100, max(0, (float) $offer['discount_amount']));
            $total   = $subtotal - ($subtotal * ($percent / 100));
        }

        // Cast explicitly: max(0, -1.5) returns the INT 0, so without this the
        // method would sometimes hand back an int and sometimes a float.
        return array(
            'subtotal' => (float) round(max(0, $subtotal), 2),
            'total'    => (float) round(max(0, $total), 2),
        );
    }

    /**
     * Create the order.
     *
     * Rewritten from the original. The original took the QUANTITY from a POST
     * field while taking the DISCOUNT from the server settings, and never checked
     * that the chosen option was enabled - so a crafted request could pair
     * "quantity 1" with the two-pack discount, claim the disabled three-pack's
     * 100 SAR off, or send quantity 0 and get the product for nothing.
     * Here the option key is the ONLY thing the browser chooses; quantity, discount
     * and price all come from the stored settings and the product.
     */
    public function handle_ajax_order() {
        $settings = $this->settings();

        $nonce = isset($_POST['wcqo_nonce']) ? sanitize_text_field(wp_unslash($_POST['wcqo_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wcqo_order')) {
            wp_send_json_error('انتهت صلاحية الصفحة، حدّثها وجرّب تاني');
        }

        // ---- the offer: chosen by key, defined entirely by the server ----
        $option_key = sanitize_key(wp_unslash($_POST['quantity_option'] ?? 'option1'));
        $offer      = self::resolve_offer($settings, $option_key);
        if (!$offer) {
            wp_send_json_error('الخيار ده مش متاح');
        }
        $quantity        = $offer['quantity'];
        $discount_type   = $offer['discount_type'];
        $discount_amount = $offer['discount_amount'];

        // ---- country must be one we actually sell to ----
        $country_code = sanitize_text_field(wp_unslash($_POST['country_code'] ?? ''));
        if (!isset($settings['countries'][$country_code])) {
            $country_code = $settings['active_country'] ?? 'SA';
        }
        if (!isset($settings['countries'][$country_code])) {
            wp_send_json_error('البلد غير مدعوم');
        }
        $country = $settings['countries'][$country_code];

        // ---- product must be real, published and buyable ----
        $product_id = absint($_POST['product_id'] ?? 0);
        $product    = $product_id ? wc_get_product($product_id) : null;

        if (!$product || !$product->exists() || 'publish' !== get_post_status($product_id)) {
            wp_send_json_error('المنتج غير متاح');
        }
        if ($product->is_type('variable')) {
            wp_send_json_error('المنتج ده له خيارات، اطلبه من صفحة المنتج');
        }
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            wp_send_json_error('المنتج غير متوفر حالياً');
        }

        // ---- customer ----
        $customer_name    = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
        $customer_phone   = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
        $customer_city    = sanitize_text_field(wp_unslash($_POST['customer_city'] ?? ''));
        $customer_address = sanitize_textarea_field(wp_unslash($_POST['customer_address'] ?? ''));

        if ('' === $customer_name || '' === $customer_address) {
            wp_send_json_error('من فضلك اكمل بياناتك');
        }
        $phone_digits = preg_replace('/\D/', '', $customer_phone);
        if (strlen($phone_digits) < 8 || strlen($phone_digits) > 15) {
            wp_send_json_error('رقم الجوال غير صحيح');
        }

        // ---- pricing, entirely server side ----
        $unit_price = (float) $product->get_price();
        $priced     = self::price_offer($offer, $unit_price);
        $subtotal   = $priced['subtotal'];
        $total      = $priced['total'];

        // The buyer is quoted a price that already includes delivery; the order
        // recorded in WooCommerce excludes it because the courier is paid separately.
        $shipping_cost_to_deduct = max(0, (float) ($settings['shipping_cost_to_deduct'] ?? 0));
        $woocommerce_total       = max(0, round($total - $shipping_cost_to_deduct, 2));

        if ($total <= 0) {
            wp_send_json_error('حصل خطأ في حساب السعر، كلمنا من فضلك');
        }

        // ---- one order per submission, even on a double tap ----
        $lock = self::LOCK_PREFIX . md5(implode('|', array(
            'qo',   // namespaced: the Elementor flow hashes the same four values
            $product_id,
            class_exists('Hayak_Fields') ? Hayak_Fields::normalise_phone($customer_phone) : $phone_digits,
            $quantity,
            $total,
        )));
        if (!add_option($lock, time() . '|0', '', 'no')) {
            $held    = (string) get_option($lock, '');
            $parts   = explode('|', $held);
            $claimed = isset($parts[1]) ? (int) $parts[1] : 0;
            $when    = isset($parts[0]) ? (int) $parts[0] : 0;

            if ($when && (time() - $when) > self::LOCK_TTL) {
                update_option($lock, time() . '|0', false);
            } elseif ($claimed) {
                // Hand the order back ONLY to the browser that created it. Otherwise
                // anyone who knows a buyer's number could replay this request inside
                // the lock window and be given that order's id, its thank-you URL and
                // a signed cookie pointing the pixels at someone else's purchase.
                $existing = $this->holds_order_cookie($claimed) ? wc_get_order($claimed) : null;
                if ($existing) {
                    wp_send_json_success(array(
                        'order_id'     => $claimed,
                        'redirect_url' => $existing->get_checkout_order_received_url(),
                    ));
                }
                wp_send_json_error('طلبك مسجّل بالفعل وهنكلمك حالاً');
            } else {
                wp_send_json_error('طلبك بيتسجل، ثانية واحدة');
            }
        }

        // ---- build the order ----
        try {
            $order = wc_create_order();

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity($quantity);
            $item->set_subtotal($subtotal);
            $item->set_total($woocommerce_total);
            $order->add_item($item);

            $order->set_billing_first_name($customer_name);
            $order->set_billing_phone($customer_phone);
            $order->set_billing_city($customer_city);
            $order->set_billing_state($customer_city);
            $order->set_billing_address_1($customer_address);
            $order->set_billing_country($country_code);
            $order->set_shipping_first_name($customer_name);
            $order->set_shipping_city($customer_city);
            $order->set_shipping_state($customer_city);
            $order->set_shipping_address_1($customer_address);
            $order->set_shipping_country($country_code);

            $order->set_created_via(self::CREATED_VIA);
            $order->set_payment_method('cod');
            $order->set_payment_method_title('الدفع عند الاستلام');

            $order->add_meta_data('_wcqo_quick_order', 'yes');
            $order->add_meta_data('_wcqo_country', $country['name']);
            $order->add_meta_data('_wcqo_quantity', $quantity);
            $order->add_meta_data('_wcqo_option_key', $option_key);
            $order->add_meta_data('_wcqo_discount_type', $discount_type);
            $order->add_meta_data('_wcqo_discount_amount', $discount_amount);
            $order->add_meta_data('_wcqo_display_total', $total);
            $order->add_meta_data('_wcqo_shipping_deducted', $shipping_cost_to_deduct);
            $order->add_meta_data('_hayak_created_via', self::CREATED_VIA);

            if ($discount_amount > 0) {
                $order->add_order_note(
                    'fixed' === $discount_type
                        ? 'تم تطبيق خصم ' . $discount_amount . ' ' . $country['currency'] . ' على الطلب'
                        : 'تم تطبيق خصم ' . $discount_amount . '% على الطلب'
                );
            }
            if ($shipping_cost_to_deduct > 0) {
                $order->add_order_note('تم خصم تكلفة الشحن المحددة مسبقاً: ' . $shipping_cost_to_deduct . ' ' . $country['currency']);
            }

            $order->calculate_totals(false);
            $order->update_status('processing', 'طلب سريع من ' . $country['name']);
            $order->save();
        } catch (\Throwable $e) {
            delete_option($lock);
            $this->log('Quick order failed: ' . $e->getMessage());
            wp_send_json_error('حصل خطأ، حاول تاني');
        }

        $order_id = $order->get_id();
        if (!$order_id) {
            delete_option($lock);
            wp_send_json_error('حصل خطأ، حاول تاني');
        }

        update_option($lock, time() . '|' . $order_id, false);
        $this->remember_for_pixel($order_id);

        $this->log(sprintf(
            'Quick order #%d - product %d x%d, charged %s, recorded %s, option %s',
            $order_id, $product_id, $quantity, $total, $woocommerce_total, $option_key
        ));

        if (($settings['send_admin_email'] ?? 'no') === 'yes') {
            $this->send_admin_notification($order);
        }

        wp_send_json_success(array(
            'order_id'     => $order_id,
            'redirect_url' => $order->get_checkout_order_received_url(),
        ));
    }

    /** Does this caller already hold the signed cookie for that order? */
    protected function holds_order_cookie($order_id) {
        if (!class_exists('Hayak_Orders') || empty($_COOKIE[Hayak_Orders::COOKIE])) {
            return false;
        }
        $raw = sanitize_text_field(wp_unslash($_COOKIE[Hayak_Orders::COOKIE]));
        if (false === strpos($raw, '.')) {
            return false;
        }
        list($id, $sig) = explode('.', $raw, 2);
        return absint($id) === (int) $order_id
            && hash_equals(Hayak_Orders::signature((int) $order_id), $sig);
    }

    /**
     * Hand the order id to the thank-you page the same way the Elementor flow
     * does, so Hayak_DataLayer picks this exact order instead of guessing.
     */
    protected function remember_for_pixel($order_id) {
        if (!class_exists('Hayak_Orders')) {
            return;
        }
        $payload = $order_id . '.' . Hayak_Orders::signature($order_id);
        if (!headers_sent()) {
            setcookie(
                Hayak_Orders::COOKIE,
                $payload,
                time() + HOUR_IN_SECONDS,
                COOKIEPATH ? COOKIEPATH : '/',
                COOKIE_DOMAIN,
                is_ssl(),
                false
            );
        }
        $_COOKIE[Hayak_Orders::COOKIE] = $payload;
    }

    /**
     * Report the price the buyer actually paid to the pixels.
     *
     * The order total in WooCommerce has the pre-paid shipping stripped out of it,
     * so using it would under-report every quick order's revenue by that amount.
     */
    public function correct_pixel_value($payload, $order) {
        if (!is_a($order, 'WC_Order') || 'yes' !== $order->get_meta('_wcqo_quick_order')) {
            return $payload;
        }

        $display = (float) $order->get_meta('_wcqo_display_total');
        if ($display <= 0) {
            return $payload;
        }

        $payload['ecommerce']['value'] = round($display, 2);

        // Keep the line items consistent with that total.
        if (!empty($payload['ecommerce']['items']) && is_array($payload['ecommerce']['items'])) {
            $count = 0;
            foreach ($payload['ecommerce']['items'] as $line) {
                $count += max(1, (int) ($line['quantity'] ?? 1));
            }
            if ($count > 0 && 1 === count($payload['ecommerce']['items'])) {
                $payload['ecommerce']['items'][0]['price'] = round($display / $count, 2);
            }
        }

        return $payload;
    }

    /* ------------------------------------------------------------------ *
     * أبسيل صفحة الشكر
     *
     * The buyer is offered products related to what they just bought, and a
     * click creates a SEPARATE order rather than touching the one they placed.
     * Nothing here goes near the cart, the session or the finished order's
     * totals, so the purchase event that already fired on this page stays
     * exactly as it was; the new order pushes its own purchase with its own
     * transaction id, which adds revenue instead of restating it.
     * ------------------------------------------------------------------ */

    /** The block renders once per page, whichever route asked for it. */
    private $upsell_printed = false;

    /** Order ids created from this order's upsell, recorded on the parent. */
    private function upsell_children($order) {
        $stored = $order->get_meta('_hayak_upsell_children');
        if (!is_array($stored)) {
            return array();
        }
        return array_values(array_unique(array_filter(array_map('absint', $stored))));
    }

    /** Category and tag ids across everything in an order. */
    private function order_term_ids($order) {
        $terms = array();

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            if (!$product_id) {
                continue;
            }
            foreach (array('product_cat', 'product_tag') as $taxonomy) {
                $terms = array_merge($terms, wc_get_product_term_ids($product_id, $taxonomy));
            }
        }

        return array_values(array_unique(array_map('absint', $terms)));
    }

    /** Products already spoken for by this order or anything added to it. */
    private function upsell_excluded($order) {
        $exclude = array();

        foreach ($order->get_items() as $item) {
            $exclude[] = (int) $item->get_product_id();
        }

        foreach ($this->upsell_children($order) as $child_id) {
            $child = wc_get_order($child_id);
            if (!$child) {
                continue;
            }
            foreach ($child->get_items() as $item) {
                $exclude[] = (int) $item->get_product_id();
            }
        }

        return array_values(array_unique(array_filter($exclude)));
    }

    /** Can this product be sold on its own, right now, as an upsell? */
    private function upsell_sellable($product) {
        if (!$product || 'publish' !== $product->get_status()) {
            return false;
        }
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return false;
        }
        if (!$product->is_type('simple')) {
            return false;
        }
        return (float) $product->get_price() > 0;
    }

    /**
     * Would we ever have offered this product for this order?
     *
     * Checked again when the button is clicked, because the id travels through
     * the browser: the displayed shortlist is shuffled by WooCommerce, so the
     * rule - shares a category or tag with what they bought, and is sellable -
     * is what authorises the order, not the list that happened to render.
     */
    private function upsell_allowed($order, $product) {
        if (!$this->upsell_sellable($product)) {
            return false;
        }

        if (in_array((int) $product->get_id(), $this->upsell_excluded($order), true)) {
            return false;
        }

        $wanted = $this->order_term_ids($order);
        if (empty($wanted)) {
            return false;
        }

        $mine = array_merge(
            wc_get_product_term_ids($product->get_id(), 'product_cat'),
            wc_get_product_term_ids($product->get_id(), 'product_tag')
        );

        return (bool) array_intersect($wanted, array_map('absint', $mine));
    }

    /** The shortlist shown on the thank-you page. */
    private function upsell_products($order, $limit) {
        $limit   = max(1, min(6, (int) $limit));
        $exclude = $this->upsell_excluded($order);
        $found   = array();

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            if (!$product_id) {
                continue;
            }
            foreach (wc_get_related_products($product_id, $limit + 6, $exclude) as $related_id) {
                $found[(int) $related_id] = true;
            }
        }

        $products = array();
        foreach (array_keys($found) as $candidate_id) {
            $product = wc_get_product($candidate_id);
            if (!$this->upsell_sellable($product)) {
                continue;
            }
            $products[] = $product;
            if (count($products) >= $limit) {
                break;
            }
        }

        return $products;
    }

    /** What the buyer pays for an upsell product, after the optional discount. */
    private function upsell_price($product, $upsell) {
        $price   = (float) $product->get_price();
        $percent = max(0, min(90, (float) ($upsell['discount_percent'] ?? 0)));

        return round($price * (1 - ($percent / 100)), 2);
    }

    /**
     * Whose order is this visitor looking at?
     *
     * Two different pages end an order here. The product-page form lands on
     * WooCommerce's own order-received URL, which carries the order key; the
     * Elementor landing pages redirect to a plain page, where the signed cookie
     * Hayak_Orders sets is the only proof. Anything else gets nothing back.
     */
    private function upsell_order_from_request() {
        $order_id = function_exists('get_query_var') ? absint(get_query_var('order-received')) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if ($order_id && '' !== $key) {
            $order = wc_get_order($order_id);
            if ($order && hash_equals($order->get_order_key(), $key)) {
                return $order;
            }
        }

        if (class_exists('Hayak_Orders') && !empty($_COOKIE[Hayak_Orders::COOKIE])) {
            $raw = sanitize_text_field(wp_unslash($_COOKIE[Hayak_Orders::COOKIE]));
            if (false !== strpos($raw, '.')) {
                list($cookie_id, $signature) = explode('.', $raw, 2);
                $cookie_id = absint($cookie_id);
                if ($cookie_id && hash_equals(Hayak_Orders::signature($cookie_id), $signature)) {
                    $order = wc_get_order($cookie_id);
                    if ($order) {
                        return $order;
                    }
                }
            }
        }

        return null;
    }

    /** The WooCommerce order-received page. */
    public function render_upsell($order_id = 0) {
        echo $this->upsell_markup($order_id);
    }

    /** [hayak_upsell] - for dropping the block anywhere on a custom thank-you page. */
    public function upsell_shortcode() {
        return $this->upsell_markup(0);
    }

    /** The configured thank-you page gets the block appended automatically. */
    public function append_upsell_to_page($content) {
        $settings = $this->settings();
        $slug     = sanitize_title((string) ($settings['upsell']['page_slug'] ?? ''));

        if ('' === $slug || !is_page($slug) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        return $content . $this->upsell_markup(0);
    }

    private function upsell_markup($order_id = 0) {
        $settings = $this->settings();
        $upsell   = $settings['upsell'];

        if ('yes' !== ($upsell['enabled'] ?? 'no') || $this->upsell_printed) {
            return '';
        }

        $order = null;
        if ($order_id) {
            $candidate = wc_get_order($order_id);
            $key       = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
            if ($candidate && '' !== $key && hash_equals($candidate->get_order_key(), $key)) {
                $order = $candidate;
            }
        }

        if (!$order) {
            $order = $this->upsell_order_from_request();
        }

        if (!$order) {
            return '';
        }

        if (count($this->upsell_children($order)) >= max(1, (int) ($upsell['max_per_order'] ?? 3))) {
            return '';
        }

        $products = $this->upsell_products($order, $upsell['count'] ?? 3);
        if (empty($products)) {
            return '';
        }

        $this->upsell_printed = true;
        $colors = $this->safe_colors($settings);

        ob_start();
        ?>
        <style>
            .hayak-upsell {
                margin: 30px 0;
                padding: 22px 20px;
                border: 1px solid #e6e2d8;
                border-radius: 14px;
                background: #fbfaf7;
            }
            .hayak-upsell h2 {
                margin: 0 0 6px;
                font-size: 20px;
                font-weight: 700;
                color: <?php echo $colors['primary_color']; ?>;
            }
            .hayak-upsell .hayak-upsell-sub {
                margin: 0 0 18px;
                font-size: 14px;
                color: #6e6a63;
            }
            .hayak-upsell-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                gap: 14px;
            }
            .hayak-upsell-card {
                display: flex;
                flex-direction: column;
                gap: 10px;
                padding: 14px;
                background: #fff;
                border: 1px solid #ece8de;
                border-radius: 12px;
            }
            .hayak-upsell-card img {
                width: 100%;
                height: auto;
                aspect-ratio: 1 / 1;
                object-fit: cover;
                border-radius: 8px;
                display: block;
            }
            .hayak-upsell-card .hayak-upsell-name {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                line-height: 1.45;
                color: #1b1f2a;
            }
            .hayak-upsell-card .hayak-upsell-price {
                font-size: 17px;
                font-weight: 700;
                color: <?php echo $colors['primary_color']; ?>;
                font-variant-numeric: tabular-nums;
            }
            .hayak-upsell-card .hayak-upsell-was {
                margin-inline-start: 8px;
                font-size: 13px;
                font-weight: 400;
                color: #999;
                text-decoration: line-through;
            }
            .hayak-upsell-card button {
                margin-top: auto;
                min-height: 48px;
                padding: 12px;
                border: none;
                border-radius: 10px;
                background: <?php echo $colors['button_bg']; ?>;
                color: <?php echo $colors['button_text']; ?>;
                font-size: 15px;
                font-weight: 700;
                font-family: inherit;
                cursor: pointer;
            }
            .hayak-upsell-card button[disabled] {
                opacity: .6;
                cursor: default;
            }
            .hayak-upsell-card.is-done button {
                background: #1e7a4c;
            }
            .hayak-upsell-note {
                margin: 16px 0 0;
                font-size: 12.5px;
                color: #6e6a63;
            }
            .hayak-upsell-error {
                margin: 12px 0 0;
                padding: 10px 12px;
                border-radius: 8px;
                background: #f8d7da;
                color: #721c24;
                font-size: 13.5px;
                display: none;
            }
            @media (max-width: 480px) {
                .hayak-upsell-grid {
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }
                .hayak-upsell {
                    padding: 18px 14px;
                }
            }
        </style>

        <section class="hayak-upsell" id="hayak-upsell"
                 data-order="<?php echo esc_attr($order->get_id()); ?>"
                 data-key="<?php echo esc_attr($order->get_order_key()); ?>"
                 data-nonce="<?php echo esc_attr(wp_create_nonce('hayak_upsell')); ?>"
                 data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-busy="جاري الإضافة..."
                 data-done="<?php echo esc_attr($upsell['done_text'] ?? ''); ?>">
            <h2><?php echo esc_html($upsell['title'] ?? ''); ?></h2>
            <p class="hayak-upsell-sub"><?php echo esc_html($upsell['subtitle'] ?? ''); ?></p>

            <div class="hayak-upsell-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $regular = (float) $product->get_price();
                    $payable = $this->upsell_price($product, $upsell);
                    ?>
                    <div class="hayak-upsell-card" data-product="<?php echo esc_attr($product->get_id()); ?>">
                        <?php echo $product->get_image('woocommerce_thumbnail'); ?>
                        <p class="hayak-upsell-name"><?php echo esc_html($product->get_name()); ?></p>
                        <span class="hayak-upsell-price">
                            <?php echo wp_kses_post(wc_price($payable)); ?>
                            <?php if ($payable < $regular - 0.004): ?>
                            <span class="hayak-upsell-was"><?php echo wp_kses_post(wc_price($regular)); ?></span>
                            <?php endif; ?>
                        </span>
                        <button type="button"><?php echo esc_html($upsell['button_text'] ?? ''); ?></button>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="hayak-upsell-note">الإضافة بتتعمل كطلب منفصل و بتوصلك مع نفس الشحنة. مش محتاج تدفع حاجة دلوقتي.</p>
            <div class="hayak-upsell-error" id="hayak-upsell-error"></div>
        </section>

        <script>
        (function () {
            var box = document.getElementById('hayak-upsell');
            if (!box) { return; }

            var errorBox = document.getElementById('hayak-upsell-error');

            box.addEventListener('click', function (event) {
                var button = event.target.closest('.hayak-upsell-card button');
                if (!button || button.disabled) { return; }

                var card = button.closest('.hayak-upsell-card');
                var original = button.textContent;
                button.disabled = true;
                button.textContent = box.dataset.busy;
                errorBox.style.display = 'none';

                var body = new URLSearchParams();
                body.append('action', 'hayak_upsell_order');
                body.append('nonce', box.dataset.nonce);
                body.append('order_id', box.dataset.order);
                body.append('order_key', box.dataset.key);
                body.append('product_id', card.dataset.product);

                fetch(box.dataset.ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (!result || !result.success) {
                        errorBox.textContent = (result && result.data) ? result.data : 'حصل خطأ، حاول تاني';
                        errorBox.style.display = 'block';
                        button.disabled = false;
                        button.textContent = original;
                        return;
                    }

                    card.classList.add('is-done');
                    button.textContent = box.dataset.done;

                    // A purchase of its own, with its own transaction id - the
                    // event already fired for the first order is untouched.
                    if (result.data.purchase && typeof window.dataLayer !== 'undefined') {
                        window.dataLayer.push({ ecommerce: null });
                        window.dataLayer.push(result.data.purchase);
                    }
                })
                .catch(function () {
                    errorBox.textContent = 'خطأ في الاتصال - حاول تاني';
                    errorBox.style.display = 'block';
                    button.disabled = false;
                    button.textContent = original;
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_upsell_order() {
        $settings = $this->settings();
        $upsell   = $settings['upsell'];

        if ('yes' !== ($upsell['enabled'] ?? 'no')) {
            wp_send_json_error('الخدمة دي مقفولة دلوقتي');
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'hayak_upsell')) {
            wp_send_json_error('الصفحة قديمة، حدّثها و جرّب تاني');
        }

        $order_id   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order_key  = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        $parent = $order_id ? wc_get_order($order_id) : false;
        if (!$parent || '' === $order_key || !hash_equals($parent->get_order_key(), $order_key)) {
            wp_send_json_error('الطلب مش موجود');
        }

        if (count($this->upsell_children($parent)) >= max(1, (int) ($upsell['max_per_order'] ?? 3))) {
            wp_send_json_error('وصلت لأقصى عدد إضافات على الطلب ده');
        }

        $product = $product_id ? wc_get_product($product_id) : false;
        if (!$product || !$this->upsell_allowed($parent, $product)) {
            wp_send_json_error('المنتج ده مش متاح دلوقتي');
        }

        // One INSERT decides the winner, so a double click - or two tabs - can
        // never turn into two orders for the same product.
        $lock = self::LOCK_PREFIX . md5('upsell|' . $order_id . '|' . $product_id);
        if (!add_option($lock, time() . '|pending', '', false)) {
            wp_send_json_error('المنتج ده متضاف بالفعل لطلبك');
        }

        $payable = $this->upsell_price($product, $upsell);
        $status  = $parent->get_status();
        if (!in_array($status, array('processing', 'completed', 'on-hold'), true)) {
            $status = 'processing';
        }

        try {
            $new = wc_create_order(array('status' => 'pending'));

            $item = new WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity(1);
            $item->set_subtotal((float) $product->get_price());
            $item->set_total($payable);
            $new->add_item($item);

            $new->set_address($parent->get_address('billing'), 'billing');
            $new->set_address($parent->get_address('shipping'), 'shipping');
            $new->set_customer_id($parent->get_customer_id());
            $new->set_currency($parent->get_currency());

            $new->set_created_via('hayak-upsell');
            $new->set_payment_method('cod');
            $new->set_payment_method_title('الدفع عند الاستلام');

            $new->add_meta_data('_hayak_created_via', 'hayak-upsell');
            $new->add_meta_data('_hayak_upsell_parent', $order_id);

            $new->calculate_totals(false);
            $new->update_status($status, 'أبسيل من الطلب #' . $order_id . ' — يتشحن مع نفس الشحنة');
            $new->save();
        } catch (\Throwable $e) {
            delete_option($lock);
            $this->log('Upsell order failed for parent #' . $order_id . ': ' . $e->getMessage());
            wp_send_json_error('حصل خطأ، حاول تاني');
        }

        $new_id = $new->get_id();
        if (!$new_id) {
            delete_option($lock);
            wp_send_json_error('حصل خطأ، حاول تاني');
        }

        update_option($lock, time() . '|' . $new_id, false);

        // Read the parent again: a second card clicked at the same moment may
        // have recorded its own child while this one was being created, and
        // writing the stale list back would drop it.
        $parent     = wc_get_order($order_id);
        $children   = $parent ? $this->upsell_children($parent) : array();
        $children[] = $new_id;

        if ($parent) {
            $parent->update_meta_data('_hayak_upsell_children', array_values(array_unique($children)));
            $parent->add_order_note(
                'العميل ضاف "' . wp_strip_all_tags($product->get_name()) . '" في طلب منفصل #' . $new_id
            );
            $parent->save();
        }

        $this->log(sprintf('Upsell order #%d created from #%d - product %d at %s', $new_id, $order_id, $product_id, $payable));

        $response = array(
            'order_id' => $new_id,
            'message'  => (string) ($upsell['done_text'] ?? ''),
        );

        if ('yes' === ($upsell['track_pixel'] ?? 'no')) {
            // Same event name as Hayak_DataLayer: the GTM trigger listens for
            // `hayak_purchase` only, so the gtag('event','purchase') calls that
            // Site Kit and Google Listings & Ads make cannot double-fire it.
            $response['purchase'] = array(
                'event'     => 'hayak_purchase',
                'event_id'  => 'hy-' . $new_id,
                'ecommerce' => array(
                    'transaction_id' => (string) $new_id,
                    'value'          => round((float) $new->get_total(), 2),
                    'currency'       => $new->get_currency(),
                    'items'          => array(
                        array(
                            'item_id'   => (string) apply_filters('hayak_pixel_item_id', $product->get_id(), $product),
                            'item_name' => $product->get_name(),
                            'price'     => $payable,
                            'quantity'  => 1,
                        ),
                    ),
                ),
            );
        }

        wp_send_json_success($response);
    }

    /** A cached page can serve a stale nonce; this hands the form a fresh one. */
    public function ajax_fresh_nonce() {
        wp_send_json_success(array('nonce' => wp_create_nonce('wcqo_order')));
    }

    public function log($message) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->info($message, array('source' => 'hayak-quick-order'));
            return;
        }
        error_log('[Hayak Quick Order] ' . $message);
    }

    private function send_admin_notification($order) {
        $to = get_option('admin_email');
        $subject = 'طلب سريع جديد #' . $order->get_id();
        $country = $order->get_meta('_wcqo_country');
        
        $message = "تم استلام طلب سريع جديد\n\n";
        $message .= "رقم الطلب: #" . $order->get_id() . "\n";
        $message .= "الاسم: " . $order->get_billing_first_name() . "\n";
        $message .= "الهاتف: " . $order->get_billing_phone() . "\n";
        $message .= "البلد: " . $country . "\n";
        $message .= "المدينة: " . $order->get_billing_city() . "\n";
        $message .= "العنوان: " . $order->get_billing_address_1() . "\n";
        $message .= "الكمية: " . $order->get_meta('_wcqo_quantity') . "\n";
        $message .= "الخصم: " . $order->get_meta('_wcqo_discount_amount') . "\n\n";
        $message .= "رابط الطلب: " . admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        
        wp_mail($to, $subject, $message);
    }
    
    private function get_quick_orders() {
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => 20,
            'meta_key' => '_wcqo_quick_order',
            'meta_value' => 'yes',
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        return get_posts($args);
    }
    
    private function get_quick_orders_count() {
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => -1,
            'meta_key' => '_wcqo_quick_order',
            'meta_value' => 'yes',
            'fields' => 'ids'
        );
        
        $orders = get_posts($args);
        return count($orders);
    }
    
    private function get_today_orders_count() {
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => -1,
            'meta_key' => '_wcqo_quick_order',
            'meta_value' => 'yes',
            'date_query' => array(
                array(
                    'after' => 'today',
                    'inclusive' => true,
                ),
            ),
            'fields' => 'ids'
        );
        
        $orders = get_posts($args);
        return count($orders);
    }
    
    private function hex2rgb($hex) {
        $hex = str_replace('#', '', $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "$r, $g, $b";
    }
    
    /**
     * تعديل عرض إجمالي الطلب في صفحة Thank You
     */
    public function modify_order_totals_display($total_rows, $order, $tax_display) {
        // التحقق من أن الطلب من Quick Order Form
        if ($order->get_meta('_wcqo_quick_order') === 'yes') {
            $display_total = $order->get_meta('_wcqo_display_total');
            $shipping_deducted = $order->get_meta('_wcqo_shipping_deducted');
            
            if ($display_total && $display_total > 0) {
                // تعديل عرض الإجمالي ليظهر السعر الذي رآه العميل
                if (isset($total_rows['order_total'])) {
                    $total_rows['order_total']['value'] = '<strong>' . wc_price($display_total, array('currency' => $order->get_currency())) . '</strong>';
                }
                
                // إضافة سطر للخصم إذا كان موجوداً
                $settings = $this->settings();
                $active_country = $order->get_meta('_wcqo_country');
                $discount_amount = $order->get_meta('_wcqo_discount_amount');
                $discount_type = $order->get_meta('_wcqo_discount_type');
                
                if ($discount_amount > 0) {
                    // حساب الخصم الفعلي
                    $discount_text = '';
                    if ($discount_type === 'percentage') {
                        $discount_text = '(' . $discount_amount . '%)';
                    }
                    
                    // إضافة سطر الخصم قبل الإجمالي
                    $new_rows = array();
                    foreach ($total_rows as $key => $row) {
                        if ($key === 'order_total') {
                            $new_rows['discount'] = array(
                                'label' => __('خصم:', 'wc-quick-order') . ' ' . $discount_text,
                                'value' => '<span style="color: #28a745;">-' . wc_price($order->get_meta('_wcqo_discount_amount'), array('currency' => $order->get_currency())) . '</span>'
                            );
                        }
                        $new_rows[$key] = $row;
                    }
                    $total_rows = $new_rows;
                }
            }
        }
        
        return $total_rows;
    }
    
    /**
     * تعديل عرض إجمالي السطر في صفحة Thank You
     */
    public function modify_line_subtotal_display($formatted_subtotal, $item, $order) {
        // التحقق من أن الطلب من Quick Order Form
        if ($order->get_meta('_wcqo_quick_order') === 'yes') {
            $display_total = $order->get_meta('_wcqo_display_total');
            $shipping_deducted = $order->get_meta('_wcqo_shipping_deducted');
            
            // إذا كان هناك خصم شحن، نعدل عرض السعر
            if ($shipping_deducted > 0 && $display_total > 0) {
                // نحسب السعر الأصلي للمنتج بدون خصم الشحن
                $quantity = $item->get_quantity();
                $product_display_total = $display_total; // السعر الذي رآه العميل
                
                return wc_price($product_display_total, array('currency' => $order->get_currency()));
            }
        }
        
        return $formatted_subtotal;
    }
}
