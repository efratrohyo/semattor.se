# ShelfSense – smart sorting per category (Semattor_ShelfSense)

Choose, per category, the order customers see under **"Populärt"** (the default sort on the shop).
Customers never see the name of the option. They only see the new order.

| Option | What happens |
|---|---|
| **Standard** *(default)* | Same as today. Products follow their position numbers, lowest first (-8, -5, -1, 0 …). ShelfSense does not touch the category. |
| **Newest first** | The product added last comes first (highest product ID first). |
| **Most relevant (smart)** | Each product gets a score: 50% sales, 25% how new it is, 25% your own position numbers. Highest score first. See below. |
| **Best sellers** | Most units sold in the last 90 days first. If equal: most sold in the last 30 days, then highest product ID. |

Extra:
- **Pinned SKUs on top** (optional) – products you always want first. See below.
- **Sold out products last** (global setting) – sold out or disabled products go last.

## Pinned SKUs on top

- Write one SKU per line. For 3 products, write 3 lines:
  ```
  124758-84001-997
  845144-310-00
  465749-564-00
  ```
  The first line is shown first, then the second, then the third.
- There is no limit on how many you can pin.
- Pinned products go above everything else: sales, newest and score do not matter for them.
  The rest of the products are sorted by the chosen option.
- Use the SKU of the product in this category. For products with sizes, use the main SKU.
  SKUs that are not in the category are ignored. Upper/lower case does not matter.
- If a pinned product is sold out, it goes last (when "Sold out products last" is on).
  When it is back in stock, it goes back to the top at the next update.
- Only works when the category option is not Standard. Standard never changes anything.
- To remove a pin: delete the line and save.

## Where it is in Magento

1. **Catalog → Categories → choose a category → "ShelfSense – smart sorting"**
   Choose the option and click **Save**. The order is updated right away, and again every night.
2. **Stores → Configuration → Catalog → ShelfSense**
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

## Install

Copy the folder to `app/code/Semattor/ShelfSense` in Magento and run:

```bash
bin/magento module:enable Semattor_ShelfSense
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Test without changing anything

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
- To change your own order in a category that uses ShelfSense: first set it to *Standard*
  and save, change the order, then choose the option again. Otherwise ShelfSense overwrites
  your changes at the next update.
- Position numbers are global in Magento, so the option applies to all countries.
  Sales from all countries count together.
- To uninstall: set all categories back to *Standard* and save (this restores the order)
  before you remove the module.
