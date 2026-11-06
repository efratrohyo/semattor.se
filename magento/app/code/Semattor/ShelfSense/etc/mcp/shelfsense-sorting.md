# ShelfSense Sorting

How to read and change ShelfSense Sorting through the REST API: which order customers see under "Populärt" on each category, pinned SKUs, and the global settings.

## The merchant's rules (always follow)

1. **SE Mattor is always number one.** In every category and every mode (newest, smart, bestseller, auto), our own brand comes before other brands. This is built in (`ownBrandFirst`).
2. **Never lift another brand without asking.** Before pinning, testing or in any other way showing a product of another brand (Elvara Living, Ruvano Interiors, Kuvani Home, BoTryggt …) above SE Mattor products, explain why in the conversation and get a clear yes. Only then send `allowOtherBrands: true`. Never turn `ownBrandFirst` off without a clear yes (`confirmOtherBrands: true`).
3. A good reason to suggest another brand: the category has no new SE Mattor products for a long time and needs something new, or SE Mattor has no products of that kind (e.g. rugs with rubber backing). Suggest it as a **test product** with an end date, then report how it did.
4. Auto never tests other brands by itself; it only tests SE Mattor products.
5. Always preview first and ask before saving on the live shop.

## Key concepts

- "Populärt" is the default sort on the shop. Customers never see the option name, only the order.
- Each category has a **mode**:
  - `standard` – lowest position number first (the merchant's own order). ShelfSense does not touch the category. Default for all categories. Empty also means standard.
  - `newest` – highest product ID first (added last).
  - `smart` ("Most relevant") – score: 50% sales (last 90 days, last 30 days count double), 25% new product boost (halves every 30 days), 25% the merchant's Standard position numbers. Weights are settings.
  - `bestseller` – most units sold in the last 90 days. All sizes and all 5 countries together.
  - `auto` ("Auto (learning)") – learns from views and clicks in this category list plus orders. Value per view = chance of a click × money per click. Products with little data are tested in every ~7th place (setting `autoExploration`, default 15%). Can only be chosen when the category has enough data (`autoReady`, `autoProgress`); default 14 days and 1 000 measured product views.
- **Pinned SKUs** (comma separated) always come first, in that order. Sold out products always go last, also when pinned.
- **Own brand first** (default on): in every mode except standard, products of our own brand (attribute `brand` = "SE Mattor") come before other brands (Elvara Living, Ruvano Interiors, Kuvani Home …). In a category without own brand products, other brands are sorted as usual. Order of rules: sold out last → pinned → own brand → mode. Preview items have `ownBrand`.
- The setting is **global**: one order per category for all countries (SE, DK, FI, NO, NL).
- The first time a category leaves `standard`, its position numbers are backed up. Setting `standard` again restores them exactly.
- Categories are sorted on save, every `intervalDays` days at night (default 2, run time = setting `schedule`), and with `/sort`.
- Data comes from Magento only: orders, product IDs/created date, MSI stock ("Combined Stock"), position numbers.

## Workflow

### See the current state
1. `GET /V1/shelfsense/categories` – categories that are not standard. Add query `all=1` for every category (id, name, mode).
2. `GET /V1/shelfsense/categories/{id}` – mode, pinned SKUs, last sort and the first 12 products customers see (query `limit` up to 100).

### Change a category
1. Always preview first: `POST /V1/shelfsense/categories/{id}/preview` with body `{"mode": "bestseller", "pinnedSkus": "SKU1, SKU2", "limit": 12}`. Nothing is saved.
2. Show the merchant the preview and ask for a yes before saving on the live shop.
3. Save: `PUT /V1/shelfsense/categories/{id}` with body `{"mode": "bestseller"}`. Add `"pinnedSkus": "SKU1, SKU2"` to change pins, `""` to remove all pins, leave it out to keep them. The category is sorted right away; `message` says how many positions changed.
4. Several categories: call PUT once per category, one after the other (not in parallel).

### Auto (learning)
- Category pages count which products customers see (at least half on screen) and click, for `sampleRate`% of page views (default 50). One small request per measured page. Only numbers per day, per store, category and product. No personal data.
- `GET /V1/shelfsense/categories/{id}` shows `autoReady` and `autoProgress`. Saving `auto` before it is ready returns an error with the progress.
- In the preview, `views` and `clicks` show what Auto has learned (last 28 days).
- Good first candidates: the categories with the most traffic (e.g. Alla Mattor, Köksmattor, Vardagsrumsmattor, Sovrumsmattor).

### Test products
- Try products on a good place for a limited time: `PUT /V1/shelfsense/categories/{id}` with `{"mode": "smart", "testSkus": "SKU1, SKU2", "testUntil": "2026-12-01"}`. They are shown from place `testPlace` (default 5), after pinned products, in the given order, until and including `testUntil`. Then they go back automatically.
- Stop a test early: `"testSkus": ""`.
- Other-brand SKUs are refused unless `allowOtherBrands: true` (rule 2).
- Report after the test: views and clicks in `semattor_shelfsense_stats` and sales of the test SKUs during the test, compared with the SE Mattor products around them.

### Many categories at once
`PUT /V1/shelfsense/categories` with body `{"categories": [{"categoryId": 507, "mode": "newest"}, {"categoryId": 389, "mode": "bestseller"}], "allowOtherBrands": false}`. Max 100. Each item takes the same fields as the single save (`pinnedSkus`, `testSkus`, `testUntil`). Preview the biggest ones first.

### Global settings
- `GET /V1/shelfsense/settings`
- `PUT /V1/shelfsense/settings` – send only what changes, e.g. `{"weightSales": 40, "weightNew": 20, "weightManual": 40}`. Fields: `enabled`, `soldOutLast`, `topCount` (0 = all), `salesDays`, `trendDays`, `newHalfLifeDays`, `weightSales`, `weightNew`, `weightManual`, `schedule` (cron, 5 parts), `trackingEnabled`, `autoMinDays`, `autoMinViews`, `autoExploration` (0-50 %), `intervalDays`, `sampleRate` (1-100 %), `ownBrandFirst`, `ownBrands` (comma separated brand names).
- Settings apply at the next sort. To apply now: `POST /V1/shelfsense/categories/{id}/sort` for each active category.

### Recommended modes
- New-arrivals categories (e.g. "Nyheter"): `newest`.
- Categories named after popularity or top lists ("Populära", "Topplistan"): `bestseller`.
- Big room, size and style categories: `smart`.
- Campaign categories (Black Friday, Cyber Monday, sales): `bestseller` while the campaign runs.
- Small categories with few sales: `standard` or `smart`.

## Statistics for the merchant

Read only, with the database reader:
- List views and clicks: table `semattor_shelfsense_stats` (stat_date, store_id, category_id, product_id, views, clicks). Only a sample of page views is measured (`sampleRate`), so compare ratios (click rate = clicks / views), not raw counts.
- Sales: `sales_order_item` joined with `sales_order` (state not canceled/closed), `parent_item_id IS NULL` for one row per product. Brand: product attribute `brand` (option labels in `eav_attribute_option_value`).
- Last sort per category: `semattor_shelfsense_state`. Mode per category: GET /V1/shelfsense/categories.
- Useful questions: click rate per category and mode, own brand share of the first 24 places, top sellers per category, products with many views but few clicks (weak picture or price), new products that already sell.

## Pitfalls

- Do not use `PUT /V1/categories/{id}` to change these fields; it saves the whole category. Use the ShelfSense endpoints.
- `pinnedSkus` uses the SKU of the product shown in the category (for products with sizes: the main SKU). Unknown SKUs are listed in `notInCategory` and ignored.
- Products that are not visible in the catalog (hidden sizes) are not sorted and do not appear in the preview.
- Only the top `topCount` products (default 300) get an exact new order; the rest keep their Standard order after them.
- Sales numbers on the dev/staging shop are low, so `smart` and `bestseller` look different there than on live.
- Settings endpoints need the "ShelfSense Sorting" configuration permission; category endpoints need the Categories permission.
