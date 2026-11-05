<?php
declare(strict_types=1);

namespace Semattor\ShelfSense\Api;

/**
 * ShelfSense Sorting REST API. See etc/mcp/shelfsense-sorting.md.
 */
interface CategorySortManagementInterface
{
    /**
     * List categories with their ShelfSense option.
     *
     * @param bool $all false = only categories that are not Standard; true = every category
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface[]
     */
    public function getList(bool $all = false): array;

    /**
     * Settings, last sort and the first products customers see now.
     *
     * @param int $categoryId
     * @param int $limit
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function get(int $categoryId, int $limit = 12): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Preview an option without saving anything.
     *
     * @param int $categoryId
     * @param string $mode standard|newest|smart|bestseller
     * @param string $pinnedSkus comma separated
     * @param int $limit
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function preview(
        int $categoryId,
        string $mode,
        string $pinnedSkus = '',
        int $limit = 12
    ): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Save the option (and optionally pinned SKUs) and sort the category now.
     * Mode "standard" restores the old order exactly.
     *
     * @param int $categoryId
     * @param string $mode standard|newest|smart|bestseller
     * @param string|null $pinnedSkus comma separated; null = keep current, "" = remove all
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\InputException
     */
    public function save(
        int $categoryId,
        string $mode,
        ?string $pinnedSkus = null
    ): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;

    /**
     * Sort the category again now with its saved option (e.g. after changing settings).
     *
     * @param int $categoryId
     * @return \Semattor\ShelfSense\Api\Data\CategoryStatusInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function sortNow(int $categoryId): \Semattor\ShelfSense\Api\Data\CategoryStatusInterface;
}
