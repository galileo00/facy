# مهمة يومية: سيو حياك ستور (Cowork)

إنت مسؤول عن سيو يومي لمتجر hayak.store (ووكومرس + Rank Math). اشتغل من خلال موصّل hayak_store: wp_db_query للقراءة (وللكتابة في termmeta بس بـ confirm_write = true)، و wc_update_product و wp_update_post_meta للمنتجات، و wp_update_term لصفحات الأقسام، و mwai_vision لقراءة الصور لو الوصف ناقص. بادئة الجداول 6F27TMRe_.

**المهمة دي بتعمل إيه وما بتعملش إيه:**
- بتعملها: عناوين المنتجات، أوصاف المنتجات، حقول Rank Math للمنتجات (rank_math_title / rank_math_description / rank_math_focus_keyword)، ووصف صفحات الأقسام الرئيسية والفرعية وحقول Rank Math بتاعتها.
- ما تعملهاش أبدًا: أقسام المنتجات (إضافة Hayak Core هي اللي بتصنّف المنتجات من تصنيف تاجر، وممنوع wp_add_post_terms أو wp_create_term أو تغيير parent لأي قسم)، الصور، الأسعار والمخزون، _wc_gla_visibility أو أي meta بتاع جوجل مرشنت، وأي تعديل في إضافة مزامنة تاجر (hayak-taager-sync). دي كلها بتاعة لفة حياك ستور اليومية أو الإضافة.
- شغّل المهمة دي بعد لفة حياك ستور اليومية بساعتين على الأقل. ما يشتغلوش في نفس الوقت.

**قاعدة الحفظ:** العنوان والوصف بـ wc_update_product (name / description) بـ status = publish، وحقول Rank Math بـ wp_update_post_meta. ما تستخدمش wp_update_post على المنتجات. الحفظ بـ wc_update_product بيبعت المنتج لجوجل تاني لوحده، وبيخلي الإضافة تراجع قسمه، فمتقلقش من ده.

## قواعد توفير التوكنز (مهمة جدًا)
- ما تقراش الكتالوج كله أبدًا. هات بس IDs المنتجات اللي طالعة في الفحوصات تحت.
- أي منتج مش طالع في الفحوصات يبقى سليم. ما تفتحوش وما تعدلش فيه.
- الحد الأقصى 30 منتج و3 صفحات أقسام في التشغيلة. لو فيه أكتر، خد الأحدث واترك الباقي لبكرة واكتب عددهم في التقرير.
- اقرأ من كل منتج بس: العنوان، أول 1500 حرف من الوصف، الـSKU، وحقول Rank Math.
- حالة المتابعة محفوظة في option اسمه hayak_seo_pass (JSON: last_run، و products_done عدد، و terms_done عدد، و notes). اقراه في الأول واكتبه في الآخر. ما تلمسش hayak_daily_pass (ده بتاع اللفة اليومية).
- لو أداة رجعت خطأ أو timeout، اعمل mcp_ping. لو ما ردش، وقّف وكمّل بكرة.

## 1) المنتجات (أقصى حاجة 30 في التشغيلة، الأحدث الأول)
هات المنتجات المنشورة المتاحة اللي فيها واحدة على الأقل من دول:
```sql
SELECT p.ID, p.post_title, LENGTH(p.post_content) content_len, s.meta_value sku,
  rt.meta_value rm_title, rd.meta_value rm_desc, rk.meta_value rm_kw, h.meta_value mc_handled, sa.meta_value seo_at
FROM 6F27TMRe_posts p
JOIN 6F27TMRe_postmeta st ON st.post_id = p.ID AND st.meta_key = '_stock_status' AND st.meta_value = 'instock'
LEFT JOIN 6F27TMRe_postmeta s  ON s.post_id = p.ID AND s.meta_key = '_sku'
LEFT JOIN 6F27TMRe_postmeta rt ON rt.post_id = p.ID AND rt.meta_key = 'rank_math_title'
LEFT JOIN 6F27TMRe_postmeta rd ON rd.post_id = p.ID AND rd.meta_key = 'rank_math_description'
LEFT JOIN 6F27TMRe_postmeta rk ON rk.post_id = p.ID AND rk.meta_key = 'rank_math_focus_keyword'
LEFT JOIN 6F27TMRe_postmeta h  ON h.post_id = p.ID AND h.meta_key = '_hayak_mc_handled'
LEFT JOIN 6F27TMRe_postmeta sa ON sa.post_id = p.ID AND sa.meta_key = '_hayak_seo_at'
WHERE p.post_type = 'product' AND p.post_status = 'publish'
  AND (sa.meta_value IS NULL OR sa.meta_value < UNIX_TIMESTAMP() - 7*86400)
  AND (
    rd.meta_value IS NULL OR rd.meta_value = ''
    OR CHAR_LENGTH(p.post_title) < 15
    OR p.post_title NOT REGEXP '[ء-ي]'
    OR p.post_title LIKE '%•%' OR p.post_title LIKE '%  %'
    OR CHAR_LENGTH(p.post_content) < 200
  )
ORDER BY p.ID DESC LIMIT 30;
```

