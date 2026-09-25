# مهمة يومية: لفة حياك ستور (Cowork)

إنت مسؤول عن متابعة يومية لمتجر hayak.store (ووكومرس + Rank Math + Google for WooCommerce + مزامنة تاجر). اشتغل من خلال موصّل hayak_store: wp_db_query للقراءة، و wp_update_post و wp_update_post_meta و wc_update_product و wp_update_option للتعديل. بادئة الجداول 6F27TMRe_.

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
- **القسم (لو غير مصنّف بس):**
  - الأقسام: 298 المنزل والمطبخ، 299 الإلكترونيات، 300 الصحة والجمال، 301 الترفيه والألعاب، 302 الرياضة واللياقة، 303 السيارة، 188 أدوات وإصلاحات.
  - الإضافة (Hayak_Product_Category) بتصنّف لوحدها حسب كود تاجر. لو لقيت منتج لسه غير مصنّف، صنّفه بإيدك واكتب الـSKU بتاعه في التقرير.
- **الوصف القصير جدًا:** اكتب وصف من 400 لـ900 حرف: جملة افتتاحية، وبعدها "المميزات:" و5-7 سطور تبدأ بـ"- ". الحقائق من نص المنتج بس.
- احفظ من خلال wc_update_product (العنوان/الوصف) و wp_update_post_meta (حقول Rank Math)، علشان المزامنة مع جوجل تشتغل.

## 2) جوجل مرشنت سنتر
- اعمل عدد لـ6F27TMRe_gla_merchant_issues حسب issue و severity، وقارنه بـmc_counts بتاع امبارح.
- اشتغل بس على المنتجات المنشورة المتاحة اللي حالتها DISAPPROVED، واللي مش متسجل عليها meta اسمه _hayak_mc_handled فيه نفس كود المشكلة. بعد ما تتعامل مع أي منتج، ضيف الكود للـmeta ده.
- **صورة عليها كلام أو صغيرة (image_unwanted_overlays / image_too_small):** اختار لجوجل صورة نظيفة من صور المنتج نفسه. صورة الموقع (الـfeatured) ما تتغيرش.
  1. هات من 6F27TMRe_postmeta قيم _thumbnail_id و _product_image_gallery و _hayak_feed_image و _hayak_feed_rejected و _hayak_feed_image_at.
     - لو _hayak_feed_image_at من أقل من 3 أيام، استنى: جوجل لسه ما راجعش الصورة اللي اتبعتت.
  2. الصورة اللي بتتبعت دلوقتي (_hayak_feed_image، ولو فاضي الـfeatured) اعتبرها مرفوضة، لأن جوجل رفضها.
  3. هات مسار كل صورة تانية مش في _hayak_feed_rejected (_wp_attached_file)، ومقاسها (أول "width" و "height" في _wp_attachment_metadata).
  4. افحص الصور واحدة واحدة بالترتيب بـ mwai_vision، على الرابط https://hayak.store/wp-content/uploads/<file>، بالرسالة دي بالظبط:
     "Google Merchant Center disapproves a product's main image if ANYTHING was added on top of the photo: promotional or marketing text in any language, prices, discount or free-delivery badges, warranty seals, logo stamps, watermarks, stickers, arrows or callouts, icons, frames or borders, or if it is a collage or infographic. Text physically printed on the product itself or on its retail packaging is allowed. Inspect the image carefully, including all four corners and edges, and answer ONLY with JSON: {\"clean\": true|false, \"found\": \"what you found\"}."
     - وقف عند أول صورة نظيفة ضلعها الأصغر 500 بكسل أو أكتر. لو مفيش، خد أول صورة نظيفة ضلعها 250 أو أكتر.
  5. سجّل النتيجة بـ wp_update_post_meta:
     - _hayak_feed_image = الصورة النظيفة.
     - _hayak_feed_rejected = القائمة القديمة + الصورة المرفوضة + أي صورة لقيتها عليها كلام (array أرقام).
     - _hayak_feed_image_at = الوقت الحالي (unix).
     بعدها احفظ المنتج بـ wc_update_product بـ status = publish، علشان يتبعت لجوجل تاني.
  6. لو كل الصور عليها كلام، أو المنتج صورة واحدة بس: حط الصور كلها في _hayak_feed_rejected، وما تحطش _hayak_feed_image، واكتبه في قائمة "محتاج صورة حقيقية".
  7. **حدود الاستهلاك:**
     - أقصى حاجة 15 منتج و60 فحص vision في التشغيلة.
     - لو mwai_vision رجع 429 أو quota، وقّف الفحص خالص وكمّل بكرة. ما تحاولش تاني النهارده.
     - لو رجع timeout أو 503، جرّب مرة كمان بس.
  - كمان المراجعة اليومية Hayak_Merchant_Feed بتجرب الصورة اللي بعدها لو جوجل فضل رافض. أي منتج في option اسمه hayak_core_merchant_feed_report ضيفه لقائمة "محتاج صورة حقيقية".
