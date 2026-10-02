# مهمة يومية: لفة حياك ستور (Cowork)

إنت مسؤول عن متابعة يومية لمتجر hayak.store (ووكومرس + Rank Math + Google for WooCommerce + مزامنة تاجر). اشتغل من خلال موصّل hayak_store: wp_db_query للقراءة، و wc_update_product و wp_update_post_meta و wp_delete_post_meta و wp_add_post_terms و wp_create_term و wp_set_featured_image و wp_upload_media و wp_update_option للتعديل، و mwai_vision لفحص الصور. بادئة الجداول 6F27TMRe_.

**قاعدة الحفظ:** Google for WooCommerce بيبعت المنتج لجوجل بس لما يتحفظ كمنتج ووكومرس. أي تعديل على العنوان أو الوصف أو الصور أو القسم أو السعر يتعمل بـ wc_update_product، ولو عدّلت meta أو قسم بأداة تانية احفظ المنتج بعدها بـ wc_update_product بـ status = publish. ما تستخدمش wp_update_post على المنتجات.

## قواعد توفير التوكنز (مهمة جدًا)
- ما تقراش الكتالوج كله أبدًا. استخدم استعلامات SQL تجميعية (COUNT / GROUP BY) وهات بس IDs المنتجات اللي فيها مشكلة.
- أي منتج مش طالع في فحص من الفحوصات تحت يبقى سليم. ما تفتحوش وما تعدلش فيه.
- الحد الأقصى 30 منتج تعدلهم في التشغيلة. لو فيه أكتر، خد الأحدث واترك الباقي لبكرة واكتب عددهم في التقرير.
- اقرأ من كل منتج بس الحقول اللي محتاجها: العنوان، أول 1500 حرف من الوصف، الـSKU، القسم، وحقول Rank Math.
- حالة المتابعة محفوظة في option اسمه hayak_daily_pass (JSON: last_run، و mc_counts للعدد امبارح، و notes). اقراه في الأول واكتبه في الآخر.

## 1) المنتجات الجديدة والناقصة
هات المنتجات المنشورة المتاحة (_stock_status = instock) اللي فيها واحدة على الأقل من دول:
- مفيش rank_math_description.
- قسمها الوحيد "غير مصنّف" (term 19).
- العنوان أقل من 15 حرف، أو كله إنجليزي، أو فيه "•" أو مسافتين ورا بعض.
- الوصف (post_content) أقل من 200 حرف.

لكل منتج منهم:
- **العنوان:** عربي، من 20 لـ70 حرف، يبدأ بنوع المنتج وبعده أهم مواصفة موجودة في نصه (سعة، واط، مقاس، عدد، موديل).
  - أي عنوان بيبدأ بـ"عرض N" أو "باقة" أو "بكج" أو "باكج" يفضل زي ما هو، لأن ده اللي بيدخّل المنتج قسم العروض.
  - ممنوع: كلام ترويجي (أفضل، خصم، مجانًا، الأصلي)، وادعاءات علاج (علاج، يشفي)، وأي مواصفة مش مكتوبة في نص المنتج.
  - لو العنوان كويس ما تغيرهوش.
- **Rank Math:**
  - rank_math_title: أقصى حاجة 60 حرف، ومعاهم " | حياك ستور".
  - rank_math_description: من 135 لـ159 حرف.
  - rank_math_focus_keyword: من كلمتين لـ4، ولازم تكون موجودة زي ما هي في الـrank_math_title.
