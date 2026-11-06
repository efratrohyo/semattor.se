# ShelfSense Sorting per category (Semattor_ShelfSense)

Choose, per category, the order customers see under **"Populärt"** (the default sort on the shop).
Customers never see the name of the option. They only see the new order.

| Option | What happens |
|---|---|
| **Standard** *(default)* | Products follow their position numbers, lowest first (-8, -5, -1, 0 …). ShelfSense does not touch the category. |
| **Newest first** | The product added last comes first (highest product ID first). |
| **Most relevant (smart)** | Each product gets a score: 50% sales, 25% how new it is, 25% your own position numbers. Highest score first. See below. |
| **Auto (learning)** | Learns from what customers see, click and buy in this category. Tests new products in a few places and moves up what sells. Unlocks when the category has enough data. See below. |
| **Best sellers** | Most units sold in the last 90 days first. If equal: most sold in the last 30 days, then highest product ID. |

Extra:
- **Own brand first** (global setting, on by default) – in all options except Standard, SE Mattor products come before other brands. In categories without SE Mattor products (e.g. rugs with rubber backing), other brands are sorted as usual.
- **Pinned SKUs on top** (optional) – products you always want first. See below.
- **Sold out products last** (global setting) – sold out or disabled products go last.

## Auto (learning)

**Data:** category pages count which products customers see in the list (at least half on screen)
and which they click. Only numbers per day, per store, category and product are saved
(table `semattor_shelfsense_stats`, kept 120 days). No cookies, no personal data. Bots are skipped.
Turn it off under Settings → "Collect views and clicks".

**When it unlocks:** the Auto card is locked and shows the progress, e.g.
"Collecting data: 9 of 14 days, 640 of 1 000 product views". It unlocks per category when both are reached.

**How it ranks:** for each product: *chance of a click* (views and clicks in this category) ×
*money per click* (orders and clicks of the product). It does not just take the average: products with
little data get a wider guess, so they are sometimes tested higher (Thompson sampling).
Every ~7th place (15%, setting "Testing share") is used to test products Auto knows little about.
What sells moves up, what does not moves down. Pinned SKUs first and sold out last still apply.

**How often:** like all options, every 2 days at night (setting "Re-sort every X days").

