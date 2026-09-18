<?php
define('ABSPATH', true);
foreach (['add_action','add_filter','register_activation_hook','load_plugin_textdomain'] as $f) {
    if (!function_exists($f)) eval("function $f(){}");
}
// The exact wcqo_settings array stored on the live site today (14 keys, no ux/upsell).
$LIVE = array(
  'enable_form'=>'yes','form_title'=>'اطلب من هنا','active_country'=>'SA',
  'countries'=>array('SA'=>array('name'=>'السعودية','code'=>'+966','pattern'=>'^05[0-9]{8}$','placeholder'=>'05xxxxxxxx','phone_label'=>'رقم الجوال','currency'=>'SAR','areas'=>"منطقة الرياض\r\nمنطقة مكة المكرمة")),
  'quantity_options'=>array(
     'option1'=>array('enabled'=>'yes','quantity'=>1,'label'=>'اشتري حبة','discount_text'=>'','discount_amount'=>0.0,'discount_type'=>'fixed'),
     'option2'=>array('enabled'=>'yes','quantity'=>2,'label'=>'اشتري حبتين و وفر 50 ريال','discount_text'=>'خصم 50 ريال','discount_amount'=>50.0,'discount_type'=>'fixed'),
     'option3'=>array('enabled'=>'no','quantity'=>3,'label'=>'Buy 3','discount_text'=>'','discount_amount'=>100.0,'discount_type'=>'fixed')),
  'colors'=>array('primary_color'=>'#1A2A4F','selected_bg'=>'#fff8e8','selected_border'=>'#C9A24B','button_bg'=>'#1A2A4F','button_text'=>'#FFFFFF','whatsapp_bg'=>'#25D366','discount_badge_bg'=>'#1A2A4F','discount_badge_text'=>'#FFFFFF'),
  'texts'=>array('button_text'=>'اكمال الطلب','whatsapp_text'=>'Order in WhatsApp','name_label'=>'الاسم','city_label'=>'المدينة','address_label'=>'العنوان بالتفصيل'),
  'whatsapp_enabled'=>'no','whatsapp_number'=>'','send_admin_email'=>'yes','send_customer_email'=>'yes',
  'track_gtm'=>'yes','gtm_event_name'=>'quick_order_completed','shipping_cost_to_deduct'=>28.0,
);
$OPTIONS = array('wcqo_settings' => $LIVE);
function get_option($n, $d = false) { global $OPTIONS; return array_key_exists($n,$OPTIONS) ? $OPTIONS[$n] : $d; }
function sanitize_hex_color($c) { return (is_string($c) && preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $c)) ? $c : null; }

$src = preg_replace('/^<\?php/', '', file_get_contents(__DIR__.'/hayak-quick-order.php'), 1);
eval($src);

$fails = 0;
function ok($c,$m,$got=null){ global $fails; if(!$c){$fails++; echo "  FAIL  $m".($got!==null?"  (got: ".var_export($got,true).")":"")."\n";} else echo "  PASS  $m\n"; }
function call($method, $args = array()) {
    $r = new ReflectionMethod('Hayak_Quick_Order', $method);
    $r->setAccessible(true);
    $o = (new ReflectionClass('Hayak_Quick_Order'))->newInstanceWithoutConstructor();
    return $r->invokeArgs($o, $args);
}

echo "=== A. The live option has no ux/upsell keys — the merge must supply them ===\n";
$s = call('settings');
ok(isset($s['ux']['summary_enabled']), 'ux group present after merge');
ok(isset($s['upsell']['enabled']), 'upsell group present after merge');
ok($s['form_title'] === 'اطلب من هنا', 'the owner\'s saved title survives', $s['form_title']);
ok($s['shipping_cost_to_deduct'] === 28.0, 'the saved 28 SAR shipping deduction survives', $s['shipping_cost_to_deduct']);
ok($s['texts']['button_text'] === 'اكمال الطلب', 'saved texts survive', $s['texts']['button_text']);
ok($s['colors']['primary_color'] === '#1A2A4F', 'saved colours survive', $s['colors']['primary_color']);
ok($s['quantity_options']['option3']['enabled'] === 'no', 'the disabled 3-pack stays disabled', $s['quantity_options']['option3']['enabled']);
ok(count($s['countries']) === 1, 'stored countries are NOT overwritten by the 5 defaults', count($s['countries']));

