# Merchant Center and catalogue pass 6 (2026-09-24)

Input: fresh Merchant Center statuses fetched through Google for WooCommerce
(GLA 3.9.4) on 24 Sep 2026, 12:24 UTC, and the live catalogue (2,152 in-stock
published products).

| Disapproved (in stock) | Items | Cause found |
|---|---|---|
| Promotional overlay on image | 63 | Taager's images are ad creatives: price, "free delivery", warranty seal burned in |
| Personalized advertising: personal hardships | 44 (42 partially approved) | health products; restricts personalized ads only |
| Inappropriate image (Demand Gen surfaces) | 43 (39 partially approved) | creatives, body-focused shots |
| Guns and Parts | 15 | tools Arabic calls "مسدس" (heat, nail, massage, foam, tag guns), a gaming light gun, a powder-actuated nailer, the word ذخيرة |
| Inappropriate title | 6 | sensational or ambiguous names (الإنقاذ, حمل, عصا تدليك) |
| Product page unavailable | 3 | transient: all three answer 200 in about 1 s to Storebot and Googlebot |
| Image too small | 1 | 56627: a 38 x 50 px image, no gallery |
| Restricted adult / Sexual interests | 1 | 54081 named "عصا تدليك" |
| Vehicles | 1 | 59213 titled only "انتنا" |

Also found: 20 failed `gla/jobs/update_products` runs for product 38801.

## Root causes and fixes

### 1. Two Taager products on one store product (Taager sync plugin, live)

38801's Taager SKU is `SA03010100َ991` (with a fatha). A second Taager
product, 16363 `SA03010100991`, has the same SKU without it. The sync matched
SKUs with `meta_value = %s`, and the table collation ignores diacritics and
case, so both Taager products landed on 38801: every run set it in stock at
268 SAR, then out of stock at 253 SAR. GLA scheduled an update while it was in
stock and the job failed once it was out ("Job item not found"), and the
in-stock product 16363 was never imported.

Fix in `hayak-taager-sync/sync.php` (live only; the plugin is not in this
repo): the SKU lookup is byte-exact, `BINARY m.meta_value = %s`. A scan of the
sync report (3,597 SKUs) found no other collision. 38801 is now only out of
stock, so it is trashed after its 10 days; WooCommerce's own SKU uniqueness
check has the same collation, so 16363 can be imported once 38801 is in the
trash. The scrambled title of 38801 was also restored.

### 2. The feed picks its own main image (hayak-core 2.8.0, `class-hayak-merchant-feed.php`)

The storefront keeps Taager's creative as the featured image. What Merchant
Center receives is set through GLA's `woocommerce_gla_product_attribute_values`
filter:

- `_hayak_feed_image`: the attachment sent as the main image;
  `_hayak_feed_rejected`: attachments never sent as main or additional image.
- A daily review (`hayak_core_merchant_feed_review`) asks GLA to fetch
  statuses, and two hours later reads Google's verdict. A product still
  disapproved for overlay, too small or single colour three days after its
  image was sent has that image rejected and the next one sent (largest first,
  at least 250 px). A product with nothing left is listed in
  `hayak_core_merchant_feed_report`. Broken or unreachable links never reject
  an image: that is a server problem, not the picture.

Seeding: every image of the 123 flagged products was put on a numbered
contact sheet and judged by the store's AI Engine vision. Each pick was then
verified on the full image with a strict overlay prompt. The Gemini free-tier
daily quota ran out after about two thirds of the products.

| Result | Products |
|---|---|
| Verified clean image sent | 32 |
| Google-rejected image dropped, next image sent (Google judges) | 43 |
| Every image carries promotional text: needs a real photo | 9: 38968, 41637, 44021, 47306, 57372, 57605, 57863, 57929, 58597 |
| Unchanged (restriction only on Demand Gen surfaces with no clean alternative, or no image issue) | 39 |

A follow-up on 25 Sep runs vision on the 43 once the quota resets.

### 3. Tool names in the feed

Arabic names many tools "مسدس". The feed title and description (GLA 3.9.4
builds the Merchant API payload with `WCProductInputAdapter`, so the title is
set from the product and the description through
`woocommerce_gla_product_attribute_value_description`) name the tool for what
it is:

