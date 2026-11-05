<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Plugin;

use Magento\Catalog\Block\Adminhtml\Category\Tab\Product as ProductsGrid;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Semattor\ShelfSense\Model\PositionManager;

/**
 * Admin only. While a ShelfSense option is active, the "Products in Category" grid shows
 * and edits the Standard order (the backed-up positions) instead of the ShelfSense order.
 * Customers keep seeing the ShelfSense order. The edits show on the shop when the
 * category goes back to Standard.
 */
class StandardPositionsInAdmin
{
    private const ACTIONS = ['catalog_category_edit', 'catalog_category_grid'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly PositionManager $positions,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Values behind the grid's position inputs (the JSON that is posted on save).
     */
    public function afterGetProductsPosition(Category $subject, $result)
    {
        $categoryId = (int)$subject->getId();
        if (!is_array($result) || !$categoryId || !$this->isCategoryEditPage()) {
            return $result;
        }
        $backup = $this->positions->getBackup($categoryId);
        return $backup ? array_replace($result, array_intersect_key($backup, $result)) : $result;
    }

    /**
     * Values shown in the grid's "Position" column.
     */
    public function beforeSetCollection(ProductsGrid $subject, $collection): array
    {
        $category = $subject->getCategory();
        $categoryId = $category ? (int)$category->getId() : 0;
        if (!$categoryId || !$collection instanceof AbstractDb || !$this->positions->hasBackup($categoryId)) {
            return [$collection];
        }

        $select = $collection->getSelect();
        $select->joinLeft(
            ['shelfsense_backup' => $this->resource->getTableName('semattor_shelfsense_position_backup')],
            'shelfsense_backup.product_id = e.entity_id AND shelfsense_backup.category_id = ' . $categoryId,
            []
        );
        $columns = $select->getPart(Select::COLUMNS);
        foreach ($columns as $i => $column) {
            if (($column[2] ?? null) === 'position' && ($column[0] ?? null) === 'at_position') {
                $columns[$i] = [
                    null,
                    new Expression('COALESCE(shelfsense_backup.position, at_position.position)'),
                    'position',
                ];
            }
        }
        $select->setPart(Select::COLUMNS, $columns);

        return [$collection];
    }

    private function isCategoryEditPage(): bool
    {
        return method_exists($this->request, 'getFullActionName')
            && in_array($this->request->getFullActionName(), self::ACTIONS, true);
    }
}
