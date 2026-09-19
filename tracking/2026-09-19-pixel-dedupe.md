# Pixel de-duplication — 19 Sep 2026

Tag Assistant on a normal COD order showed every All-Pages tag firing 2× and every
purchase tag (Snap, TikTok, Meta, Google Ads) firing 3×. Nothing was wrong with any
one pixel; the *triggers* were firing that many times.

## What the site actually does (server-side reads, not guesses)

| Who | Injects | Effect |
|---|---|---|
| GTM4WP | `GTM-MBJVPZW` once | fine (Site Kit's duplicate snippet was already switched off) |
| Site Kit — GA4 module | `gtag.js` + `config GT-TNCCTFBN` + `config AW-17449085869` | extra "Container Loaded" |
| Site Kit — conversion tracking (`googlesitekit_conversion_tracking.enabled`) | `gtag('event','purchase')` on the thank-you page | **purchase #2** in the shared `dataLayer` |
| Google Listings & Ads | `gtag.js` + `config AW-17449085869 {groups:GLA}` + `gtag('event','purchase')` | extra "Container Loaded" + **purchase #3** |
| Hayak Core | `dataLayer.push({event:'purchase', event_id:'hy-<id>', …})` | **purchase #1** (the one with real data) |
| TikTok for WooCommerce | its own `ttq` bootstrap for pixel `D40TL6BC77U0J94MB2F0` + `identify` + `CompletePayment` | not a dataLayer event, but it defines `window.ttq` **before** GTM's two Custom-HTML base tags re-bootstrap it → those two tags throw ("Failed") |
| Theme (Flatsome child), Elementor custom code, taager-addon, hayak-taager-sync | nothing | clean |

Both Site Kit and GLA mark the order (`_googlesitekit_ga_purchase_event_tracked`, `_gla_tracked`)
after their first render, so a server-side re-fetch never sees their snippets — the order meta is
the proof they fired.

Meta "Failed" is a different thing: the official Meta template calls `gtmOnFailure` only when
`connect.facebook.net/en_US/fbevents.js` does not load (see the template source). That is an
ad-blocker / privacy-shield in the testing browser, not a container fault; verify in Events
Manager → Test Events from a clean profile.

## Fix — nothing touched in the third-party plugins

1. **Hayak Core 2.3.1**: the event is now `hayak_purchase` (datalayer class and the quick-order
   upsell payload). `gtag('event','purchase')` from Site Kit / GLA can no longer match our trigger.
   Verified on the live thank-you page: `"event":"hayak_purchase"` ×1, `"event":"purchase"` ×0.
2. **Container `gtm/GTM-MBJVPZW_hayak_dedupe.json`** (built from workspace72):
   - trigger 81 → `CE - hayak_purchase`
   - fallback tag 109 pushes / checks `hayak_purchase`
   - every tag: *Once per page* (`ONCE_PER_LOAD`) — kills the 2× on All Pages regardless of how
     many Google tags share the page
   - TikTok base: one guarded tag (110) that reuses the plugin's `ttq` if present and only
     `load()`s + `instance(id).page()` for pixels 1 and 2; tag 112 removed

Import the JSON with **Merge → Overwrite conflicting**, Preview one order, Publish.