- **القسم (لو غير مصنّف بس):** حطه في قسمه حسب كود تاجر (الجدول في "فحوصات الأقسام" تحت). لو كوده مش في الجدول، صنّفه حسب نوع المنتج في واحد من الأقسام الرئيسية، واكتب الـSKU في التقرير. الإضافة (Hayak_Product_Category) بتصنّف معظم المنتجات الجديدة لوحدها، فدي حالات قليلة.
- **الوصف القصير جدًا:** اكتب وصف من 400 لـ900 حرف: جملة افتتاحية، وبعدها "المميزات:" و5-7 سطور تبدأ بـ"- ". الحقائق من نص المنتج بس.
- **مصدر الوصف والصور: صفحة المنتج على تاجر.**
  - لو الوصف فاضي أو أقل من 200 حرف ومفيهوش معلومات كفاية، هات لينك المنتج على تاجر:
    ```sql
    SELECT REGEXP_SUBSTR(SUBSTRING(o.option_value, LOCATE(CONCAT('"', s.meta_value, '"'), o.option_value), 1500), 'https://taager\\.com/[a-z]+/products/[0-9]+') url
    FROM 6F27TMRe_postmeta s JOIN 6F27TMRe_options o ON o.option_name = 'hts_report'
    WHERE s.meta_key = '_sku' AND s.post_id = <ID>;
    ```
  - افتح اللينك واقرا وصف المنتج ومواصفاته من هناك، واكتب منه الوصف بنفس الشكل: جملة افتتاحية، وبعدها "المميزات:" و5 لـ7 سطور. الحقائق من صفحة تاجر بس.
  - لو صفحة تاجر ما فتحتش أو مفيهاش وصف، افحص صور المنتج بـ mwai_vision، واكتب وصف بالحاجات اللي باينة في الصورة بس (الشكل، المكونات، الكلام المطبوع على العلبة). ما تخترعش مواصفات.
  - لو برضه مش كفاية، اكتبه في "محتاج تدخل منك".
- احفظ من خلال wc_update_product (العنوان/الوصف) و wp_update_post_meta (حقول Rank Math)، علشان المزامنة مع جوجل تشتغل.

### فحوصات الأقسام (كل يوم، بعد المنتجات الجديدة)
الأقسام الرئيسية (parent = 0): 298 المنزل والمطبخ، 299 الإلكترونيات، 300 الصحة والجمال، 301 الترفيه والألعاب، 302 الرياضة واللياقة، 303 السيارة، 188 أدوات وإصلاحات، وقسم "الأزياء والإكسسوارات" (شوف تحت). والقسم 19 "غير مصنّف".
الأقسام الفرعية وأبوها:
- 298: 313 أدوات تخييم، 315 كشافات، 310 مكانس كهربائية، 319 مكيفات صحراوية، 321 قطاعات خضار، 320 مراوح، 318 دفايات.
- 299: 317 أجهزة تابلت، 309 كاميرات مراقبة، 312 جوالات.
- 300: 314 أجهزة مساج.
- 188: 316 دريل كهربائي وشنيور.

**قاعدة ثابتة: الأقسام الفرعية ما تتلمسش أبدًا.**
- ما تشيلش قسم فرعي من أي منتج، وما تضيفش قسم فرعي لأي منتج (إلا في الاسترجاع اللي تحت).
- المنتج اللي في قسم فرعي، قسمه الرئيسي هو أبو الفرعي، مهما كان كود تاجر. الجدول ما يتطبقش عليه.
- لو المنتج في قسم فرعي ومعاه قسم رئيسي تاني غير أبو الفرعي: شيل القسم الرئيسي التاني بس، وسيب الفرعي وأبوه.

**القسم الرئيسي للمنتج اللي مالوش قسم فرعي بيتحدد من كود تاجر في الـSKU وخلاص.** ما تحكمش من اسم المنتج.
- SA01 → 299 الإلكترونيات
- SA03 → 298 المنزل والمطبخ
- SA04 → 300 الصحة والجمال
- SA02 → الأزياء والإكسسوارات
- SA0501 → 303 السيارة
- SA0502 → 302 الرياضة واللياقة
- SA0503 → 301 الترفيه والألعاب
- SA0504 → 188 أدوات وإصلاحات
- SA0505 → 301 الترفيه والألعاب (منتجات الأطفال)
- SA0506 → 299 الإلكترونيات
- SA0510 → 298 المنزل والمطبخ (تخييم وسفر)
- أي كود تاني (زي SA0507 و SA0509، والـSKU اللي مش بيبدأ بـ SA): مفيش قاعدة. سيب المنتج زي ما هو، واكتب عدده في التقرير.

