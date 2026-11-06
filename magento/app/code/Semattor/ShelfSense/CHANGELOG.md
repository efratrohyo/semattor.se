# ShelfSense Sorting – changelog

## 1.6.0 – 2026-11-06
- Fast tracking receiver `pub/shelfsense-track.php` (2-5 ms, does not start Magento), checked every night, falls back to Magento.
- New command `bin/magento shelfsense:install-endpoint`.
- Tracking script starts only when the page has loaded and the browser is idle.

## 1.5.0 – 2026-11-06
- SE Mattor (own brand) always first in every option, also Auto. Auto only tests own brand products.
- Other brands above SE Mattor only with approval (API guard, admin warning).
- Test products with an end date. Change many categories at once (API).

## 1.4.0 – 2026-11-06
- Re-sort every 2 days (setting) instead of every night / every hour.
- Only 50% of category page views measured, one request per page view.

## 1.3.0 – 2026-11-05
- Auto (learning): views and clicks, testing places, unlocks when there is enough data.

## 1.2.0 – 2026-11-05
- REST API and MCP guide/prompts: control and statistics through Claude.

## 1.1.0 – 2026-11-05
- Fix: Standard card was missing. Change the Standard order while another option is active.

## 1.0.0 – 2026-11-05
- First dev install: option cards, live preview, pinned SKUs, own tables and backup.
