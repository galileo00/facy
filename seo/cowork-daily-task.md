# مهمة يومية: لفة حياك ستور (Cowork)

إنت مسؤول عن متابعة يومية لمتجر hayak.store (ووكومرس + Google for WooCommerce + مزامنة تاجر + إضافة Hayak Core). اشتغل من خلال موصّل hayak_store: wp_db_query للقراءة، و wc_update_product و wp_update_post_meta و wp_delete_post_meta و wp_set_featured_image و wp_upload_media و wp_update_option للتعديل، و mwai_vision لفحص الصور. بادئة الجداول 6F27TMRe_.

**اللفة دي بتعمل إيه وما بتعملش إيه:**
- بتعملها: جوجل مرشنت سنتر (المنتجات المش ظاهرة، الصور، الأسلحة اللي جوجل رفضها)، المسودات المحجوزة من غير صورة، صحة الأنظمة، ومتابعة الأقسام (قراءة وتبليغ، وحل المنتجات اللي الإضافة ما عرفتش تصنيفها على تاجر).
- ما تعملهاش أبدًا: العناوين والأوصاف وحقول Rank Math (دي مهمة السيو اليومية، ليها نصها لوحدها)، وتغيير أقسام أي منتج بإيدك (إضافة Hayak Core هي اللي بتصنّف، شوف قسم 1)، وأي تعديل في إضافة مزامنة تاجر (hayak-taager-sync) أو خياراتها.
- ممنوع تستخدم wp_add_post_terms أو wp_create_term أو wp_update_term على product_cat. ولو لقيت منتج في قسم غلط، بلّغ بس.
- شغّل اللفة دي الصبح، ومهمة السيو بعدها بساعتين على الأقل. ما يشتغلوش في نفس الوقت.

**قاعدة الحفظ:** Google for WooCommerce بيبعت المنتج لجوجل بس لما يتحفظ كمنتج ووكومرس. أي تعديل على الصور أو meta يتبعه حفظ للمنتج بـ wc_update_product بـ status = publish. ما تستخدمش wp_update_post على المنتجات.

## قواعد توفير التوكنز (مهمة جدًا)
- ما تقراش الكتالوج كله أبدًا. استخدم استعلامات SQL تجميعية (COUNT / GROUP BY) وهات بس IDs المنتجات اللي فيها مشكلة.
- أي منتج مش طالع في فحص من الفحوصات تحت يبقى سليم. ما تفتحوش وما تعدلش فيه.
- اقرأ من كل منتج بس الحقول اللي محتاجها.
- حالة المتابعة محفوظة في option اسمه hayak_daily_pass (JSON: last_run، و mc_counts للعدد امبارح، و notes). اقراه في الأول واكتبه في الآخر. ما تلمسش hayak_seo_pass (ده بتاع مهمة السيو).
- لو أداة رجعت خطأ أو timeout، اعمل mcp_ping. لو ما ردش، وقّف وكمّل بكرة.

## 1) الأقسام: الإضافة بتصنّف، وإنت بتراجع وتبلّغ
**القاعدة العامة:** كل منتج ليه قسم رئيسي واحد بس، ومعاه قسم فرعي واحد على الأكتر من نفس الرئيسي. مينفعش قسمين رئيسيين، ولا قسم فرعي ومعاه رئيسي غير أبوه. القسم بيتحدد من تصنيف المنتج على تاجر.

**مين بيعمل إيه:**
- إضافة Hayak Core (Hayak_Product_Category) هي الوحيدة اللي بتحط الأقسام: بتقرا كود تاجر من الـSKU (SA01 إلكترونيات، SA02 أزياء، SA03 منزل، SA04 صحة وجمال، SA05 ترفيه وسيارات ورياضة وأدوات)، وبتجيب تصنيف تاجر الفرعي من كتالوج تاجر المحفوظ في الإضافة (رقم المنتج على تاجر → رقم التصنيف)، وبتحطه في أقرب قسم فرعي عندنا. بتشتغل عند حفظ أي منتج، وكمان لفة يومية بالليل.
- إنت: بتقرا نتيجة اللفة وبتبلّغ، وبتحل المنتجات اللي الإضافة ما لقتش تصنيفها (بتقرا صفحة المنتج على تاجر وبتكتب رقم تصنيفه في meta، والإضافة تصنّفه).
- ممنوع تغيّر قسم أي منتج بإيدك مهما كان السبب. لو شايف إن الإضافة حطت منتج في قسم غلط، اكتبه في التقرير بالرقم والقسم الحالي واللي إنت شايفه، وصاحب المتجر يقرر.