- مسدس حرارة → منفاخ هواء ساخن
- مسدس مسامير → جهاز تثبيت مسامير
- مسدس تدليك → جهاز تدليك
- مسدس سيليكون → جهاز ضخ السيليكون
- مسدس شمع → جهاز لصق حراري
- مسدس رش/فوم → بخاخ رش/فوم
- مسدس تعديل المقاسات → جهاز تثبيت البطاقات

Definite forms and clitics are kept (بالمسدس → بمنفاخ الهواء الساخن). In a
tool's own description, the bare word, "بشكل مسدس" and the trigger are
translated as well. The storefront keeps the words shoppers search with.

Not reworded, on purpose:

- A toy (لعبة، أطفال) keeps its words.
- A product whose text mentions powder loads (بارود، طلقات، ذخيرة) keeps its
  words.
- The two genuinely weapon-like products are out of the feed with GLA's own
  `dont-sync-and-show`:
  - 52359, a gaming light gun "بتصميم SMG يعطي شعور السلاح الواقعي";
  - 55422, a nailer that "يطرد بقايا البارود". It had also been filed under
    الصحة والجمال with SEO meta written for a massage gun; both were
    corrected.

After this, 11 of the 15 flagged products reach Google with no gun word. The
other 4 are the toy (51462), the two excluded products, and 54034, whose
store title ("باور بنك الذخيرة") was renamed.

### 4. New imports land in a category (`class-hayak-product-category.php`)

The importer leaves every product in "غير مصنّف". A Taager SKU carries
Taager's category path, and the catalogue shows where each path belongs. So a
product whose only category is the default one is filed by its SKU: into the
category that at least 70% of 8 or more products sharing the first four path
characters are in. The map is learnt daily and currently covers 20 paths
(0101-0106 and 010S electronics, 0301-0305 home and kitchen, 0401-0405 health
and beauty, 0501 car, 0502 sports, 0503 entertainment, 0504 tools). It runs
whenever categories are set, plus a daily sweep. The first sweep filed 12
products, the 7 published ones among them.

### 5. Titles and images guarded at save

- `Hayak_Product_Text` strips the importer's leading "• " (and other list
  marks, emoji variation selectors, bidi marks) and doubled spaces from titles
  on every save. 9 titles were fixed by the first sweep.
- `Hayak_Product_Guard` treats a featured image under 100 px as no image: the
  product is held as a draft until a real one arrives. 56627 (38 x 50 px) is
  held.

## Catalogue text (112 products)

Drafted in batches from each product's own text, then checked by an
independent reviewer (lengths, facts supported by the description, no cure
claims, bundle prefixes kept); 18 were corrected by the checker. Backups:
`6F27TMRe_hayak_pass6_posts` (114 rows) and `6F27TMRe_hayak_pass6_meta`.
Data: `seo/data/2026-09-24-catalogue-pass-6-text.json`.

- **Policy titles.** Each flagged title was renamed for what the product is:
  - 48624 بكج لاصقات حرارية عشبية لأسفل البطن
  - 53284 لصقات نوم بالمغنيسيوم واللافندر
  - 53931 مرهم عشبي للرجال من ساوث مون 20 جم (was a prostate claim)
  - 54263 and 54081: massagers named by body area
  - 57125 حمالة أطفال لحديثي الولادة
  - 57729 حامل جوال وتابلت دوار
  - 59213 هوائي سيارة مغناطيسي لأجهزة الراديو المحمولة
  - 54034 power bank named by its specs
  - 58450 بخاخ فوم لغسيل السيارات
  - 48659 مقبض بخاخ لعلب الصبغ بقفل أمان
- **20 new imports** got Rank Math title, description and keyword.
- **66 short titles** (كواية, طاولة, هواية, "عرض الجوال", …) became descriptive
  titles with the product type and its main spec. **7 English titles** (and one mixed) became
  Arabic.
- **9 thin descriptions** (under 200 characters) rewritten; 3 over-long
  titles shortened.
- Categories: 59213 → السيارة, 48659 and 55422 → أدوات وإصلاحات.

