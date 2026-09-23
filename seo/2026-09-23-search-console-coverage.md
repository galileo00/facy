# Search Console "Page indexing" report: root causes and fixes (2026-09-23)

Input: the Coverage export of 2026-09-23 (counts only).

| Reason | Source | Pages |
|---|---|---|
| Alternate page with proper canonical tag | Website | 841 |
| Not found (404) | Website | 97 |
| Page with redirect | Website | 71 |
| Excluded by 'noindex' tag | Website | 46 |
| Blocked due to other 4xx issue | Website | 1 |
| Crawled - currently not indexed | Google systems | 208 |
| Discovered - currently not indexed | Google systems | 112 |

The export has no URL lists, so the URLs were rebuilt from the site and
checked one by one with Google's own URL Inspection API through the Site Kit
connection (temporary table `hayak_gsc_url`, ~1,900 inspections), plus Search
Analytics by page for the last 95 days. Arabic URLs must be inspected with
upper-case percent-encoding; WordPress prints lower-case, which the API reports
as "URL is unknown to Google".

## Root causes found

1. **The sitemap was never submitted.** Search Console held
   `sitemap_index.xm` (typo, error since 2025-07-26) plus the first page of
   the product, page and post sitemaps. ~2,200 products, the category and
   policy pages were never in a submitted sitemap.
2. **The sitemap index was stale.** Rank Math's file cache only deletes files
   it has recorded; 11 of 14 cache files, the index among them, were not
   recorded, so from 18 Sep the index listed 4 of 13 product sitemaps, no
   product-category sitemap, and demo sitemaps; cached product sitemaps still
   listed renamed products under their old slugs ("Page with redirect").
3. **A second product grid on the Shop page.** Page 654 carried
   `[products paginate="true"]` above WooCommerce's own listing, so every
   /shop/page/N/ linked to every ?product-page=M. Google had some of these
   indexed and the rest as alternates or "crawled, not indexed".
4. **Wishlist action links were crawled.** YITH prints
   `?add_to_wishlist=ID&_wpnonce=...` on every card; all 631 stored wishlists
   (25 Aug–23 Sep) were anonymous with one item: crawlers.
5. **Removed URLs had no destination.** 99 deleted products (59 still indexed,
   24 with impressions), 20 child categories removed by the flattening (16
   nested URLs still indexed), older categories, /shop-2/, /return-policy/,
   /privacy-policy/, and ~100 product links shared elsewhere with one or two
   Arabic letters corrupted.
6. **Low-value pages in the sitemaps:** 8 Flatsome demo portfolio items and 8
   demo blog posts; the offers tag archive (linked from the header) was noindex.
7. **Duplicate WooCommerce pages.** /cart/, /checkout/ and /my-account/
   (pages 655–657) are published copies; WooCommerce uses /cart-2/,
   /checkout-2/ and /my-account-2/. /cart/ was indexed (8 impressions).
8. **Flatsome and Astra demo content was published.** 80 demo pages
   (/elements/*, /demos/*, /images-*, /test/, /sample-page/, the old
   /shop-2/ renamed old-shop-archive with 280 impressions, /home/,
   /my-account-2/wishlist/) sat in the page sitemap; the front page itself
   was a child of /demos/shop-demos/.
9. **Ad landing pages were indexable.** 456 Elementor Canvas order-form pages
   (and 12 funnel posts) were in the sitemap with no robots choice; 12 of
   them ever earned a search click.
10. **Every product page printed its text twice.** The Taager importer copies
    the whole description into the short description (2,121 byte-identical,
    ~300 an older copy, some with specs that no longer match); Flatsome prints
    both. 767 descriptions also carried reseller notes ("زوايا تسويقية",
    "أفكار المحتوى", runs of "زاوية ..." lines) and tatweel dividers.
11. **Template links to redirecting or empty URLs.** The blog sidebar's
    Archives widget linked every month to a date archive Rank Math redirects
    home; the shop sidebar listed the empty misc-products category; seven
    empty demo terms were live.
12. **Page cache.** The host's nginx cache keeps each page per User-Agent for a
   while; a browser type that already had a copy keeps seeing the old page.
   This is the likely reason an out-of-stock product was seen on the home page
   on 2026-09-22 while the server rendered only in-stock products.
13. **Three renamed products lost their old slugs.** Their titles and slugs
    were changed directly in the database, so WordPress never recorded the old
    URL that Google and shared links still use.

What the data rules out: in-stock products are not the problem (the inspected
sample is overwhelmingly "Submitted and indexed"); out-of-stock products are
indexed and earn clicks (200 of 244 have impressions, 253 clicks in 95 days),
so they stay live.

## Fixes (all live on 2026-09-23)

### Code (hayak-core 2.7.0)

- `class-hayak-url-recovery.php`: one redirect authority inside WordPress's
  own 404 guess (`pre_redirect_guess_404_permalink`), so it only ever sees
  real 404s and never shadows a live page.
  - A stored map of retired paths (256 rules: `seo/data/2026-09-23-redirect-map.json`)
    gives each one a 301 to its equivalent or a 410.
  - A product that is trashed or deleted records its own rule (same SKU, then
    same in-stock title, then its category), and rules that pointed at it are
    re-pointed, so no chains form. The Taager sync's out-of-stock trashing now
    leaves a redirect instead of a 404.
  - A mangled product link is matched to the one product at most three
    letters away (never across different numbers or Latin model names, never
    to a product that is drafted or trashed), including against every slug a
    product had before. Measured on the 97 mangled URLs in the export: 78
    recover, 0 onto a different product.
  - /page/N past the end of an archive goes to page 1; `?product-page=N`
    collapses into the real archive page.
- `class-hayak-seo.php`: sitemaps are built on request (Rank Math's file
  cache is off); Canvas landing pages published without a robots choice get
  noindex, follow. The robots.txt wishlist Disallow was dropped: the source of
  those URLs is gone, and blocking them would only hide their canonical.
- `class-hayak-product-text.php`: inside every product save, reseller notes
  and dividers are removed, and a short description longer than a summary is
  replaced by the opening lines of the current description (never emptied,
  because the TikTok catalogue sends it). The old text is kept in
  `_hayak_original_short_description`; a daily sweep catches writes that
  bypass WooCommerce.

### Data and settings

- Redirect map loaded: deleted products, all pre-flattening category URLs
  (including /product-category/uncategorized/ and two only Google still had),
  /shop-2/, the policy aliases, /cart/ /checkout/ /my-account/ to the live
  WooCommerce pages, demo pages with impressions to their real equivalents,
  every other demo page, post, portfolio item and term to 410.
- 80 demo and orphan pages drafted; the front page moved to the top level.
- YITH WooCommerce Wishlist deactivated (not deleted): no customer had ever
  used it, it had no wishlist page, and its plain links fed crawlers.
- 456 Canvas landing pages and funnel posts set to noindex; the 12 with
  clicks set to index explicitly.
- Blog sidebar Archives widget removed; shop category widget hides empty
  categories; seven empty demo terms deleted.
- The hand-set canonical that pointed in-stock product 39997 at its hidden
  out-of-stock twin 39981 now points the other way.
- `_wp_old_slug` restored for products 46151, 42957 and 40552.
- Rank Math: product tags indexable (the offers tag), portfolio sitemap off.