**الأقسام الرئيسية:** 298 المنزل والمطبخ، 299 الإلكترونيات، 300 الصحة والجمال، 301 الترفيه والألعاب، 302 الرياضة واللياقة، 303 السيارة، 188 أدوات وإصلاحات، 324 الأزياء والإكسسوارات. و19 "غير مصنّف" (مفروض يبقى فاضي).
**الأقسام الفرعية** محفوظة في option اسمه hayak_core_category_subs (اسم مختصر → رقم القسم). تحت 298: أدوات تخييم، كشافات، مكانس كهربائية، مكيفات صحراوية، قطاعات خضار، مراوح، دفايات، خلاطات كهربائية، أدوات المطبخ، أجهزة المطبخ، أدوات التنظيف، التخزين والتنظيم، أثاث. تحت 299: أجهزة تابلت، كاميرات مراقبة، جوالات، إكسسوارات الجوال، شواحن وباور بانك. تحت 300: أجهزة مساج، العناية بالبشرة، أدوات التجميل والتصفيف، العناية بالشعر، منتجات طبية. تحت 303: داش كام للسيارة، العناية بالسيارة. تحت 188: دريل كهربائي وشنيور، أدوات كهربائية، أدوات يدوية.

**أ) نتيجة لفة الأقسام (كل يوم):**
- اقرا option اسمه hayak_core_product_category_last_sweep: time (لازم يكون أحدث من 26 ساعة)، queued، done، changed، unresolved (قائمة أرقام)، pending (قائمة الباقي). لو pending فيها أرقام و time أقدم من ساعتين، يبقى الدفعات واقفة: بلّغ.
- في التقرير اكتب: اللفة شغّالة إمتى، عدّت على كام منتج، غيّرت كام، وكام واحد ما عرفتش تصنّفه.

**ب) فحص القاعدة (قراءة بس):** هات المنتجات المنشورة اللي بتكسر القاعدة:
```sql
SELECT p.ID, GROUP_CONCAT(CONCAT(tt.term_id, ':', tt.parent) ORDER BY tt.parent, tt.term_id) cats
FROM 6F27TMRe_posts p
JOIN 6F27TMRe_term_relationships tr ON tr.object_id = p.ID
JOIN 6F27TMRe_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
WHERE p.post_type = 'product' AND p.post_status = 'publish'
GROUP BY p.ID
HAVING SUM(tt.parent = 0 AND tt.term_id <> 19) <> 1
    OR SUM(tt.parent <> 0) > 1
    OR SUM(tt.term_id = 19) > 0
    OR SUM(tt.parent <> 0 AND NOT EXISTS (SELECT 1 FROM 6F27TMRe_term_relationships r2 JOIN 6F27TMRe_term_taxonomy t2 ON t2.term_taxonomy_id = r2.term_taxonomy_id WHERE r2.object_id = p.ID AND t2.term_id = tt.parent)) > 0
LIMIT 50;
```
- اللي يطلع هنا وعليه meta اسمه _hayak_cat_unresolved: ده منتج الإضافة ما عرفتش تصنيفه، هتحله في (ج).
- اللي يطلع هنا ومش عليه _hayak_cat_unresolved ومتعدل قبل وقت اللفة: ده خطأ في الإضافة. بلّغ بالأرقام وأقسامها، وما تصلحش بإيدك.

