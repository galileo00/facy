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
8. **Page cache.** The host's nginx cache keeps each page per User-Agent for a
   while; a browser type that already had a copy keeps seeing the old page.
   This is the likely reason an out-of-stock product was seen on the home page
   on 2026-09-22 while the server rendered only in-stock products.

What the data rules out: in-stock products are not the problem (the inspected
sample is overwhelmingly "Submitted and indexed"); out-of-stock products are
indexed and earn clicks (200 of 244 have impressions, 253 clicks in 95 days),
so they stay live.
