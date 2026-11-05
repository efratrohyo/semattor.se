<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Auto (learning).
 *
 * For each product in a category, Auto estimates the money it brings per time it is seen in the list:
 *
 *   value = chance of a click (from views and clicks in THIS category)
 *         × money per click   (from orders and clicks of the product, all categories)
 *
 * It does not use the plain average. It draws a random guess from what it knows (Thompson sampling):
 * a product with lots of data gets a guess close to its real value; a product with little data gets a
 * wide guess, so it sometimes comes out high and gets tested. Known products fill most places. A share
 * of the places (default 15%) is kept for products Auto knows little about.
 *
 * The random draw uses a seed that changes every hour, so the preview and the saved order in the
 * same hour are the same.
 */
class Learning
{
    private const STATS_TABLE = 'semattor_shelfsense_stats';
    private const PRIOR_STRENGTH = 20.0;  // how many "virtual views" the category average is worth
    private const VALUE_SMOOTHING = 5.0;  // how many "virtual clicks" the average money per click is worth

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    /**
     * Can Auto be used for this category yet?
     *
     * @return array{ready: bool, days: int, needDays: int, views: int, needViews: int}
     */
    public function getReadiness(int $categoryId): array
    {
        $c = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::STATS_TABLE);
        $windowFrom = gmdate('Y-m-d', time() - $this->config->getAutoWindowDays() * 86400);

        $first = $c->fetchOne(
            $c->select()->from($table, [new Expression('MIN(stat_date)')])->where('category_id = ?', $categoryId)
        );
        $views = (int)$c->fetchOne(
            $c->select()->from($table, [new Expression('SUM(views)')])
                ->where('category_id = ?', $categoryId)
                ->where('stat_date >= ?', $windowFrom)
        );
        $days = $first ? (int)floor((time() - strtotime($first . ' 00:00:00 UTC')) / 86400) + 1 : 0;
        $needDays = $this->config->getAutoMinDays();
        $needViews = $this->config->getAutoMinViews();

