<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads/writes catalog_category_product.position and keeps a backup of the merchant's
 * original positions so "Standard" can always restore them exactly.
 *
 * Only rows that already exist are updated – ShelfSense never adds or removes
 * products from a category.
 */
class PositionManager
{
    private const BACKUP_TABLE = 'semattor_shelfsense_position_backup';
    private const STATE_TABLE = 'semattor_shelfsense_state';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array<int,int> productId => position
     */
    public function getCurrent(int $categoryId): array
    {
        $c = $this->connection();
        return array_map('intval', $c->fetchPairs(
            $c->select()
                ->from($this->table('catalog_category_product'), ['product_id', 'position'])
                ->where('category_id = ?', $categoryId)
        ));
    }

    /**
     * Original positions for every product currently in the category.
     * Products added after ShelfSense took over have no backup yet, so their current
     * position is used (and snapshotted by backupMissing()).
     *
     * @return array<int,int>
     */
    public function getBaseline(int $categoryId): array
    {
        $current = $this->getCurrent($categoryId);
        $backup = $this->getBackup($categoryId);
        return array_replace($current, array_intersect_key($backup, $current));
    }

    public function hasBackup(int $categoryId): bool
    {
        $c = $this->connection();
        return (bool)$c->fetchOne(
            $c->select()->from($this->table(self::BACKUP_TABLE), ['category_id'])
                ->where('category_id = ?', $categoryId)->limit(1)
        );
    }

    /**
     * Snapshot positions of products not yet backed up. Must run BEFORE writing.
     */
    public function backupMissing(int $categoryId): void
    {
        $c = $this->connection();
        $select = $c->select()
            ->from($this->table('catalog_category_product'), ['category_id', 'product_id', 'position'])
            ->where('category_id = ?', $categoryId);
        $c->query($c->insertFromSelect(
            $select,
            $this->table(self::BACKUP_TABLE),
            ['category_id', 'product_id', 'position'],
            AdapterInterface::INSERT_IGNORE
        ));
    }

    /**
     * @param int[] $orderedProductIds
     * @return int[] product IDs whose position actually changed
     */
    public function write(int $categoryId, array $orderedProductIds): array
    {
        $target = [];
        foreach (array_values($orderedProductIds) as $i => $productId) {
            $target[(int)$productId] = $i + 1;
        }
        return $this->applyPositions($categoryId, $target);
    }

    /**
     * Put back the original positions and forget the backup.
     *
     * @return int[] changed product IDs
     */
    public function restore(int $categoryId): array
    {
        $current = $this->getCurrent($categoryId);
        $target = array_intersect_key($this->getBackup($categoryId), $current);
        $changed = $this->applyPositions($categoryId, $target);
        $this->connection()->delete($this->table(self::BACKUP_TABLE), ['category_id = ?' => $categoryId]);
        return $changed;
    }

    /**
     * @return int[] category IDs that ShelfSense currently has a backup for
     */
    public function getBackedUpCategoryIds(): array
    {
        $c = $this->connection();
        return array_map('intval', $c->fetchCol(
            $c->select()->distinct()->from($this->table(self::BACKUP_TABLE), ['category_id'])
        ));
    }

    public function markUpdated(int $categoryId, int $changed): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table(self::STATE_TABLE),
            ['category_id' => $categoryId, 'updated_at' => gmdate('Y-m-d H:i:s'), 'changed' => $changed],
            ['updated_at', 'changed']
        );
    }

    public function clearUpdated(int $categoryId): void
    {
        $this->connection()->delete($this->table(self::STATE_TABLE), ['category_id = ?' => $categoryId]);
    }

    /**
     * @return string|null UTC "Y-m-d H:i:s" of the last time ShelfSense sorted this category
     */
    public function getLastUpdated(int $categoryId): ?string
    {
        $c = $this->connection();
        $value = $c->fetchOne(
            $c->select()->from($this->table(self::STATE_TABLE), ['updated_at'])
                ->where('category_id = ?', $categoryId)
        );
        return $value ?: null;
    }

    /**
     * @param array<int,int> $target productId => position
     * @return int[]
     */
    private function applyPositions(int $categoryId, array $target): array
    {
        $current = $this->getCurrent($categoryId);
        $changed = [];
        foreach ($target as $productId => $position) {
            if (array_key_exists($productId, $current) && $current[$productId] !== $position) {
                $changed[$productId] = $position;
            }
        }
        if ($changed === []) {
            return [];
        }

        $c = $this->connection();
        $c->beginTransaction();
        try {
            foreach ($changed as $productId => $position) {
                $c->update(
                    $this->table('catalog_category_product'),
                    ['position' => $position],
                    ['category_id = ?' => $categoryId, 'product_id = ?' => $productId]
                );
            }
            $c->commit();
        } catch (\Throwable $e) {
            $c->rollBack();
            throw $e;
        }
        return array_keys($changed);
    }

    /**
     * @return array<int,int>
     */
    private function getBackup(int $categoryId): array
    {
        $c = $this->connection();
        return array_map('intval', $c->fetchPairs(
            $c->select()
                ->from($this->table(self::BACKUP_TABLE), ['product_id', 'position'])
                ->where('category_id = ?', $categoryId)
        ));
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