لكل منتج منهم:
- **العنوان:** عربي، من 20 لـ70 حرف، يبدأ بنوع المنتج وبعده أهم مواصفة موجودة في نصه (سعة، واط، مقاس، عدد، موديل).
  - أي عنوان بيبدأ بـ"عرض N" أو "باقة" أو "بكج" أو "باكج" يفضل زي ما هو، لأن ده اللي بيدخّل المنتج قسم العروض.
  - ممنوع: كلام ترويجي (أفضل، خصم، مجانًا، الأصلي)، وادعاءات علاج (علاج، يشفي)، وأي مواصفة مش مكتوبة في نص المنتج.
  - لو العنوان كويس ما تغيرهوش.
  - لو mc_handled فيه كود عن العنوان (title أو adult أو vehicle أو personal)، اللفة اليومية غيّرت العنوان ده علشان جوجل مرشنت. ما تلمسهوش، وكمّل باقي الحقول.
- **Rank Math:**
  - rank_math_title: أقصى حاجة 60 حرف، ومعاهم " | حياك ستور".
  - rank_math_description: من 135 لـ159 حرف.
  - rank_math_focus_keyword: من كلمتين لـ4، ولازم تكون موجودة زي ما هي في الـrank_math_title.
  - لو الحقول موجودة وبالقواعد دي، ما تغيرهاش.
- **الوصف القصير جدًا (أقل من 200 حرف):** اكتب وصف من 400 لـ900 حرف: جملة افتتاحية، وبعدها "المميزات:" و5-7 سطور تبدأ بـ"- ". الحقائق من نص المنتج بس.
- **مصدر الوصف: صفحة المنتج على تاجر.**
  - لو الوصف فاضي أو أقل من 200 حرف ومفيهوش معلومات كفاية، هات لينك المنتج على تاجر: لو عليه meta اسمه _hayak_taager_id، اللينك هو https://taager.com/sa/products/<القيمة>. لو مش موجود:
    ```sql
    SELECT REGEXP_SUBSTR(SUBSTRING(o.option_value, LOCATE(CONCAT('"', s.meta_value, '"'), o.option_value), 1500), 'https://taager[.]com/[a-z]+/products/[0-9]+') url
    FROM 6F27TMRe_postmeta s JOIN 6F27TMRe_options o ON o.option_name = 'hts_report'
    WHERE s.meta_key = '_sku' AND s.post_id = <ID>;
    ```
  - افتح اللينك واقرا وصف المنتج ومواصفاته من هناك، واكتب منه الوصف بنفس الشكل: جملة افتتاحية، وبعدها "المميزات:" و5 لـ7 سطور. الحقائق من صفحة تاجر بس. ما تنقلش نص تاجر زي ما هو، اكتبه بأسلوبك.
  - لو صفحة تاجر ما فتحتش أو مفيهاش وصف، افحص صور المنتج بـ mwai_vision، واكتب وصف بالحاجات اللي باينة في الصورة بس (الشكل، المكونات، الكلام المطبوع على العلبة). ما تخترعش مواصفات. أقصى حاجة 20 فحص vision في التشغيلة، ولو رجع 429 أو quota وقّف الفحص وكمّل بكرة.
  - لو برضه مش كفاية، اكتبه في "محتاج تدخل منك".
- بعد ما تخلص المنتج (حتى لو ما قدرتش تكمّله)، اكتب عليه meta اسمه _hayak_seo_at = الوقت الحالي (unix) علشان ما يرجعش يطلع بكرة. لو عدّلت العنوان أو الوصف احفظ بـ wc_update_product، ولو عدّلت Rank Math بس احفظ برضه بـ wc_update_product بـ status = publish علشان جوجل.

## 2) صفحات الأقسام (أقصى حاجة 3 في التشغيلة)
الأقسام الرئيسية: 298 المنزل والمطبخ، 299 الإلكترونيات، 300 الصحة والجمال، 301 الترفيه والألعاب، 302 الرياضة واللياقة، 303 السيارة، 188 أدوات وإصلاحات، 324 الأزياء والإكسسوارات. الأقسام الفرعية كلها تحت واحد من دول. القسم 19 "غير مصنّف" ما يتلمسش.

