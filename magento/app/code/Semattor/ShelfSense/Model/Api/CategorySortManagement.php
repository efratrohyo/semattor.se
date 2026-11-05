<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model\Api;

use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Semattor\ShelfSense\Api\CategorySortManagementInterface;
use Semattor\ShelfSense\Api\Data\CategoryStatusInterface;
use Semattor\ShelfSense\Model\Applier;
use Semattor\ShelfSense\Model\PositionManager;
use Semattor\ShelfSense\Model\Source\SortMode;
use Semattor\ShelfSense\Setup\Patch\Data\AddCategoryAttributes as Attr;

class CategorySortManagement implements CategorySortManagementInterface
{
    private const MODES = [SortMode::STANDARD, SortMode::NEWEST, SortMode::SMART, SortMode::BESTSELLER, SortMode::AUTO];

    public function __construct(
        private readonly Applier $applier,
        private readonly PositionManager $positions,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    public function getList(bool $all = false): array
    {
        if (!$all) {
            $result = [];
            foreach ($this->applier->getManagedCategories() as $id => $settings) {
                $result[] = $this->status($id, $settings);
            }
            return $result;
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId(0)
            ->addAttributeToSelect(['name', Attr::ATTR_MODE, Attr::ATTR_PINNED])
            ->addFieldToFilter('level', ['gteq' => 2])
            ->setOrder('path', 'ASC');
        $result = [];
        foreach ($collection as $category) {
            $result[] = $this->status((int)$category->getId(), [
                'name' => (string)$category->getName(),
                'mode' => (string)$category->getData(Attr::ATTR_MODE),
                'pinned' => $this->applier->parseSkus((string)$category->getData(Attr::ATTR_PINNED)),
            ]);
        }
        return $result;
    }

    public function get(int $categoryId, int $limit = 12): CategoryStatusInterface
    {
        $settings = $this->settings($categoryId);
        return $this->withPreview(
            $categoryId,
            $settings,
            $settings['mode'],
            implode(', ', $settings['pinned']),
            $limit,
            ''
        );
    }

    public function preview(int $categoryId, string $mode, string $pinnedSkus = '', int $limit = 12): CategoryStatusInterface
    {
        $mode = $this->validMode($mode);
        $settings = $this->settings($categoryId);
        return $this->withPreview(
            $categoryId,
            ['mode' => $mode, 'pinned' => $this->applier->parseSkus($pinnedSkus)] + $settings,
            $mode,
            $pinnedSkus,
            $limit,
            'Preview only. Nothing was saved.'
        );
    }

    public function save(int $categoryId, string $mode, ?string $pinnedSkus = null): CategoryStatusInterface
    {
        $mode = $this->validMode($mode);
        $current = $this->settings($categoryId); // 404 if missing
        if ($mode === SortMode::AUTO && $current['mode'] !== SortMode::AUTO) {
            $readiness = $this->applier->autoReadiness($categoryId);
            if (!$readiness['ready']) {
                throw new InputException(__(
                    'Auto is not available for this category yet. Collected: %1.',
                    $this->progress($readiness)
                ));
            }
        }

        // Save only these two attributes (global, store 0). No full category save,
        // so URL keys, products and other fields are never touched.
        $category = $this->categoryFactory->create()->setStoreId(0);
        $this->categoryResource->load($category, $categoryId);
        $category->setData(Attr::ATTR_MODE, $mode);
        $this->categoryResource->saveAttribute($category, Attr::ATTR_MODE);
        if ($pinnedSkus !== null) {
            $category->setData(Attr::ATTR_PINNED, implode(', ', $this->applier->parseSkus($pinnedSkus)));
            $this->categoryResource->saveAttribute($category, Attr::ATTR_PINNED);
        }

        $result = $this->applier->applyCategory($categoryId);
        return $this->get($categoryId)->withMessage(sprintf(
            'Saved "%s". %s, %d positions changed.',
            $mode,
            ucfirst($result['action'] ?? 'done'),
            (int)($result['changed'] ?? 0)
        ));
    }

    public function sortNow(int $categoryId): CategoryStatusInterface
    {
        $this->settings($categoryId);
        $result = $this->applier->applyCategory($categoryId);
        return $this->get($categoryId)->withMessage(sprintf(
            '%s, %d positions changed.',
            ucfirst($result['action'] ?? 'done'),
            (int)($result['changed'] ?? 0)
        ));
    }

    /**
     * @return array{name: string, mode: string, pinned: string[]}
     */
    private function settings(int $categoryId): array
    {
        $settings = $this->applier->getManagedCategories([$categoryId])[$categoryId] ?? null;
        if ($settings === null) {
            throw new NoSuchEntityException(__('Category %1 does not exist.', $categoryId));
        }
        return $settings;
    }

    private function validMode(string $mode): string
    {
        $mode = strtolower(trim($mode)) ?: SortMode::STANDARD;
        if (!in_array($mode, self::MODES, true)) {
            throw new InputException(__('mode must be one of: %1', implode(', ', self::MODES)));
        }
        return $mode;
    }

    private function status(int $categoryId, array $settings): CategoryStatus
    {
        $mode = $settings['mode'] ?: SortMode::STANDARD;
        return new CategoryStatus(
            $categoryId,
            (string)$settings['name'],
            $mode,
            implode(', ', $settings['pinned']),
            in_array($mode, SortMode::activeModes(), true) ? $this->positions->getLastUpdated($categoryId) : null
        );
    }

    private function withPreview(
        int $categoryId,
        array $settings,
        string $mode,
        string $pinnedRaw,
        int $limit,
        string $message
    ): CategoryStatus {
        $data = $this->applier->previewWith($categoryId, $mode, $pinnedRaw, max(1, min($limit, 100)));
        $items = [];
        foreach ($data['items'] as $i => $row) {
            $items[] = new PreviewItem(
                $i + 1,
                (int)$row['id'],
                (string)$row['sku'],
                (string)$row['name'],
                (int)$row['position'],
                (int)$row['sold'],
                (bool)$row['soldOut'],
                (bool)$row['pinned'],
                (bool)$row['isNew'],
                $row['score'] === null ? null : (float)$row['score'],
                (int)($row['views'] ?? 0),
                (int)($row['clicks'] ?? 0)
            );
        }
        $mode = $mode ?: SortMode::STANDARD;
        if ($mode === SortMode::AUTO && ($data['usedMode'] ?? '') === SortMode::SMART) {
            $message = trim($message . ' Auto does not have enough data yet, so Most relevant is used until it has.');
        }
        return new CategoryStatus(
            $categoryId,
            (string)$settings['name'],
            $mode,
            implode(', ', $settings['pinned']),
            in_array($mode, SortMode::activeModes(), true) ? $data['lastUpdated'] : null,
            (int)$data['total'],
            $data['notInCategory'],
            $items,
            $message,
            (bool)$data['auto']['ready'],
            $this->progress($data['auto'])
        );
    }

    private function progress(array $r): string
    {
        return sprintf(
            '%d of %d days, %d of %d product views',
            min($r['days'], $r['needDays']),
            $r['needDays'],
            min($r['views'], $r['needViews']),
            $r['needViews']
        );
    }
}
