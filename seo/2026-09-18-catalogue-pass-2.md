# Catalogue pass — 18 Sep 2026 (second pass)

A full sweep of every published product: category structure, titles, descriptions,
Rank Math SEO, and the Google Merchant Center image and policy rejections.

This is the follow-up to `2026-09-18-catalogue-seo-pass.md`, which covered SEO
fields only. The catalogue grew from 685 to ~896 products between the two passes,
and the Taager sync keeps adding more while a pass runs, so counts in this
document are point-in-time.

## Result

| | Before | After |
|---|---:|---:|
| Published products | 928 | 906 |
| Product categories | 28 (8 top + 20 sub) | **7 flat** (+ an empty default) |
| Products with exactly one category | 823 | **all** |
| Products in the `Various Products` bucket | 185 | **0** |
| Products with no featured image | 33 | **0** |
| Descriptions carrying content-brief artifacts | 480 | **0** |
| Shortest product description | 0 chars | **179 chars** |
| Titles rewritten | — | **144** |
| Titles carrying a Google-policy trigger | 58 | **0** |

The Taager sync adds products continuously, so the "after" figures move: they are
the state at the end of this pass, not a frozen catalogue.

## What was wrong, and what changed

### Categories: 28 → 7, flat

The taxonomy was two levels deep with 20 sub-categories, and a 185-product bucket
called `Various Products` — an English name on an Arabic storefront, holding a
fifth of the catalogue with no other category.

Every product in a sub-category was moved up to its parent, the 20 sub-category
terms were deleted, and all 185 misc products plus 24 uncategorised and 20
double-categorised ones were classified by hand into the seven that remain:

| Category | Products |
|---|---:|
| المنزل والمطبخ | 317 |
| الإلكترونيات | 166 |
| الصحة والجمال | 153 |
| أدوات وإصلاحات | 72 |
| الترفيه والألعاب | 58 |
| السيارة | 49 |
| الرياضة واللياقة | 47 |

`Various Products` was kept but renamed `غير مصنّف`, because it is WooCommerce's
`default_product_cat` — deleting it makes WordPress recreate an "Uncategorized"
term. It is now empty, and new imports land there visibly instead of being mixed
into a real category. The site's nav menu already pointed at exactly these seven
terms, so nothing in the menu broke.

### Descriptions: content-brief leakage

Customers were reading the copywriter's brief, not the product copy:

- **53 products** opened with `أفكار المحتوى:` — a content-brief header.
- **82 products** opened with `زوايا تسويقية:` ("marketing angles"), each bullet
  prefixed `زاوية الراحة:`, `زاوية السرعة:` and so on.
- **342 products** carried long tatweel rules (`ــــــــــ`) as section separators.
- **11 products** started with a literal `&gt;`, and **4** showed `&amp;bull;`
  as visible text.

All removed. The `زاوية ` strip was scoped to the products that carried the
brief header, so the 48 legitimate uses (`زاوية الرؤية 85 درجة`, `أي زاوية بالبيت`)
were left alone.

### Titles: 144 rewritten

**10 had already been rejected by Google on policy** — all false positives from
its classifier reacting to one word:

| Was | Google read it as | Now |
|---|---|---|
| دباسة مسامير كهربائية للنجارة | Guns and Parts | أداة تثبيت الخشب والتنجيد الكهربائية |
| مثبت مسامير كهربائي للنجارة | Guns and Parts | أداة تثبيت خشب كهربائية للنجارة |
| دباسة مسامير يدوية للنجارة | Guns and Parts | أداة تثبيت خشب يدوية للنجارة |
| مبخرة بخور وعود كهربائية | Tobacco products | جهاز تعطير الشعر والملابس الكهربائي |
| دهان الحنظل … للعضلات والمفاصل | personal hardships | كريم تدليك الحنظل الطبيعي للعضلات بعد المجهود |
| دهن النعام … للعضلات والمفاصل | personal hardships | كريم تدليك دهن النعام الطبيعي للعضلات |
| مشد للركبة مع مشد داعم للكاحل | personal hardships | دعامة ركبة وكاحل رياضية مرنة |
| حزام تدفئة … للبطن والظهر | Inappropriate title | حزام تدفئة كهربائي محمول قابل للشحن |
| فرشاة تدليك فروة الرأس | Inappropriate title | فرشاة تدليك الشعر اليدوية |
| ترمس الحرم | Inappropriate title | ترمس ستانلس حافظ للحرارة والبرودة |