## Verified live

- The feed filter for 48801 sends the clean packshot 48797. For 54007 the
  rejected creative is dropped. 52105 is sent as "منفاخ هواء ساخن 2000 واط".
- 131 `update_products` jobs completed in the hour after the changes, 0
  failed; 105 products were queued for resync.
- The live `hayak-core.php` and module files match this branch.
- The one-shot deploy file, the contact sheets and the temporary options are
  gone.

## For the owner

- **9 products need a real photo.** Every image they have is a creative with
  text. Taager's product page often has a plain photo. Upload it as the
  featured or a gallery image, and the next daily review uses it.
- **If a tool is still flagged as Guns and Parts after Google re-reviews
  (2–3 days)**, use "Request review" on that item in Merchant Center. The
  products are 48376, 50953, 51753, 52105, 52675, 52878, 53804, 53995,
  57339, 58450 and 48659. Their feed text now names the tool; the remaining
  signal is the pistol-grip shape in the photo, which a human reviewer
  clears.
- **"Personal hardships" is not a disapproval for Shopping.** It stops
  personalized (remarketing) ads for health products, which is Google's rule
  for the product type itself. Wording cannot change it without misdescribing
  the product. The misleading medical claims in titles were removed.
- **56627** (body-shaping shorts) stays a draft until it has a real image.

## Follow-up, 25 Sep 2026

Once the vision quota reset, every gallery image of the 43 "next image" products was judged. The 5 products with a single image were not.

- 31 now send a verified clean image.
- 7 have promotional text on every image (53317, 53931, 54007, 54263, 56679, 56851, 57869). Every image of these is rejected, so the daily review will list them as needing a real photo.
- Together with the 5 single-image products (53794, 54339, 56445, 56471, 56627) and the 9 above, 21 products need a real photo.

All 38 were saved again so Google for WooCommerce resends them.

## Permanently out of Merchant Center (owner's decision, 25 Sep 2026)

Four SKUs are listed in option `hayak_core_merchant_feed_never`:

- SA010403LMX2099 (41395, "شبيه الأيفون 17 برو ماكس مينى")
- SA12 (53755, "ضمان إضافي 12 شهر")
- SA06 (53757, "ضمان إضافي 6 شهور"), added the same day
- SA18 (53753, "ضمان إضافي 18 شهر"), added the same day

On every save, `Hayak_Merchant_Feed::keep_out` sets GLA's `dont-sync-and-show` visibility on any product carrying one of these SKUs. That includes a later Taager re-import under a new product ID. Both products were saved, and GLA queued their deletion from Merchant Center. To keep another SKU out for good, add it to the option.

## Follow-up, 26 Sep 2026: the Google Ads product report

The owner exported the PMax product report for 1–26 Sep (2,684 rows). A read-only check found four things; the links themselves are not changing.

- **Links.** Every published product's slug matches the four catalogue backups from 18–21 Sep. Nothing on the site rewrites a slug. The URL-recovery redirects fire on 404s only. Google for WooCommerce sends the plain permalink, and title and price in the report match the site for 2,120 of 2,161 `gla_` items.
- **"Product page unavailable" (15 products, 16 rows).** Among them are the laptop 52867 (the top item: 315 clicks, 5 conversions) and 43002. Google's shopping crawler failed to load the pages. The pages are fine:
  - they are published and in stock;
  - there is no noindex or redirect on them;
  - Wordfence blocked no Google IP in 31 days;
  - Search Console fetched 10 of them successfully on 25 Sep.

  13 of the 15 appeared in the 26 Sep status refresh. The failure left no trace in the database, so the reply Google got can only be read in the host's logs.
- **A second product source.** "Found by Google" had built 452 items from the product pages: ids are lowercased SKUs, titles are the page titles. 274 of them duplicated `gla_` items. These items ignored the store's exclusions: SA18 was shown as eligible, and deleted products appeared under another product's title. The owner hid them in Merchant Center.
- **Legacy English copies.** 103 products created before the 17 Sep Merchant API cutover still had a copy in the source "Google for WooCommerce (en/SA)". Google for WooCommerce can no longer update or delete these copies, so they kept old prices (gla_12979 at 918 against 325). gla_41395 was among them. The owner deleted that source. The Arabic source (2,135 products) is the only one left.
- **Edits that bypass Google for WooCommerce.** Google for WooCommerce only reacts to a WooCommerce product save. Filing a category with `wp_set_object_terms`, or editing through `wp_update_post`, never reached Merchant Center. 7 products had edits waiting.