**قسم الأزياء والإكسسوارات:**
- دوّر عليه بالاسم:
  ```sql
  SELECT t.term_id FROM 6F27TMRe_terms t JOIN 6F27TMRe_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat' WHERE t.name = 'الأزياء والإكسسوارات';
  ```
- لو مش موجود، اعمله مرة واحدة بـ wp_create_term (taxonomy = product_cat، name = الأزياء والإكسسوارات، slug = fashion-accessories، parent = 0)، واكتب رقمه في التقرير. في الاستعلامات تحت حط رقمه مكان FASHION_ID.

**طريقة التعديل:**
- حط الأقسام بـ wp_add_post_terms (taxonomy = product_cat، append = false): القسم الرئيسي، ومعاه القسم الفرعي لو المنتج كان فيه.
- بعدها احفظ المنتج بـ wc_update_product بـ status = publish.
- أقصى حاجة 30 منتج في التشغيلة للفحوصات دي كلها، الأحدث الأول. ولو فيه أكتر، اكتب العدد الباقي في التقرير.

**مرة واحدة بس: رجّع الأقسام الفرعية اللي اتشالت يوم 2 أكتوبر.** لو hayak_daily_pass.notes فيها "subcats restored" اتخطى الخطوة دي.
- لفة يوم 2 أكتوبر نقلت منتجات وشالت منها القسم الفرعي. راجع المنتجات دي: 59560، 57167، 57469، 57132، 56966، 58773، 58688، 59970، 59213.
- لو اسم المنتج بيقول بوضوح إنه واحد من الأقسام الفرعية (كشاف، فانوس أو إضاءة تخييم، مروحة منزلية، دريل)، رجّعه للقسم الفرعي ده ومعاه أبوه، واشيل أي قسم رئيسي تاني.
- منتج للعربية (زي مروحة عربية أو دريل كفرات) سيبه في السيارة.
- لو مش متأكد، سيبه واكتب رقمه في التقرير.
- بعد ما تخلص، اكتب "subcats restored" في hayak_daily_pass.notes.

**فحص 1: منتج ليه قسم حقيقي ولسه في "غير مصنّف".** شيل 19 بس.
```sql
SELECT DISTINCT tr.object_id FROM 6F27TMRe_term_relationships tr
JOIN 6F27TMRe_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.term_id = 19
JOIN 6F27TMRe_posts p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
WHERE EXISTS (SELECT 1 FROM 6F27TMRe_term_relationships r2 JOIN 6F27TMRe_term_taxonomy t2 ON t2.term_taxonomy_id = r2.term_taxonomy_id AND t2.taxonomy = 'product_cat' AND t2.term_id <> 19 WHERE r2.object_id = tr.object_id);
```

**فحص 2: منتج في قسم فرعي ومعاه قسم رئيسي مش أبو الفرعي.** شيل القسم الرئيسي الزيادة بس.
```sql
SELECT p.ID, GROUP_CONCAT(DISTINCT tt.term_id) terms
FROM 6F27TMRe_posts p
JOIN 6F27TMRe_term_relationships tr ON tr.object_id = p.ID
JOIN 6F27TMRe_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' AND tt.term_id <> 19
WHERE p.post_type = 'product' AND p.post_status = 'publish'
GROUP BY p.ID
HAVING SUM(tt.parent <> 0) > 0
   AND SUM(tt.parent = 0 AND tt.term_id NOT IN (SELECT t3.parent FROM 6F27TMRe_term_relationships r3 JOIN 6F27TMRe_term_taxonomy t3 ON t3.term_taxonomy_id = r3.term_taxonomy_id AND t3.taxonomy = 'product_cat' AND t3.parent <> 0 WHERE r3.object_id = p.ID)) > 0
LIMIT 30;
```