The rest: one title was **348 characters** (the entire description pasted into
the title field), 28 were over 95 characters, 38 were too vague to identify the
product (`اصغر ايفون`, `شنيور Denix`, `منفاخ نفاث`, `فوط المطبخ`), 6 were
English-only, and one carried stray quote marks (`بخاخ """" (NORA)`).

A sweep for the same triggers across the whole catalogue then found **48 more
titles that Google had not flagged yet but would have**:

- **33 carried promotional text**, which Google Shopping's title policy forbids
  outright: `هدية مجانية`, `عرض 50% خصم`, `سعر جملة للكمية`, `ارخص جوال بالمملكة`.
  The promotional framing was removed and the bundle contents kept — `مع منظفي فوم`
  stays, `هدية مجانية` goes.
- **15 carried a health claim or a weapon word**: `مشد خصر للتنحيف`,
  `وسادة القضاء على آلام الصداع النصفي`, `جهاز مساج الركبة لآلام المفاصل`,
  `بخاخ علاج الشخير`, a second `مبخرة بخور وعود`, plus
  `محطة لحام مع مسدس حراري` → `منفاخ حراري` and `مسدس الماء العكسي` →
  `لعبة رشاش الماء للأطفال`.

One more the title fix alone would not have saved: product `38915`'s
**description** still read `يطرد بقايا البارود` — gunpowder residue. Google reads
descriptions too, so that was fixed as well.

Two typos surfaced on the way: `محمصة خبز كاهربائبة` → `كهربائية`, and
`مصباح ببببور بنك` → `مصباح LED ببطارية باور بنك مدمجة`.

### Images: 59 swapped, then 25 of those corrected by looking

Merchant Center had disapproved 65 products for **Promotional overlay on image**
or **Inappropriate image**, plus 18 flagged as too small.

The first pass picked a replacement from each product's gallery mechanically —
largest image, preferring the second in gallery order, guarding against
downgrades. That covered 59 products; the other 35 had no gallery alternative
that met the size floor.

Then AI vision was pointed at every replacement, and **25 of the 59 were wrong**:

- `38915` — the chosen image had **"Gun body"** burned into it. That, not the
  title, is the likely cause of the Guns and Parts flag.
- `13085` — **"مسدس إطلاق المقاتلات الحربية"** burned into a children's toy image.
- `38284` — a full CTA block: `اشترى الحين` + `الدفع عند الاستلام` + `شحن سريع`.
- `41386` — the **back of the sachet**: ingredient list, usage instructions,
  safety warnings.
- `38362` — a Chinese instructional sentence (`开小门时注意不要用力过猛!`).
- `38748`, `39080`, `41626` — marketing infographics and how-to step graphics.

**Six products have no usable image at all** — every image they own carries a
warranty badge or a slogan. These need a real product photo before they will pass:
`37543` صانعة الثلج, `40450` الخلاط, `40149` زيت الأرغان, `44021` مقوي الواي فاي,
`45171` سجادة الصلاة, `46640` لوح الضغط.

`39366` was retitled as well — vision flagged that its old title would keep
triggering the rejection even with a clean image.

### Descriptions: 19 rewritten from scratch

Separate from the artifact cleanup, 19 products had a description that was not
usable copy at all:

- `49162` حقيبة سرير أطفال — **completely empty**
- `48777` بخاخ الضمادة — the whole description was `- ..`
- `43990` معجون Tile reform — **English template boilerplate**:
  `Discover the benefits of … for your home improvement needs.`
- `37506` ماكينة الإسبريسو — `لا تخلي العرض يفوتك` and nothing else
- `40246` منظف مكيف السيارة — the title, repeated

Each got a real Arabic description in the store's house format: an opening
sentence, then `المميزات:` with seven benefit lines. No product in the catalogue
now has a description under 150 characters; the shortest is 179.

### Categories: a second pass caught 23 wrong assignments

After the flattening, a check comparing keywords in each title against its
category found 23 products filed wrongly — most inherited from the old taxonomy,
one (`40246`) misfiled in this pass:

