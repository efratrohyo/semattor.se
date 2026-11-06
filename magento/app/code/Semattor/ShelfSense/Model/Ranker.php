<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Semattor\ShelfSense\Model\Source\SortMode;

/**
 * Pure ranking: given the products in a category and their original positions,
 * returns them in the order the chosen strategy wants.
 */
class Ranker
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Config $config,
        private readonly Learning $learning,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param array<int,int> $baseline productId => original position
     * @param string[] $pinnedSkus
     * @return RankedItem[] in final order
     */
    public function rank(
        string $mode,
        array $baseline,
        array $pinnedSkus = [],
        int $categoryId = 0,
        array $testSkus = []
    ): array
    {
        if ($baseline === []) {
            return [];
        }
        $items = $this->loadItems($baseline);
        if ($items === []) {
            return [];
        }

        $pinIndex = array_flip(array_map('mb_strtolower', array_values($pinnedSkus)));
        foreach ($items as $item) {
            $key = mb_strtolower($item->sku);
            if (isset($pinIndex[$key])) {
                $item->pinned = true;
                $item->pinIndex = $pinIndex[$key];
            }
        }

        if ($mode === SortMode::SMART) {
            $this->scoreSmart($items);
        }
        if ($mode === SortMode::AUTO) {
            $this->learning->score($categoryId, $items);
        }

        $strategy = $this->comparator($mode);
        $oosLast = $this->config->isOutOfStockLast();

        $ownBrandFirst = $this->config->isOwnBrandFirst();
        usort($items, static function (RankedItem $a, RankedItem $b) use ($strategy, $oosLast, $ownBrandFirst): int {
            // 1) Sold-out / disabled last – also when pinned, so a sold out product never sits on top.
            if ($oosLast && $a->sellable !== $b->sellable) {
                return $b->sellable <=> $a->sellable;
            }
            // 2) Pinned SKUs first, in the order the merchant typed them.
            if ($a->pinned || $b->pinned) {
                return $b->pinned <=> $a->pinned ?: $a->pinIndex <=> $b->pinIndex;
            }
            // 3) Own brand (SE Mattor) before other brands.
            if ($ownBrandFirst && $a->ownBrand !== $b->ownBrand) {
                return $b->ownBrand <=> $a->ownBrand;
            }
            // 4) Strategy, falling back to the original manual order (stable + predictable).
            return $strategy($a, $b)
                ?: $a->baselinePosition <=> $b->baselinePosition
                ?: $a->productId <=> $b->productId;
        });

        return $this->placeTestProducts($items, $testSkus);
    }

    /**
     * Test products go to a fixed place (setting, default 5) in the order given, after pinned products.
     * Sold out test products are not moved.
     *
     * @param RankedItem[] $items
     * @param string[] $testSkus
     * @return RankedItem[]
     */
    private function placeTestProducts(array $items, array $testSkus): array
    {
        if ($testSkus === []) {
            return $items;
        }
        $wanted = array_flip(array_map('mb_strtolower', array_values($testSkus)));
        $tests = [];
        foreach ($items as $i => $item) {
            $key = mb_strtolower($item->sku);
            if (isset($wanted[$key]) && !$item->pinned && $item->sellable) {
                $item->testing = true;
                $tests[$wanted[$key]] = $item;
                unset($items[$i]);
            }
        }
        if ($tests === []) {
            return array_values($items);
        }
        ksort($tests);
        $items = array_values($items);
        $pinnedCount = count(array_filter($items, static fn (RankedItem $i): bool => $i->pinned));
        $at = min(count($items), max($pinnedCount, $this->config->getTestPlace() - 1));
        array_splice($items, $at, 0, array_values($tests));
        return $items;
    }

    /**
     * @param string[] $skus
     * @return string[] SKUs that are in the catalog but NOT our own brand
     */
    public function otherBrandSkus(array $skus): array
    {
        if ($skus === [] || !$this->config->isOwnBrandFirst()) {
            return [];
        }
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId(0)
            ->addAttributeToFilter('sku', ['in' => $skus])
            ->addAttributeToSelect([$this->config->getBrandAttribute()]);
        $own = $this->ownBrandOptionIds();
        $other = [];
        foreach ($collection as $product) {
            if (!isset($own[(string)$product->getData($this->config->getBrandAttribute())])) {
                $other[] = (string)$product->getSku();
            }
        }
        return $other;
    }

    /**
     * Option ids of the brand attribute whose label is one of our own brands.
     *
     * @return array<string,bool>
     */
    private function ownBrandOptionIds(): array
    {
        $names = array_map('mb_strtolower', $this->config->getOwnBrands());
        if ($names === []) {
            return [];
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $this->config->getBrandAttribute());
            if (!$attribute || !$attribute->getId() || !$attribute->usesSource()) {
                return [];
            }
            $ids = [];
            foreach ($attribute->setStoreId(0)->getSource()->getAllOptions(false) as $option) {
                if (in_array(mb_strtolower(trim((string)$option['label'])), $names, true)) {
                    $ids[(string)$option['value']] = true;
                }
            }
            return $ids;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Today's order (position numbers, lowest first), without any ShelfSense rules.
     *
     * @param array<int,int> $baseline
     * @return RankedItem[]
     */
    public function currentOrder(array $baseline): array
    {
        if ($baseline === []) {
            return [];
        }
        $items = $this->loadItems($baseline);
        usort($items, static fn (RankedItem $a, RankedItem $b): int =>
            $a->baselinePosition <=> $b->baselinePosition ?: $a->productId <=> $b->productId);
        return $items;
    }

    /**
     * @return callable(RankedItem, RankedItem): int
     */
    private function comparator(string $mode): callable
    {
        return match ($mode) {
            // Newest = most recently added = highest product ID (IDs only ever go up).
            SortMode::NEWEST => static fn (RankedItem $a, RankedItem $b): int =>
                $b->productId <=> $a->productId,
            SortMode::BESTSELLER => static fn (RankedItem $a, RankedItem $b): int =>
                $b->qtySold <=> $a->qtySold
                ?: $b->qtyTrend <=> $a->qtyTrend
                ?: $b->productId <=> $a->productId,
            SortMode::SMART, SortMode::AUTO => static fn (RankedItem $a, RankedItem $b): int =>
                $b->score <=> $a->score,
            default => static fn (): int => 0,
        };
    }

    /**
     * Score = sales (log-dampened, recent trend counts double)
     *       + new-product boost (exponential decay, configurable half-life)
     *       + the merchant's original manual order.
     * Each component is 0..1 within the category, then weighted.
     *
     * @param RankedItem[] $items
     */
    private function scoreSmart(array $items): void
    {
        $weights = $this->config->getSmartWeights();
        $halfLife = $this->config->getNewHalfLifeDays();
        $now = time();
        $count = count($items);

        $salesRaw = [];
        foreach ($items as $i => $item) {
            $salesRaw[$i] = log1p(max(0.0, $item->qtySold) + max(0.0, $item->qtyTrend));
        }
        $maxSales = max($salesRaw) ?: 1.0;

        $byBaseline = $items;
        usort($byBaseline, static fn (RankedItem $a, RankedItem $b): int =>
            $a->baselinePosition <=> $b->baselinePosition ?: $a->productId <=> $b->productId);
        $manualRank = [];
        foreach ($byBaseline as $rank => $item) {
            $manualRank[$item->productId] = $count > 1 ? 1 - $rank / ($count - 1) : 1.0;
        }

        foreach ($items as $i => $item) {
            $ageDays = max(0, ($now - $item->newTimestamp) / 86400);
            $newness = 0.5 ** ($ageDays / $halfLife);

            $item->score = $weights['sales'] * ($salesRaw[$i] / $maxSales)
                + $weights['new'] * $newness
                + $weights['manual'] * $manualRank[$item->productId];
        }
    }

    /**
     * @param array<int,int> $baseline
     * @return RankedItem[]
     */
    private function loadItems(array $baseline): array
    {
        $ids = array_keys($baseline);

        $collection = $this->productCollectionFactory->create();
        // Only products customers can see in a category list. Hidden child products
        // (sizes of a configurable) are ~70% of the rows and are left untouched.
        $collection->setStoreId(0)
            ->addIdFilter($ids)
            ->addAttributeToFilter('visibility', ['in' => [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_BOTH,
            ]])
            ->addAttributeToSelect(['status', 'name', $this->config->getBrandAttribute()]);
        $ownBrandIds = $this->ownBrandOptionIds();

        $skuById = [];
        foreach ($collection as $product) {
            $skuById[(int)$product->getId()] = (string)$product->getSku();
        }
        $stock = $this->loadStock($skuById);
        [$sold, $trend] = $this->loadSales($ids);

        $items = [];
        foreach ($collection as $product) {
            $id = (int)$product->getId();
            $created = strtotime((string)$product->getData('created_at')) ?: 0;

            $item = new RankedItem(
                $id,
                (string)$product->getSku(),
                (int)$baseline[$id],
                $created,
                $sold[$id] ?? 0.0,
                $trend[$id] ?? 0.0,
                ($stock[$id] ?? true) && (int)$product->getData('status') !== Status::STATUS_DISABLED
            );
            $item->name = (string)$product->getName();
            $item->ownBrand = isset($ownBrandIds[(string)$product->getData($this->config->getBrandAttribute())]);
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Salable per product. Uses the MSI stock index of the stocks assigned to the
     * websites (e.g. "Combined Stock" = inventory_stock_2); a product counts as
     * sellable if it is salable in any of them. Falls back to the legacy
     * cataloginventory_stock_status table when MSI is not installed.
     * Products missing from the index are treated as sellable (unknown ≠ sold out).
     *
     * @param array<int,string> $skuById
     * @return array<int,bool>
     */
    private function loadStock(array $skuById): array
    {
        if ($skuById === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $channelTable = $this->resource->getTableName('inventory_stock_sales_channel');

        if ($connection->isTableExists($channelTable)) {
            $stockIds = $connection->fetchCol(
                $connection->select()->distinct()
                    ->from($channelTable, ['stock_id'])
                    ->where('type = ?', 'website')
            );
            $salableBySku = [];
            foreach ($stockIds as $stockId) {
                $table = $this->resource->getTableName('inventory_stock_' . (int)$stockId);
                if (!$connection->isTableExists($table)) {
                    continue;
                }
                $rows = $connection->fetchPairs(
                    $connection->select()
                        ->from($table, ['sku', 'is_salable'])
                        ->where('sku IN (?)', array_values($skuById))
                );
                foreach ($rows as $sku => $salable) {
                    $key = mb_strtolower((string)$sku);
                    $salableBySku[$key] = ($salableBySku[$key] ?? false) || (int)$salable === 1;
                }
            }
            $result = [];
            foreach ($skuById as $id => $sku) {
                $result[$id] = $salableBySku[mb_strtolower($sku)] ?? true;
            }
            return $result;
        }

        $table = $this->resource->getTableName('cataloginventory_stock_status');
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['product_id', 'stock_status'])
                ->where('product_id IN (?)', array_keys($skuById))
                ->where('website_id = ?', 0)
        );
        return array_map(static fn ($s): bool => (int)$s === 1, $rows);
    }

    /**
     * Net units sold per product (ordered - cancelled - refunded), excluding cancelled orders.
     * For configurable products the parent order line carries the configurable's ID, so the
     * configurable in the category gets credit for every size/colour variant sold.
     *
     * @param int[] $ids
     * @return array{0: array<int,float>, 1: array<int,float>}
     */
    private function loadSales(array $ids): array
    {
        $connection = $this->resource->getConnection('sales');
        $days = $this->config->getBestsellerDays();
        $trendDays = min($days, $this->config->getTrendDays());
        $from = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $trendFrom = gmdate('Y-m-d H:i:s', time() - $trendDays * 86400);

        // Grouped products are sold as their child lines, so credit the parent with them.
        $childToParent = $this->groupedChildren($ids);
        $inCategory = array_flip($ids);
        $lookupIds = array_values(array_unique(array_merge($ids, array_keys($childToParent))));

        $net = 'GREATEST(oi.qty_ordered - IFNULL(oi.qty_canceled, 0) - IFNULL(oi.qty_refunded, 0), 0)';
        $select = $connection->select()
            ->from(['oi' => $this->resource->getTableName('sales_order_item', 'sales')], [
                'product_id',
                'qty' => new Expression("SUM($net)"),
                'trend' => new Expression($connection->quoteInto(
                    "SUM(IF(oi.created_at >= ?, $net, 0))",
                    $trendFrom
                )),
            ])
            ->join(
                ['o' => $this->resource->getTableName('sales_order', 'sales')],
                'o.entity_id = oi.order_id',
                []
            )
            ->where('oi.product_id IN (?)', $lookupIds)
            ->where('oi.created_at >= ?', $from)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group('oi.product_id');

        $sold = $trend = [];
        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int)$row['product_id'];
            $targets = $childToParent[$productId] ?? [];
            if (isset($inCategory[$productId])) {
                $targets[] = $productId;
            }
            foreach (array_unique($targets) as $target) {
                $sold[$target] = ($sold[$target] ?? 0.0) + (float)$row['qty'];
                $trend[$target] = ($trend[$target] ?? 0.0) + (float)$row['trend'];
            }
        }
        return [$sold, $trend];
    }

    /**
     * @param int[] $ids
     * @return array<int,int[]> childId => grouped parent IDs (only parents in $ids)
     */
    private function groupedChildren(array $ids): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_link'), ['product_id', 'linked_product_id'])
                ->where('product_id IN (?)', $ids)
                ->where('link_type_id = ?', \Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED)
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['linked_product_id']][] = (int)$row['product_id'];
        }
        return $map;
    }
}
