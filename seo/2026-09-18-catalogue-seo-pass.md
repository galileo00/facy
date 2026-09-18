# Catalogue SEO + Google Merchant Center pass — hayak.store

Ran against all 685 published products on 2026-09-18. Every change below was
applied to the live site; the "not fixed" section is what could not be done
from here and why.

## Rank Math

| | before | after |
|---|---|---|
| products with no SEO title | 110 | 0 |
| products with no meta description | 110 | 0 |
| products with no focus keyword | 110 | 0 |
| titles cut off mid-word | 45 | 0 |
| titles containing raw template variables (`%title%`, `%sep%`, `%sitename%`) | 112 | 0 |
| meta descriptions over 168 chars (clipped in the SERP) | 64 | 0 |
| product titles containing a stray quote or tab | 3 | 0 |

- The 110 missing entries were written by hand, one per product, from the
  product's own title and short description: title under 60 characters ending
  on a whole word, a 120-165 character Arabic description, and a focus keyword.
- The 112 titles with template variables were the worst find. Someone had
  pasted Rank Math's own placeholders into the per-product title field, so
  Rank Math expanded them and the live `<title>` came out duplicated, e.g.
  `%title% %page% %sep% جهاز حجامة كهربائي …`. Stripped, trimmed to a word
  boundary and given the `| حياك ستور` suffix.
- The 45 mid-word cuts were the same generator truncating at a fixed character
  count: `… وبشر الجب | حياك ستور`. Rebuilt on a word boundary.

## Site-wide Rank Math settings

- Featured images now included in the XML sitemap (was off) — product images
  become discoverable in Google Images.
- Product category pages added to the sitemap (was off) — these are real
  landing pages for a shop.
- Elementor "blocks" and "floating buttons" post types removed from the
  sitemap; they were padding it with non-pages.
- Homepage meta description written (was empty).

## Google Merchant Center

174 issues across 157 products, pulled from the Google for WooCommerce table.

Fixed:
- **Inappropriate title (3)** — rewritten.
- **Description too short (10)** — short descriptions expanded to 250+ chars.
- **Guns and Parts (3)** — all three are nail guns / staplers. Titles now say
  `للنجارة والتنجيد` so the classifier has something to work with. May still
  need a policy appeal in Merchant Center.
- **Personalized advertising: personal hardships (3)** — knee/ankle brace and
  two massage creams. Titles and descriptions no longer lead with the
  customer's pain; they describe the product.
- **Tobacco (1) + a related image flag** — two incense burners were reading as
  vaping hardware. Renamed to `مبخرة بخور وعود`, which is also more accurate.
- **Image too small (7 of 18)** — promoted a larger image from the product's
  own gallery to be the main image. The old main image was pushed into the
  gallery, so nothing was lost and it is one click to revert.
- **8 more products** with the same defect that Google had not flagged yet
  were found by sweeping the whole catalogue and fixed the same way.

Not fixed, and why:
- **Promotional overlay on image (50 disapproved)** — the fix is to pick a
  gallery image without a price/discount badge burned into it. Choosing needs
  eyes on the pixels: the site is not reachable from this sandbox, and the
  store's own AI vision is on a Gemini free tier with a zero-token limit. The
  image dimensions carry no signal that separates a creative from a packshot,
  so a blind swap could replace a good photo with a worse one.
- **22 products with no image anywhere** — no featured image, no gallery, no
  attachment, no image in the description. There is nothing to promote. They
  have been set to `dont-sync-and-show` in Google for WooCommerce so they stop
  generating feed errors; set them back once images are uploaded.
- **Product page unavailable (2)** — 38255 and 42969 are published, in stock,
  priced and visible. Looks like a transient crawl failure rather than a real
  404; worth a re-crawl before doing anything.
