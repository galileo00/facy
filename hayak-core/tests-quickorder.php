<?php
define('ABSPATH', true);
// Minimal stubs so the class file can be loaded for its two pure methods.
foreach (['add_action','add_filter','register_activation_hook','load_plugin_textdomain'] as $f) {
    if (!function_exists($f)) eval("function $f(){}");
}
$src = file_get_contents(__DIR__.'/hayak-quick-order.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
eval($src);

// The REAL settings, copied verbatim from the live site's wcqo_settings option.
$settings = ['quantity_options' => [
  'option1' => ['enabled'=>'yes','quantity'=>1,'label'=>'اشتري حبة','discount_text'=>'','discount_amount'=>0,'discount_type'=>'fixed'],
  'option2' => ['enabled'=>'yes','quantity'=>2,'label'=>'اشتري حبتين و وفر 50 ريال','discount_text'=>'خصم 50 ريال','discount_amount'=>50,'discount_type'=>'fixed'],
  'option3' => ['enabled'=>'no','quantity'=>3,'label'=>'Buy 3 and save 100 SAR','discount_text'=>'100 SAR OFF','discount_amount'=>100,'discount_type'=>'fixed'],
]];
$UNIT = 345.0;   // product 12979

$fails = 0;
function ok($c,$m,$got=null){ global $fails; if(!$c){$fails++; echo "  FAIL  $m".($got!==null?"  (got: ".var_export($got,true).")":"")."\n";} else echo "  PASS  $m\n"; }
function price($settings,$key,$unit){
    $o = Hayak_Quick_Order::resolve_offer($settings,$key);
    return $o ? array_merge($o, Hayak_Quick_Order::price_offer($o,$unit)) : null;
}

echo "=== A. Normal offers price exactly as the storefront advertises ===\n";
$a = price($settings,'option1',$UNIT);
ok($a['quantity']===1 && $a['total']===345.0, 'حبة واحدة -> qty 1 / 345', $a);
$b = price($settings,'option2',$UNIT);
ok($b['quantity']===2 && $b['total']===640.0, 'حبتين بخصم 50 -> qty 2 / 640', $b);
ok($b['subtotal']===690.0, 'subtotal 690 so WooCommerce shows a 50 discount', $b['subtotal']);

echo "\n=== B. THE EXPLOITS IN THE ORIGINAL — all must be closed ===\n";
// The original read quantity from $_POST and the discount from settings, so a
// crafted request could combine quantity 1 with the two-pack's 50 SAR discount.
$e1 = price($settings,'option2',$UNIT);
ok($e1['quantity']===2, 'quantity can no longer be forced to 1 while keeping the 50 discount', $e1['quantity']);
ok($e1['total']===640.0, '  -> charged 640, not the exploit price 295', $e1['total']);

// The original never checked "enabled", so the disabled 3-pack's 100 SAR was claimable.
ok(price($settings,'option3',$UNIT) === null, 'disabled option3 is refused (was worth 100 SAR off)');

// Unknown / crafted keys
foreach (['option99','','../../etc','0','<script>'] as $junk) {
    ok(price($settings,$junk,$UNIT) === null, 'unknown key '.var_export($junk,true).' refused');
}

// quantity 0 / negative used to yield a FREE order
$zero = ['quantity'=>0,'discount_type'=>'fixed','discount_amount'=>50,'key'=>'x'];
$r = Hayak_Quick_Order::price_offer($zero,$UNIT);
ok($r['total'] === 295.0, 'quantity 0 is clamped to 1, never a free order', $r['total']);
$neg = ['quantity'=>-5,'discount_type'=>'fixed','discount_amount'=>0,'key'=>'x'];
ok(Hayak_Quick_Order::price_offer($neg,$UNIT)['total'] === 345.0, 'negative quantity clamped to 1');

echo "\n=== C. Discount maths cannot go negative or over-discount ===\n";
$huge = ['quantity'=>1,'discount_type'=>'fixed','discount_amount'=>9999,'key'=>'x'];
ok(Hayak_Quick_Order::price_offer($huge,$UNIT)['total'] === 0.0, 'over-large fixed discount floors at 0');
$pct = ['quantity'=>2,'discount_type'=>'percentage','discount_amount'=>10,'key'=>'x'];
ok(Hayak_Quick_Order::price_offer($pct,$UNIT)['total'] === 621.0, '10% off 690 -> 621', Hayak_Quick_Order::price_offer($pct,$UNIT)['total']);
$pct200 = ['quantity'=>1,'discount_type'=>'percentage','discount_amount'=>200,'key'=>'x'];
ok(Hayak_Quick_Order::price_offer($pct200,$UNIT)['total'] === 0.0, 'percentage clamped to 100 -> 0, never negative');
$badunit = ['quantity'=>2,'discount_type'=>'fixed','discount_amount'=>0,'key'=>'x'];
ok(Hayak_Quick_Order::price_offer($badunit,-50)['total'] === 0.0, 'negative unit price cannot create a credit');

echo "\n=== D. Shipping deduction keeps the buyer's price separate from the order total ===\n";
$ship = 28.0;
$charged = $b['total'];                       // what the buyer pays / pixel value
$recorded = max(0, round($charged - $ship,2)); // what WooCommerce stores
ok($charged===640.0 && $recorded===612.0, 'buyer 640, WooCommerce 612, pixel must use 640', [$charged,$recorded]);

echo "\n" . ($fails ? "$fails FAILED\n" : "ALL QUICK-ORDER TESTS PASSED\n");
exit($fails?1:0);
