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

/**
 * Applies (or undoes) ShelfSense for categories, then refreshes indexes and page cache
 * for exactly those categories.
 */
class Applier
{
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
        private readonly LoggerInterface $logger
    ) {
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
            ->addAttributeToSelect([Attr::ATTR_MODE, Attr::ATTR_PINNED, 'name']);

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
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{name: string, mode: string, action: string, changed: int}>
     */
    public function applyAll(?array $onlyIds = null): array
    {
        $report = [];
        foreach ($this->getManagedCategories($onlyIds) as $categoryId => $settings) {
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

    public function applyCategory(int $categoryId): array
    {
        $settings = $this->getManagedCategories([$categoryId])[$categoryId] ?? null;
        return $settings === null ? [] : $this->applySettings($categoryId, $settings);
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
            $settings['mode'],
            $this->positions->getBaseline($categoryId),
            $settings['pinned']
        );
    }

    /**
     * Live preview for the admin form, using the values on screen (not yet saved).
     *
     * @return array{total: int, items: array<int, array<string, mixed>>, notInCategory: string[], lastUpdated: ?string}
     */
    public function previewWith(int $categoryId, string $mode, string $pinnedRaw, int $limit = 12): array
    {
        $baseline = $this->positions->getBaseline($categoryId);
        $active = in_array($mode, SortMode::activeModes(), true);
        $pinned = $active ? $this->parseSkus($pinnedRaw) : [];

        $items = $active
            ? $this->ranker->rank($mode, $baseline, $pinned)
            : $this->ranker->currentOrder($baseline);

        $inCategory = [];
        foreach ($items as $item) {
            $inCategory[mb_strtolower($item->sku)] = true;
        }
        $notInCategory = array_values(array_filter(
            $pinned,
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
            ];
        }

        return [
            'total' => count($items),
            'items' => $rows,
            'notInCategory' => $notInCategory,
            'lastUpdated' => $this->positions->getLastUpdated($categoryId),
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
            $ranked = $this->ranker->rank($mode, $this->positions->getBaseline($categoryId), $settings['pinned']);
            $changed = $this->positions->write(
                $categoryId,
                array_map(static fn (RankedItem $i): int => $i->productId, $ranked)
            );
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
