<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product\Processor as CategoryProductIndexer;
use Magento\Catalog\Model\Indexer\Product\Category\Processor as ProductCategoryIndexer;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor as FulltextIndexer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Psr\Log\LoggerInterface;
use Semattor\ShelfSense\Model\Source\SortMode;
use Semattor\ShelfSense\Setup\Patch\Data\AddCategoryAttributes as Attr;
use Semattor\ShelfSense\Setup\Patch\Data\AddTestAttributes as TestAttr;

/**
 * Applies (or undoes) ShelfSense for categories, then refreshes indexes and page cache
 * for exactly those categories.
 */
class Applier
{
    private const TAIL = 100000;
    private const TAIL_SOLD_OUT = 200000;

    public function __construct(
        private readonly Config $config,
        private readonly Ranker $ranker,
        private readonly PositionManager $positions,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryProductIndexer $categoryProductIndexer,
        private readonly ProductCategoryIndexer $productCategoryIndexer,
        private readonly FulltextIndexer $fulltextIndexer,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly Learning $learning
    ) {
    }

    /**
     * Auto falls back to Most relevant until the category has enough data.
     */
    public function effectiveMode(int $categoryId, string $mode): string
    {
        if ($mode === SortMode::AUTO && !$this->learning->getReadiness($categoryId)['ready']) {
            return SortMode::SMART;
        }
        return $mode;
    }

    /**
     * @return array{ready: bool, days: int, needDays: int, views: int, needViews: int}
     */
    public function autoReadiness(int $categoryId): array
    {
        return $this->learning->getReadiness($categoryId);
    }

    /**
     * Every category with an active mode, plus every category that still has a backup
     * (so clearing the mode by import/API also restores the old order).
     *
     * @return array<int, array{mode: string, pinned: string[]}> categoryId => settings
     */
    public function getManagedCategories(?array $onlyIds = null): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId(0)
            ->addAttributeToSelect([Attr::ATTR_MODE, Attr::ATTR_PINNED, TestAttr::ATTR_TEST_SKUS, TestAttr::ATTR_TEST_UNTIL, 'name']);

        if ($onlyIds !== null) {
            $collection->addIdFilter($onlyIds);
        } else {
            $backedUp = $this->positions->getBackedUpCategoryIds();
            $collection->addAttributeToFilter([
                ['attribute' => Attr::ATTR_MODE, 'in' => SortMode::activeModes()],
                ['attribute' => 'entity_id', 'in' => $backedUp ?: [0]],
            ], null, 'left');
        }