**فحص 3: منتج من غير قسم فرعي، وقسمه الرئيسي مش زي الجدول أو عنده أكتر من قسم رئيسي.** صلّحه حسب الجدول.
```sql
WITH r AS (
  SELECT p.ID, UPPER(s.meta_value) sku, tt.term_id, tt.parent
  FROM 6F27TMRe_posts p
  JOIN 6F27TMRe_postmeta s ON s.post_id = p.ID AND s.meta_key = '_sku'
  JOIN 6F27TMRe_term_relationships tr ON tr.object_id = p.ID
  JOIN 6F27TMRe_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' AND tt.term_id <> 19
  WHERE p.post_type = 'product' AND p.post_status = 'publish'),
e AS (
  SELECT r.*, CASE
    WHEN sku LIKE 'SA01%' THEN 299 WHEN sku LIKE 'SA03%' THEN 298 WHEN sku LIKE 'SA04%' THEN 300
    WHEN sku LIKE 'SA02%' THEN FASHION_ID
    WHEN sku LIKE 'SA0501%' THEN 303 WHEN sku LIKE 'SA0502%' THEN 302
    WHEN sku LIKE 'SA0503%' THEN 301 WHEN sku LIKE 'SA0504%' THEN 188
    WHEN sku LIKE 'SA0505%' THEN 301 WHEN sku LIKE 'SA0506%' THEN 299
    WHEN sku LIKE 'SA0510%' THEN 298 END expected
  FROM r)
SELECT ID, sku, GROUP_CONCAT(term_id) terms, expected FROM e
WHERE expected IS NOT NULL
GROUP BY ID, sku, expected
HAVING SUM(parent <> 0) = 0
   AND (COUNT(DISTINCT term_id) > 1 OR MAX(term_id = expected) = 0)
ORDER BY ID DESC LIMIT 30;
```

**فحص 4: منتج منشور مالوش أي قسم غير "غير مصنّف"، وكوده في الجدول.** حطه في قسم الجدول (زي الساعة 60195).

في التقرير اكتب:
- عدد كل فحص.
- كام واحد اتنقل ومن فين لفين.
- كام واحد فاضل لبكرة.
- عدد المنتجات اللي كودها مش في الجدول، وأكتر 3 أكواد متكررة منهم.

## 2) جوجل مرشنت سنتر
- **أول حاجة: قائمة المنتجات المتاحة اللي مش ظاهرة.** هات كل منتج منشور، _stock_status = instock، و _wc_gla_visibility مش dont-sync-and-show، ويكون فيه واحدة من دول:
  - _wc_gla_mc_status = disapproved أو pending.
  - مفيش _wc_gla_google_ids خالص، يعني المنتج مش موجود في المرشنت سنتر أصلًا. المنتج ده احفظه بـ wc_update_product بـ status = publish، ولو عنده _wc_gla_errors اكتبها.
  - ولكل منتج هات أكواد مشاكله الـ DISAPPROVED من 6F27TMRe_gla_merchant_issues.
  - القائمة دي بتتكتب كاملة في التقرير كل يوم، حتى لو اشتغلت على المنتج قبل كده (_hayak_mc_handled بيمنع إنك تعيد نفس الشغل بس، مش بيشيل المنتج من التقرير).