**ج) المنتجات اللي الإضافة ما عرفتش تصنيفها (أقصى حاجة 15 في التشغيلة، الأحدث الأول):**
```sql
SELECT p.ID, LEFT(p.post_title, 60) title, s.meta_value sku, u.meta_value reason, t.meta_value taager_id,
  (SELECT GROUP_CONCAT(tt.term_id) FROM 6F27TMRe_term_relationships tr JOIN 6F27TMRe_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' WHERE tr.object_id = p.ID) cats
FROM 6F27TMRe_posts p
JOIN 6F27TMRe_postmeta u ON u.post_id = p.ID AND u.meta_key = '_hayak_cat_unresolved'
JOIN 6F27TMRe_postmeta st ON st.post_id = p.ID AND st.meta_key = '_stock_status' AND st.meta_value = 'instock'
LEFT JOIN 6F27TMRe_postmeta s ON s.post_id = p.ID AND s.meta_key = '_sku'
LEFT JOIN 6F27TMRe_postmeta t ON t.post_id = p.ID AND t.meta_key = '_hayak_taager_id'
WHERE p.post_type = 'product' AND p.post_status = 'publish'
ORDER BY p.ID DESC LIMIT 15;
```
- reason = "not a taager sku": منتج مش من تاجر. ما تعملش حاجة غير إنك تكتبه في التقرير مرة واحدة (سجّل رقمه في hayak_daily_pass.notes علشان ما تكرروش). صاحب المتجر يختار له قسم من شاشة تعديل المنتج، والإضافة بتحترم اختياره.
- reason = "two roots": المنتج في قسمين رئيسيين، وتاجر حاطه في "عروض" أو "خصومات" (تصنيف ما بيقولش نوعه)، ومفيش كلمة في اسمه بتحسم. اكتبه في التقرير بالاسم والقسمين، وصاحب المتجر يسيب واحد بس من شاشة تعديل المنتج (الإضافة بتثبّت اختياره). ما تحلوش إنت.
- أي reason تانية ("no taager id" / "taager product not in catalogue" / "leaf X not in map"):
  1. هات لينك المنتج على تاجر: لو taager_id موجود، اللينك هو https://taager.com/sa/products/<taager_id>. لو مش موجود:
     ```sql
     SELECT REGEXP_SUBSTR(SUBSTRING(o.option_value, LOCATE(CONCAT('"', s.meta_value, '"'), o.option_value), 1500), 'https://taager[.]com/[a-z]+/products/[0-9]+') url
     FROM 6F27TMRe_postmeta s JOIN 6F27TMRe_options o ON o.option_name = 'hts_report'
     WHERE s.meta_key = '_sku' AND s.post_id = <ID>;
     ```
     لو مفيش لينك، اكتب المنتج في التقرير تحت "مش موجود في تقرير مزامنة تاجر" وكمّل.
  2. افتح الصفحة واقرا تصنيف المنتج على تاجر (المسار أو خانة التصنيف، زي: المنزل > مستلزمات المطبخ > اجهزة المطبخ).
  3. هات شجرة تصنيفات تاجر من option اسمه hayak_taager_tree (رقم التصنيف → [الاسم، رقم الأب، المستوى]). دوّر على الاسم الأعمق في المسار. لو نفس الاسم موجود مرتين (زي "ساعات" أو "العاب")، خد اللي أبوه اسمه موجود في المسار. لو ما لقيتش مطابقة، خد تصنيف الأب. لو ولا واحد موجود، اكتب المنتج في التقرير مع المسار اللي شفته على تاجر.
  4. اكتب الرقم في meta: wp_update_post_meta بـ key = _hayak_taager_category و value = الرقم. ولو taager_id كان فاضي، اكتب كمان _hayak_taager_id برقم المنتج من اللينك.
  5. احفظ المنتج بـ wc_update_product بـ status = publish. الإضافة بتصنّفه ساعتها لوحدها وبتشيل _hayak_cat_unresolved.
  6. اتأكد بـ wp_get_post_terms (taxonomy = product_cat) إن المنتج بقى في قسم رئيسي واحد (ومعاه فرعي أو لأ). لو لسه فيه _hayak_cat_unresolved (reason بقت "leaf X not in map")، يبقى التصنيف ده مش في خريطة الإضافة: اكتب الرقم والاسم في التقرير تحت "تصنيف تاجر جديد محتاج يتضاف للخريطة".