echo "\n=== B. A never-saved shop falls back to the defaults ===\n";
$OPTIONS = array();
$fresh = call('settings');
ok($fresh['texts']['address_label'] === 'العنوان الوطني', 'default address label is the national address', $fresh['texts']['address_label']);
ok($fresh['ux']['badges_enabled'] === 'yes', 'badges on by default');
$OPTIONS = array('wcqo_settings' => $LIVE);

echo "\n=== C. Badges ===\n";
$b = call('ux_badges', array(array('badges' => "الدفع عند الاستلام\nشحن مجاني\n\n  ضمان سنة  \nإرجاع مجاني خلال 14 يوم")));
ok(count($b) === 4, 'blank lines dropped, 4 badges left', count($b));
ok($b[2] === 'ضمان سنة', 'whitespace trimmed', $b[2]);
ok($b[3] === 'إرجاع مجاني خلال 14 يوم', 'the 14-day free return badge is there', $b[3]);
ok(count(call('ux_badges', array(array('badges' => str_repeat("x\n", 20))))) === 6, 'capped at 6');
ok(call('ux_badges', array(array())) === array(), 'missing key is not a crash');

echo "\n=== D. Colours reaching the stylesheet ===\n";
$c = call('safe_colors', array(array('colors' => array('primary_color' => '#1A2A4F'))));
ok($c['primary_color'] === '#1A2A4F', 'a valid hex passes through');
ok($c['button_bg'] === '#ff6b35', 'a missing colour falls back to the default', $c['button_bg']);
$evil = call('safe_colors', array(array('colors' => array('primary_color' => 'red;} body{display:none} .x{a:b'))));
ok($evil['primary_color'] === '#ff6b35', 'a CSS-breakout value is refused', $evil['primary_color']);

echo "\n=== E. Stock line only ever shows a real number ===\n";
class FakeProduct {
    public $manage, $qty;
    function __construct($m, $q) { $this->manage = $m; $this->qty = $q; }
    function managing_stock() { return $this->manage; }
    function get_stock_quantity() { return $this->qty; }
}
ok(call('stock_left', array(new FakeProduct(false, null), 15)) === null, 'stock not managed -> nothing shown');
ok(call('stock_left', array(new FakeProduct(true, null), 15)) === null, 'managed but unknown -> nothing shown');
ok(call('stock_left', array(new FakeProduct(true, 0), 15)) === null, 'zero left -> nothing shown');
ok(call('stock_left', array(new FakeProduct(true, -3), 15)) === null, 'backorder negative -> nothing shown');
ok(call('stock_left', array(new FakeProduct(true, 40), 15)) === null, 'above the threshold -> nothing shown');
ok(call('stock_left', array(new FakeProduct(true, 4), 15)) === 4, 'four left -> shows 4');
ok(call('stock_left', array(new FakeProduct(true, 15), 15)) === 15, 'exactly at the threshold -> shown');

echo "\n=== F. Upsell price ===\n";
class FakePriced { public $p; function __construct($p){$this->p=$p;} function get_price(){return $this->p;} }
ok(call('upsell_price', array(new FakePriced(330), array('discount_percent'=>0))) === 330.0, 'no discount -> full price');
ok(call('upsell_price', array(new FakePriced(330), array('discount_percent'=>10))) === 297.0, '10% off 330 -> 297');
ok(call('upsell_price', array(new FakePriced(330), array('discount_percent'=>999))) === 33.0, 'discount capped at 90%');
ok(call('upsell_price', array(new FakePriced(330), array('discount_percent'=>-50))) === 330.0, 'negative discount ignored');
ok(call('upsell_price', array(new FakePriced(99.99), array())) === 99.99, 'missing key -> full price');

echo "\n" . ($fails ? "$fails FAILURE(S)\n" : "ALL PASS\n");
exit($fails ? 1 : 0);