Done:

- The 15 unavailable products and the 7 with pending edits were sent again. Their sync hash was cleared so the unchanged-data skip could not drop them. All 22 were sent on 26 Sep at 19:45–19:46 UTC, with no errors.
- hayak-core 2.8.1 makes both fixes permanent:
  - The daily Merchant feed review re-sends each published, in-stock product still flagged `landing_page_error`, once per 3 days. It records `_hayak_feed_resent_at` and forces Google for WooCommerce past its unchanged-data skip for 6 hours (`woocommerce_gla_force_product_resync`).
  - The category module asks Google for WooCommerce to send a product it files.
- The Cowork daily task:
  - saves products only through WooCommerce;
  - no longer asks for a plain re-save on "Product page unavailable";
  - lists a product still unavailable after a re-send, so the owner can ask the host for the server's reply to Storebot-Google.

## Check, 29 Sep 2026

**Merchant Center.** Disapproved items compared with 24 Sep:

| Issue | 24 Sep | 28 Sep |
|---|---|---|
| Promotional overlay | 63 | 17 |
| Guns and Parts | 15 | 0 |
| Inappropriate title | 6 | 0 |
| Product page unavailable | 3 | 1 |
| Image too small, Vehicles | 1 each | 0 |
| Inappropriate image | 43 | 38 |
| Personal hardships | 44 | 52 (the new health imports) |

The 17 overlay items are the products whose every image carries text; they are left to the Cowork task.

**New imports missing from Merchant Center.** 5 imports of 28 Sep were never sent: 59737, 59756, 59757, 59764 and 59765.

- The importer saves a product several times before it is ready, and each early Google for WooCommerce job fails ("Job item not found").
- After three failures for the same product within two hours, Google for WooCommerce stops that product's jobs, so the save that published it was dropped.
- The 5 were saved again. The daily review now also sends every published, in-stock product that has no Merchant Center id and is more than three hours old.

**Redirects.** Nothing has been trashed since 17 Sep. The oldest out-of-stock product (48063, out since 18 Sep 18:39) passed its 10 days after the last Taager run (28 Sep 10:47), so the first trashing, and the first redirect rules, come with the next run.

**38801.** Still out of stock since 24 Sep, so it is trashed from 4 Oct. Taager 16363 (`SA03010100991`) is imported after that.

## Categories follow Taager, 2 Oct 2026

The owner's rule: a product has one top-level category, plus at most one subcategory of it, and the category follows Taager's own classification, down to the second level only (electronics > phones, never phones > Nokia). Earlier filing by hand and by the Cowork task had left products under two top-level categories, or under a subcategory and an unrelated second top-level one, and a run on 2 Oct had removed subcategories it should not have touched.

**What the SKU encodes.** The owner's Taager catalogue export (3,469 products, 178 categories) joined to the store by Taager product id (from the Taager URL the sync plugin records in `hts_report`) matched 2,292 of 2,335 products. A SKU is `SA` + Taager's main category (01 electronics, 02 fashion, 03 home, 04 health and beauty, 05 "entertainment", which at Taager holds cars, sport, toys, tools, camping, kids and gifts) + a grouping code + the supplier's code. The main category disagrees with the catalogue for 35 products (1.5%), always the catalogue being the better guide (a breast pump filed `SA05` sits in Taager's medical devices). The grouping code does not follow Taager's subcategories: `SA0301` holds cleaning, storage and household tools alike. Under 05 it is only a tendency: 0501 cars 81%, 0502 sport 78%, 0503 toys 90%, 0504 tools 79%, 0505 kids 65%, 0510 camping 72%; 0506, 0507 and 0509 are mixed. So the subcategory can only come from the catalogue.