- لو صفحة تاجر ما فتحتش، سيب المنتج لبكرة واكتب عدد اللي ما اتحلوش.

**د) لو صاحب المتجر عايز منتج يفضل في قسم معين مهما قال تاجر:** هو اللي يحط meta اسمه _hayak_cat_lock = 1 على المنتج (الإضافة ساعتها بتسيب أقسامه زي ما هي، بس بتطبق قاعدة الرئيسي الواحد). إنت ما تحطش اللوك ده من نفسك.

في التقرير اكتب: نتيجة اللفة (أ)، عدد اللي كسر القاعدة (ب) ولو فيه حاجة الإضافة ما ظبطتهاش، وكام منتج حليته في (ج) من فين لفين، وكام فاضل.

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
- **Inappropriate title / Vehicles / Adult:** غيّر العنوان لاسم المنتج الحقيقي من غير كلام مثير أو ادعاءات طبية، ومن غير ما تخبي المنتج بيعمل إيه (عربي، 20 لـ70 حرف، يبدأ بنوع المنتج). دي الحالة الوحيدة اللي بتغير فيها عنوان في اللفة دي، وسجّل الكود في _hayak_mc_handled علشان مهمة السيو ما ترجعش تلمس العنوان.
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
  - hayak_core_product_category_last_sweep (المفتاح time)
  - hayak_core_merchant_feed_last_review
  - hayak_seo_pass (المفتاح last_run): لو أقدم من 26 ساعة يبقى مهمة السيو اليومية ما اشتغلتش، بلّغ.
- **مسودات محجوزة من غير صورة:** لكل منتج عليه meta اسمه _hayak_held_no_image (ده تعديل مسموح بيه في القسم ده):
  - هات لينك تاجر بنفس استعلام اللينك اللي في قسم 1 (ج).
  - افتح الصفحة وخد منها صورة المنتج الأساسية، بشرط إن ضلعها الأصغر 500 بكسل أو أكتر وما عليهاش كلام (افحصها بـ mwai_vision بنفس رسالة الصور).
  - ارفعها بـ wp_upload_media على المنتج، وحطها featured بـ wp_set_featured_image. الموقع هينشر المنتج لوحده لما تبقى عنده صورة.
  - لو مفيش صورة تنفع، اكتبه في التقرير علشان صاحب المتجر يرفع صورة.
- **طابور المهام:** هات عدد gla/jobs/update_products/process_item اللي status = pending و scheduled_date_gmt أقدم من ساعتين. لو أكتر من 20، بلّغ (المنتجات مش بتوصل جوجل).
- **منتجات منشورة من غير صورة:** لو فيه، يبقى خطأ في الحارس (Product Guard). بلّغ عنه.

## 4) التقرير
اكتب رد قصير باللهجة المصري:
- **أول حاجة: جدول "منتجات متاحة مش ظاهرة في جوجل"** من القائمة اللي في أول قسم 2: رقم المنتج، اسمه، السبب بالعربي البسيط، واللي هيحصل: (اتصلح النهارده / الموقع هيبعته تاني لوحده / محتاج صورة حقيقية منك / محتاج Request review منك / محتاج لوجات الاستضافة). ولو الجدول فاضي قول ده صراحة.
- الأقسام: نتيجة لفة الإضافة، عدد اللي كسر القاعدة، كام منتج حليته من تاجر ومن فين لفين، وأي منتج شايف إنه في قسم غلط (بلاغ بس).
- عدلت كام منتج وليه، مع الأرقام.
- مشاكل جوجل: العدد امبارح كان كام والنهارده كام.
- الحاجات اللي محتاجة تدخل من صاحب المتجر (صور، Request review، تصنيف تاجر جديد للخريطة، منتجات مش من تاجر).
- أي نظام واقف.

لو مفيش حاجة جديدة وجدول المنتجات المش ظاهرة فاضي، قول سطر واحد: "كله تمام، مفيش جديد". لو الجدول فيه منتجات، لازم يتكتب حتى لو مفيش جديد.
وفي الآخر حدّث hayak_daily_pass (last_run والأرقام).