- `بوكس إكسسوارات السيارة`, `عرض 6 أداة تنظيف السيارة`,
  `مبخرة مشغل القرآن للسيارة` — all sitting in **الترفيه والألعاب**
- `مساج للسيارة` — in **الرياضة واللياقة**
- `منظف مكيف السيارة` — in **المنزل والمطبخ**
- `كرسي تخييم` and `خيمة تخييم` — in **المنزل والمطبخ**, moved to الرياضة واللياقة

السيارة went from 49 products to 67 as a result.

### 33 products with no image anywhere: deleted

These had no featured image, no gallery, no attachment, and no image reference in
any meta field.

Verified rather than assumed. The importer's invariable pattern is *upload images,
then create the product*, so a product's images sit in the ID range immediately
below it:

| Product | Featured | Gallery |
|---|---|---|
| 48865 مكنسة سيارة | 48858 | 48859–48864 |
| **48866 مصباح LED** | **none** | **none** |
| 48874 جل تدليك | 48867 | 48868–48873 |

For 29 of the 33 there were **zero attachments** in that gap. The other four were
checked individually: two had neighbours already used by another product's gallery,
and the rest were `hf_*` AI-generated files from April, not product photos.

Origin split: **9 came from the `hts` importer, 22 were created by hand** in the
admin — mostly assembled bundles (`باقة البيت`, `عرض 4 زيت الشعر`) where the image
step was skipped.

All 33 had zero sales and appeared in zero order lines, so no order history was
affected. Backed up to `6F27TMRe_hayak_deleted_noimage_20260918` (33 rows) and
`6F27TMRe_hayak_deleted_meta_20260918` (1066 meta rows) before removal.

Note: the site has `EMPTY_TRASH_DAYS` at zero, so WordPress skipped the trash and
deleted them permanently. Recovery is from the backup tables, not the trash.

### AI Engine was broken

`mwai_vision` returned HTTP 429 with `limit: 0 input tokens per minute` — the
Vision model was set to **Gemini 3.1 Pro**, and Google gives Pro models no free-tier
quota at all. Changed to **`gemini-flash-latest`** (an alias, so it survives Google
retiring a specific model — `gemini-2.0-flash` had already been removed).

Also found: the **JSON model** was set to `gpt-5-mini`, an OpenAI model, while the
only configured environment is Google. Anything using that feature would have
failed. Changed to `gemini-flash-latest` too.

Image *generation* models remain unavailable on the free tier (`limit: 0`).

## Not fixed, and why

- **Six products with no clean image** (listed above) — every image they own has
  promotional text burned in. Needs a real photo.
- **9 deleted products will return** on the next sync, because the gap is in the
  importer, not the product. Left alone at the user's instruction.
- **9 duplicate product pairs** — identical titles, different Taager SKUs, e.g.
  `منشر غسيل 3 طبقات` listed twice at 210 and 173 SAR. Merchant Center will read
  these as duplicate offers. Not touched: deciding which listing to keep is a
  commercial call.
- **2 `landing_page_error` products** (`38255`, `42969`) — both published, in stock
  and synced; a transient crawl failure, nothing to fix.
- **2 `price_updated` notices** — Google auto-corrected a stale feed price from the
  landing page. No action needed.

## Safety net

Every destructive step wrote a backup table first:

| Table | Contents |
|---|---|
| `6F27TMRe_hayak_catalogue_backup_20260918` | 868 products — title, content, excerpt, slug |
| `6F27TMRe_hayak_terms_backup_20260918` | product_cat relationships before flattening |
| `6F27TMRe_hayak_image_swaps_20260918` | 59 mechanical image swaps, old and new |
| `6F27TMRe_hayak_vision_swaps_20260918` | 25 vision corrections, old and new |
| `6F27TMRe_hayak_titles_20260918` | 144 titles, old and new |
| `6F27TMRe_hayak_desc_20260918` | 19 descriptions, old and new |
| `6F27TMRe_hayak_deleted_noimage_20260918` | 33 deleted products |
| `6F27TMRe_hayak_deleted_meta_20260918` | their 1066 meta rows |

## Still running

Rank Math title, meta description and focus keyword for the products that still
have none. That set keeps growing as the sync imports, and every title rewritten
above had its old SEO cleared so it gets regenerated against the new title.