- **Guns and Parts:**
  - لو أداة اسمها "مسدس" (حرارة، مسامير، تدليك، رش/فوم، سيليكون، تسعير): الموقع بيغير اسمها لوحده في الفيد. قول لصاحب المتجر يدوس "Request review" في المرشنت سنتر.
  - لو لعبة شكل سلاح، أو منتج بيشتغل بطلقات/بارود، أو مجسم سلاح: ما تغيرش الكلام علشان تعدّيه من سياسة جوجل. حط _wc_gla_visibility = dont-sync-and-show واحفظ المنتج.
- **Inappropriate title / Vehicles / Adult:** غيّر العنوان لاسم المنتج الحقيقي من غير كلام مثير أو ادعاءات طبية، ومن غير ما تخبي المنتج بيعمل إيه.
- **Personal hardships:** ما تعملش حاجة. ده منع للإعلانات المخصصة بس.
- **Product page unavailable:** افحص إن المنتج منشور ومتاح. لو كده، يبقى خطأ مؤقت؛ احفظه تاني بس.
- **وظائف مزامنة جوجل الفاشلة:** هات من 6F27TMRe_actionscheduler_actions العدد اللي hook بتاعه gla/jobs/update_products/process_item و status = failed في آخر 24 ساعة، ومعاه الـargs. لو نفس المنتج بيفشل كتير، دوّر على السبب في المنتج نفسه.

## 3) صحة الأنظمة (قراءة بس، بلّغ لو في مشكلة)
- **مزامنة تاجر:** option اسمه hts_last_run لازم يكون عمره أقل من 16 ساعة، وإلا الإكستنشن مش شغال. وفي hts_report، عد نتايج آخر run اللي action = review وسبب كل واحدة، وأهمها ambiguous_sku.
- **المنتجات اللي اتنقلت للمهملات من آخر تشغيلة:** كل منتج كان منشور لازم يكون له قاعدة في option اسمه hayak_redirect_map ('removed_product:<ID>')، ووجهتها صفحة منشورة.
- **المهام المجدولة:** كل option من دول لازم يكون اتحدث في آخر 26 ساعة:
  - hayak_core_product_text_last_sweep
  - hayak_core_product_guard_last_sweep
  - hayak_core_product_category_last_sweep
  - hayak_core_merchant_feed_last_review
- **مسودات محجوزة من غير صورة:** عدد المنتجات اللي عليها meta اسمه _hayak_held_no_image. اكتبها في التقرير علشان صاحب المتجر يرفع لها صور.
- **منتجات منشورة من غير صورة:** لو فيه، يبقى خطأ في الحارس (Product Guard). بلّغ عنه.

## 4) التقرير
اكتب رد قصير باللهجة المصري:
- عدلت كام منتج وليه، مع الأرقام.
- مشاكل جوجل: العدد امبارح كان كام والنهارده كام.
- الحاجات اللي محتاجة تدخل من صاحب المتجر (صور، Request review).
- أي نظام واقف.

لو مفيش حاجة جديدة، قول سطر واحد: "كله تمام، مفيش جديد".
وفي الآخر حدّث hayak_daily_pass (last_run والأرقام).