**Taager's own noise.** Taager category 739 "بلسم" (conditioner) holds 26 products of which one is a conditioner; the rest are body shapers, light-therapy devices and nail dryers. It maps to health and beauty with no subcategory. Category 348 "cameras" is mostly surveillance cameras, with a few photography cameras and a USB microscope; it maps to the store's security cameras. 776 "lights and flashes" is floodlights and solar lamps, and the store keeps floodlights under home, so it maps there.

**hayak-core 2.10.0.** `Hayak_Product_Category` is now the only writer of product categories:

- The catalogue ships with the plugin (`data/taager-catalog.json`, product id to category id, with the tree); `hayak_taager_catalog` holds additions made since. A product's Taager id and category are stored on it (`_hayak_taager_id`, `_hayak_taager_category`).
- `LEAVES` maps all 178 Taager categories to a store root and, where the store has one, a subcategory. Taager's offer, exclusive, discount and Islamic categories say nothing about the product, so they keep the product's root (its subcategory's parent, its single root, or the root its SKU group points to).
- Title words refine inside a root where Taager does not separate (fans, coolers and heaters all sit in Taager's "air care"): vacuums, coolers, fans, heaters, slicers, floodlights, blenders, camping, tablets, phones, security cameras, chargers, massagers, dash cams, drills. Phones and tablets only match at the start of the title, so a phone holder stays an accessory.
- A subcategory the product already has under the right root is kept. `_hayak_cat_lock` keeps a product's categories by hand.
- 14 subcategories were created on the first sweep: kitchen tools (325), kitchen appliances (326), cleaning tools (327), storage (328), furniture (329), mobile accessories (330), chargers and power banks (331), skin care (332), styling tools (333), hair care (334), medical products (335), car care (336), power tools (337), hand tools (338).
- A product Taager has not seen (no Taager id, not in the catalogue, or in an offer category with two roots and nothing to decide) is left where it is and marked `_hayak_cat_unresolved` for the daily task, which reads the Taager page and writes `_hayak_taager_category`; the plugin files it on the save.
- Rank Math's primary category follows the filing, and Google for WooCommerce is asked to send the product so Merchant Center sees the new product type.
- The migration runs in batches of 100 a minute when `MAP_VERSION` changes; the daily sweep re-files products saved since the last run and anything breaking the one-root rule. All term relationships were backed up first (`hayak_termrel_backup_20261002`).

**The two Cowork tasks.** The daily sweep (`seo/cowork-daily-task.md`) keeps Merchant Center, images, held drafts and system health; on categories it only reads the sweep result, reports rule breakers, and resolves unresolved products through the Taager page. A new daily SEO task (`seo/cowork-seo-task.md`) owns product titles, descriptions and Rank Math fields, and the category pages' descriptions and Rank Math term meta, including the 14 new subcategories. Neither task assigns categories, touches the other's state option, or runs at the same time.

**Result, 2 Oct 20:30 UTC.** Two sweeps (the second after the "بلسم" and SKU-group refinements) filed all 2,335 products:

| | Before | After |
|---|---|---|
| Exactly one top-level category | 2,286 | 2,335 |
| Two or more top-level categories | 48 | 0 |
| In "غير مصنّف" | 1 | 0 |
| With a subcategory | 417 | 1,345 |
| Two subcategories | 7 | 0 |

303 products changed top-level category, the largest moves being electronics to home (37, floodlights and solar lamps), tools to home (28), sport to home (14) and sport to health (13), home to toys (16, kids' products), and home to car (12). Health and beauty is the largest root after home (605), and its five new subcategories hold 364 products. 26 products are unresolved, all "no taager id": they are not in the sync plugin's report (old DENX imports and the "المفتاح الذكي" offer bundles 55708–55720), every one already in a single root and most in a subcategory, so they wait for the daily task's Taager-page lookup. Rank Math's primary category was corrected on 4 products it no longer matched.

Known edges, left as Taager has them: a USB microscope sits in Taager's "cameras" and so in security cameras; one of two "دريل كفرات" listings sits in Taager's car accessories, the other in power tools; two "مساحات سيارة" offers kept the roots they had (tools, toys) because Taager files them under offers. `_hayak_cat_lock` keeps any of these as the owner sets them.