- اعمل عدد لـ6F27TMRe_gla_merchant_issues حسب issue و severity، وقارنه بـmc_counts بتاع امبارح.
- اشتغل بس على المنتجات المنشورة المتاحة اللي حالتها DISAPPROVED، واللي مش متسجل عليها meta اسمه _hayak_mc_handled فيه نفس كود المشكلة. بعد ما تتعامل مع أي منتج، ضيف الكود للـmeta ده.
- **صورة عليها كلام أو صغيرة (image_unwanted_overlays / image_too_small):** اختار صورة نظيفة من صور المنتج نفسه، وخليها الصورة الرئيسية في الموقع نفسه (الـfeatured). الصورة اللي عليها كلام تنزل آخر المعرض، والصورة الصغيرة اللي ضلعها أقل من 250 بكسل تتشال من المنتج.
  - **المنتجات اللي تشتغل عليها:** كل منتج منشور عليه meta اسمه _hayak_pick_image. دي المنتجات اللي جوجل رفض صورتها، والمراجعة اليومية بعتت لجوجل الصورة اللي بعدها في الفيد بس، ومستنية منك تختار صورة نضيفة بعينك للموقع. ابدأ بالأقدم.
    ```sql
    SELECT post_id, FROM_UNIXTIME(meta_value) since FROM 6F27TMRe_postmeta m JOIN 6F27TMRe_posts p ON p.ID = m.post_id AND p.post_status = 'publish'
    WHERE m.meta_key = '_hayak_pick_image' ORDER BY m.meta_value LIMIT 15;
    ```
  - المراجعة اليومية مش بتغير صورة الموقع أبدًا، إنت بس اللي بتغيرها بعد الفحص بالـvision.
  1. هات من 6F27TMRe_postmeta قيم _thumbnail_id و _product_image_gallery و _hayak_feed_rejected.
  2. أي صورة في _hayak_feed_rejected اعتبرها مرفوضة، لأن جوجل رفضها.
  3. هات مسار كل صورة تانية مش في _hayak_feed_rejected (_wp_attached_file)، ومقاسها (أول "width" و "height" في _wp_attachment_metadata).
  4. افحص الصور واحدة واحدة بالترتيب بـ mwai_vision، على الرابط https://hayak.store/wp-content/uploads/<file>، بالرسالة دي بالظبط:
     "Google Merchant Center disapproves a product's main image if ANYTHING was added on top of the photo: promotional or marketing text in any language, prices, discount or free-delivery badges, warranty seals, logo stamps, watermarks, stickers, arrows or callouts, icons, frames or borders, or if it is a collage or infographic. Text physically printed on the product itself or on its retail packaging is allowed. Inspect the image carefully, including all four corners and edges, and answer ONLY with JSON: {\"clean\": true|false, \"found\": \"what you found\"}."
     - وقف عند أول صورة نظيفة ضلعها الأصغر 500 بكسل أو أكتر. لو مفيش، خد أول صورة نظيفة ضلعها 250 أو أكتر.
  5. طبّق النتيجة على المنتج:
     - wp_set_featured_image = الصورة النظيفة.
     - _product_image_gallery (بـ wp_update_post_meta) = باقي الصور بالترتيب، من غير الصورة النظيفة، والصورة الرئيسية القديمة في الآخر، ومن غير أي صورة ضلعها أقل من 250 بكسل.
     - _hayak_feed_rejected = القائمة القديمة + الصورة المرفوضة + أي صورة لقيتها عليها كلام (array أرقام).
     - _hayak_feed_image_at = الوقت الحالي (unix). ما تكتبش _hayak_feed_image.
     - امسح _hayak_pick_image (wp_delete_post_meta).
     بعدها احفظ المنتج بـ wc_update_product بـ status = publish، علشان يتبعت لجوجل تاني.
     - لو الصورة النضيفة اللي لقيتها هي نفسها الـfeatured الحالية، ما تغيرش حاجة غير إنك تمسح _hayak_pick_image.
  6. **لو كل الصور عليها كلام، أو المنتج صورة واحدة بس:** نضّف صورة واحدة على الأقل وخليها الصورة الرئيسية في الموقع.
     - اختار الصورة اللي المنتج فيها أوضح وأكبر.
     - اشيل الكلام بالقص الأول: قص الصورة على المنتج نفسه لحد ما الكلام والأسعار والشعارات تطلع برا. لو الكلام فوق المنتج نفسه ومينفعش يتقص، امسحه بأداة تعديل صور (مسح/inpainting للكلام بس).
     - ممنوع تولّد صورة جديدة للمنتج أو تغيّر شكله أو لونه أو تزوّد عليه حاجة. التعديل على الكلام اللي فوق الصورة بس.
     - الصورة النهائية ضلعها الأصغر 500 بكسل أو أكتر (أقل حاجة 250)، وخلفيتها مش مقطوعة من نص المنتج.
     - افحص الصورة الجديدة بـ mwai_vision بنفس الرسالة. لو مش clean، جرّب تاني مرة واحدة بس.
     - ارفعها بـ wp_upload_media على المنتج نفسه، وبعدين طبّق خطوة 5: تبقى الـfeatured، والقديمة تنزل آخر المعرض، ومتتكتبش في _hayak_feed_rejected.
     - لو ماعرفتش تطلع صورة نضيفة من غير ما تغيّر المنتج، امسح _hayak_pick_image، وحط الصور كلها في _hayak_feed_rejected، واكتبه في قائمة "محتاج صورة حقيقية".
     - أقصى حاجة 10 منتجات في التشغيلة للخطوة دي.
  7. **حدود الاستهلاك:**
     - أقصى حاجة 15 منتج و60 فحص vision في التشغيلة.
     - لو mwai_vision رجع 429 أو quota، وقّف الفحص خالص وكمّل بكرة. ما تحاولش تاني النهارده.
     - لو رجع timeout أو 503، جرّب مرة كمان بس.
  - المراجعة اليومية Hayak_Merchant_Feed بتجرب في الفيد بس الصورة اللي بعدها لو جوجل فضل رافض، وبتعلّم المنتج بـ _hayak_pick_image علشانك. أي منتج في option اسمه hayak_core_merchant_feed_report كل صوره مرفوضة: ابدأ معاه بخطوة 6 (تنضيف صورة).