**Server load:** only 50% of category page views are measured (setting "Measure X% of category page
views"). A measured page sends one small request when the customer leaves it. Pages that are not
measured do no work at all.

## Pinned SKUs on top

- Write the SKU. For more than one, put a comma between them. For 3 products:
  ```
  124758-84001-997, 845144-310-00, 465749-564-00
  ```
  The first SKU is shown first, then the second, then the third.
- There is no limit on how many you can pin.
- Pinned products go above everything else: sales, newest and score do not matter for them.
  The rest of the products are sorted by the chosen option.
- Use the SKU of the product in this category. For products with sizes, use the main SKU.
  SKUs that are not in the category are ignored. Upper/lower case does not matter.
- If a pinned product is sold out, it goes last (when "Sold out products last" is on).
  When it is back in stock, it goes back to the top at the next update.
- Only works when the category option is not Standard. Standard never changes anything.
- To remove a pin: delete that SKU (and its comma) and save.

## Where it is in Magento

**Catalog → Categories → choose a category → "ShelfSense Sorting"**

1. Click one of the 4 cards: **Standard**, **Newest first**, **Most relevant** or **Best sellers**.
   Each card says in one line what it does.
2. Optional: **Pinned on top** – SKUs you always want first (only shown when a card other than Standard is chosen).
3. Look at the **preview** under the cards. It shows the first 12 products customers will see
   under "Populärt" – before you save. Tags show *Pinned*, *New*, *Sold out* and units sold.
   If a pinned SKU is not in the category, you get a warning.
4. Click **Save**. The order is updated right away, and again every 2 days at night (setting).
   The preview shows when it was last updated.

**Stores → Configuration → Catalog → ShelfSense Sorting** (set once)
Turn on/off, sold out last, number of days for sales, weights for "Most relevant", night schedule.

## Where the data comes from

Everything comes from Magento itself. No external connection is needed.

| Data | Source in Magento |
|---|---|
| Sales | Your orders (all 5 countries together). Cancelled orders, cancelled items and refunds do not count. |
| Newest | The product ID. Each new product gets a higher ID than the one before. |
| Stock | Your MSI stock ("Combined Stock"), the same one that decides if a customer can buy the product. |
| Your order | The position numbers on the category. |

Details:
- All sizes/colours of a configurable product count as sales for that product.
- Grouped products get the sales of the products inside them.

## How "Most relevant (smart)" works

Each product in the category gets a score from 0 to 1:

- **50% sales** – units sold in the last 90 days, and sales in the last 30 days count double.
  The best seller in the category gets the full 50%. Others get a part of it.
  The difference between 0 and 50 sold counts more than the difference between 1 000 and 1 200,
  so one big seller cannot take over the whole list.
- **25% new product** – a brand new product gets the full 25%. It halves every 30 days
  (half after 30 days, a quarter after 60 days, almost nothing after a few months).
  This uses the date the product was created.
- **25% your position numbers** – the product you placed first gets the full 25%,
  the one you placed last gets 0.

Pinned SKUs are always first and sold out products are always last, whatever the score.
You can change the percentages in the settings, for example 40 / 20 / 40.

## Why it works with any theme

ShelfSense does not change any template, layout or frontend code. It only rewrites the
**position numbers** (`catalog_category_product.position`) in the categories where you chose an option.
The shop already sorts "Populärt" by position, so it shows the new order automatically.

- Categories set to **Standard** are never read or changed.
- No products are added to or removed from categories. Only the order changes.
- The first time a category gets an option, the old position numbers are saved in a backup.
  If you go back to **Standard**, the old order comes back exactly.

## REST API (and AI assistant through MCP)

Everything can also be done through the REST API, for example by an AI assistant through the
Magento MCP server. The MCP server reads the guide in `etc/mcp/shelfsense-sorting.md` automatically.

| Method | Endpoint | What it does |
|---|---|---|
| GET | `/V1/shelfsense/categories` | Categories that are not Standard (`?all=1` = all categories) |
| GET | `/V1/shelfsense/categories/{id}` | Option, pinned SKUs, last sort + first 12 products customers see |
| POST | `/V1/shelfsense/categories/{id}/preview` | Preview an option, nothing is saved |
| PUT | `/V1/shelfsense/categories/{id}` | Save option (+ pinned SKUs) and sort now |
| POST | `/V1/shelfsense/categories/{id}/sort` | Sort again now |
| GET / PUT | `/V1/shelfsense/settings` | Read / change the global settings |

Permissions: category endpoints use "Catalog > Categories". Settings use "ShelfSense Sorting"
under Stores > Configuration in the role resources.

## Performance and safety

- **No conflicts by design:** no rewrites, no preferences and no plugins on Magento code.
  It only adds: 2 category fields, 2 small tables, 1 observer (category save), 1 nightly cron job,
  1 admin preview page and 1 command. Nothing on the frontend.
- **Only visible products:** hidden child products (the sizes of a configurable, ~70% of rows)
  are never read or written.
- **Only the top is re-ordered:** the top 300 products (setting "Sort the top X products") get
  an exact new order. The rest keep a fixed position after them, so they almost never change.
  Fewer changed rows = less reindexing for search, Searchanise and feeds.
- **Only changed rows are written**, in batches of 500, in one transaction.
  It only UPDATEs positions. It never adds or removes products.
- Categories set to Standard cost nothing: nothing is loaded or written.
- Indexes in "Update by Schedule" pick up the changes through the normal Magento cron.

## Install

Copy the folder to `app/code/Semattor/ShelfSense` in Magento and run:

```bash
bin/magento module:enable Semattor_ShelfSense
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Command line (optional, for developers)

```bash
bin/magento shelfsense:apply --category=123 --dry-run
```

Shows the exact order with units sold, date and score. To update all categories now:

```bash
bin/magento shelfsense:apply
```

## Good to know

- If a customer chooses a price sort themselves, the customer's choice wins.
- Categories that also show products from sub-categories: only products placed **directly**
  in the category get new positions.
- **Change the Standard order any time:** while another option than Standard is active,
  "Products in Category" shows your **Standard** position numbers (not the ShelfSense order).
  Change them and save as usual. Customers keep seeing the ShelfSense order. Your changes
  show on the shop when you go back to Standard. "Most relevant" also uses them right away
  (25% of the score).
- Position numbers are global in Magento, so the option applies to all countries.
  Sales from all countries count together.
- To uninstall: set all categories back to *Standard* and save (this restores the order)
  before you remove the module.
