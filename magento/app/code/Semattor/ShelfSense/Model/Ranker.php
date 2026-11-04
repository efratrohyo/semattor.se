<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
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
        private readonly Config $config
    ) {
    }

    /**
     * @param array<int,int> $baseline productId => original position
     * @param string[] $pinnedSkus
     * @return RankedItem[] in final order
     */
    public function rank(string $mode, array $baseline, array $pinnedSkus = []): array
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

        $strategy = $this->comparator($mode);
        $oosLast = $this->config->isOutOfStockLast();

        usort($items, static function (RankedItem $a, RankedItem $b) use ($strategy, $oosLast): int {
            // 1) Pinned SKUs first, in the order the merchant typed them.
            if ($a->pinned || $b->pinned) {
                return $b->pinned <=> $a->pinned ?: $a->pinIndex <=> $b->pinIndex;
            }
            // 2) Sold-out / disabled last.
            if ($oosLast && $a->sellable !== $b->sellable) {
                return $b->sellable <=> $a->sellable;
            }
            // 3) Strategy, falling back to the original manual order (stable + predictable).
            return $strategy($a, $b)
                ?: $a->baselinePosition <=> $b->baselinePosition
                ?: $a->productId <=> $b->productId;
        });

        return $items;
    }

    /**
     * @return callable(RankedItem, RankedItem): int
     */
    private function comparator(string $mode): callable
    {
        return match ($mode) {
            SortMode::NEWEST => static fn (RankedItem $a, RankedItem $b): int =>
                $b->newTimestamp <=> $a->newTimestamp,
            SortMode::BESTSELLER => static fn (RankedItem $a, RankedItem $b): int =>
                $b->qtySold <=> $a->qtySold
                ?: $b->qtyTrend <=> $a->qtyTrend
                ?: $b->newTimestamp <=> $a->newTimestamp,
            SortMode::SMART => static fn (RankedItem $a, RankedItem $b): int =>
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
        $collection->setStoreId(0)
            ->addIdFilter($ids)
            ->addAttributeToSelect(['news_from_date', 'status']);

        $stock = $this->loadStock($ids);
        [$sold, $trend] = $this->loadSales($ids);

        $items = [];
        foreach ($collection as $product) {
            $id = (int)$product->getId();
            $created = strtotime((string)$product->getData('created_at')) ?: 0;
            $newsFrom = $product->getData('news_from_date')
                ? (strtotime((string)$product->getData('news_from_date')) ?: 0)
                : 0;

            $items[] = new RankedItem(
                $id,
                (string)$product->getSku(),
                (int)$baseline[$id],
                max($created, $newsFrom),
                $sold[$id] ?? 0.0,
                $trend[$id] ?? 0.0,
                ($stock[$id] ?? true) && (int)$product->getData('status') !== Status::STATUS_DISABLED
            );
        }
        return $items;
    }

    /**
     * @param int[] $ids
     * @return array<int,bool>
     */
    private function loadStock(array $ids): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('cataloginventory_stock_status');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['product_id', 'stock_status'])
                ->where('product_id IN (?)', $ids)
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

        $net = 'GREATEST(oi.qty_ordered - IFNULL(oi.qty_canceled, 0) - IFNULL(oi.qty_refunded, 0), 0)';
        $select = $connection->select()
            ->from(['oi' => $this->resource->getTableName('sales_order_item', 'sales')], [
                'product_id',
                'qty' => new \Zend_Db_Expr("SUM($net)"),
                'trend' => new \Zend_Db_Expr($connection->quoteInto(
                    "SUM(IF(oi.created_at >= ?, $net, 0))",
                    $trendFrom
                )),
            ])
            ->join(
                ['o' => $this->resource->getTableName('sales_order', 'sales')],
                'o.entity_id = oi.order_id',
                []
            )
            ->where('oi.product_id IN (?)', $ids)
            ->where('oi.created_at >= ?', $from)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group('oi.product_id');

        $sold = $trend = [];
        foreach ($connection->fetchAll($select) as $row) {
            $sold[(int)$row['product_id']] = (float)$row['qty'];
            $trend[(int)$row['product_id']] = (float)$row['trend'];
        }
        return [$sold, $trend];
    }
}
