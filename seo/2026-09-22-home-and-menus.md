# Home page and menus rebuild (2026-09-22)

Request: fix the menus, rebuild the whole home page, base "best sellers" on the
last 30 days, and never show out-of-stock products.

## What was wrong

- Three identical 14-item menus (40 "Primary", 295 "Secondary", 296 "Main");
  every Flatsome location (primary, primary_mobile, footer, top_bar_nav)
  pointed at menu 40, so the same list rendered in the header, the mobile
  drawer and the footer.
- Menu links `/?orderby=date` ("عروض اليوم") and `/?orderby=popularity`
  ("الأكثر مبيعاً") just reloaded the home page. `/return-policy/` and
  `/privacy-policy/` were 404: the legal pages live under `/wpautoterms/`.
  The same 404 links sat in the footer widget and in two home sections.
- Home "الأكثر مبيعاً" used Flatsome `orderby="sales"` (all-time totals).
- Slide 2 promised "خصومات على منتجات مختارة" and a banner promised
  "خصومات تصل إلى 50%" while 0 products were on sale.
- Out-of-stock cards on the home page could not be reproduced server-side:
  every `[ux_products]` already carried `out_of_stock="exclude"` and all
  rendered cards were in stock. Most likely a browser/page cache or a stock
  change between visits; the rebuild keeps the exclusion on every grid.

## What changed

### hayak-core 2.5.0: `includes/class-hayak-storefront.php`

- `[hayak_best_sellers days="30" number="8"]`: product ids by units sold in
  the last N days (wc_order_product_lookup, orders processing/completed/
  on-hold), published and `stock_status = instock` only, topped up from
  all-time `total_sales` when the window is thin; cached 1 h, flushed on
  any order status change. Renders through `[ux_products ids=...]` with
  `out_of_stock="exclude"`.
- Product tag `offers` ("عروض وباقات"): created on init (after WooCommerce
  registers its taxonomies), applied on every product save when the title
  starts with "عرض" or contains "+", removed when it no longer does.
  Backfilled with one SQL insert: 312 published products (259 in stock).

### Menus (built once through a temporary job in the plugin, then removed)

- Header menu 306 "القائمة الرئيسية": الرئيسية | الأقسام (/shop/, dropdown of
  the 7 categories) | كل المنتجات | عروض وباقات (/product-tag/offers/) |
  الأكثر مبيعاً (/best-sellers/) | تواصل معنا. Locations primary and
  primary_mobile.
- Footer menu 307 "قائمة الفوتر": shipping, return, privacy, terms
  (`/wpautoterms/...` post-type items), track order, contact. Location
  footer; top_bar_nav cleared.
- Menus 40, 295, 296 deleted (no Elementor or widget referenced them).
- New page 59148 `/best-sellers/` with `[hayak_best_sellers number="24"]`.

### Home page 39590

Single hero banner (one H1) → trust strip → category grid →
"الأكثر مبيعاً هذا الشهر" (`[hayak_best_sellers]`, link to /best-sellers/) →
"عروض وباقات" (`[ux_products tags="offers"]`, link to the tag) → honest
banner (free delivery + cash on delivery + return policy link) → four
category rows (home-kitchen, health-beauty, electronics, car; 4 newest each)
→ "وصل حديثاً" → why section → SEO text → AI chat section. All policy links
point at `/wpautoterms/...`. Flatsome's `ux_products` takes `products=`,
not `number=`, for the count.

### Theme polish 1.3.0

Footer widget 21 links: shop, offers tag, shipping, return, privacy, terms.

## Verified (server-side render)

Home: 40 cards, all in stock, 0 out-of-stock badges, 1 H1, no imgur, no
404 policy links. /best-sellers/: 24 cards in stock. /product-tag/offers/,
/shop/, /product-category/home-kitchen/: all cards in stock. Header,
mobile and footer navigation render the new menus.

## Left for later

- Privacy policy (17), terms (13) and return policy (36164) are English
  auto-terms templates; worth an Arabic rewrite.
- AI chatbot tuning deferred by the owner.