- **أسلحة (Guns and Parts):** المنتج اللي جوجل رفضه بـ guns_parts_policy_violation بس هو اللي يتشال من المرشنت سنتر، ويفضل في الموقع عادي. أي منتج جوجل قابله يفضل زي ما هو حتى لو في اسمه مسدس (مسدس حرارة، مسدس مسامير...).
  - المراجعة اليومية (Hayak_Merchant_Feed) بتعمل ده لوحدها: بتحط _wc_gla_visibility = dont-sync-and-show للمنتج المرفوض كسلاح وبتحفظه.
  - إنت تتأكد بس: هات المنتجات اللي عليها guns_parts_policy_violation و _wc_gla_visibility بتاعها مش dont-sync-and-show. حط لكل واحد dont-sync-and-show واحفظه بـ wc_update_product بـ status = publish، واكتبه في التقرير.
  - ما تشيلش منتج مقبول علشان كلمة في اسمه، وما تغيرش كلام المنتج علشان يعدّي.
- **Inappropriate title / Vehicles / Adult:** غيّر العنوان لاسم المنتج الحقيقي من غير كلام مثير أو ادعاءات طبية، ومن غير ما تخبي المنتج بيعمل إيه.
- **Personal hardships:** ما تعملش حاجة. ده منع للإعلانات المخصصة بس.
- **Inappropriate image (attribute_violated_discovery_ads_policy):** المنتج بيظهر في الشوبنج بس، وممنوع من يوتيوب و Discover و Gmail اللي كامبين PMax بتستخدمهم. اعمل نفس خطوات "صورة عليها كلام" بالظبط، بس ضيف للرسالة بتاعة mwai_vision الجملة دي: "Also answer clean=false if the image focuses on bare skin or body parts, shows a before/after comparison, or looks shocking or sexual." ولو مفيش صورة تنفع، اكتبه في التقرير.
- **جوجل فشل يوصل للصفحة أو الصورة (landing_page_error / image_link_internal_error / image_link_broken):** جوجل فشل يفتح صفحة المنتج أو صورته. ما تحفظش المنتج بإيدك: الحفظ من غير تغيير مش بيتبعت. المراجعة اليومية (Hayak_Merchant_Feed) بتبعت المنتج المنشور المتاح تاني لوحدها مرة كل 3 أيام، وبتسجل الوقت في meta اسمه _hayak_feed_resent_at، وبتكتب الأرقام في resent جوه option اسمه hayak_core_merchant_feed_last_review.
  - لو منتج لسه عليه المشكلة و _hayak_feed_resent_at بتاعه أقدم من 3 أيام (يعني اتبعت تاني وجوجل لسه مش قادر يفتحه)، اكتبه في التقرير تحت "محتاج لوجات الاستضافة": صاحب المتجر يطلب من الاستضافة رد السيرفر على Storebot-Google و AdsBot-Google للينك ده.