هات الأقسام اللي فيها منتجات ومحتاجة شغل:
```sql
SELECT t.term_id, t.name, tt.parent, tt.count, CHAR_LENGTH(tt.description) desc_len,
  (SELECT meta_value FROM 6F27TMRe_termmeta WHERE term_id = t.term_id AND meta_key = 'rank_math_title' LIMIT 1) rm_title,
  (SELECT meta_value FROM 6F27TMRe_termmeta WHERE term_id = t.term_id AND meta_key = 'rank_math_description' LIMIT 1) rm_desc,
  (SELECT meta_value FROM 6F27TMRe_termmeta WHERE term_id = t.term_id AND meta_key = 'rank_math_focus_keyword' LIMIT 1) rm_kw
FROM 6F27TMRe_terms t JOIN 6F27TMRe_term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
WHERE t.term_id <> 19 AND tt.count > 0
HAVING desc_len IS NULL OR desc_len < 200 OR rm_title IS NULL OR rm_title = '' OR rm_desc IS NULL OR rm_desc = '' OR rm_kw IS NULL OR rm_kw = ''
ORDER BY (tt.parent = 0) DESC, tt.count DESC LIMIT 3;
```
لكل قسم:
- **وصف القسم (description):** عربي، من 300 لـ600 حرف، بيقول القسم فيه إيه (أنواع المنتجات اللي فيه فعلًا، هاتها من أسماء 10 منتجات منه بـ SQL)، ولمين، ومن غير كلام ترويجي أو ادعاءات. لو القسم فرعي، اذكر القسم الرئيسي في الجملة الأولى. اكتبه بـ wp_update_term (term_id، taxonomy = product_cat، description).
- **Rank Math للقسم (termmeta):**
  - rank_math_title: أقصى حاجة 60 حرف، ومعاهم " | حياك ستور".
  - rank_math_description: من 135 لـ159 حرف.
  - rank_math_focus_keyword: اسم القسم أو اسمه مع كلمة توضيحية (كلمتين لـ4)، ولازم تكون موجودة زي ما هي في الـrank_math_title.
  - الكتابة في termmeta بـ wp_db_query مع confirm_write = true. لكل مفتاح: لو السطر موجود UPDATE، ولو مش موجود INSERT:
    ```sql
    UPDATE 6F27TMRe_termmeta SET meta_value = '<القيمة>' WHERE term_id = <ID> AND meta_key = 'rank_math_title';
    -- لو UPDATE رجع 0 صفوف:
    INSERT INTO 6F27TMRe_termmeta (term_id, meta_key, meta_value) VALUES (<ID>, 'rank_math_title', '<القيمة>');
    ```
    (نفس الشيء لـ rank_math_description و rank_math_focus_keyword. اهرب علامة ' جوه القيمة بكتابتها مرتين '' .)
  - بعد الكتابة بالـ SQL، اعمل wp_update_term للقسم بنفس الـ description (حتى لو ما اتغيرش) علشان الكاش يتحدث.
- الأقسام الفرعية الجديدة ليها الأولوية بعد الأقسام الرئيسية: اللي اتعملت يوم 2 أكتوبر 2026 (أدوات المطبخ، أجهزة المطبخ، أدوات التنظيف، التخزين والتنظيم، أثاث، إكسسوارات الجوال، شواحن وباور بانك، العناية بالبشرة، أدوات التجميل والتصفيف، العناية بالشعر، منتجات طبية، العناية بالسيارة، أدوات كهربائية، أدوات يدوية)، وأي قسم فرعي جديد بتعمله لفة حياك ستور اليومية بعد كده (بيطلع لوحده في الاستعلام اللي فوق أول ما يبقى فيه منتجات).
- ممنوع تغيّر اسم القسم أو الـslug أو الـparent، وممنوع تعمل أقسام جديدة.

## 3) التقرير
اكتب رد قصير باللهجة المصري:
- عدلت كام منتج (عنوان / وصف / Rank Math) مع الأرقام، وكام واحد فاضل لبكرة.
- عملت كام صفحة قسم، وكام فاضل.
- المنتجات اللي ما قدرتش تجيب لها وصف (مش في تاجر ولا في الصور): "محتاج تدخل منك".
- لو مفيش حاجة طالعة في الفحوصات، قول سطر واحد: "كله تمام، مفيش جديد".
وفي الآخر حدّث hayak_seo_pass (last_run والأرقام).
