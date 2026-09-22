# Merchant listings: "Missing field image" (Search Console, 2026-09-22)

## What Google saw

Search Console flagged the Product structured data on hayak.store with the
critical issue `Missing field "image"` under the Merchant listings report.

## Root cause (verified on the live site, not guessed)

Rank Math builds the Product node from the WooCommerce product: featured image
first, then the gallery (`class-product-woocommerce.php`, `get_images()`).
When a product has no `_thumbnail_id` the node simply has no `image` key.
WooCommerce's own Product schema is removed by Rank Math on every page, and no
Product node is emitted on the home, shop or category pages, so the only
affected URLs are single product pages of products with no featured image.

On 2026-09-22 the catalogue had exactly one such published product left
(55225, out of stock, created during the 09-19 00:01–06:03 import window in
which the importer created 177 products without images; those were deleted in
passes 4 and 5). It was backed up to `hayak_deleted_noimg_p5` /
`hayak_deleted_meta_p5` and deleted permanently, like the others.

## The fix: enforce the invariant, not the symptom

`hayak-core` 2.4.0 adds `Hayak_Product_Guard`
(`includes/class-hayak-product-guard.php`):

- a product saved as `publish` without a featured image is held as a draft
  (meta `_hayak_held_no_image`), inside `woocommerce_before_product_object_save`
  so REST, admin and importer saves are all covered and the product is never
  public for a moment;
- when a featured image lands on a held product it is published (queued on
  `added/updated_post_meta`, applied on `shutdown` so it never nests inside a
  WooCommerce save);
- when a published product loses its featured image (meta removed or the
  attachment deleted) it is held again;
- a daily cron sweep (`hayak_core_product_guard_sweep`) reconciles anything
  written behind WooCommerce's back; last result in
  `hayak_core_product_guard_last_sweep`;
- the product edit screen shows why a held product is a draft.

Verified live with a throwaway product: publish without image -> draft + marker;
set featured image -> publish, marker cleared; remove featured image -> draft
again. Test products deleted.

A draft is also invisible to Google for WooCommerce, so the same rule keeps
image-less items out of Merchant Center.

## What to do in Search Console

Open the Merchant listings report and press "Validate fix". The affected URLs
are either gone (404, the deleted products) or now carry a full `image` array.