        return [
            'ready' => $days >= $needDays && $views >= $needViews,
            'days' => $days,
            'needDays' => $needDays,
            'views' => $views,
            'needViews' => $needViews,
        ];
    }

    /**
     * Sets $item->score (higher = earlier) and $item->views / $item->clicks.
     *
     * @param RankedItem[] $items
     */
    public function score(int $categoryId, array $items): void
    {
        if ($items === []) {
            return;
        }
        $ids = array_map(static fn (RankedItem $i): int => $i->productId, $items);
        [$views, $clicks] = $this->loadCategoryStats($categoryId, $ids);
        $allClicks = $this->loadAllClicks($ids);
        $revenue = $this->loadRevenue($ids);

        // Category averages = the starting guess for products with little data.
        $totalViews = array_sum($views);
        $totalClicks = array_sum($clicks);
        $avgCtr = $totalViews > 0 ? max(0.001, min(0.5, $totalClicks / $totalViews)) : 0.02;
        $sumClicksAll = array_sum($allClicks);
        $avgValuePerClick = $sumClicksAll > 0 ? array_sum($revenue) / $sumClicksAll : 1.0;
        if ($avgValuePerClick <= 0) {
            $avgValuePerClick = 1.0;
        }

        mt_srand(crc32($categoryId . '|' . gmdate('Y-m-d H')));
        $alpha0 = self::PRIOR_STRENGTH * $avgCtr;
        $beta0 = self::PRIOR_STRENGTH * (1 - $avgCtr);
        $minViews = $this->config->getAutoMinProductViews();

        $known = $unknown = [];
        foreach ($items as $item) {
            $id = $item->productId;
            $v = $views[$id] ?? 0;
            $k = min($clicks[$id] ?? 0, $v);
            $item->views = $v;
            $item->clicks = $k;

            $ctr = $this->betaSample($alpha0 + $k, $beta0 + ($v - $k));
            $valuePerClick = (($revenue[$id] ?? 0.0) + self::VALUE_SMOOTHING * $avgValuePerClick)
                / (($allClicks[$id] ?? 0) + self::VALUE_SMOOTHING);
            $item->score = $ctr * $valuePerClick;

            if ($v >= $minViews) {
                $known[] = $item;
            } else {
                $unknown[] = $item;
            }
        }

        $byScore = static fn (RankedItem $a, RankedItem $b): int => $b->score <=> $a->score;
        usort($known, $byScore);
        usort($unknown, $byScore);

        // Every Nth place is a testing place (15% → every 7th place).
        $share = $this->config->getAutoExploration();
        $every = $share > 0 ? max(2, (int)round(100 / $share)) : PHP_INT_MAX;
        $order = [];
        $place = 0;
        while ($known || $unknown) {
            $place++;
            $testPlace = $place % $every === 0;
            if (($testPlace && $unknown) || !$known) {
                $order[] = array_shift($unknown);
            } else {
                $order[] = array_shift($known);
            }
        }

        // Turn the final order into a score the Ranker can sort on (pins and sold out rules still apply).
        $n = count($order);
        foreach ($order as $i => $item) {
            $item->score = (float)($n - $i);
        }
        mt_srand(); // back to a random seed for the rest of the request
    }

    public function record(int $storeId, int $categoryId, array $viewedIds, array $clickedIds): void
    {
        $rows = [];
        $date = gmdate('Y-m-d');
        foreach (array_unique($viewedIds) as $id) {
            $rows[$id] = ['stat_date' => $date, 'store_id' => $storeId, 'category_id' => $categoryId,
                'product_id' => $id, 'views' => 1, 'clicks' => 0];
        }
        foreach (array_unique($clickedIds) as $id) {
            $rows[$id] ??= ['stat_date' => $date, 'store_id' => $storeId, 'category_id' => $categoryId,
                'product_id' => $id, 'views' => 1, 'clicks' => 0];
            $rows[$id]['clicks'] = 1;
        }
        if ($rows === []) {
            return;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::STATS_TABLE),
            array_values($rows),
            [
                'views' => new Expression('views + VALUES(views)'),
                'clicks' => new Expression('clicks + VALUES(clicks)'),
            ]
        );
    }

    public function purgeOlderThan(int $days): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::STATS_TABLE),
            ['stat_date < ?' => gmdate('Y-m-d', time() - $days * 86400)]
        );
    }

    /**
     * @param int[] $ids
     * @return array{0: array<int,int>, 1: array<int,int>}
     */
    protected function loadCategoryStats(int $categoryId, array $ids): array
    {
        $c = $this->resource->getConnection();
        $rows = $c->fetchAll(
            $c->select()
                ->from($this->resource->getTableName(self::STATS_TABLE), [
                    'product_id',
                    'v' => new Expression('SUM(views)'),
                    'k' => new Expression('SUM(clicks)'),
                ])
                ->where('category_id = ?', $categoryId)
                ->where('product_id IN (?)', $ids)
                ->where('stat_date >= ?', $this->windowFrom())
                ->group('product_id')
        );
        $views = $clicks = [];
        foreach ($rows as $row) {
            $views[(int)$row['product_id']] = (int)$row['v'];
            $clicks[(int)$row['product_id']] = (int)$row['k'];
        }
        return [$views, $clicks];
    }

    /**
     * Clicks on each product in any category list.
     *
     * @param int[] $ids
     * @return array<int,int>
     */
    protected function loadAllClicks(array $ids): array
    {
        $c = $this->resource->getConnection();
        return array_map('intval', $c->fetchPairs(
            $c->select()
                ->from($this->resource->getTableName(self::STATS_TABLE), [
                    'product_id',
                    new Expression('SUM(clicks)'),
                ])
                ->where('product_id IN (?)', $ids)
                ->where('stat_date >= ?', $this->windowFrom())
                ->group('product_id')
        ));
    }

    /**
     * Net revenue per product (base currency) in the learning window, cancelled/refunded excluded.
     *
     * @param int[] $ids
     * @return array<int,float>
     */
    protected function loadRevenue(array $ids): array
    {
        $c = $this->resource->getConnection('sales');
        $rows = $c->fetchPairs(
            $c->select()
                ->from(['oi' => $this->resource->getTableName('sales_order_item', 'sales')], [
                    'product_id',
                    new Expression('SUM(GREATEST(oi.base_row_total - IFNULL(oi.base_amount_refunded, 0), 0))'),
                ])
                ->join(['o' => $this->resource->getTableName('sales_order', 'sales')], 'o.entity_id = oi.order_id', [])
                ->where('oi.product_id IN (?)', $ids)
                ->where('oi.parent_item_id IS NULL')
                ->where('oi.created_at >= ?', $this->windowFrom() . ' 00:00:00')
                ->where('o.state NOT IN (?)', ['canceled', 'closed'])
                ->group('oi.product_id')
        );
        return array_map('floatval', $rows);
    }

    private function windowFrom(): string
    {
        return gmdate('Y-m-d', time() - $this->config->getAutoWindowDays() * 86400);
    }

    private function betaSample(float $a, float $b): float
    {
        $x = $this->gammaSample(max($a, 1e-6));
        $y = $this->gammaSample(max($b, 1e-6));
        return ($x + $y) > 0 ? $x / ($x + $y) : 0.0;
    }

    /**
     * Marsaglia & Tsang gamma sampler (shape k, scale 1).
     */
    private function gammaSample(float $k): float
    {
        if ($k < 1.0) {
            return $this->gammaSample($k + 1.0) * ($this->uniform() ** (1.0 / $k));
        }
        $d = $k - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);
        while (true) {
            do {
                $x = $this->normal();
                $v = 1.0 + $c * $x;
            } while ($v <= 0);
            $v = $v * $v * $v;
            $u = $this->uniform();
            if ($u < 1 - 0.0331 * $x ** 4 || log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    private function normal(): float
    {
        return sqrt(-2.0 * log($this->uniform())) * cos(2.0 * M_PI * $this->uniform());
    }

    private function uniform(): float
    {
        return mt_rand(1, mt_getrandmax() - 1) / mt_getrandmax();
    }
}