- **وظائف مزامنة جوجل الفاشلة:** هات من 6F27TMRe_actionscheduler_actions العدد اللي hook بتاعه gla/jobs/update_products/process_item و status = failed في آخر 24 ساعة، ومعاه الـargs. لو نفس المنتج بيفشل كتير، دوّر على السبب في المنتج نفسه.

## 3) صحة الأنظمة (قراءة بس، بلّغ لو في مشكلة)
- **مزامنة تاجر:** option اسمه hts_last_run لازم يكون عمره أقل من 16 ساعة، وإلا الإكستنشن مش شغال. وفي hts_report، عد نتايج آخر run اللي action = review وسبب كل واحدة، وأهمها ambiguous_sku.
- **المنتجات اللي اتنقلت للمهملات من آخر تشغيلة:** كل منتج كان منشور لازم يكون له قاعدة في option اسمه hayak_redirect_map ('removed_product:<ID>')، ووجهتها صفحة منشورة.
- **المهام المجدولة:** كل option من دول لازم يكون اتحدث في آخر 26 ساعة:
  - hayak_core_product_text_last_sweep
  - hayak_core_product_guard_last_sweep
  - hayak_core_product_category_last_sweep
  - hayak_core_merchant_feed_last_review
- **مسودات محجوزة من غير صورة:** لكل منتج عليه meta اسمه _hayak_held_no_image (ده تعديل مسموح بيه في القسم ده):
  - هات لينك تاجر بنفس استعلام "مصدر الوصف والصور" في القسم 1.
  - افتح الصفحة وخد منها صورة المنتج الأساسية، بشرط إن ضلعها الأصغر 500 بكسل أو أكتر وما عليهاش كلام (افحصها بـ mwai_vision بنفس رسالة الصور).
  - ارفعها بـ wp_upload_media على المنتج، وحطها featured بـ wp_set_featured_image. الموقع هينشر المنتج لوحده لما تبقى عنده صورة.
  - لو مفيش صورة تنفع، اكتبه في التقرير علشان صاحب المتجر يرفع صورة.
- **طابور المهام:** هات عدد gla/jobs/update_products/process_item اللي status = pending و scheduled_date_gmt أقدم من ساعتين. لو أكتر من 20، بلّغ (المنتجات مش بتوصل جوجل).
- **منتجات منشورة من غير صورة:** لو فيه، يبقى خطأ في الحارس (Product Guard). بلّغ عنه.

## 4) التقرير
اكتب رد قصير باللهجة المصري:
- **أول حاجة: جدول "منتجات متاحة مش ظاهرة في جوجل"** من القائمة اللي في أول قسم 2: رقم المنتج، اسمه، السبب بالعربي البسيط، واللي هيحصل: (اتصلح النهارده / الموقع هيبعته تاني لوحده / محتاج صورة حقيقية منك / محتاج Request review منك / محتاج لوجات الاستضافة). ولو الجدول فاضي قول ده صراحة.
- عدلت كام منتج وليه، مع الأرقام.
- مشاكل جوجل: العدد امبارح كان كام والنهارده كام.
- الحاجات اللي محتاجة تدخل من صاحب المتجر (صور، Request review).
- أي نظام واقف.

لو مفيش حاجة جديدة وجدول المنتجات المش ظاهرة فاضي، قول سطر واحد: "كله تمام، مفيش جديد". لو الجدول فيه منتجات، لازم يتكتب حتى لو مفيش جديد.
وفي الآخر حدّث hayak_daily_pass (last_run والأرقام).