        $result = [];
        /** @var Category $category */
        foreach ($collection as $category) {
            $result[(int)$category->getId()] = [
                'name' => (string)$category->getName(),
                'mode' => (string)$category->getData(Attr::ATTR_MODE),
                'pinned' => $this->parseSkus((string)$category->getData(Attr::ATTR_PINNED)),
                'testSkus' => $this->parseSkus((string)$category->getData(TestAttr::ATTR_TEST_SKUS)),
                'testUntil' => $this->dateOnly((string)$category->getData(TestAttr::ATTR_TEST_UNTIL)),
            ];
        }
        return $result;
    }

    /**
     * Test products count only until (and including) their end date.
     *
     * @return string[]
     */
    public function activeTests(array $skus, ?string $until): array
    {
        if ($skus === [] || $until === null || $until < gmdate('Y-m-d')) {
            return [];
        }
        return $skus;
    }

    public function dateOnly(string $value): ?string
    {
        $time = $value !== '' ? strtotime($value) : false;
        return $time ? gmdate('Y-m-d', $time) : null;
    }

    /**
     * @param string[] $skus
     * @return string[] SKUs that are another brand than our own
     */
    public function otherBrandSkus(array $skus): array
    {
        return $this->ranker->otherBrandSkus($skus);
    }

    /**
     * @return array<int, array{name: string, mode: string, action: string, changed: int}>
     */
    public function applyAll(?array $onlyIds = null, bool $onlyDue = false): array
    {
        $report = [];
        foreach ($this->getManagedCategories($onlyIds) as $categoryId => $settings) {
            if ($onlyDue && !$this->isDue($categoryId, $settings['mode'], $settings['testUntil'] ?? null)) {
                continue;
            }
            try {
                $report[$categoryId] = $this->applySettings($categoryId, $settings);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('ShelfSense: category %d failed: %s', $categoryId, $e->getMessage()), [
                    'exception' => $e,
                ]);
                $report[$categoryId] = $settings + ['action' => 'error: ' . $e->getMessage(), 'changed' => 0];
            }
        }
        return $report;
    }

    /**
     * Night job: a category is re-sorted when its last sort is older than the interval
     * (default every 2 days). A small margin makes sure "every 2 days" does not slip to 3.
     */
    private function isDue(int $categoryId, string $mode, ?string $testUntil = null): bool
    {
        if (!in_array($mode, SortMode::activeModes(), true)) {
            return true; // Standard with a backup left: restore
        }
        $last = $this->positions->getLastUpdated($categoryId);
        if ($last === null) {
            return true;
        }
        // A test that ended since the last sort: re-sort now so the test product leaves its place.
        if ($testUntil !== null && $testUntil < gmdate('Y-m-d') && strtotime($last . ' UTC') <= strtotime($testUntil . ' 23:59:59 UTC')) {
            return true;
        }
        $interval = $this->config->getIntervalDays() * 86400 - 6 * 3600;
        return strtotime($last . ' UTC') <= time() - $interval;
    }

    /**
     * @param array<int,int>|null $postedPositions positions from the admin "Products in Category" grid.
     *        While ShelfSense is active that grid shows and edits the Standard order, so these
     *        are saved as the new Standard order before sorting (or restoring).
     */
    public function applyCategory(int $categoryId, ?array $postedPositions = null): array
    {
        $settings = $this->getManagedCategories([$categoryId])[$categoryId] ?? null;
        if ($settings === null) {
            return [];
        }
        if ($postedPositions !== null
            && $this->config->isEnabled()
            && (in_array($settings['mode'], SortMode::activeModes(), true) || $this->positions->hasBackup($categoryId))
        ) {
            $this->positions->captureBaseline($categoryId, $postedPositions);
        }
        return $this->applySettings($categoryId, $settings);
    }

    /**
     * Ranking preview without touching the database.
     *
     * @return RankedItem[]
     */
    public function preview(int $categoryId): array
    {
        $settings = $this->getManagedCategories([$categoryId])[$categoryId] ?? null;
        if ($settings === null || !in_array($settings['mode'], SortMode::activeModes(), true)) {
            return [];
        }
        return $this->ranker->rank(
            $this->effectiveMode($categoryId, $settings['mode']),
            $this->positions->getBaseline($categoryId),
            $settings['pinned'],
            $categoryId,
            $this->activeTests($settings['testSkus'], $settings['testUntil'])
        );
    }

    /**
     * Live preview for the admin form, using the values on screen (not yet saved).
     *
     * @return array{total: int, items: array<int, array<string, mixed>>, notInCategory: string[], lastUpdated: ?string}
     */
    public function previewWith(
        int $categoryId,
        string $mode,
        string $pinnedRaw,
        int $limit = 12,
        ?string $testRaw = null,
        ?string $testUntil = null
    ): array {
        if ($testRaw === null) {
            $saved = $this->getManagedCategories([$categoryId])[$categoryId] ?? [];
            $testSkus = $saved['testSkus'] ?? [];
            $testUntil = $saved['testUntil'] ?? null;
        } else {
            $testSkus = $this->parseSkus($testRaw);
            $testUntil = $this->dateOnly((string)$testUntil);
        }
        $baseline = $this->positions->getBaseline($categoryId);
        $active = in_array($mode, SortMode::activeModes(), true);
        $readiness = $this->learning->getReadiness($categoryId);
        $mode = $this->effectiveMode($categoryId, $mode);
        $pinned = $active ? $this->parseSkus($pinnedRaw) : [];
        $tests = $active ? $this->activeTests($testSkus, $testUntil) : [];

        $items = $active
            ? $this->ranker->rank($mode, $baseline, $pinned, $categoryId, $tests)
            : $this->ranker->currentOrder($baseline);

        $inCategory = [];
        foreach ($items as $item) {
            $inCategory[mb_strtolower($item->sku)] = true;
        }
        $notInCategory = array_values(array_filter(
            array_merge($pinned, $tests),
            static fn (string $sku): bool => !isset($inCategory[mb_strtolower($sku)])
        ));

        $rows = [];
        $now = time();
        foreach (array_slice($items, 0, $limit) as $item) {
            $rows[] = [
                'id' => $item->productId,
                'sku' => $item->sku,
                'name' => $item->name,
                'position' => $item->baselinePosition,
                'sold' => (int)round($item->qtySold),
                'isNew' => $item->newTimestamp > $now - 60 * 86400,
                'soldOut' => !$item->sellable,
                'pinned' => $item->pinned,
                'score' => $mode === SortMode::SMART ? round($item->score, 2) : null,
                'views' => $item->views,
                'clicks' => $item->clicks,
                'ownBrand' => $item->ownBrand,
                'testing' => $item->testing,
            ];
        }

        return [
            'total' => count($items),
            'items' => $rows,
            'notInCategory' => $notInCategory,
            'lastUpdated' => $this->positions->getLastUpdated($categoryId),
            'auto' => $readiness,
            'intervalDays' => $this->config->getIntervalDays(),
            'otherBrands' => $this->otherBrandSkus(array_merge($pinned, $tests)),
            'testUntil' => $tests ? $testUntil : null,
            'usedMode' => $active ? $mode : SortMode::STANDARD,
        ];
    }

    private function applySettings(int $categoryId, array $settings): array
    {
        if (!$this->config->isEnabled()) {
            return $settings + ['action' => 'skipped (module disabled)', 'changed' => 0];
        }

        $mode = $settings['mode'];
        if (in_array($mode, SortMode::activeModes(), true)) {
            $this->positions->backupMissing($categoryId);
            $ranked = $this->ranker->rank(
                $this->effectiveMode($categoryId, $mode),
                $this->positions->getBaseline($categoryId),
                $settings['pinned'],
                $categoryId,
                $this->activeTests($settings['testSkus'] ?? [], $settings['testUntil'] ?? null)
            );
            $changed = $this->positions->write($categoryId, $this->targetPositions($ranked));
            $this->positions->markUpdated($categoryId, count($changed));
            $action = 'sorted';
        } elseif ($this->positions->hasBackup($categoryId)) {
            $changed = $this->positions->restore($categoryId);
            $this->positions->clearUpdated($categoryId);
            $action = 'restored original order';
        } else {
            return $settings + ['action' => 'untouched (Standard)', 'changed' => 0];
        }

        if ($changed !== []) {
            $this->refresh($categoryId, $changed);
        }
        return $settings + ['action' => $action, 'changed' => count($changed)];
    }

    /**
     * Top X products get 1, 2, 3 … in the new order. The rest get a fixed number based on
     * their original position, so they only change when a product enters or leaves the top.
     * This keeps nightly writes (and the reindexing they cause) small.
     *
     * @param RankedItem[] $ranked
     * @return array<int,int> productId => position
     */
    private function targetPositions(array $ranked): array
    {
        $top = $this->config->getTopCount();
        $oosLast = $this->config->isOutOfStockLast();
        $target = [];
        foreach (array_values($ranked) as $i => $item) {
            if ($top === 0 || $i < $top) {
                $target[$item->productId] = $i + 1;
                continue;
            }
            $base = ($oosLast && !$item->sellable) ? self::TAIL_SOLD_OUT : self::TAIL;
            $target[$item->productId] = $base + $item->baselinePosition;
        }
        return $target;
    }

    /**
     * @param int[] $productIds
     */
    private function refresh(int $categoryId, array $productIds): void
    {
        // Each processor is a no-op when its indexer is "Update by Schedule":
        // the mview triggers on catalog_category_product pick the change up instead.
        $this->categoryProductIndexer->reindexRow($categoryId);
        $this->productCategoryIndexer->reindexList($productIds);
        $this->fulltextIndexer->reindexList($productIds);

        $tags = [Category::CACHE_TAG . '_' . $categoryId, 'cat_c_p_' . $categoryId];
        $this->cacheContext->registerEntities(Category::CACHE_TAG, [$categoryId]);
        $this->cacheContext->registerTags(['cat_c_p_' . $categoryId]);
        // Flushes built-in FPC and Varnish for this category only.
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        $this->cache->clean($tags);
    }

    /**
     * @return string[]
     */
    public function parseSkus(string $raw): array
    {
        $skus = preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($skus));
    }
}
